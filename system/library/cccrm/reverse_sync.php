<?php
namespace Opencart\System\Library\Cccrm;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/adapters/registry.php';

use Opencart\System\Library\Cccrm\Adapters\Registry;

/**
 * Reverse sync: pulls order updates (status, tracking code) and optionally
 * stock levels from KeyCRM into OpenCart. Read-only towards the CRM — it never
 * sends anything to KeyCRM; while it writes order history the forward-sync
 * event is muted via self::$active, so no push loop is possible.
 *
 * Only adapters exposing the fetch* methods (currently KeyCRM) take part;
 * anything else in the registry is a no-op here.
 */
class ReverseSync {
	/** True while this class writes order history — checked by events.php (anti-loop). */
	public static bool $active = false;

	/** Window overlap so runs never miss updates on the boundary. */
	private const OVERLAP_SECONDS = 600;
	/** First-run lookback when no last-run timestamp is stored yet. */
	private const FIRST_RUN_SECONDS = 86400;
	/** Safety cap on paginated requests per endpoint per run. */
	private const MAX_PAGES = 40;

	private $db;
	private Settings $settings;
	/** Catalog checkout/order model — used for addHistory (status + notify mail). */
	private $orderModel;
	private Logger $logger;

	public function __construct($db, Settings $settings, $orderModel) {
		$this->db         = $db;
		$this->settings   = $settings;
		$this->orderModel = $orderModel;
		$this->logger     = new Logger($db);
	}

	/**
	 * @return array{orders_seen:int, statuses_updated:int, ttn_added:int, stock_updated:int, errors:string[]}
	 */
	public function run(): array {
		$summary = ['orders_seen' => 0, 'statuses_updated' => 0, 'ttn_added' => 0, 'stock_updated' => 0, 'errors' => []];

		$adapter = Registry::get('keycrm');
		if (!$adapter || !method_exists($adapter, 'fetchOrders')) {
			$summary['errors'][] = 'No reverse-capable adapter';
			return $summary;
		}
		$cfg = $this->settings->target('keycrm');
		if (($cfg['api_key'] ?? '') === '') {
			$summary['errors'][] = 'KeyCRM API key is empty';
			return $summary;
		}

		// KeyCRM timestamps are UTC; the window is built in UTC with an overlap.
		$nowUtc  = gmdate('Y-m-d H:i:s');
		$lastRun = (string)$this->settings->get('reverse_last_run', '');
		$fromTs  = $lastRun !== ''
			? (int)strtotime($lastRun . ' UTC') - self::OVERLAP_SECONDS
			: time() - self::FIRST_RUN_SECONDS;
		$fromUtc = gmdate('Y-m-d H:i:s', $fromTs);

		$map = $this->settings->get('reverse_status_map', []);
		if (!is_array($map)) {
			$map = [];
		}
		$notify = $this->settings->get('reverse_notify', '0') === '1';

		$this->ensureTrackingColumn();

		$ordersOk = true;
		$page = 1;
		do {
			$res = $adapter->fetchOrders($cfg, $fromUtc, $nowUtc, $page);
			if (empty($res['ok'])) {
				$summary['errors'][] = 'orders page ' . $page . ': ' . ($res['error'] ?? 'error');
				$ordersOk = false;
				break;
			}
			foreach ($res['data'] as $crmOrder) {
				$this->applyOrder($crmOrder, $map, $notify, $summary);
			}
			$lastPage = (int)($res['last_page'] ?? 1);
			$page++;
		} while ($page <= $lastPage && $page <= self::MAX_PAGES);

		if ($this->settings->get('reverse_stock', '0') === '1') {
			$this->syncStocks($adapter, $cfg, $summary);
		}

		// Advance the window only after a clean pull; a failed run keeps the old
		// mark, so the next run re-covers it (status/TTN application is diff-based
		// and deduplicated, so re-processing is harmless).
		if ($ordersOk) {
			$this->storeLastRun($nowUtc);
		}
		return $summary;
	}

