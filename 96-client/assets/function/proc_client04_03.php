<?php
/*
 * [96-client/assets/function/proc_client04_03.php]
 *  食事メニュー一覧の有効切替・並び替え
 */

require_once dirname(__DIR__, 3) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

header('Content-Type: application/json; charset=UTF-8');

/** 管理画面のJSON responseを作成する。 */
function foodMenuListResponse($status, $title, $msg, $postCommitWarnings = [])
{
	return [
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'requiresConfirmation' => false,
		'warningCodes' => [],
		'postCommitWarnings' => $postCommitWarnings,
	];
}
/** transaction開始前のエラーを返す。 */
function foodMenuListExit($title, $msg)
{
	echo json_encode(foodMenuListResponse('error', $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}
/** HTTP由来のASCII正整数をPHP int範囲内で受け付ける。 */
function foodMenuListPositiveInteger($value)
{
	if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
		return null;
	}
	$result = filter_var($value, FILTER_VALIDATE_INT);
	return $result === false || $result < 1 ? null : $result;
}
/** action別のPOST allow-listとfield型を検証する。 */
function normalizeFoodMenuListRequest($post)
{
	if (!is_array($post) || !is_string($post['action'] ?? null)) {
		return false;
	}
	$fields = [
		'toggle' => ['menuId', 'menuVersion', 'isActive'],
		'sort' => ['menuOrder', 'orderVersion'],
	];
	$action = $post['action'];
	if (!isset($fields[$action])) {
		return false;
	}
	$allowed = array_merge(['noUpDateKey', 'csrfToken', 'action'], $fields[$action]);
	if (
		count($post) !== count($allowed) || array_diff(array_keys($post), $allowed) !== [] ||
		!is_string($post['noUpDateKey'] ?? null) || !is_string($post['csrfToken'] ?? null) ||
		$post['noUpDateKey'] === '' || $post['csrfToken'] === ''
	) {
		return false;
	}
	$result = ['action' => $action, 'key' => $post['noUpDateKey'], 'csrf' => $post['csrfToken']];
	if ($action === 'toggle') {
		$result['id'] = foodMenuListPositiveInteger($post['menuId'] ?? null);
		$result['version'] = $post['menuVersion'] ?? null;
		$result['active'] = $post['isActive'] ?? null;
		if (
			$result['id'] === null || !is_string($result['version']) ||
			preg_match('/\A[0-9a-f]{64}\z/D', $result['version']) !== 1 ||
			!in_array($result['active'], ['0', '1'], true)
		) {
			return false;
		}
		$result['active'] = (int)$result['active'];
	} else {
		$result['version'] = $post['orderVersion'] ?? null;
		$order = $post['menuOrder'] ?? null;
		if (
			!is_string($result['version']) || preg_match('/\A[0-9a-f]{64}\z/D', $result['version']) !== 1 ||
			!is_array($order) || !array_is_list($order)
		) {
			return false;
		}
		$result['order'] = [];
		foreach ($order as $rawId) {
			$id = foodMenuListPositiveInteger($rawId);
			if ($id === null || in_array($id, $result['order'], true)) {
				return false;
			}
			$result['order'][] = $id;
		}
	}
	return $result;
}
/** 予約基本設定に応じた保存後warningをtransaction内のfresh countから決める。 */
function foodMenuListPostCommitWarnings($shopId)
{
	$raw = getShopReservationSettings($shopId);
	if ($raw === false) {
		return false;
	}
	if ($raw === null) {
		return [];
	}
	$settings = normalizeShopReservationSettingsData($raw);
	if ($settings === false) {
		return false;
	}
	if ($settings['menu_selection_type'] !== 2) {
		return [];
	}
	$count = getActiveFoodMenuCountForReservation($shopId);
	if ($count === false) {
		return false;
	}
	return $count === 0 ? ['menu_required_no_active_menu'] : [];
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $_FILES !== []) {
	foodMenuListExit('送信エラー', '送信方法が正しくありません。');
}
$request = normalizeFoodMenuListRequest($_POST);
if ($request === false) {
	foodMenuListExit('入力エラー', '送信された項目が正しくありません。');
}
$shopRaw = $_SESSION['client_login']['shop_id'] ?? null;
$shopId = is_int($shopRaw) ? $shopRaw : foodMenuListPositiveInteger($shopRaw);
$instance = $_SESSION[$request['key']] ?? null;
if (
	!is_int($shopId) || $shopId < 1 || ($_SESSION['sKey'] ?? null) !== $request['key'] || !is_array($instance) ||
	($instance['foodMenuPage'] ?? null) !== 'list' ||
	($instance['shopId'] ?? null) !== $shopId ||
	($instance['clientKey'] ?? null) !== ($_SESSION['client_login']['account_id'] ?? null)
) {
	foodMenuListExit('セッションエラー', 'ページを再読み込みしてください。');
}
if (validateClientCsrfToken($request['csrf']) !== true) {
	foodMenuListExit('セッションエラー', 'ページを再読み込みしてください。');
}
$transactionStarted = false;
$committed = false;
$wasEnabled = false;
$warningEvaluationFailed = false;
$response = foodMenuListResponse('error', '保存エラー', '処理できませんでした。ページを再読み込みしてください。');
try {
	if (DB_Transaction(1) !== true) {
		throw new RuntimeException('begin_failed');
	}
	$transactionStarted = true;
	$shop = getReservationShopForUpdate($shopId);
	if (!is_array($shop) || (int)($shop['shop_id'] ?? 0) !== $shopId) {
		throw new RuntimeException('shop_lock_failed');
	}
	$wasEnabled = isReservationEnabledForShop($shopId);
	$warnings = [];
	if ($request['action'] === 'toggle') {
		$current = getFoodMenuForManagement($shopId, $request['id']);
		if ($current === false) {
			throw new RuntimeException('menu_read_failed');
		}
		if ($current === null) {
			$response = foodMenuListResponse('error', '更新エラー', 'メニューが見つかりません。ページを再読み込みしてください。');
			throw new RuntimeException('menu_missing');
		}
		$version = buildFoodMenuManagementVersion($current);
		if ($version === false || !hash_equals($version, $request['version'])) {
			$response = foodMenuListResponse('error', '更新エラー', 'メニューの情報が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('menu_stale');
		}
		$target = $current;
		$target['is_active'] = $request['active'];
		if (
			$current['is_active'] === 0 && $request['active'] === 1 &&
			!isFoodMenuCurrentValid($target, $shopId)
		) {
			$response = foodMenuListResponse('error', '入力エラー', 'メニューの内容を修正してから有効にしてください。');
			throw new RuntimeException('menu_invalid');
		}
		if (
			$current['is_active'] !== $request['active'] &&
			updateFoodMenuForManagement($shopId, $request['id'], ['is_active' => $request['active']]) !== true
		) {
			throw new RuntimeException('toggle_failed');
		}
		try {
			$warnings = foodMenuListPostCommitWarnings($shopId);
		} catch (Throwable $warningError) {
			$warnings = false;
		}
		if ($warnings === false) {
			$warningEvaluationFailed = true;
			$warnings = [];
		}
	} else {
		$menus = getFoodMenusForManagement($shopId);
		if ($menus === false) {
			throw new RuntimeException('menu_list_failed');
		}
		$version = buildFoodMenuManagementOrderVersion($menus);
		if ($version === false || !hash_equals($version, $request['version'])) {
			$response = foodMenuListResponse('error', '並び替えエラー', '並び順が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('order_stale');
		}
		$freshIds = array_column($menus, 'id');
		$sentIds = $request['order'];
		$sortedFresh = $freshIds;
		$sortedSent = $sentIds;
		sort($sortedFresh, SORT_NUMERIC);
		sort($sortedSent, SORT_NUMERIC);
		if ($sortedFresh !== $sortedSent) {
			$response = foodMenuListResponse('error', '並び替えエラー', 'メニュー一覧が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('order_set_mismatch');
		}
		if ($freshIds !== $sentIds) {
			$currentById = [];
			foreach ($menus as $menu) {
				$currentById[$menu['id']] = $menu['sort_order'];
			}
			foreach ($sentIds as $index => $id) {
				$newOrder = $index + 1;
				if (
					$currentById[$id] !== $newOrder &&
					updateFoodMenuSortOrderForManagement($shopId, $id, $newOrder) !== true
				) {
					throw new RuntimeException('sort_write_failed');
				}
			}
		}
	}
	if (DB_Transaction(2) !== true) {
		throw new RuntimeException('commit_failed');
	}
	$transactionStarted = false;
	$committed = true;
	$response = foodMenuListResponse('success', '食事メニュー管理', '保存しました。', $warnings);
} catch (Throwable $e) {
	if ($transactionStarted && is_object($DB_CONNECT) && method_exists($DB_CONNECT, 'inTransaction') && $DB_CONNECT->inTransaction()) {
		try {
			if (DB_Transaction(3) !== true) {
				$response = foodMenuListResponse('error', '保存エラー', '処理結果を確認できません。ページを再読み込みしてください。');
			}
		} catch (Throwable $rollbackError) {
			$response = foodMenuListResponse('error', '保存エラー', '処理結果を確認できません。ページを再読み込みしてください。');
		}
	}
}
if ($response['status'] === 'success' && $warningEvaluationFailed) {
	try {
		makeLog(['pageName' => 'proc_client04_03', 'reason' => '食事メニュー保存後警告の判定失敗']);
	} catch (Throwable $logError) {
		// Committed save remains successful even if logging fails.
	}
}
if ($committed) {
	syncFrontendReservationBaseJson($response, $shopId);
	syncFrontendReservationAvailabilityMonthsJson($response, $shopId, $wasEnabled, false);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
