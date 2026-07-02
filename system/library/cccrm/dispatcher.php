<?php
namespace Opencart\System\Library\Cccrm;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/adapters/registry.php';

use Opencart\System\Library\Cccrm\Adapters\Registry;

/**
 * Sends a normalized order to every enabled CRM target, idempotently, and
 * records each attempt in the sync journal.
 */
class Dispatcher {
	private $db;
	private Settings $settings;
	private Logger $logger;

	public function __construct($db, Settings $settings) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->logger   = new Logger($db);
	}

	/**
	 * @param array $normalized OrderMapper::build output
	 * @return array<string,array> per-target result
	 */
	public function dispatch(int $orderId, array $normalized): array {
		$results = [];
		foreach ($this->settings->enabledTargets() as $slug) {
			$results[$slug] = $this->sendOne($orderId, $slug, $normalized);
		}
		return $results;
	}

	/** Retry a single target for an order (used by the retry cron). */
	public function retry(int $orderId, string $slug, array $normalized): array {
		return $this->sendOne($orderId, $slug, $normalized);
	}

	private function sendOne(int $orderId, string $slug, array $normalized): array {
		if ($this->logger->isCompleted($orderId, $slug)) {
			return ['ok' => true, 'skipped' => true];
		}
		$adapter = Registry::get($slug);
		if (!$adapter) {
			return ['ok' => false, 'error' => 'Unknown target: ' . $slug];
		}

		$id  = $this->logger->begin($orderId, $slug);
		$cfg = $this->settings->target($slug);

		try {
			$res = $adapter->send($normalized, $cfg);
		} catch (\Throwable $e) {
			$this->logger->fail($id, $e->getMessage(), '', '');
			return ['ok' => false, 'error' => $e->getMessage()];
		}

		if (!empty($res['ok'])) {
			$this->logger->complete($id, (string)$res['external_id'], (string)$res['request'], (string)$res['response']);
		} else {
			$this->logger->fail($id, (string)$res['error'], (string)$res['request'], (string)$res['response']);
		}
		return $res;
	}
}