	/** Apply one KeyCRM order (status + tracking code) to its OpenCart order. */
	private function applyOrder(array $crmOrder, array $map, bool $notify, array &$summary): void {
		// Matching: the forward sync writes source_uuid "oc-{order_id}".
		if (!preg_match('/^oc-(\d+)$/', (string)($crmOrder['source_uuid'] ?? ''), $m)) {
			return;
		}
		$orderId = (int)$m[1];

		$row = $this->db->query("SELECT order_id, order_status_id FROM `" . DB_PREFIX . "order`
			WHERE order_id = " . $orderId . " LIMIT 1")->row;
		if (!$row) {
			return;
		}
		$summary['orders_seen']++;
		$current = (int)$row['order_status_id'];

		// Status: apply only when mapped and actually different.
		$crmStatus = (string)(int)($crmOrder['status_id'] ?? 0);
		if ($crmStatus !== '0' && isset($map[$crmStatus]) && (int)$map[$crmStatus] > 0) {
			$target = (int)$map[$crmStatus];
			if ($target !== $current) {
				$this->addHistory($orderId, $target, 'CRM sync — KeyCRM status #' . $crmStatus, $notify);
				$current = $target;
				$summary['statuses_updated']++;
			}
		}

		// Tracking code — one "ТТН: {code}" history comment per code.
		$tracking = trim((string)($crmOrder['shipping']['tracking_code'] ?? ''));
		if ($tracking !== '' && $current > 0 && !$this->trackingRecorded($orderId, $tracking)) {
			$this->addHistory($orderId, $current, 'ТТН: ' . $tracking, $notify);
			$this->rememberTracking($orderId, $tracking);
			$summary['ttn_added']++;
		}
	}

	/** Pull /offers/stocks and update oc_product.quantity by SKU. */
	private function syncStocks($adapter, array $cfg, array &$summary): void {
		if (!method_exists($adapter, 'fetchStocks')) {
			return;
		}
		$page = 1;
		do {
			$res = $adapter->fetchStocks($cfg, $page);
			if (empty($res['ok'])) {
				$summary['errors'][] = 'stocks page ' . $page . ': ' . ($res['error'] ?? 'error');
				break;
			}
			foreach ($res['data'] as $offer) {
				$sku = trim((string)($offer['sku'] ?? ''));
				if ($sku === '' || !isset($offer['quantity'])) {
					continue;
				}
				$qty = (int)$offer['quantity'];
				$this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = " . $qty . "
					WHERE sku = '" . $this->db->escape($sku) . "' AND quantity <> " . $qty);
				$summary['stock_updated'] += (int)$this->db->countAffected();
			}
			$lastPage = (int)($res['last_page'] ?? 1);
			$page++;
		} while ($page <= $lastPage && $page <= self::MAX_PAGES);
	}

	/**
	 * Order history via the checkout/order model so status + notify mail behave
	 * like a native change; self::$active mutes the forward-sync event handler
	 * for the duration, so nothing is ever pushed back to the CRM.
	 */
	private function addHistory(int $orderId, int $statusId, string $comment, bool $notify): void {
		self::$active = true;
		try {
			$this->orderModel->addHistory($orderId, $statusId, $comment, $notify);
		} finally {
			self::$active = false;
		}
	}

	/** Was this tracking code already written to the order? */
	private function trackingRecorded(int $orderId, string $code): bool {
		$row = $this->logger->find($orderId, 'keycrm');
		if ($row !== null) {
			return (string)($row['tracking_code'] ?? '') === $code;
		}
		// No journal row (e.g. order pushed by an older install) — check history.
		$hit = $this->db->query("SELECT COUNT(*) AS c FROM `" . DB_PREFIX . "order_history`
			WHERE order_id = " . $orderId . "
			AND comment LIKE '%" . $this->db->escape('ТТН: ' . $code) . "%'")->row;
		return (int)($hit['c'] ?? 0) > 0;
	}

	private function rememberTracking(int $orderId, string $code): void {
		$this->db->query("UPDATE `" . Logger::table() . "` SET
			tracking_code = '" . $this->db->escape(mb_substr($code, 0, 64)) . "',
			updated_at = NOW()
			WHERE order_id = " . $orderId . " AND target = 'keycrm'");
	}

	/** Upgrade path for installs created before the reverse sync existed. */
	private function ensureTrackingColumn(): void {
		$col = $this->db->query("SHOW COLUMNS FROM `" . Logger::table() . "` LIKE 'tracking_code'")->row;
		if (!$col) {
			$this->db->query("ALTER TABLE `" . Logger::table() . "` ADD COLUMN `tracking_code` VARCHAR(64) DEFAULT NULL");
		}
	}

	/** Persist the last-run timestamp under the module settings (store 0). */
	private function storeLastRun(string $utc): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "setting`
			WHERE store_id = 0 AND `code` = 'module_cc_crm' AND `key` = 'module_cc_crm_reverse_last_run'");
		$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET
			store_id = 0,
			`code` = 'module_cc_crm',
			`key` = 'module_cc_crm_reverse_last_run',
			`value` = '" . $this->db->escape($utc) . "',
			serialized = 0");
	}
}
