<?php
/*
 * [cms_config/api/zeus/zeus_api.php]
 * ZEUS API helper
 */

require_once __DIR__ . '/zeus_api_config.php';

if (!function_exists('callZeusPriceChangeApi')) {
	/**
	 * ZEUS金額変更API呼び出し
	 * transaction.money には「変更後売上金額」を渡す（返金額ではない）
	 *
	 * @param mixed $currentZeusOrderId
	 * @param mixed $changeMoney
	 * @param array $context
	 * @return array{
	 *   success: bool,
	 *   status: string|null,
	 *   code: string|null,
	 *   result_order_number: string|null,
	 *   raw_response_summary: array|string|null,
	 *   error: string|null
	 * }
	 */
	function callZeusPriceChangeApi($currentZeusOrderId, $changeMoney, array $context = [])
	{
		$result = [
			'success' => false,
			'status' => null,
			'code' => null,
			'result_order_number' => null,
			'raw_response_summary' => null,
			'error' => null,
		];
		$orderNumber = trim((string)$currentZeusOrderId);
		if ($orderNumber === '') {
			$result['error'] = 'zeus_order_idが不正です。';
			return $result;
		}
		$changeMoneyString = trim((string)$changeMoney);
		if ($changeMoneyString === '' || preg_match('/^(0|[1-9][0-9]*)$/', $changeMoneyString) !== 1) {
			$result['error'] = 'change_moneyが不正です。';
			return $result;
		}
		$changeMoneyInt = (int)$changeMoneyString;
		if ($changeMoneyInt < 0) {
			$result['error'] = 'change_moneyは0以上で指定してください。';
			return $result;
		}
		# NOTE: change_money = 0（全額返金相当）の実環境挙動は別途確認が必要
		if (defined('ZEUS_API_MOCK_SUCCESS') && ZEUS_API_MOCK_SUCCESS === true) {
			$mockOrderPrefix = defined('ZEUS_API_MOCK_ORDER_PREFIX') ? trim((string)ZEUS_API_MOCK_ORDER_PREFIX) : 'MOCK-ZEUS-';
			if ($mockOrderPrefix === '') {
				$mockOrderPrefix = 'MOCK-ZEUS-';
			}
			$mockOrderNumber = $mockOrderPrefix . date('YmdHis') . '-' . substr(md5($orderNumber . ':' . $changeMoneyInt), 0, 8);
			$result['success'] = true;
			$result['status'] = 'success';
			$result['code'] = '000';
			$result['result_order_number'] = $mockOrderNumber;
			$result['raw_response_summary'] = [
				'mock' => true,
				'status' => 'success',
				'code' => '000',
				'order_number' => $orderNumber,
				'result_order_number' => $mockOrderNumber,
				'change_money' => $changeMoneyInt,
			];
			$result['error'] = null;
			return $result;
		}
		$clientIp = defined('ZEUS_API_CLIENT_IP') ? trim((string)ZEUS_API_CLIENT_IP) : '';
		$apiKey = defined('ZEUS_API_KEY') ? trim((string)ZEUS_API_KEY) : '';
		$apiUrl = defined('ZEUS_PRICE_CHANGE_API_URL') ? trim((string)ZEUS_PRICE_CHANGE_API_URL) : '';
		if ($clientIp === '' || $apiKey === '' || $apiUrl === '') {
			$result['error'] = 'ZEUS API設定が不正です。';
			return $result;
		}
		$requestXml =
			'<?xml version="1.0" encoding="utf-8"?>'
			. '<request>'
			. '<authentication>'
			. '<clientip>' . htmlspecialchars($clientIp, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</clientip>'
			. '<key>' . htmlspecialchars($apiKey, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</key>'
			. '</authentication>'
			. '<transaction>'
			. '<money>' . $changeMoneyInt . '</money>'
			. '<order_number>' . htmlspecialchars($orderNumber, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</order_number>'
			. '<pubsec>yes</pubsec>'
			. '</transaction>'
			. '</request>';
		$headers = [
			'Content-Type: application/xml',
			'Content-Length: ' . strlen($requestXml),
		];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $apiUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $requestXml);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$response = curl_exec($ch);
		$curlError = null;
		if ($response === false) {
			$curlError = (string)curl_error($ch);
		}
		$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($response === false) {
			$result['error'] = 'ZEUS API通信失敗: ' . $curlError;
			$result['raw_response_summary'] = [
				'http_code' => $httpCode,
				'order_number' => $orderNumber,
				'change_money' => $changeMoneyInt,
			];
			return $result;
		}
		$responseXml = @simplexml_load_string($response);
		if ($responseXml === false) {
			$result['error'] = 'ZEUS APIレスポンスXML解析失敗';
			$result['raw_response_summary'] = [
				'http_code' => $httpCode,
				'order_number' => $orderNumber,
				'change_money' => $changeMoneyInt,
			];
			return $result;
		}
		$status = isset($responseXml->result->status) ? trim((string)$responseXml->result->status) : null;
		$code = isset($responseXml->result->code) ? trim((string)$responseXml->result->code) : null;
		$resultOrderNumber = isset($responseXml->result->result_order_number) ? trim((string)$responseXml->result->result_order_number) : null;
		if ($resultOrderNumber === null || $resultOrderNumber === '') {
			$resultOrderNumber = isset($responseXml->result_order_number) ? trim((string)$responseXml->result_order_number) : null;
		}
		$result['status'] = ($status === '') ? null : $status;
		$result['code'] = ($code === '') ? null : $code;
		$result['result_order_number'] = ($resultOrderNumber === '') ? null : $resultOrderNumber;
		$result['raw_response_summary'] = [
			'status' => $result['status'],
			'code' => $result['code'],
			'result_order_number' => $result['result_order_number'],
			'http_code' => $httpCode,
		];
		if ($result['status'] === 'success' && $result['code'] === '000' && empty($result['result_order_number']) === false) {
			$result['success'] = true;
			return $result;
		}
		$result['error'] = 'ZEUS API返却エラー';
		return $result;
	}
}
