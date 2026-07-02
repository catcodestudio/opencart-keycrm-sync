<?php
namespace Opencart\System\Library\Cccrm;

/**
 * At-rest encryption for stored CRM API keys. Mirrors the scheme used by the
 * CatCode PRRO connector: sodium_crypto_secretbox when available, otherwise an
 * HMAC-stream cipher, with legacy plaintext returned as-is on decrypt so an
 * upgrade never loses a key. The key is derived from OpenCart path/db
 * constants so a value encrypted in admin decrypts from catalog/cron/cli.
 */
class Crypto {
	private const PREFIX_SODIUM = 'crm1:';
	private const PREFIX_HMAC   = 'crm2:';

	public static function encrypt(string $plain): string {
		if ($plain === '') {
			return '';
		}
		$key = self::key();

		if (function_exists('sodium_crypto_secretbox')) {
			$nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$cipher = sodium_crypto_secretbox($plain, $nonce, substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
			return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
		}

		$nonce  = random_bytes(16);
		$stream = self::hmacStream($key, $nonce, strlen($plain));
		$cipher = $plain ^ $stream;
		$mac    = hash_hmac('sha256', $nonce . $cipher, $key, true);
		return self::PREFIX_HMAC . base64_encode($nonce . $mac . $cipher);
	}

	public static function decrypt(string $stored): string {
		if ($stored === '') {
			return '';
		}
		$key = self::key();

		if (str_starts_with($stored, self::PREFIX_SODIUM) && function_exists('sodium_crypto_secretbox_open')) {
			$raw = base64_decode(substr($stored, strlen(self::PREFIX_SODIUM)), true);
			if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
				return '';
			}
			$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$plain = sodium_crypto_secretbox_open($ct, $nonce, substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
			return $plain === false ? '' : $plain;
		}

		if (str_starts_with($stored, self::PREFIX_HMAC)) {
			$raw = base64_decode(substr($stored, strlen(self::PREFIX_HMAC)), true);
			if ($raw === false || strlen($raw) < 48) {
				return '';
			}
			$nonce = substr($raw, 0, 16);
			$mac   = substr($raw, 16, 32);
			$ct    = substr($raw, 48);
			$calc  = hash_hmac('sha256', $nonce . $ct, $key, true);
			if (!hash_equals($mac, $calc)) {
				return '';
			}
			$stream = self::hmacStream($key, $nonce, strlen($ct));
			return $ct ^ $stream;
		}

		return $stored; // Legacy plaintext.
	}

	private static function key(): string {
		$root     = defined('DIR_OPENCART') ? DIR_OPENCART : (defined('DIR_SYSTEM') ? DIR_SYSTEM : __DIR__);
		$material = $root . (defined('DB_DATABASE') ? DB_DATABASE : '');
		return hash('sha256', 'CatCodeCrmSync|' . $material, true);
	}

	private static function hmacStream(string $key, string $nonce, int $len): string {
		$out     = '';
		$counter = 0;
		while (strlen($out) < $len) {
			$out .= hash_hmac('sha256', $nonce . pack('N', $counter++), $key, true);
		}
		return substr($out, 0, $len);
	}
}
