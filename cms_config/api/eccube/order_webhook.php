<?php
/*
 * EC-CUBE Api42 Webhook受信
 */
require_once dirname(__DIR__, 2) . '/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';

/*
 * Webhookレスポンス返却
 */
function respondOrderWebhook($statusCode, $payload)
{
	http_response_code((int)$statusCode);
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}
/*
 * Webhookログ出力
 */
function logOrderWebhook($reason, $detail = '')
{
	$data = [
		'pageName' => 'order_webhook',
		'reason' => (string)$reason,
		'detail' => $detail,
	];
	if (function_exists('makeLog')) {
		makeLog($data);
		return;
	}
	error_log(print_r($data, true));
}
/*
 * EC-CUBE署名ヘッダー取得
 */
function getEccubeSignatureHeader()
{
	if (isset($_SERVER['HTTP_X_ECCUBE_SIGNATURE']) && trim((string)$_SERVER['HTTP_X_ECCUBE_SIGNATURE']) !== '') {
		return trim((string)$_SERVER['HTTP_X_ECCUBE_SIGNATURE']);
	}
	if (function_exists('getallheaders')) {
		$headers = getallheaders();
		if (is_array($headers)) {
			foreach ($headers as $key => $value) {
				if (strtolower((string)$key) === 'x-eccube-signature' && trim((string)$value) !== '') {
					return trim((string)$value);
				}
			}
		}
	}
	return '';
}
if (isset($_SERVER['REQUEST_METHOD']) === false || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
	respondOrderWebhook(405, ['status' => 'error', 'message' => 'Method Not Allowed']);
}
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
	respondOrderWebhook(400, ['status' => 'error', 'message' => 'Bad Request']);
}
$receivedSignature = getEccubeSignatureHeader();
if ($receivedSignature === '') {
	respondOrderWebhook(401, ['status' => 'error', 'message' => 'Unauthorized']);
}
if (defined('DEFINE_ECCUBE_WEBHOOK_SECRET') === false || trim((string)DEFINE_ECCUBE_WEBHOOK_SECRET) === '') {
	logOrderWebhook('Webhook secret未設定', 'DEFINE_ECCUBE_WEBHOOK_SECRET is empty.');
	respondOrderWebhook(500, ['status' => 'error', 'message' => 'Internal Server Error']);
}
if (stripos($receivedSignature, 'sha256=') === 0) {
	$receivedSignature = substr($receivedSignature, 7);
}
$receivedSignature = trim($receivedSignature);
$expectedSignature = hash_hmac('sha256', $rawBody, (string)DEFINE_ECCUBE_WEBHOOK_SECRET);
if (hash_equals($expectedSignature, $receivedSignature) === false) {
	respondOrderWebhook(401, ['status' => 'error', 'message' => 'Unauthorized']);
}
$payload = json_decode($rawBody, true);
if (json_last_error() !== JSON_ERROR_NONE || is_array($payload) === false) {
	logOrderWebhook('Webhook JSON解析失敗', json_last_error_msg());
	respondOrderWebhook(400, ['status' => 'error', 'message' => 'Bad Request']);
}
$savedCount = 0;
foreach ($payload as $item) {
	if (is_array($item) === false) {
		continue;
	}
	$entity = isset($item['entity']) ? trim((string)$item['entity']) : '';
	$eccubeId = isset($item['id']) && is_numeric($item['id']) ? (int)$item['id'] : 0;
	$action = isset($item['action']) ? trim((string)$item['action']) : '';
	if ($entity === '' || $eccubeId < 1 || $action === '') {
		continue;
	}
	if (in_array($action, ['created', 'updated', 'deleted'], true) === false) {
		continue;
	}
	$logId = insertWebhookLog($entity, $eccubeId, $action, $rawBody);
	if ($logId === false) {
		logOrderWebhook('Webhookログ保存失敗', [
			'entity' => $entity,
			'eccube_id' => $eccubeId,
			'action' => $action,
		]);
		continue;
	}
	$savedCount++;
}
respondOrderWebhook(200, ['status' => 'ok', 'saved' => $savedCount]);
