<?php
namespace Opencart\System\Library\Cccrm\Adapters;

require_once __DIR__ . '/adapter_interface.php';
require_once dirname(__DIR__) . '/http.php';

use Opencart\System\Library\Cccrm\Http;

/**
 * KeyCRM (openapi.keycrm.app) order create adapter.
 * Auth: Bearer token. Endpoint: POST {base_url}/order.
 * Also exposes the read endpoints used by the reverse sync (orders updated in
 * a window, order statuses, offer stocks). All list endpoints are Laravel-style
 * paginated: {current_page, data: [], last_page, total}.
 * Docs: https://docs.keycrm.app/
 */
class KeycrmAdapter implements AdapterInterface {
	public function slug(): string  { return 'keycrm'; }
	public function label(): string { return 'KeyCRM'; }

	/** Marker for the reverse sync — adapters without these methods are no-ops there. */
	public function supportsReverse(): bool { return true; }

	public function send(array $order, array $config): array {
		$base   = rtrim((string)($config['base_url'] ?? 'https://openapi.keycrm.app/v1'), '/');
		$apiKey = (string)($config['api_key'] ?? '');
		$url    = $base . '/order';

		if ($apiKey === '') {
			return ['ok' => false, 'external_id' => '', 'request' => '', 'response' => '', 'error' => 'KeyCRM API key is empty'];
		}

		$products = [];
		foreach ($order['items'] as $it) {
			$products[] = [
				'sku'        => $it['sku'],
				'name'       => $it['name'],
				'price'      => $it['price'],
				'quantity'   => $it['qty'],
			];
		}

		$uuid = self::sourceUuid((int)$order['order_id'], (string)($config['uuid_prefix'] ?? ''));

		$payload = [
			'source_uuid'     => $uuid,
			'buyer'           => [
				'full_name' => $order['buyer']['full_name'],
				'email'     => $order['buyer']['email'],
				'phone'     => $order['buyer']['phone'],
			],
			'manager_comment' => $order['comment'],
			'products'        => $products,
			// Delivery cost is a top-level field in the KeyCRM order schema;
			// shipping_cost inside shipping{} is silently ignored by the API.
			'shipping_price'  => $order['shipping']['cost'],
			'shipping'        => [
				'shipping_service'        => $order['shipping']['method'],
				'shipping_address_city'   => $order['shipping']['city'],
				'shipping_secondary_line' => $order['shipping']['address'],
			],
		];

		// Add payment only when payment method is specified.
		if (
			isset($order['payment']['method']) &&
			trim((string)$order['payment']['method']) !== ''
		) {
			$payload['payments'] = [[
				'payment_method' => $order['payment']['method'],
				'amount'         => $order['grand_total'],
				'status'         => 'not_paid',
			]];
		}

		// KeyCRM requires either source_id or source_name. Prefer an explicit
		// source_id; otherwise fall back to a named source (auto-created by
		// KeyCRM on first order) so the integration works out of the box.
		if (($config['source_id'] ?? '') !== '') {
			$payload['source_id'] = (int)$config['source_id'];
		} else {
			$payload['source_name'] = (string)($order['source_label'] ?? 'OpenCart') ?: 'OpenCart';
		}

		$res = Http::json('POST', $url, ['Authorization' => 'Bearer ' . $apiKey], $payload);
		$reqJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$decoded = json_decode($res['body'], true);
		$ok = $res['status'] >= 200 && $res['status'] < 300 && is_array($decoded) && !empty($decoded['id']);

		if (!$ok) {
			// ⚠ KeyCRM answers a bare HTTP 500 "Server Error" when an order with the
			// same source_uuid already exists (verified against the live API). That
			// happens after a reinstall/replay — the order IS in the CRM, so look it
			// up and treat it as synced instead of retrying forever.
			$existing = $this->findBySourceUuid($config, $uuid);
			if ($existing !== null) {
				if ($this->sameOrder($existing, $order)) {
					return [
						'ok'          => true,
						'external_id' => (string)$existing['id'],
						'request'     => $reqJson,
						'response'    => json_encode($existing, JSON_UNESCAPED_UNICODE),
						'error'       => '',
					];
				}
				// Same uuid, different order — two stores share one KeyCRM account.
				return [
					'ok'          => false,
					'external_id' => '',
					'request'     => $reqJson,
					'response'    => $res['body'],
					'error'       => 'source_uuid ' . $uuid . ' already belongs to another order in KeyCRM — set a unique UUID prefix for this store',
				];
			}
		}

		return [
			'ok'          => $ok,
			'external_id' => $ok ? (string)$decoded['id'] : '',
			'request'     => $reqJson,
			'response'    => $res['body'],
			'error'       => $ok ? '' : ('HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : '')),
		];
	}

	/**
	 * source_uuid used to tie an OpenCart order to its KeyCRM order. The optional
	 * prefix keeps two stores that share one KeyCRM account from colliding on the
	 * same OpenCart order id.
	 */
	public static function sourceUuid(int $orderId, string $prefix = ''): string {
		$prefix = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $prefix));
		return $prefix !== '' ? 'oc-' . $prefix . '-' . $orderId : 'oc-' . $orderId;
	}

	/** Look up an existing CRM order by source_uuid (returns null when absent). */
	private function findBySourceUuid(array $config, string $uuid): ?array {
		$res = $this->fetchPage($config, '/order', ['limit' => 5, 'filter[source_uuid]' => $uuid]);
		if (empty($res['ok']) || empty($res['data'])) {
			return null;
		}
		foreach ($res['data'] as $row) {
			if ((string)($row['source_uuid'] ?? '') === $uuid) {
				return $row;
			}
		}
		return null;
	}

	/** Is the CRM order the same one we tried to push (total match, 1 cent tolerance)? */
	private function sameOrder(array $crmOrder, array $order): bool {
		$crmTotal = (float)($crmOrder['grand_total'] ?? 0);
		$ourTotal = (float)$order['grand_total'];
		return abs($crmTotal - $ourTotal) < 0.011;
	}

	/**
	 * Orders updated inside a UTC window (reverse sync source).
	 * GET {base}/order?limit=50&page=N&include=shipping
	 *     &filter[updated_between]=FROM,TO&sort=-updated_at
	 *
	 * @return array{ok:bool, data:array, last_page:int, error:string}
	 */
	public function fetchOrders(array $config, string $fromUtc, string $toUtc, int $page): array {
		return $this->fetchPage($config, '/order', [
			'limit'                   => 50,
			'page'                    => $page,
			'include'                 => 'shipping',
			'filter[updated_between]' => $fromUtc . ',' . $toUtc,
			'sort'                    => '-updated_at',
		]);
	}

	/**
	 * Order statuses configured in the KeyCRM account.
	 * GET {base}/order/status — items: {id, name, alias, group_id, is_closing_order, …}.
	 *
	 * @return array{ok:bool, data:array, last_page:int, error:string}
	 */
	public function fetchStatuses(array $config, int $page = 1): array {
		return $this->fetchPage($config, '/order/status', ['limit' => 50, 'page' => $page]);
	}

	/**
	 * Offer stock levels. GET {base}/offers/stocks — items carry sku / quantity.
	 *
	 * @return array{ok:bool, data:array, last_page:int, error:string}
	 */
	public function fetchStocks(array $config, int $page): array {
		return $this->fetchPage($config, '/offers/stocks', ['limit' => 50, 'page' => $page]);
	}

	/** Shared GET for the Laravel-paginated list endpoints. */
	private function fetchPage(array $config, string $path, array $query): array {
		$base   = rtrim((string)($config['base_url'] ?? 'https://openapi.keycrm.app/v1'), '/');
		$apiKey = (string)($config['api_key'] ?? '');

		if ($apiKey === '') {
			return ['ok' => false, 'data' => [], 'last_page' => 0, 'error' => 'KeyCRM API key is empty'];
		}

		$url = $base . $path . '?' . http_build_query($query);
		$res = Http::json('GET', $url, ['Authorization' => 'Bearer ' . $apiKey], null, 30);

		$decoded = json_decode($res['body'], true);
		$ok = $res['status'] >= 200 && $res['status'] < 300 && is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']);

		return [
			'ok'        => $ok,
			'data'      => $ok ? $decoded['data'] : [],
			'last_page' => $ok ? (int)($decoded['last_page'] ?? 1) : 0,
			'error'     => $ok ? '' : ('HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : '')),
		];
	}
}
