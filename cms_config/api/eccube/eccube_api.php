<?php
/*
 * [cms_config/api/eccube/eccube_api.php]
 * EC-CUBE GraphQL API 共通クライアント
 */
if (!defined('DOCUMENT_ROOT_PATH')) {
	exit;
}

require_once __DIR__ . '/eccube_api_config.php';

/**
 * Client Credentials Grant でアクセストークンを取得する
 *
 * @return string
 * @throws Exception
 */
function eccube_api_get_token(): string
{
	$cacheFile = ECCUBE_API_TOKEN_CACHE_FILE;
	$now = time();
	#キャッシュが有効なら再利用する
	if (is_file($cacheFile)) {
		$cacheJson = @file_get_contents($cacheFile);
		if ($cacheJson !== false && $cacheJson !== '') {
			$cacheData = json_decode($cacheJson, true);
			if (
				is_array($cacheData)
				&& !empty($cacheData['token'])
				&& isset($cacheData['expires_at'])
				&& is_numeric($cacheData['expires_at'])
				&& (int)$cacheData['expires_at'] > ($now + 60)
			) {
				return (string)$cacheData['token'];
			}
		}
	}
	$postFields = http_build_query([
		'grant_type' => 'client_credentials',
		'client_id' => ECCUBE_API_CLIENT_ID,
		'client_secret' => ECCUBE_API_CLIENT_SECRET,
	], '', '&');
	$curlHandle = curl_init();
	if ($curlHandle === false) {
		throw new Exception('EC-CUBE APIトークン取得の初期化に失敗しました。');
	}
	curl_setopt_array($curlHandle, [
		CURLOPT_URL => ECCUBE_API_TOKEN_URL,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $postFields,
		CURLOPT_HTTPHEADER => [
			'Content-Type: application/x-www-form-urlencoded',
		],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	]);
	$responseBody = curl_exec($curlHandle);
	if ($responseBody === false) {
		$curlError = curl_error($curlHandle);
		curl_close($curlHandle);
		throw new Exception('EC-CUBE APIトークン取得通信に失敗しました。' . $curlError);
	}
	$httpStatus = (int)curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
	curl_close($curlHandle);
	if ($httpStatus !== 200) {
		throw new Exception('EC-CUBE APIトークン取得に失敗しました。HTTPステータス: ' . $httpStatus . ' / 応答: ' . $responseBody);
	}
	$responseData = json_decode($responseBody, true);
	if (!is_array($responseData)) {
		throw new Exception('EC-CUBE APIトークン応答のJSONデコードに失敗しました。');
	}
	$accessToken = isset($responseData['access_token']) ? trim((string)$responseData['access_token']) : '';
	if ($accessToken === '') {
		throw new Exception('EC-CUBE APIトークン応答にaccess_tokenがありません。');
	}
	$expiresIn = isset($responseData['expires_in']) && is_numeric($responseData['expires_in'])
		? (int)$responseData['expires_in']
		: 300;
	if ($expiresIn <= 0) {
		$expiresIn = 300;
	}
	$expiresAt = $now + $expiresIn;
	#キャッシュ書き込み失敗は致命エラーにしない
	$cachePayload = json_encode([
		'token' => $accessToken,
		'expires_at' => $expiresAt,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($cachePayload !== false) {
		@file_put_contents($cacheFile, $cachePayload, LOCK_EX);
	}
	return $accessToken;
}
/**
 * GraphQL API を呼び出して data 配列を返す
 *
 * @param string $query
 * @return array
 * @throws Exception
 */
function eccube_api_call(string $query): array
{
	$token = eccube_api_get_token();
	$requestBody = json_encode([
		'query' => $query,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($requestBody === false) {
		throw new Exception('EC-CUBE GraphQLリクエストのJSON生成に失敗しました。');
	}
	$curlHandle = curl_init();
	if ($curlHandle === false) {
		throw new Exception('EC-CUBE GraphQL API呼び出しの初期化に失敗しました。');
	}
	curl_setopt_array($curlHandle, [
		CURLOPT_URL => ECCUBE_API_GRAPHQL_URL,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $requestBody,
		CURLOPT_HTTPHEADER => [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $token,
		],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
	]);
	$responseBody = curl_exec($curlHandle);
	if ($responseBody === false) {
		$curlError = curl_error($curlHandle);
		curl_close($curlHandle);
		throw new Exception('EC-CUBE GraphQL API通信に失敗しました。' . $curlError);
	}
	$httpStatus = (int)curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
	curl_close($curlHandle);
	if ($httpStatus !== 200) {
		throw new Exception('EC-CUBE GraphQL API呼び出しに失敗しました。HTTPステータス: ' . $httpStatus . ' / 応答: ' . $responseBody);
	}
	$responseData = json_decode($responseBody, true);
	if (!is_array($responseData)) {
		throw new Exception('EC-CUBE GraphQL応答のJSONデコードに失敗しました。');
	}
	if (isset($responseData['errors'])) {
		$errorMessage = 'EC-CUBE GraphQL APIエラーが発生しました。';
		if (
			is_array($responseData['errors'])
			&& isset($responseData['errors'][0])
			&& is_array($responseData['errors'][0])
			&& !empty($responseData['errors'][0]['message'])
		) {
			$errorMessage = (string)$responseData['errors'][0]['message'];
		}
		throw new Exception($errorMessage);
	}
	if (!array_key_exists('data', $responseData) || !is_array($responseData['data'])) {
		throw new Exception('EC-CUBE GraphQL応答にdataがありません。');
	}
	return $responseData['data'];
}
