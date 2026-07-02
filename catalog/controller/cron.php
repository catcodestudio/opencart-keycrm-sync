<?php
namespace Opencart\Catalog\Controller\Extension\CcCrm;

require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/settings.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/logger.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/order_mapper.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/dispatcher.php';

use Opencart\System\Library\Cccrm\Settings;
use Opencart\System\Library\Cccrm\Logger;
use Opencart\System\Library\Cccrm\OrderMapper;
use Opencart\System\Library\Cccrm\Dispatcher;

/**
 * Retries failed CRM pushes that are still under the attempt cap.
 * Registered as extension/cc_crm/cron.retry.
 */
class Cron extends \Opencart\System\Engine\Controller {

	public function retry(): void {
		$settings = new Settings($this->config);
		if ($settings->get('status', '0') !== '1' || $settings->get('retry_enabled', '1') !== '1') {
			return;
		}

		$logger     = new Logger($this->db);
		$dispatcher = new Dispatcher($this->db, $settings);
		$maxAtt     = (int)$settings->get('max_attempts', 5);

		$this->load->model('checkout/order');

		foreach ($logger->retryable($maxAtt) as $row) {
			$orderId = (int)$row['order_id'];
			$slug    = (string)$row['target'];

			$order = $this->model_checkout_order->getOrder($orderId);
			if (!$order) {
				continue;
			}
			$normalized = OrderMapper::build($this->db, $order, $settings);
			if (empty($normalized['items'])) {
				continue;
			}
			$dispatcher->retry($orderId, $slug, $normalized);
		}
	}
}
