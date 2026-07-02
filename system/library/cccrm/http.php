<?php
namespace Opencart\System\Library\Cccrm;

/**
 * Minimal JSON HTTP client over cURL. Returns [http_status, body, error].
 */
class Http {
	public static function json(string $method, string $url, array $headers, ?array $body, int $timeout = 20): array {
		$ch = curl_init();
		$hdr = ['Accept: application/json'];
		foreach ($headers as $k => $v) {
			$hdr[] = $k . ': ' . $v;
		}

		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => strtoupper($method),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);

		if ($body !== null) {
			$json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			$hdr[] = 'Content-Type: application/json';
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);

		$respBody = curl_exec($ch);
		$err      = curl_error($ch);
		$status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return [
			'status' => $status,
			'body'   => $respBody === false ? '' : (string)$respBody,
			'error'  => $err,
		];
	}
}
