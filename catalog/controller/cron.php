<?php
namespace Opencart\Catalog\Controller\Extension\CcCrm;

require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/settings.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/logger.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/order_mapper.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/dispatcher.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/reverse_sync.php';

use Opencart\System\Library\Cccrm\Settings;
use Opencart\System\Library\Cccrm\Logger;
use Opencart\System\Library\Cccrm\OrderMapper;
use Opencart\System\Library\Cccrm\Dispatcher;
use Opencart\System\Library\Cccrm\ReverseSync;

/**
 * Cron tasks: retry of failed CRM pushes (extension/cc_crm/cron.retry) and the
 * reverse sync pulling status/tracking/stock updates from the CRM
 * (extension/cc_crm/cron.reverse). Both are registered through the OpenCart
 * cron registry, same as every other task of this extension.
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

	/**
	 * Reverse sync: KeyCRM -> OpenCart (order statuses, tracking codes and,
	 * optionally, stock levels). Never sends anything to the CRM.
	 * Registered as extension/cc_crm/cron.reverse.
	 */
	public function reverse(): void {
		$settings = new Settings($this->config);
		if ($settings->get('status', '0') !== '1' || $settings->get('reverse_enabled', '0') !== '1') {
			return;
		}

		$this->load->model('checkout/order');

		$sync    = new ReverseSync($this->db, $settings, $this->model_checkout_order);
		$summary = $sync->run();

		if ($summary['statuses_updated'] || $summary['ttn_added'] || $summary['stock_updated'] || $summary['errors']) {
			$this->log->write('CC CRM reverse sync: ' . json_encode($summary, JSON_UNESCAPED_UNICODE));
		}
	}
}
