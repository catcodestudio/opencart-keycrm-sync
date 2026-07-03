<?php
namespace Opencart\Admin\Controller\Extension\CcCrm\Module;

require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/crypto.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/settings.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/logger.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/http.php';
require_once DIR_EXTENSION . 'cc_crm/system/library/cccrm/adapters/registry.php';

use Opencart\System\Library\Cccrm\Crypto;
use Opencart\System\Library\Cccrm\Settings;
use Opencart\System\Library\Cccrm\Logger;
use Opencart\System\Library\Cccrm\Http;
use Opencart\System\Library\Cccrm\Adapters\Registry;

class CcCrm extends \Opencart\System\Engine\Controller {
	private string $route = 'extension/cc_crm/module/cc_crm';

	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}

	public function index(): void {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_title'));

		$settings = new Settings($this->config);
		$all = $settings->all();

		$data = [];
		foreach (Settings::defaults() as $key => $default) {
			if ($key === 'targets') {
				continue;
			}
			$data[$key] = $all[$key];
		}

		// Targets — blank secrets for display, expose *_set flags.
		$targets = [];
		foreach ($all['targets'] as $slug => $cfg) {
			foreach (Settings::SECRET_KEYS as $sk) {
				if (isset($cfg[$sk])) {
					$cfg[$sk . '_set'] = $cfg[$sk] !== '' ? 1 : 0;
					$cfg[$sk] = '';
				}
			}
			$targets[$slug] = $cfg;
		}
		$data['targets'] = $targets;
		$data['target_labels'] = Registry::labels();

		// Reverse sync is available only for adapters exposing the pull API (KeyCRM).
		$keycrm = Registry::get('keycrm');
		$data['reverse_supported']  = $keycrm !== null && method_exists($keycrm, 'fetchOrders');
		$data['reverse_status_map'] = is_array($all['reverse_status_map']) ? $all['reverse_status_map'] : [];

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, 'user_token=' . $this->session->data['user_token'])],
		];
		$data['save']       = $this->url->link($this->route . '.save', 'user_token=' . $this->session->data['user_token']);
		$data['test']       = $this->url->link($this->route . '.test', 'user_token=' . $this->session->data['user_token']);
		$data['log']        = $this->url->link($this->route . '.log', 'user_token=' . $this->session->data['user_token']);
		$data['statuses']   = $this->url->link($this->route . '.statuses', 'user_token=' . $this->session->data['user_token']);
		$data['back']       = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
		$data['user_token'] = $this->session->data['user_token'];

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	public function save(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('modify', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}
		$post = $this->request->post;

		$data = [];
		foreach (Settings::defaults() as $key => $default) {
			if ($key === 'targets') {
				continue;
			}
			$field = 'module_cc_crm_' . $key;
			$data[$field] = isset($post[$field]) ? $post[$field] : $default;
		}

		// Reverse-sync state written by cron, not by the form — keep the stored value.
		$data['module_cc_crm_reverse_last_run'] = (string)$this->config->get('module_cc_crm_reverse_last_run');

		// Normalize the status-map rows (KeyCRM status_id => OC order_status_id) to a JSON-serialized map.
		$map  = [];
		$rows = isset($post['module_cc_crm_reverse_status_map']) && is_array($post['module_cc_crm_reverse_status_map']) ? $post['module_cc_crm_reverse_status_map'] : [];
		foreach ($rows as $row) {
			$crm = trim((string)($row['keycrm'] ?? ''));
			$oc  = (int)($row['oc'] ?? 0);
			if ($crm !== '' && ctype_digit($crm) && (int)$crm > 0 && $oc > 0) {
				$map[$crm] = $oc;
			}
		}
		$data['module_cc_crm_reverse_status_map'] = $map;

		// Merge targets: keep existing encrypted secret when the field is left blank.
		$existing = $this->config->get('module_cc_crm_targets');
		$existing = is_array($existing) ? $existing : [];
		$postTargets = isset($post['module_cc_crm_targets']) && is_array($post['module_cc_crm_targets']) ? $post['module_cc_crm_targets'] : [];

		$targets = [];
		foreach (Settings::targetDefaults() as $slug => $defaults) {
			$in  = $postTargets[$slug] ?? [];
			$row = [];
			foreach ($defaults as $fk => $fv) {
				if (in_array($fk, Settings::SECRET_KEYS, true)) {
					$plain = trim((string)($in[$fk] ?? ''));
					if ($plain !== '') {
						$row[$fk] = Crypto::encrypt($plain);          // new secret
					} else {
						$row[$fk] = (string)($existing[$slug][$fk] ?? ''); // keep existing (already encrypted)
					}
				} else {
					$row[$fk] = isset($in[$fk]) ? $in[$fk] : $fv;
				}
			}
			$targets[$slug] = $row;
		}
		$data['module_cc_crm_targets'] = $targets;

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('module_cc_crm', $data);

		$this->jsonResponse(['success' => $this->language->get('text_success')]);
	}

	public function test(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('access', $this->route)) {
			$this->jsonResponse(['error' => $this->language->get('error_permission')]);
			return;
		}
		$slug = (string)($this->request->get['target'] ?? $this->request->post['target'] ?? '');
		$settings = new Settings($this->config);
		$cfg = $settings->target($slug);

		if ($slug === 'keycrm') {
			$base = rtrim((string)($cfg['base_url'] ?? ''), '/');
			$key  = (string)($cfg['api_key'] ?? '');
			if ($key === '') {
				$this->jsonResponse(['ok' => false, 'error' => 'API key empty']);
				return;
			}
			$res = Http::json('GET', $base . '/order?limit=1', ['Authorization' => 'Bearer ' . $key], null, 15);
			$ok = $res['status'] >= 200 && $res['status'] < 300;
			$this->jsonResponse(['ok' => $ok, 'error' => $ok ? '' : ('HTTP ' . $res['status'])]);
			return;
		}

		$this->jsonResponse(['ok' => false, 'error' => 'Unknown target']);
	}

	/**
	 * AJAX: KeyCRM order statuses for the reverse-sync mapping UI.
	 * Uses the key typed into the form when present (not yet saved), otherwise
	 * the stored one.
	 */
	public function statuses(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('access', $this->route)) {
			$this->jsonResponse(['ok' => false, 'error' => $this->language->get('error_permission')]);
			return;
		}

		$settings = new Settings($this->config);
		$cfg = $settings->target('keycrm');

		$key = trim((string)($this->request->post['api_key'] ?? ''));
		if ($key !== '') {
			$cfg['api_key'] = $key;
		}
		$base = trim((string)($this->request->post['base_url'] ?? ''));
		if ($base !== '') {
			$cfg['base_url'] = $base;
		}
		if (($cfg['api_key'] ?? '') === '') {
			$this->jsonResponse(['ok' => false, 'error' => 'API key empty']);
			return;
		}

		$adapter = Registry::get('keycrm');
		if (!$adapter || !method_exists($adapter, 'fetchStatuses')) {
			$this->jsonResponse(['ok' => false, 'error' => 'Unknown target']);
			return;
		}

		$statuses = [];
		$page = 1;
		do {
			$res = $adapter->fetchStatuses($cfg, $page);
			if (empty($res['ok'])) {
				$this->jsonResponse(['ok' => false, 'error' => (string)$res['error']]);
				return;
			}
			foreach ($res['data'] as $s) {
				$statuses[] = [
					'id'               => (int)($s['id'] ?? 0),
					'name'             => (string)($s['name'] ?? ''),
					'alias'            => (string)($s['alias'] ?? ''),
					'group_id'         => (int)($s['group_id'] ?? 0),
					'is_closing_order' => !empty($s['is_closing_order']),
				];
			}
			$lastPage = (int)($res['last_page'] ?? 1);
			$page++;
		} while ($page <= $lastPage && $page <= 10);

		$this->jsonResponse(['ok' => true, 'statuses' => $statuses]);
	}

	public function log(): void {
		$this->load->language($this->route);
		if (!$this->user->hasPermission('access', $this->route)) {
			$this->response->setOutput($this->language->get('error_permission'));
			return;
		}
		$logger = new Logger($this->db);
		$data['rows'] = $logger->recent(100);
		$data['user_token'] = $this->session->data['user_token'];
		$this->response->setOutput($this->load->view('extension/cc_crm/module/cc_crm_log', $data));
	}

	public function install(): void {
		$prefix = DB_PREFIX;
		$this->db->query("CREATE TABLE IF NOT EXISTS `{$prefix}cc_crm_sync` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`order_id` BIGINT UNSIGNED NOT NULL,
			`target` VARCHAR(32) NOT NULL,
			`external_id` VARCHAR(128) DEFAULT NULL,
			`status` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			`last_error` TEXT NULL,
			`request_excerpt` MEDIUMTEXT NULL,
			`response_excerpt` MEDIUMTEXT NULL,
			`tracking_code` VARCHAR(64) DEFAULT NULL,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `order_target` (`order_id`, `target`),
			KEY `status` (`status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

		// Upgrade path: installs created before the reverse sync lack the column.
		try { $this->db->query("ALTER TABLE `{$prefix}cc_crm_sync` ADD COLUMN `tracking_code` VARCHAR(64) DEFAULT NULL"); } catch (\Throwable $e) {}

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('cc_crm_order_history_added');
		$this->model_setting_event->addEvent([
			'code'        => 'cc_crm_order_history_added',
			'description' => 'CatCode CRM Sync — push order to CRM on placement / status change',
			'trigger'     => 'catalog/model/checkout/order/addHistory/after',
			'action'      => 'extension/cc_crm/events.orderHistoryAdded',
			'status'      => 1,
			'sort_order'  => 20,
		]);

		$this->load->model('setting/cron');
		try { $this->model_setting_cron->deleteCronByCode('cc_crm_retry'); } catch (\Throwable $e) {}
		$this->model_setting_cron->addCron('cc_crm_retry', 'CatCode CRM Sync — retry failed pushes', 'hour', 'extension/cc_crm/cron.retry', true);
		try { $this->model_setting_cron->deleteCronByCode('cc_crm_reverse'); } catch (\Throwable $e) {}
		$this->model_setting_cron->addCron('cc_crm_reverse', 'CatCode CRM Sync — pull status/tracking/stock updates from CRM', 'hour', 'extension/cc_crm/cron.reverse', true);

		$this->load->model('user/user_group');
		try {
			$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'access', $this->route);
			$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), 'modify', $this->route);
		} catch (\Throwable $e) {}
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		try { $this->model_setting_event->deleteEventByCode('cc_crm_order_history_added'); } catch (\Throwable $e) {}
		$this->load->model('setting/cron');
		try { $this->model_setting_cron->deleteCronByCode('cc_crm_retry'); } catch (\Throwable $e) {}
		try { $this->model_setting_cron->deleteCronByCode('cc_crm_reverse'); } catch (\Throwable $e) {}
		// Table preserved to keep sync history.
	}
}
