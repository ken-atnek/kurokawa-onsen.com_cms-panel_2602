<?php
/*
 * [webhook_logs] Webhookログ登録
 */
function insertWebhookLog($entity, $eccubeId, $action, $rawPayload)
{
	global $DB_CONNECT;
	if ($entity === null || trim((string)$entity) === '') {
		return false;
	}
	if ($eccubeId === null || is_numeric($eccubeId) === false || (int)$eccubeId < 1) {
		return false;
	}
	$allowedActions = ['created', 'updated', 'deleted'];
	if (in_array((string)$action, $allowedActions, true) === false) {
		return false;
	}
	try {
		$dbFiledData = [
			'entity' => [':entity', trim((string)$entity), 0],
			'eccube_id' => [':eccube_id', (int)$eccubeId, 1],
			'action' => [':action', (string)$action, 0],
			'raw_payload' => [':raw_payload', (string)$rawPayload, 0],
			'process_status' => [':process_status', 'pending', 0],
			'received_at' => [':received_at', date('Y-m-d H:i:s'), 0],
		];
		$result = SQL_Process($DB_CONNECT, 'webhook_logs', $dbFiledData, [], 1, 2);
		if ($result != 1) {
			return false;
		}
		return (int)$DB_CONNECT->lastInsertId();
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [webhook_logs] Webhookログステータス更新
 */
function updateWebhookLogStatus($logId, $status, $errorMessage = null)
{
	global $DB_CONNECT;
	if ($logId === null || is_numeric($logId) === false || (int)$logId < 1) {
		return false;
	}
	$allowedStatus = ['pending', 'processing', 'completed', 'failed', 'skipped'];
	if (in_array((string)$status, $allowedStatus, true) === false) {
		return false;
	}
	try {
		$dbFiledData = [
			'process_status' => [':process_status', (string)$status, 0],
		];
		if (in_array((string)$status, ['completed', 'failed', 'skipped'], true)) {
			$dbFiledData['processed_at'] = [':processed_at', date('Y-m-d H:i:s'), 0];
		}
		if ($errorMessage !== null) {
			$errorMessage = (string)$errorMessage;
			$dbFiledData['error_message'] = ($errorMessage === '')
				? [':error_message', null, 2]
				: [':error_message', $errorMessage, 0];
		}
		$dbFiledValue = [
			'log_id' => [':log_id', (int)$logId, 1],
		];
		return SQL_Process($DB_CONNECT, 'webhook_logs', $dbFiledData, $dbFiledValue, 2, 2) == 1;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [webhook_logs] pending受注Webhookログ一覧取得
 */
function getPendingOrderWebhookLogs()
{
	global $DB_CONNECT;
	try {
		$strSQL = "
			SELECT
				*
			FROM
				webhook_logs
			WHERE
				entity = 'order'
				AND process_status = 'pending'
			ORDER BY
				log_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_products / shop_product_variants] 商品コードから店舗ID取得
 */
function getShopIdByProductClassCode($productClassCode)
{
	global $DB_CONNECT;
	$productClassCode = trim((string)$productClassCode);
	if ($productClassCode === '') {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				shop_id
			FROM
				shop_products
			WHERE
				eccube_product_class_code = :product_class_code
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($row && isset($row['shop_id']) && (int)$row['shop_id'] > 0) {
			return (int)$row['shop_id'];
		}
		$strSQL = "
			SELECT
				shop_id
			FROM
				shop_product_variants
			WHERE
				eccube_product_class_code = :product_class_code
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($row && isset($row['shop_id']) && (int)$row['shop_id'] > 0) {
			return (int)$row['shop_id'];
		}
		return false;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_orders] EC-CUBE受注登録・更新
 */
function upsertShopOrder($shopId, $orderData)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if (is_array($orderData) === false) {
		return false;
	}
	$eccubeOrderId = isset($orderData['eccube_order_id']) ? (int)$orderData['eccube_order_id'] : 0;
	if ($eccubeOrderId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				order_id,
				eccube_order_status_id,
				zeus_order_id
			FROM
				shop_orders
			WHERE
				eccube_order_id = :eccube_order_id
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':eccube_order_id', $eccubeOrderId, PDO::PARAM_INT);
		$newStmt->execute();
		$currentOrder = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		$orderNo = isset($orderData['order_no']) && trim((string)$orderData['order_no']) !== '' ? trim((string)$orderData['order_no']) : null;
		$ordererName = isset($orderData['orderer_name']) && trim((string)$orderData['orderer_name']) !== '' ? trim((string)$orderData['orderer_name']) : null;
		$ordererEmail = isset($orderData['orderer_email']) && trim((string)$orderData['orderer_email']) !== '' ? trim((string)$orderData['orderer_email']) : null;
		$ordererTel = isset($orderData['orderer_tel']) && trim((string)$orderData['orderer_tel']) !== '' ? trim((string)$orderData['orderer_tel']) : null;
		$ordererPostalCode = isset($orderData['orderer_postal_code']) && trim((string)$orderData['orderer_postal_code']) !== '' ? trim((string)$orderData['orderer_postal_code']) : null;
		$ordererPrefId = isset($orderData['orderer_pref_id']) && is_numeric($orderData['orderer_pref_id']) && (int)$orderData['orderer_pref_id'] > 0 ? (int)$orderData['orderer_pref_id'] : null;
		$ordererPrefName = isset($orderData['orderer_pref_name']) && trim((string)$orderData['orderer_pref_name']) !== '' ? trim((string)$orderData['orderer_pref_name']) : null;
		$ordererAddr01 = isset($orderData['orderer_addr01']) && trim((string)$orderData['orderer_addr01']) !== '' ? trim((string)$orderData['orderer_addr01']) : null;
		$ordererAddr02 = isset($orderData['orderer_addr02']) && trim((string)$orderData['orderer_addr02']) !== '' ? trim((string)$orderData['orderer_addr02']) : null;
		$ordererMessage = isset($orderData['orderer_message']) && trim((string)$orderData['orderer_message']) !== '' ? trim((string)$orderData['orderer_message']) : null;
		$shippingName = isset($orderData['shipping_name']) && trim((string)$orderData['shipping_name']) !== '' ? trim((string)$orderData['shipping_name']) : null;
		$shippingPostalCode = isset($orderData['shipping_postal_code']) && trim((string)$orderData['shipping_postal_code']) !== '' ? trim((string)$orderData['shipping_postal_code']) : null;
		$shippingPrefId = isset($orderData['shipping_pref_id']) && is_numeric($orderData['shipping_pref_id']) && (int)$orderData['shipping_pref_id'] > 0 ? (int)$orderData['shipping_pref_id'] : null;
		$shippingPrefName = isset($orderData['shipping_pref_name']) && trim((string)$orderData['shipping_pref_name']) !== '' ? trim((string)$orderData['shipping_pref_name']) : null;
		$shippingAddr01 = isset($orderData['shipping_addr01']) && trim((string)$orderData['shipping_addr01']) !== '' ? trim((string)$orderData['shipping_addr01']) : null;
		$shippingAddr02 = isset($orderData['shipping_addr02']) && trim((string)$orderData['shipping_addr02']) !== '' ? trim((string)$orderData['shipping_addr02']) : null;
		$shippingTel = isset($orderData['shipping_tel']) && trim((string)$orderData['shipping_tel']) !== '' ? trim((string)$orderData['shipping_tel']) : null;
		$statusId = isset($orderData['eccube_order_status_id']) ? (int)$orderData['eccube_order_status_id'] : 0;
		$statusName = isset($orderData['eccube_order_status_name']) && $orderData['eccube_order_status_name'] !== null ? (string)$orderData['eccube_order_status_name'] : null;
		$paymentTotal = isset($orderData['payment_total']) ? (int)$orderData['payment_total'] : 0;
		$deliveryFeeTotal = isset($orderData['delivery_fee_total']) ? (int)$orderData['delivery_fee_total'] : 0;
		$zeusOrderId = isset($orderData['zeus_order_id']) && trim((string)$orderData['zeus_order_id']) !== '' ? trim((string)$orderData['zeus_order_id']) : null;
		$orderedAt = normalizeShopOrderDateTime($orderData['ordered_at'] ?? null);
		if ($currentOrder === false || empty($currentOrder)) {
			$dbFiledData = [
				'shop_id' => [':shop_id', (int)$shopId, 1],
				'eccube_order_id' => [':eccube_order_id', $eccubeOrderId, 1],
				'order_no' => [':order_no', $orderNo, ($orderNo === null) ? 2 : 0],
				'orderer_name' => [':orderer_name', $ordererName, ($ordererName === null) ? 2 : 0],
				'orderer_email' => [':orderer_email', $ordererEmail, ($ordererEmail === null) ? 2 : 0],
				'orderer_tel' => [':orderer_tel', $ordererTel, ($ordererTel === null) ? 2 : 0],
				'orderer_postal_code' => [':orderer_postal_code', $ordererPostalCode, ($ordererPostalCode === null) ? 2 : 0],
				'orderer_pref_id' => [':orderer_pref_id', $ordererPrefId, ($ordererPrefId === null) ? 2 : 1],
				'orderer_pref_name' => [':orderer_pref_name', $ordererPrefName, ($ordererPrefName === null) ? 2 : 0],
				'orderer_addr01' => [':orderer_addr01', $ordererAddr01, ($ordererAddr01 === null) ? 2 : 0],
				'orderer_addr02' => [':orderer_addr02', $ordererAddr02, ($ordererAddr02 === null) ? 2 : 0],
				'orderer_message' => [':orderer_message', $ordererMessage, ($ordererMessage === null) ? 2 : 0],
				'shipping_name' => [':shipping_name', $shippingName, ($shippingName === null) ? 2 : 0],
				'shipping_postal_code' => [':shipping_postal_code', $shippingPostalCode, ($shippingPostalCode === null) ? 2 : 0],
				'shipping_pref_id' => [':shipping_pref_id', $shippingPrefId, ($shippingPrefId === null) ? 2 : 1],
				'shipping_pref_name' => [':shipping_pref_name', $shippingPrefName, ($shippingPrefName === null) ? 2 : 0],
				'shipping_addr01' => [':shipping_addr01', $shippingAddr01, ($shippingAddr01 === null) ? 2 : 0],
				'shipping_addr02' => [':shipping_addr02', $shippingAddr02, ($shippingAddr02 === null) ? 2 : 0],
				'shipping_tel' => [':shipping_tel', $shippingTel, ($shippingTel === null) ? 2 : 0],
				'eccube_order_status_id' => [':eccube_order_status_id', $statusId, 1],
				'eccube_order_status_name' => [':eccube_order_status_name', $statusName, ($statusName === null) ? 2 : 0],
				'payment_total' => [':payment_total', $paymentTotal, 1],
				'delivery_fee_total' => [':delivery_fee_total', $deliveryFeeTotal, 1],
				'zeus_order_id' => [':zeus_order_id', $zeusOrderId, ($zeusOrderId === null) ? 2 : 0],
				'ordered_at' => [':ordered_at', $orderedAt, ($orderedAt === null) ? 2 : 0],
				'is_active' => [':is_active', 1, 1],
				'created_at' => [':created_at', date('Y-m-d H:i:s'), 0],
				'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
			];
			$result = SQL_Process($DB_CONNECT, 'shop_orders', $dbFiledData, [], 1, 2);
			if ($result != 1) {
				return false;
			}
			return (int)$DB_CONNECT->lastInsertId();
		}
		$dbFiledData = [
			'shop_id' => [':shop_id', (int)$shopId, 1],
			'order_no' => [':order_no', $orderNo, ($orderNo === null) ? 2 : 0],
			'orderer_name' => [':orderer_name', $ordererName, ($ordererName === null) ? 2 : 0],
			'orderer_email' => [':orderer_email', $ordererEmail, ($ordererEmail === null) ? 2 : 0],
			'orderer_tel' => [':orderer_tel', $ordererTel, ($ordererTel === null) ? 2 : 0],
			'orderer_postal_code' => [':orderer_postal_code', $ordererPostalCode, ($ordererPostalCode === null) ? 2 : 0],
			'orderer_pref_id' => [':orderer_pref_id', $ordererPrefId, ($ordererPrefId === null) ? 2 : 1],
			'orderer_pref_name' => [':orderer_pref_name', $ordererPrefName, ($ordererPrefName === null) ? 2 : 0],
			'orderer_addr01' => [':orderer_addr01', $ordererAddr01, ($ordererAddr01 === null) ? 2 : 0],
			'orderer_addr02' => [':orderer_addr02', $ordererAddr02, ($ordererAddr02 === null) ? 2 : 0],
			'orderer_message' => [':orderer_message', $ordererMessage, ($ordererMessage === null) ? 2 : 0],
			'shipping_name' => [':shipping_name', $shippingName, ($shippingName === null) ? 2 : 0],
			'shipping_postal_code' => [':shipping_postal_code', $shippingPostalCode, ($shippingPostalCode === null) ? 2 : 0],
			'shipping_pref_id' => [':shipping_pref_id', $shippingPrefId, ($shippingPrefId === null) ? 2 : 1],
			'shipping_pref_name' => [':shipping_pref_name', $shippingPrefName, ($shippingPrefName === null) ? 2 : 0],
			'shipping_addr01' => [':shipping_addr01', $shippingAddr01, ($shippingAddr01 === null) ? 2 : 0],
			'shipping_addr02' => [':shipping_addr02', $shippingAddr02, ($shippingAddr02 === null) ? 2 : 0],
			'shipping_tel' => [':shipping_tel', $shippingTel, ($shippingTel === null) ? 2 : 0],
			'payment_total' => [':payment_total', $paymentTotal, 1],
			'delivery_fee_total' => [':delivery_fee_total', $deliveryFeeTotal, 1],
			'ordered_at' => [':ordered_at', $orderedAt, ($orderedAt === null) ? 2 : 0],
			'is_active' => [':is_active', 1, 1],
			'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
		];
		if ((int)($currentOrder['eccube_order_status_id'] ?? 0) !== 9) {
			$dbFiledData['eccube_order_status_id'] = [':eccube_order_status_id', $statusId, 1];
			$dbFiledData['eccube_order_status_name'] = [':eccube_order_status_name', $statusName, ($statusName === null) ? 2 : 0];
		}
		if ($zeusOrderId !== null) {
			$dbFiledData['zeus_order_id'] = [':zeus_order_id', $zeusOrderId, 0];
		}
		$dbFiledValue = [
			'order_id' => [':order_id', (int)$currentOrder['order_id'], 1],
		];
		$result = SQL_Process($DB_CONNECT, 'shop_orders', $dbFiledData, $dbFiledValue, 2, 2);
		if ($result != 1) {
			return false;
		}
		return (int)$currentOrder['order_id'];
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [EC-CUBE受注明細] 商品コードから店舗ID解決
 */
function resolveShopIdFromOrderItems($orderItems)
{
	if (is_array($orderItems) === false || empty($orderItems)) {
		return false;
	}
	$shopIds = [];
	foreach ($orderItems as $orderItem) {
		if (is_array($orderItem) === false) {
			continue;
		}
		$productClassCode = isset($orderItem['product_class_code']) ? trim((string)$orderItem['product_class_code']) : '';
		if ($productClassCode === '') {
			continue;
		}
		$shopId = getShopIdByProductClassCode($productClassCode);
		if ($shopId === false || (int)$shopId < 1) {
			continue;
		}
		$shopIds[(int)$shopId] = true;
	}
	if (count($shopIds) !== 1) {
		return false;
	}
	$keys = array_keys($shopIds);
	return (int)$keys[0];
}
/*
 * [shop_products / shop_product_variants] EC-CUBE商品コードから商品ID取得
 */
function getProductIdByProductClassCode($productClassCode)
{
	global $DB_CONNECT;
	$productClassCode = trim((string)$productClassCode);
	if ($productClassCode === '') {
		return null;
	}
	try {
		$strSQL = "
			SELECT
				product_id
			FROM
				shop_products
			WHERE
				eccube_product_class_code = :product_class_code
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($row && isset($row['product_id']) && (int)$row['product_id'] > 0) {
			return (int)$row['product_id'];
		}
		$strSQL = "
			SELECT
				product_id
			FROM
				shop_product_variants
			WHERE
				eccube_product_class_code = :product_class_code
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($row && isset($row['product_id']) && (int)$row['product_id'] > 0) {
			return (int)$row['product_id'];
		}
		return null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [shop_order_items] 受注明細全削除
 */
function deleteShopOrderItems($orderId)
{
	global $DB_CONNECT;
	if ($orderId === null || is_numeric($orderId) === false || (int)$orderId < 1) {
		return false;
	}
	try {
		$dbFiledValue = [
			'order_id' => [':order_id', (int)$orderId, 1],
		];
		return SQL_Process($DB_CONNECT, 'shop_order_items', [], $dbFiledValue, 3, 2) == 1;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_order_items] 受注明細登録
 */
function insertShopOrderItem($orderId, $shopId, $itemData)
{
	global $DB_CONNECT;
	if ($orderId === null || is_numeric($orderId) === false || (int)$orderId < 1) {
		return false;
	}
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if (is_array($itemData) === false) {
		return false;
	}
	try {
		$productId = isset($itemData['product_id']) && $itemData['product_id'] !== null ? (int)$itemData['product_id'] : null;
		$productClassCode = isset($itemData['eccube_product_class_code']) && trim((string)$itemData['eccube_product_class_code']) !== '' ? trim((string)$itemData['eccube_product_class_code']) : null;
		$productName = isset($itemData['product_name']) ? (string)$itemData['product_name'] : '';
		$currentItemStatus = isset($itemData['current_item_status']) && trim((string)$itemData['current_item_status']) !== '' ? trim((string)$itemData['current_item_status']) : 'normal';
		$dbFiledData = [
			'order_id' => [':order_id', (int)$orderId, 1],
			'shop_id' => [':shop_id', (int)$shopId, 1],
			'product_id' => [':product_id', $productId, ($productId === null) ? 2 : 1],
			'eccube_product_class_code' => [':eccube_product_class_code', $productClassCode, ($productClassCode === null) ? 2 : 0],
			'product_name' => [':product_name', $productName, 0],
			'unit_price' => [':unit_price', isset($itemData['unit_price']) ? (int)$itemData['unit_price'] : 0, 1],
			'quantity' => [':quantity', isset($itemData['quantity']) ? (int)$itemData['quantity'] : 0, 1],
			'subtotal' => [':subtotal', isset($itemData['subtotal']) ? (int)$itemData['subtotal'] : 0, 1],
			'temp_type' => [':temp_type', isset($itemData['temp_type']) ? $itemData['temp_type'] : null, empty($itemData['temp_type']) ? 2 : 0],
			'class_name1' => [':class_name1', isset($itemData['class_name1']) ? $itemData['class_name1'] : null, empty($itemData['class_name1']) ? 2 : 0],
			'class_category1' => [':class_category1', isset($itemData['class_category1']) ? $itemData['class_category1'] : null, empty($itemData['class_category1']) ? 2 : 0],
			'class_name2' => [':class_name2', isset($itemData['class_name2']) ? $itemData['class_name2'] : null, empty($itemData['class_name2']) ? 2 : 0],
			'class_category2' => [':class_category2', isset($itemData['class_category2']) ? $itemData['class_category2'] : null, empty($itemData['class_category2']) ? 2 : 0],
			'current_item_status' => [':current_item_status', $currentItemStatus, 0],
			'created_at' => [':created_at', date('Y-m-d H:i:s'), 0],
			'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
		];
		$result = SQL_Process($DB_CONNECT, 'shop_order_items', $dbFiledData, [], 1, 2);
		if ($result != 1) {
			return false;
		}
		return (int)$DB_CONNECT->lastInsertId();
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_orders] 受注明細に基づく在庫減算
 */
function deductStockByOrderItems($orderId, $shopId, $orderItems)
{
	global $DB_CONNECT;
	if ($orderId === null || is_numeric($orderId) === false || (int)$orderId < 1) {
		return false;
	}
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if (is_array($orderItems) === false) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				stock_deducted_at
			FROM
				shop_orders
			WHERE
				order_id = :order_id
				AND shop_id = :shop_id
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':order_id', (int)$orderId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$order = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if (empty($order)) {
			return false;
		}
		if (isset($order['stock_deducted_at']) && $order['stock_deducted_at'] !== null && trim((string)$order['stock_deducted_at']) !== '') {
			return true;
		}
		foreach ($orderItems as $orderItem) {
			if (is_array($orderItem) === false) {
				continue;
			}
			$productClassCode = isset($orderItem['product_class_code']) ? trim((string)$orderItem['product_class_code']) : '';
			$quantity = isset($orderItem['quantity']) ? (int)$orderItem['quantity'] : 0;
			if ($productClassCode === '' || $quantity < 1) {
				continue;
			}
			$strSQL = "
				SELECT
					product_id,
					stock,
					stock_unlimited
				FROM
					shop_products
				WHERE
					shop_id = :shop_id
					AND eccube_product_class_code = :product_class_code
				LIMIT 1
			";
			$newStmt = $DB_CONNECT->prepare($strSQL);
			$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
			$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
			$newStmt->execute();
			$product = $newStmt->fetch(PDO::FETCH_ASSOC);
			$newStmt->closeCursor();
			if (!empty($product)) {
				if ((int)($product['stock_unlimited'] ?? 0) !== 1) {
					$currentStock = isset($product['stock']) && $product['stock'] !== null ? (int)$product['stock'] : 0;
					$newStock = $currentStock - $quantity;
					if ($newStock < 0) {
						logOrderStockDeductionMessage('在庫不足補正', [
							'order_id' => (int)$orderId,
							'shop_id' => (int)$shopId,
							'product_class_code' => $productClassCode,
							'current_stock' => $currentStock,
							'quantity' => $quantity,
						]);
						$newStock = 0;
					}
					$dbFiledData = [
						'stock' => [':stock', $newStock, 1],
						'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
					];
					$dbFiledValue = [
						'shop_id' => [':shop_id', (int)$shopId, 1],
						'product_id' => [':product_id', (int)$product['product_id'], 1],
					];
					if (SQL_Process($DB_CONNECT, 'shop_products', $dbFiledData, $dbFiledValue, 2, 2) != 1) {
						return false;
					}
				}
				continue;
			}
			$strSQL = "
				SELECT
					variant_id,
					product_id,
					stock,
					stock_unlimited
				FROM
					shop_product_variants
				WHERE
					shop_id = :shop_id
					AND eccube_product_class_code = :product_class_code
				LIMIT 1
			";
			$newStmt = $DB_CONNECT->prepare($strSQL);
			$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
			$newStmt->bindValue(':product_class_code', $productClassCode, PDO::PARAM_STR);
			$newStmt->execute();
			$variant = $newStmt->fetch(PDO::FETCH_ASSOC);
			$newStmt->closeCursor();
			if (!empty($variant)) {
				if ((int)($variant['stock_unlimited'] ?? 0) !== 1) {
					$currentStock = isset($variant['stock']) && $variant['stock'] !== null ? (int)$variant['stock'] : 0;
					$newStock = $currentStock - $quantity;
					if ($newStock < 0) {
						logOrderStockDeductionMessage('在庫不足補正', [
							'order_id' => (int)$orderId,
							'shop_id' => (int)$shopId,
							'product_class_code' => $productClassCode,
							'current_stock' => $currentStock,
							'quantity' => $quantity,
						]);
						$newStock = 0;
					}
					$dbFiledData = [
						'stock' => [':stock', $newStock, 1],
						'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
					];
					$dbFiledValue = [
						'variant_id' => [':variant_id', (int)$variant['variant_id'], 1],
						'shop_id' => [':shop_id', (int)$shopId, 1],
					];
					if (SQL_Process($DB_CONNECT, 'shop_product_variants', $dbFiledData, $dbFiledValue, 2, 2) != 1) {
						return false;
					}
					if (recalcShopProductRepresentativeStock((int)$shopId, (int)$variant['product_id']) !== true) {
						return false;
					}
				}
				continue;
			}
			logOrderStockDeductionMessage('在庫減算対象未特定', [
				'order_id' => (int)$orderId,
				'shop_id' => (int)$shopId,
				'product_class_code' => $productClassCode,
			]);
		}
		$dbFiledData = [
			'stock_deducted_at' => [':stock_deducted_at', date('Y-m-d H:i:s'), 0],
			'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
		];
		$dbFiledValue = [
			'order_id' => [':order_id', (int)$orderId, 1],
			'shop_id' => [':shop_id', (int)$shopId, 1],
		];
		return SQL_Process($DB_CONNECT, 'shop_orders', $dbFiledData, $dbFiledValue, 2, 2) == 1;
	} catch (PDOException $e) {
		return false;
	}
}

/*
 * [shop_products] 規格在庫代表値再計算
 */
function recalcShopProductRepresentativeStock($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if ($productId === null || is_numeric($productId) === false || (int)$productId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				stock,
				stock_unlimited
			FROM
				shop_product_variants
			WHERE
				shop_id = :shop_id
				AND product_id = :product_id
				AND is_active = 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		$newStmt->execute();
		$variants = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if (empty($variants)) {
			return true;
		}
		$allUnlimited = true;
		$stockTotal = 0;
		foreach ($variants as $variant) {
			if ((int)($variant['stock_unlimited'] ?? 0) !== 1) {
				$allUnlimited = false;
				$stockTotal += isset($variant['stock']) && $variant['stock'] !== null ? (int)$variant['stock'] : 0;
			}
		}
		if ($allUnlimited) {
			$dbFiledData = [
				'stock' => [':stock', null, 2],
				'stock_unlimited' => [':stock_unlimited', 1, 1],
				'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
			];
		} else {
			$dbFiledData = [
				'stock' => [':stock', $stockTotal, 1],
				'stock_unlimited' => [':stock_unlimited', 0, 1],
				'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
			];
		}
		$dbFiledValue = [
			'shop_id' => [':shop_id', (int)$shopId, 1],
			'product_id' => [':product_id', (int)$productId, 1],
		];
		return SQL_Process($DB_CONNECT, 'shop_products', $dbFiledData, $dbFiledValue, 2, 2) == 1;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [受注在庫減算] 補助ログ出力
 */
function logOrderStockDeductionMessage($reason, $detail = [])
{
	try {
		$data = [
			'pageName' => 'order_stock_deduction',
			'reason' => (string)$reason,
			'detail' => $detail,
		];
		if (function_exists('makeLog')) {
			makeLog($data);
			return true;
		}
		error_log(print_r($data, true));
	} catch (Exception $e) {
	}
	return true;
}
/*
 * [shop_orders] 受注一覧検索条件SQL生成
 */
function buildShopOrderListSearchSqlParts($searchConditions = [], $fixedShopId = null)
{
	$where = [
		'o.is_active = 1',
	];
	$params = [];
	if ($fixedShopId !== null && is_numeric($fixedShopId) && (int)$fixedShopId > 0) {
		$where[] = 'o.shop_id = :fixed_shop_id';
		$params[':fixed_shop_id'] = [(int)$fixedShopId, PDO::PARAM_INT];
	} else {
		$shopId = isset($searchConditions['shopId']) ? trim((string)$searchConditions['shopId']) : '';
		if ($shopId !== '' && ctype_digit($shopId) && (int)$shopId > 0) {
			$where[] = 'o.shop_id = :shop_id';
			$params[':shop_id'] = [(int)$shopId, PDO::PARAM_INT];
		}
	}
	$orderNo = isset($searchConditions['orderNo']) ? trim((string)$searchConditions['orderNo']) : '';
	if ($orderNo !== '') {
		$where[] = 'o.order_no LIKE :order_no';
		$params[':order_no'] = ['%' . $orderNo . '%', PDO::PARAM_STR];
	}
	$ordererName = isset($searchConditions['ordererName']) ? trim((string)$searchConditions['ordererName']) : '';
	if ($ordererName !== '') {
		$where[] = 'o.orderer_name LIKE :orderer_name';
		$params[':orderer_name'] = ['%' . $ordererName . '%', PDO::PARAM_STR];
	}
	$ordererEmail = isset($searchConditions['ordererEmail']) ? trim((string)$searchConditions['ordererEmail']) : '';
	if ($ordererEmail !== '') {
		$where[] = 'o.orderer_email LIKE :orderer_email';
		$params[':orderer_email'] = ['%' . $ordererEmail . '%', PDO::PARAM_STR];
	}
	$ordererTel = isset($searchConditions['ordererTel']) ? trim((string)$searchConditions['ordererTel']) : '';
	if ($ordererTel !== '') {
		$where[] = 'o.orderer_tel LIKE :orderer_tel';
		$params[':orderer_tel'] = ['%' . $ordererTel . '%', PDO::PARAM_STR];
	}
	$statusId = isset($searchConditions['statusId']) ? trim((string)$searchConditions['statusId']) : '';
	if ($statusId === '' && isset($searchConditions['searchStatus'])) {
		$statusId = trim((string)$searchConditions['searchStatus']);
	}
	if ($statusId !== '' && ctype_digit($statusId) && in_array((int)$statusId, [1, 4, 5], true)) {
		$where[] = 'o.eccube_order_status_id = :status_id';
		$params[':status_id'] = [(int)$statusId, PDO::PARAM_INT];
	}
	$orderDateFrom = isset($searchConditions['orderDateFrom']) ? trim((string)$searchConditions['orderDateFrom']) : '';
	if ($orderDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDateFrom)) {
		$where[] = 'o.ordered_at >= :order_date_from';
		$params[':order_date_from'] = [$orderDateFrom . ' 00:00:00', PDO::PARAM_STR];
	}
	$orderDateTo = isset($searchConditions['orderDateTo']) ? trim((string)$searchConditions['orderDateTo']) : '';
	if ($orderDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderDateTo)) {
		$where[] = 'o.ordered_at <= :order_date_to';
		$params[':order_date_to'] = [$orderDateTo . ' 23:59:59', PDO::PARAM_STR];
	}
	return [
		'where' => $where,
		'params' => $params,
	];
}
/*
 * [shop_orders] 受注詳細取得
 */
function getShopOrderDetail($orderId) {}

/*
 * [shop_orders] 受注一覧検索
 */
function searchShopOrderList($searchConditions = [], $pageNumber = 1, $displayNumber = 10, $fixedShopId = null)
{
	global $DB_CONNECT;
	try {
		$pageNumber = (int)$pageNumber;
		$displayNumber = (int)$displayNumber;
		if ($pageNumber < 1) {
			$pageNumber = 1;
		}
		if ($displayNumber < 1) {
			$displayNumber = 10;
		}
		$offset = ($pageNumber - 1) * $displayNumber;
		$parts = buildShopOrderListSearchSqlParts($searchConditions, $fixedShopId);
		$strSQL = "
			SELECT
				o.*,
				s.shop_name,
				COALESCE(oi.total_quantity, 0) AS total_quantity,
				COALESCE(oi.item_subtotal, 0) AS item_subtotal
			FROM
				shop_orders AS o
				LEFT JOIN shops AS s
					ON s.shop_id = o.shop_id
				LEFT JOIN (
					SELECT
						order_id,
						SUM(quantity) AS total_quantity,
						SUM(subtotal) AS item_subtotal
					FROM
						shop_order_items
					GROUP BY
						order_id
				) AS oi
					ON oi.order_id = o.order_id
			WHERE
				" . implode("\n				AND ", $parts['where']) . "
			ORDER BY
				o.ordered_at DESC,
				o.order_id DESC
			LIMIT :limit OFFSET :offset
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($parts['params'] as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->bindValue(':limit', $displayNumber, PDO::PARAM_INT);
		$newStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_orders] 受注一覧件数取得
 */
function countShopOrderList($searchConditions = [], $fixedShopId = null)
{
	global $DB_CONNECT;
	try {
		$parts = buildShopOrderListSearchSqlParts($searchConditions, $fixedShopId);
		$strSQL = "
			SELECT
				COUNT(*) AS cnt
			FROM
				shop_orders AS o
				LEFT JOIN shops AS s
					ON s.shop_id = o.shop_id
			WHERE
				" . implode("\n				AND ", $parts['where']) . "
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($parts['params'] as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return isset($row['cnt']) ? (int)$row['cnt'] : 0;
	} catch (PDOException $e) {
		return 0;
	}
}
/*
 * [shop_order_items] 受注ID配列から明細一覧取得
 */
function getShopOrderItemsByOrderIds(array $orderIds)
{
	global $DB_CONNECT;
	$orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), function ($orderId) {
		return $orderId > 0;
	})));
	if (empty($orderIds)) {
		return [];
	}
	try {
		$placeholders = [];
		foreach ($orderIds as $idx => $orderId) {
			$placeholders[] = ':order_id_' . $idx;
		}
		$strSQL = "
			SELECT
				i.order_id,
				i.order_item_id,
				i.product_name,
				i.quantity,
				i.unit_price,
				i.subtotal,
				p.tax_rate
			FROM
				shop_order_items AS i
				LEFT JOIN shop_products AS p
					ON p.shop_id = i.shop_id
					AND p.product_id = i.product_id
			WHERE
				i.order_id IN (" . implode(', ', $placeholders) . ")
			ORDER BY
				i.order_id ASC,
				i.order_item_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($orderIds as $idx => $orderId) {
			$newStmt->bindValue(':order_id_' . $idx, (int)$orderId, PDO::PARAM_INT);
		}
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		$itemsByOrderId = [];
		foreach ($rows ?: [] as $row) {
			$key = (int)($row['order_id'] ?? 0);
			if ($key < 1) {
				continue;
			}
			if (!isset($itemsByOrderId[$key])) {
				$itemsByOrderId[$key] = [];
			}
			$itemsByOrderId[$key][] = $row;
		}
		return $itemsByOrderId;
	} catch (PDOException $e) {
		return [];
	}
}

/*
 * [shop_orders] ステータス更新用受注取得
 */
function getShopOrderForStatusUpdate($orderId, $shopId = null)
{
	global $DB_CONNECT;
	if ($orderId === null || is_numeric($orderId) === false || (int)$orderId < 1) {
		return [];
	}
	try {
		$where = ['order_id = :order_id', 'is_active = 1'];
		$strSQL = "
			SELECT
				order_id,
				shop_id,
				eccube_order_id,
				eccube_order_status_id,
				eccube_order_status_name,
				shipped_at
			FROM
				shop_orders
			WHERE
				" . implode("\n				AND ", $where) . "
		";
		if ($shopId !== null && is_numeric($shopId) && (int)$shopId > 0) {
			$strSQL .= "\n				AND shop_id = :shop_id";
		}
		$strSQL .= "\n			LIMIT 1";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':order_id', (int)$orderId, PDO::PARAM_INT);
		if ($shopId !== null && is_numeric($shopId) && (int)$shopId > 0) {
			$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		}
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_orders] カラム存在確認
 */
function shopOrderColumnExists($columnName)
{
	global $DB_CONNECT;
	$columnName = trim((string)$columnName);
	if ($columnName === '') {
		return false;
	}
	try {
		$newStmt = $DB_CONNECT->prepare("SHOW COLUMNS FROM shop_orders LIKE :column_name");
		$newStmt->bindValue(':column_name', $columnName, PDO::PARAM_STR);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_orders] EC-CUBE反映後ステータス更新
 */
function updateShopOrderStatusAfterEccube($orderId, $statusId, $statusName, $shippedAt = null)
{
	global $DB_CONNECT;
	if ($orderId === null || is_numeric($orderId) === false || (int)$orderId < 1) {
		return false;
	}
	if (in_array((int)$statusId, [1, 4, 5], true) === false) {
		return false;
	}
	$statusName = trim((string)$statusName);
	if ($statusName === '') {
		$statusName = null;
	}
	$now = date('Y-m-d H:i:s');
	$dbFiledData = [
		'eccube_order_status_id' => [':eccube_order_status_id', (int)$statusId, 1],
		'eccube_order_status_name' => [':eccube_order_status_name', $statusName, ($statusName === null) ? 2 : 0],
		'status_changed_at' => [':status_changed_at', $now, 0],
		'updated_at' => [':updated_at', $now, 0],
	];
	if ((int)$statusId === 5 && $shippedAt !== null && trim((string)$shippedAt) !== '') {
		$dbFiledData['shipped_at'] = [':shipped_at', trim((string)$shippedAt), 0];
	}
	$dbFiledValue = [
		'order_id' => [':order_id', (int)$orderId, 1],
	];
	$result = SQL_Process($DB_CONNECT, 'shop_orders', $dbFiledData, $dbFiledValue, 2, 2);
	return ($result == 1);
}
/*
 * [shop_orders] 受注日時文字列正規化
 */
function normalizeShopOrderDateTime($dateTime)
{
	$dateTime = trim((string)$dateTime);
	if ($dateTime === '') {
		return null;
	}
	try {
		$date = new DateTime($dateTime);
		return $date->format('Y-m-d H:i:s');
	} catch (Exception $e) {
		return null;
	}
}
