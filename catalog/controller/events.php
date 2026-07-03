<?php
namespace Opencart\Catalog\Controller\Extension\CcCrm;

require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/settings.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/logger.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/order_mapper.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/dispatcher.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/reverse_sync.php';

use Opencart\System\Library\Cccrm\Settings;
use Opencart\System\Library\Cccrm\OrderMapper;
use Opencart\System\Library\Cccrm\Dispatcher;
use Opencart\System\Library\Cccrm\ReverseSync;

class Events extends \Opencart\System\Engine\Controller {

	/**
	 * catalog/model/checkout/order/addHistory/after
	 * args: [order_id, order_status_id, comment, notify]
	 */
	public function orderHistoryAdded(string &$route, array &$args, mixed &$output): void {
		// Anti-loop: history written by the reverse sync must never be pushed back.
		if (ReverseSync::$active) {
			return;
		}

		$settings = new Settings($this->config);
		if ($settings->get('status', '0') !== '1') {
			return;
		}
		$orderId  = (int)($args[0] ?? 0);
		$statusId = (int)($args[1] ?? 0);
		if ($orderId <= 0 || $statusId <= 0) {
			return;
		}

		$sendOn = (string)$settings->get('send_on', 'create');
		if ($sendOn === 'status') {
			if ($statusId !== (int)$settings->get('trigger_status', 0)) {
				return;
			}
		}
		// send_on === 'create': fire on the first real status; the sync journal's
		// UNIQUE(order_id,target) key guarantees a single push per target.

		if (!$settings->enabledTargets()) {
			return;
		}

		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		if (!$order) {
			return;
		}

		$normalized = OrderMapper::build($this->db, $order, $settings);
		if (empty($normalized['items'])) {
			return;
		}

		$dispatcher = new Dispatcher($this->db, $settings);
		$results = $dispatcher->dispatch($orderId, $normalized);

		$this->annotate($orderId, $statusId, $results);
	}

	/** Add an internal order note summarizing the sync result. */
	private function annotate(int $orderId, int $statusId, array $results): void {
		$parts = [];
		foreach ($results as $slug => $res) {
			if (!empty($res['skipped'])) {
				continue;
			}
			if (!empty($res['ok'])) {
				$parts[] = $slug . ': OK' . (!empty($res['external_id']) ? ' #' . $res['external_id'] : '');
			} else {
				$parts[] = $slug . ': ' . ($res['error'] ?? 'error');
			}
		}
		if (!$parts) {
			return;
		}
		$this->db->query("INSERT INTO `" . DB_PREFIX . "order_history` SET
			order_id = " . (int)$orderId . ",
			order_status_id = " . (int)$statusId . ",
			notify = 0,
			comment = '" . $this->db->escape('CRM sync — ' . implode('; ', $parts)) . "',
			date_added = NOW()");
	}
}
