<?php
/*
 * [96-master/assets/function/proc_master04_01.php]
 *  トップページ並び順変更
 */

require_once dirname(__DIR__, 3) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeFoodShopsSortJson.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * 並び替え処理のJSON応答を返す
 *  画面側はstatusから確定済みの通知文と言い分けを選ぶ。
 */
function masterFoodShopOrderExit($status)
{
	echo json_encode(['tag' => '', 'status' => $status, 'title' => '', 'msg' => ''], JSON_UNESCAPED_UNICODE);
	exit;
}
/**
 * POSTの店舗IDを正規化する
 *  ASCII正整数のみを許可し、重複・欠落・余剰は後続のfull-set検証に渡す。
 */
function masterFoodShopOrderPositiveInteger($value)
{
	if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
		return null;
	}
	$id = filter_var($value, FILTER_VALIDATE_INT);
	return $id === false || $id < 1 ? null : $id;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $_FILES !== [] ||
	!is_array($_POST) || count($_POST) !== 4 ||
	array_diff(array_keys($_POST), ['action', 'noUpDateKey', 'orderVersion', 'shopOrder']) !== [] ||
	($_POST['action'] ?? null) !== 'sort' || !is_string($_POST['noUpDateKey'] ?? null) ||
	!is_string($_POST['orderVersion'] ?? null) ||
	preg_match('/\A[0-9a-f]{64}\z/D', $_POST['orderVersion']) !== 1 ||
	!is_array($_POST['shopOrder'] ?? null) || !array_is_list($_POST['shopOrder'])
) {
	masterFoodShopOrderExit('error');
}
$key = $_POST['noUpDateKey'];
$accountId = $_SESSION['master_login']['account_id'] ?? null;
$instance = $_SESSION[$key] ?? null;
if ((int)($_SESSION['master_login']['status'] ?? 0) !== 1 ||
	!is_numeric($accountId) || (int)$accountId < 1) {
	masterFoodShopOrderExit('error');
}
if (($_SESSION['sKey'] ?? null) !== $key || !is_array($instance) ||
	($instance['foodShopOrderPage'] ?? null) !== true ||
	($instance['masterKey'] ?? null) !== (int)$accountId
) {
	masterFoodShopOrderExit('stale');
}
$sentIds = [];
foreach ($_POST['shopOrder'] as $rawId) {
	$id = masterFoodShopOrderPositiveInteger($rawId);
	if ($id === null || in_array($id, $sentIds, true)) {
		masterFoodShopOrderExit('stale');
	}
	$sentIds[] = $id;
}
if (count($sentIds) < 2) {
	masterFoodShopOrderExit('stale');
}

$transactionStarted = false;
$commitAttempted = false;
$responseStatus = 'error';
try {
	if (DB_Transaction(1) !== true) {
		throw new RuntimeException('begin_failed');
	}
	$transactionStarted = true;
	$shops = getReservationFoodShopOrderRows(true);
	if ($shops === false) {
		throw new RuntimeException('list_failed');
	}
	$allOrders = getReservationShopOrderSlots(true);
	// 他画面の店舗行→設定行というロック順に合わせ、全設定行取得後に対象集合を再確認する。
	$shops = getReservationFoodShopOrderRows(true);
	if ($allOrders === false || $shops === false) {
		throw new RuntimeException('list_failed');
	}
	$freshVersion = buildReservationFoodShopOrderVersion($shops);
	$freshIds = array_column($shops, 'shop_id');
	$sortedFresh = $freshIds;
	$sortedSent = $sentIds;
	sort($sortedFresh, SORT_NUMERIC);
	sort($sortedSent, SORT_NUMERIC);
	if ($freshVersion === false || !hash_equals($freshVersion, $_POST['orderVersion']) ||
		$sortedFresh !== $sortedSent) {
		$responseStatus = 'stale';
		throw new RuntimeException('order_stale');
	}
	if ($freshIds === $sentIds) {
		$responseStatus = 'stale';
		throw new RuntimeException('order_unchanged');
	}
	$maxOrder = $allOrders === [] ? 0 : max($allOrders);
	$availableSlots = [];
	$currentById = [];
	$unsetCount = 0;
	foreach ($shops as $shop) {
		$id = $shop['shop_id'];
		$currentOrder = $shop['sort_order'];
		$currentById[$id] = $currentOrder;
		if ($currentOrder > 0) {
			$availableSlots[] = $currentOrder;
		} else {
			$unsetCount++;
		}
	}
	if ($maxOrder > 2147483647 - $unsetCount) {
		throw new RuntimeException('order_overflow');
	}
	for ($i = 0; $i < $unsetCount; $i++) {
		$availableSlots[] = ++$maxOrder;
	}
	sort($availableSlots, SORT_NUMERIC);
	foreach ($sentIds as $index => $id) {
		$newOrder = $availableSlots[$index];
		if ($currentById[$id] !== $newOrder &&
			updateReservationFoodShopSortOrder($id, $currentById[$id], $newOrder) !== true) {
			throw new RuntimeException('sort_write_failed');
		}
	}
	$commitAttempted = true;
	if (DB_Transaction(2) !== true) {
		throw new RuntimeException('commit_failed');
	}
	$transactionStarted = false;
	$responseStatus = 'success';
} catch (Throwable $e) {
	if ($commitAttempted) {
		$responseStatus = 'unknown';
	}
	if ($transactionStarted && is_object($DB_CONNECT) && $DB_CONNECT->inTransaction()) {
		try {
			if (DB_Transaction(3) !== true) {
				$responseStatus = 'unknown';
			}
		} catch (Throwable $rollbackError) {
			$responseStatus = 'unknown';
		}
	}
}
if ($responseStatus === 'success') {
	try {
		if (generateFoodShopsSortJson() !== true) {
			$responseStatus = 'json_error';
		}
	} catch (Throwable $e) {
		$responseStatus = 'json_error';
	}
}
masterFoodShopOrderExit($responseStatus);
