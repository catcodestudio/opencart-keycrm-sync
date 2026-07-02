<?php
namespace Opencart\System\Library\Cccrm;

require_once __DIR__ . '/crypto.php';

/**
 * Settings repository over the OpenCart config. Top-level keys live under
 * module_cc_crm_*; per-target config lives in the serialized array
 * module_cc_crm_targets with secret fields transparently decrypted.
 */
class Settings {
	/** Secret per-target fields encrypted at rest. */
	public const SECRET_KEYS = ['api_key'];

	private $config;
	private ?array $cache = null;

	public function __construct($config) {
		$this->config = $config;
	}

	public static function defaults(): array {
		return [
			'status'          => '0',
			'send_on'         => 'create',   // create | status
			'trigger_status'  => '1',        // OC order_status_id when send_on = status
			'skip_zero'       => '1',
			'include_ship'    => '1',
			'retry_enabled'   => '1',
			'max_attempts'    => '5',
			'source_label'    => 'OpenCart',
			'targets'         => self::targetDefaults(),
		];
	}

	public static function targetDefaults(): array {
		return [
			'keycrm' => [
				'enabled'   => '0',
				'base_url'  => 'https://openapi.keycrm.app/v1',
				'api_key'   => '',
				'source_id' => '',   // KeyCRM source_id
			],
		];
	}

	public function all(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$out = self::defaults();
		foreach ($out as $key => $default) {
			if ($key === 'targets') {
				continue;
			}
			$val = $this->config->get('module_cc_crm_' . $key);
			if ($val !== null && $val !== '') {
				$out[$key] = $val;
			}
		}

		$stored = $this->config->get('module_cc_crm_targets');
		if (!is_array($stored)) {
			$stored = [];
		}
		foreach (self::targetDefaults() as $slug => $defaults) {
			$current = isset($stored[$slug]) && is_array($stored[$slug]) ? $stored[$slug] : [];
			$merged  = array_merge($defaults, $current);
			foreach (self::SECRET_KEYS as $secret) {
				if (!empty($merged[$secret])) {
					$merged[$secret] = Crypto::decrypt((string)$merged[$secret]);
				}
			}
			$out['targets'][$slug] = $merged;
		}

		$this->cache = $out;
		return $out;
	}

	public function get(string $key, $default = null) {
		$all = $this->all();
		return $all[$key] ?? $default;
	}

	public function target(string $slug): array {
		$all = $this->all();
		return $all['targets'][$slug] ?? [];
	}

	public function enabledTargets(): array {
		$out = [];
		foreach ($this->all()['targets'] as $slug => $cfg) {
			if (($cfg['enabled'] ?? '0') === '1') {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Encrypt secret fields in a settings payload before it is persisted with
	 * model_setting_setting->editSetting().
	 */
	public static function encryptForStore(array $values): array {
		if (!empty($values['module_cc_crm_targets']) && is_array($values['module_cc_crm_targets'])) {
			foreach ($values['module_cc_crm_targets'] as $slug => $cfg) {
				foreach (self::SECRET_KEYS as $secret) {
					if (!empty($cfg[$secret])) {
						$values['module_cc_crm_targets'][$slug][$secret] = Crypto::encrypt((string)$cfg[$secret]);
					}
				}
			}
		}
		return $values;
	}
}
