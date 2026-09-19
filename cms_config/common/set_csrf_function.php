<?php
/*
 * [管理画面CSRF対策]
 */

/**
 * 管理画面CSRF token取得
 *  SESSION内に有効なtokenがなければ新しく生成する
 */
function getClientCsrfToken()
{
	if (session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION) === false || is_array($_SESSION) === false) {
		return false;
	}
	$token = $_SESSION['client_csrf_token'] ?? null;
	if (is_string($token) === true && preg_match('/\A[0-9a-f]{64}\z/D', $token) === 1) {
		return $token;
	}
	try {
		$token = bin2hex(random_bytes(32));
	} catch (Throwable $e) {
		return false;
	}
	$_SESSION['client_csrf_token'] = $token;
	return $token;
}

/**
 * 管理画面CSRF token検証
 *  tokenを生成せずSESSION内のtokenと比較する
 */
function validateClientCsrfToken($submittedToken)
{
	if (session_status() !== PHP_SESSION_ACTIVE || isset($_SESSION) === false || is_array($_SESSION) === false) {
		return false;
	}
	$sessionToken = $_SESSION['client_csrf_token'] ?? null;
	if (
		is_string($submittedToken) === false ||
		is_string($sessionToken) === false ||
		preg_match('/\A[0-9a-f]{64}\z/D', $submittedToken) !== 1 ||
		preg_match('/\A[0-9a-f]{64}\z/D', $sessionToken) !== 1
	) {
		return false;
	}
	return hash_equals($sessionToken, $submittedToken);
}
