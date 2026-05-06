<?php
/*
 * EC-CUBE受注Webhookログ処理
 */
require_once dirname(__DIR__, 2) . '/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/api/eccube/eccube_api.php';

/*
 * 処理結果レスポンス
 */
function respondProcessOrderWebhookLogs($payload)
{
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	exit;
}
/*
 * 処理ログ出力
 */
function logProcessOrderWebhookLogs($reason, $detail = '')
{
	$data = [
		'pageName' => 'process_order_webhook_logs',
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
 * GraphQL文字列エスケープ
 */
function buildProcessOrderGraphqlString($value)
{
	$encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new Exception('GraphQL文字列変換に失敗しました。');
	}
	return $encoded;
}
/*
 * EC-CUBE受注詳細取得
 */
function fetchEccubeOrderForWebhook($eccubeOrderId)
{
	$eccubeOrderId = (int)$eccubeOrderId;
	if ($eccubeOrderId < 1) {
		return null;
	}
	$lastSyncedId = $eccubeOrderId - 1;
	if ($lastSyncedId < 0) {
		$lastSyncedId = 0;
	}
	$query = "query {\n  ordersForSync(\n    last_synced_id: " . (int)$lastSyncedId . "\n    last_synced_at: " . buildProcessOrderGraphqlString('2000-01-01T00:00:00+09:00') . "\n    limit: 50\n  ) {\n    id\n    order_no\n    orderer_name\n    orderer_name01\n    orderer_name02\n    orderer_kana01\n    orderer_kana02\n    orderer_email\n    orderer_tel\n    orderer_postal_code\n    orderer_pref_id\n    orderer_pref_name\n    orderer_addr01\n    orderer_addr02\n    orderer_message\n    shipping_name\n    shipping_postal_code\n    shipping_pref_id\n    shipping_pref_name\n    shipping_addr01\n    shipping_addr02\n    shipping_tel\n    order_status_id\n    order_status_name\n    payment_total\n    delivery_fee_total\n    update_date\n    zeus_order_id\n    order_items {\n      product_class_code\n      product_name\n      quantity\n      price\n    }\n  }\n}";
	$result = eccube_api_call($query);
	$orders = isset($result['ordersForSync']) && is_array($result['ordersForSync']) ? $result['ordersForSync'] : [];
	foreach ($orders as $order) {
		if (is_array($order) && isset($order['id']) && (int)$order['id'] === $eccubeOrderId) {
			return $order;
		}
	}
	return null;
}
$pendingLogs = getPendingOrderWebhookLogs();
$latestLogIdByEccubeId = [];
foreach ($pendingLogs as $log) {
	$eccubeId = isset($log['eccube_id']) ? (int)$log['eccube_id'] : 0;
	$logId = isset($log['log_id']) ? (int)$log['log_id'] : 0;
	if ($eccubeId < 1 || $logId < 1) {
		continue;
	}
	$latestLogIdByEccubeId[$eccubeId] = $logId;
}
$result = [
	'status' => 'ok',
	'total' => count($pendingLogs),
	'completed' => 0,
	'skipped' => 0,
	'failed' => 0,
	'details' => [],
];
foreach ($pendingLogs as $log) {
	$logId = isset($log['log_id']) ? (int)$log['log_id'] : 0;
	$eccubeId = isset($log['eccube_id']) ? (int)$log['eccube_id'] : 0;
	$action = isset($log['action']) ? (string)$log['action'] : '';
	if ($logId < 1 || $eccubeId < 1) {
		continue;
	}
	if (isset($latestLogIdByEccubeId[$eccubeId]) && (int)$latestLogIdByEccubeId[$eccubeId] !== $logId) {
		$reason = '同一eccube_order_idの古いpendingのためスキップ';
		updateWebhookLogStatus($logId, 'skipped', $reason);
		$result['skipped']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'skipped', 'reason' => $reason];
		continue;
	}
	if (updateWebhookLogStatus($logId, 'processing') !== true) {
		$reason = 'processing更新失敗';
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId]);
		continue;
	}
	if ($action === 'deleted') {
		$reason = 'action=deleted はスキップ';
		updateWebhookLogStatus($logId, 'skipped', $reason);
		$result['skipped']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'skipped', 'reason' => $reason];
		continue;
	}
	try {
		$order = fetchEccubeOrderForWebhook($eccubeId);
	} catch (Exception $e) {
		$reason = '受注詳細取得失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'error' => $e->getMessage()]);
		continue;
	}
	if (empty($order) || is_array($order) === false) {
		$reason = '受注詳細取得失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		continue;
	}
	$orderStatusId = isset($order['order_status_id']) ? (int)$order['order_status_id'] : 0;
	if (in_array($orderStatusId, [7, 8], true)) {
		$reason = 'status_id=7/8 はスキップ';
		updateWebhookLogStatus($logId, 'skipped', $reason);
		$result['skipped']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'skipped', 'reason' => $reason];
		continue;
	}
	$orderItems = isset($order['order_items']) && is_array($order['order_items']) ? $order['order_items'] : [];
	$shopId = resolveShopIdFromOrderItems($orderItems);
	if ($shopId === false || (int)$shopId < 1) {
		$reason = 'shop_id特定失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		continue;
	}
	$orderData = [
		'eccube_order_id' => $eccubeId,
		'order_no' => isset($order['order_no']) ? $order['order_no'] : null,
		'orderer_name' => isset($order['orderer_name']) ? $order['orderer_name'] : null,
		'orderer_name01' => isset($order['orderer_name01']) ? $order['orderer_name01'] : null,
		'orderer_name02' => isset($order['orderer_name02']) ? $order['orderer_name02'] : null,
		'orderer_kana01' => isset($order['orderer_kana01']) ? $order['orderer_kana01'] : null,
		'orderer_kana02' => isset($order['orderer_kana02']) ? $order['orderer_kana02'] : null,
		'orderer_email' => isset($order['orderer_email']) ? $order['orderer_email'] : null,
		'orderer_tel' => isset($order['orderer_tel']) ? $order['orderer_tel'] : null,
		'orderer_postal_code' => isset($order['orderer_postal_code']) ? $order['orderer_postal_code'] : null,
		'orderer_pref_id' => isset($order['orderer_pref_id']) ? $order['orderer_pref_id'] : null,
		'orderer_pref_name' => isset($order['orderer_pref_name']) ? $order['orderer_pref_name'] : null,
		'orderer_addr01' => isset($order['orderer_addr01']) ? $order['orderer_addr01'] : null,
		'orderer_addr02' => isset($order['orderer_addr02']) ? $order['orderer_addr02'] : null,
		'orderer_message' => isset($order['orderer_message']) ? $order['orderer_message'] : null,
		'shipping_name' => isset($order['shipping_name']) ? $order['shipping_name'] : null,
		'shipping_postal_code' => isset($order['shipping_postal_code']) ? $order['shipping_postal_code'] : null,
		'shipping_pref_id' => isset($order['shipping_pref_id']) ? $order['shipping_pref_id'] : null,
		'shipping_pref_name' => isset($order['shipping_pref_name']) ? $order['shipping_pref_name'] : null,
		'shipping_addr01' => isset($order['shipping_addr01']) ? $order['shipping_addr01'] : null,
		'shipping_addr02' => isset($order['shipping_addr02']) ? $order['shipping_addr02'] : null,
		'shipping_tel' => isset($order['shipping_tel']) ? $order['shipping_tel'] : null,
		'eccube_order_status_id' => $orderStatusId,
		'eccube_order_status_name' => isset($order['order_status_name']) ? $order['order_status_name'] : null,
		'payment_total' => isset($order['payment_total']) ? (int)$order['payment_total'] : 0,
		'delivery_fee_total' => isset($order['delivery_fee_total']) ? (int)$order['delivery_fee_total'] : 0,
		'zeus_order_id' => isset($order['zeus_order_id']) ? $order['zeus_order_id'] : null,
		'ordered_at' => isset($order['update_date']) ? $order['update_date'] : null,
	];
	$currentOrderForMail = getShopOrderByEccubeOrderId($eccubeId);
	$isNewOrderForMail = empty($currentOrderForMail);
	if (DB_Transaction(1) !== true) {
		$reason = 'DBトランザクション開始失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'shop_id' => $shopId]);
		continue;
	}
	$orderId = upsertShopOrder((int)$shopId, $orderData);
	if ($orderId === false || (int)$orderId < 1) {
		DB_Transaction(3);
		$reason = 'shop_orders保存失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'shop_id' => $shopId]);
		continue;
	}
	if (deleteShopOrderItems((int)$orderId) !== true) {
		DB_Transaction(3);
		$reason = 'shop_order_items削除失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'order_id' => $orderId]);
		continue;
	}
	$itemSaveFailed = false;
	foreach ($orderItems as $orderItem) {
		if (is_array($orderItem) === false) {
			continue;
		}
		$productClassCode = isset($orderItem['product_class_code']) && trim((string)$orderItem['product_class_code']) !== '' ? trim((string)$orderItem['product_class_code']) : null;
		$unitPrice = isset($orderItem['price']) ? (int)$orderItem['price'] : 0;
		$quantity = isset($orderItem['quantity']) ? (int)$orderItem['quantity'] : 0;
		$itemData = [
			'product_id' => ($productClassCode === null) ? null : getProductIdByProductClassCode($productClassCode),
			'eccube_product_class_code' => $productClassCode,
			'product_name' => isset($orderItem['product_name']) ? (string)$orderItem['product_name'] : '',
			'unit_price' => $unitPrice,
			'quantity' => $quantity,
			'subtotal' => $unitPrice * $quantity,
			'temp_type' => null,
			'class_name1' => null,
			'class_category1' => null,
			'class_name2' => null,
			'class_category2' => null,
			'current_item_status' => 'normal',
		];
		$orderItemId = insertShopOrderItem((int)$orderId, (int)$shopId, $itemData);
		if ($orderItemId === false || (int)$orderItemId < 1) {
			$itemSaveFailed = true;
			break;
		}
	}
	if ($itemSaveFailed) {
		DB_Transaction(3);
		$reason = 'shop_order_items保存失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'order_id' => $orderId]);
		continue;
	}
	if (deductStockByOrderItems((int)$orderId, (int)$shopId, $orderItems) === false) {
		DB_Transaction(3);
		$reason = '在庫減算失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'order_id' => $orderId]);
		continue;
	}
	if (DB_Transaction(2) !== true) {
		DB_Transaction(3);
		$reason = 'DBコミット失敗';
		updateWebhookLogStatus($logId, 'failed', $reason);
		$result['failed']++;
		$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'failed', 'reason' => $reason];
		logProcessOrderWebhookLogs($reason, ['log_id' => $logId, 'eccube_id' => $eccubeId, 'order_id' => $orderId]);
		continue;
	}
	updateWebhookLogStatus($logId, 'completed');
	if ($isNewOrderForMail && sendOrderNotificationMail((int)$orderId) !== true) {
		logProcessOrderWebhookLogs('受注通知メール送信失敗', ['log_id' => $logId, 'eccube_id' => $eccubeId, 'order_id' => $orderId]);
	}
	$result['completed']++;
	$result['details'][] = ['log_id' => $logId, 'eccube_id' => $eccubeId, 'result' => 'completed'];
}
respondProcessOrderWebhookLogs($result);
