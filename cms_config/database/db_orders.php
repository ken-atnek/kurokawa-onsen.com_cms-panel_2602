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
