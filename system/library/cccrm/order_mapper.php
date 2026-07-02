<?php
namespace Opencart\System\Library\Cccrm;

require_once __DIR__ . '/settings.php';

/**
 * Turns an OpenCart order (as returned by model_checkout_order->getOrder plus
 * the order_product / order_total rows) into a CRM-agnostic normalized array
 * that each adapter formats to its own API shape.
 */
class OrderMapper {
	public static function build($db, array $order, Settings $settings): array {
		$orderId  = (int)$order['order_id'];
		$skipZero = $settings->get('skip_zero', '1') === '1';
		$inclShip = $settings->get('include_ship', '1') === '1';

		$rows = $db->query("SELECT op.product_id, op.name, op.model, op.quantity, op.price, op.total,
				COALESCE(p.sku, '') AS sku
			FROM `" . DB_PREFIX . "order_product` op
			LEFT JOIN `" . DB_PREFIX . "product` p ON p.product_id = op.product_id
			WHERE op.order_id = " . $orderId)->rows;

		$items = [];
		foreach ($rows as $r) {
			$qty   = (float)$r['quantity'];
			$price = round((float)$r['price'], 2);
			if ($qty <= 0) {
				continue;
			}
			if ($skipZero && $price * $qty <= 0.0001) {
				continue;
			}
			$items[] = [
				'sku'   => (string)($r['sku'] !== '' ? $r['sku'] : $r['model']),
				'name'  => (string)$r['name'],
				'price' => $price,
				'qty'   => $qty,
				'total' => round((float)$r['total'], 2),
			];
		}

		$shipCost = 0.0;
		if ($inclShip) {
			$ship = $db->query("SELECT value FROM `" . DB_PREFIX . "order_total` WHERE order_id = " . $orderId . " AND code = 'shipping' LIMIT 1")->row;
			$shipCost = round((float)($ship['value'] ?? 0), 2);
		}

		$paymentCode = '';
		if (isset($order['payment_method']) && is_array($order['payment_method'])) {
			$paymentCode = (string)($order['payment_method']['code'] ?? '');
			$paymentName = (string)($order['payment_method']['name'] ?? '');
		} else {
			$paymentCode = (string)($order['payment_code'] ?? '');
			$paymentName = (string)($order['payment_method'] ?? '');
		}

		$shipName = '';
		if (isset($order['shipping_method']) && is_array($order['shipping_method'])) {
			$shipName = (string)($order['shipping_method']['name'] ?? '');
		} else {
			$shipName = (string)($order['shipping_method'] ?? '');
		}

		$first = trim((string)($order['firstname'] ?? ''));
		$last  = trim((string)($order['lastname'] ?? ''));

		$shipAddress = trim(implode(', ', array_filter([
			(string)($order['shipping_address_1'] ?? ''),
			(string)($order['shipping_address_2'] ?? ''),
		])));
		if ($shipAddress === '') {
			$shipAddress = trim(implode(', ', array_filter([
				(string)($order['payment_address_1'] ?? ''),
				(string)($order['payment_address_2'] ?? ''),
			])));
		}
		$shipCity = (string)($order['shipping_city'] ?? ($order['payment_city'] ?? ''));

		return [
			'order_id'     => $orderId,
			'order_number' => (string)$orderId,
			'source_label' => (string)$settings->get('source_label', 'OpenCart'),
			'grand_total'  => round((float)($order['total'] ?? 0), 2),
			'currency'     => (string)($order['currency_code'] ?? 'UAH'),
			'created_at'   => (string)($order['date_added'] ?? ''),
			'comment'      => (string)($order['comment'] ?? ''),
			'buyer' => [
				'first_name' => $first,
				'last_name'  => $last,
				'full_name'  => trim($first . ' ' . $last),
				'email'      => (string)($order['email'] ?? ''),
				'phone'      => self::phone((string)($order['telephone'] ?? '')),
			],
			'shipping' => [
				'method'  => $shipName,
				'address' => $shipAddress,
				'city'    => $shipCity,
				'cost'    => $shipCost,
			],
			'payment' => [
				'method' => $paymentName,
				'code'   => $paymentCode,
			],
			'items' => $items,
		];
	}

	private static function phone(string $raw): string {
		$digits = preg_replace('/\D+/', '', $raw);
		if ($digits === '') {
			return '';
		}
		if (strlen($digits) === 10 && $digits[0] === '0') {
			$digits = '38' . $digits;
		}
		return '+' . $digits;
	}
}
