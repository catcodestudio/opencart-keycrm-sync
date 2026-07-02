<?php
namespace Opencart\System\Library\Cccrm\Adapters;

require_once __DIR__ . '/adapter_interface.php';
require_once __DIR__ . '/keycrm_adapter.php';

/**
 * Registry of available CRM target adapters.
 */
class Registry {
	/** @return array<string, AdapterInterface> */
	public static function all(): array {
		$out = [];
		foreach ([new KeycrmAdapter()] as $a) {
			$out[$a->slug()] = $a;
		}
		return $out;
	}

	public static function get(string $slug): ?AdapterInterface {
		return self::all()[$slug] ?? null;
	}

	/** @return array<string,string> slug => label */
	public static function labels(): array {
		$out = [];
		foreach (self::all() as $slug => $a) {
			$out[$slug] = $a->label();
		}
		return $out;
	}
}
