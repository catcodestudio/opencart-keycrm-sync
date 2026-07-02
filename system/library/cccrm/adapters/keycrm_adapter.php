<?php
namespace Opencart\System\Library\Cccrm\Adapters;

require_once __DIR__ . '/adapter_interface.php';
require_once dirname(__DIR__) . '/http.php';

use Opencart\System\Library\Cccrm\Http;

/**
 * KeyCRM (openapi.keycrm.app) order create adapter.
 * Auth: Bearer token. Endpoint: POST {base_url}/order.
 * Docs: https://docs.keycrm.app/
 */
class KeycrmAdapter implements AdapterInterface {
	public function slug(): string  { return 'keycrm'; }
	public function label(): string { return 'KeyCRM'; }

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

		$payload = [
			'source_uuid'     => 'oc-' . $order['order_id'],
			'buyer'           => [
				'full_name' => $order['buyer']['full_name'],
				'email'     => $order['buyer']['email'],
				'phone'     => $order['buyer']['phone'],
			],
			'manager_comment' => $order['comment'],
			'products'        => $products,
			'shipping'        => [
				'shipping_service'         => $order['shipping']['method'],
				'shipping_address_city'    => $order['shipping']['city'],
				'shipping_secondary_line'  => $order['shipping']['address'],
				'shipping_cost'            => $order['shipping']['cost'],
			],
			'payments'        => [[
				'payment_method' => $order['payment']['method'],
				'amount'         => $order['grand_total'],
				'status'         => 'not_paid',
			]],
		];

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

		return [
			'ok'          => $ok,
			'external_id' => $ok ? (string)$decoded['id'] : '',
			'request'     => $reqJson,
			'response'    => $res['body'],
			'error'       => $ok ? '' : ('HTTP ' . $res['status'] . ($res['error'] ? ' ' . $res['error'] : '')),
		];
	}
}
