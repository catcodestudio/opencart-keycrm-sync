<?php
namespace Opencart\System\Library\Cccrm\Adapters;

interface AdapterInterface {
	/** Machine slug, e.g. 'keycrm'. */
	public function slug(): string;

	/** Human label for the admin UI. */
	public function label(): string;

	/**
	 * Push a normalized order (from OrderMapper::build) to the CRM.
	 * $config is the decrypted per-target settings array.
	 *
	 * @return array{ok:bool, external_id:string, request:string, response:string, error:string}
	 */
	public function send(array $order, array $config): array;
}
