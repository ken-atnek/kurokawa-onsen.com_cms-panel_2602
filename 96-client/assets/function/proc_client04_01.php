<?php
/*
 * [96-client/assets/function/proc_client04_01.php]
 *  席管理保存
 */

require_once dirname(__DIR__, 3) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * 席管理の固定形式responseを生成
 *  技術的な失敗理由を画面へ漏らさない
 */
function seatManagementResponse($status, $title, $msg, $requiresConfirmation = false, $warningCodes = [])
{
	return [
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'requiresConfirmation' => $requiresConfirmation,
		'warningCodes' => $warningCodes,
	];
}
/**
 * 席管理responseを返して終了
 *  早期validation失敗はtransaction開始前に処理する
 */
function seatManagementExit($status, $title, $msg)
{
	echo json_encode(seatManagementResponse($status, $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}
/**
 * POSTのASCII正整数をPHP int範囲で検証
 *  float、指数、符号、空白と配列を受け付けない
 */
function normalizeSeatManagementPositiveInteger($value)
{
	if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
		return null;
	}
	$result = filter_var($value, FILTER_VALIDATE_INT);
	return $result === false || $result < 1 ? null : $result;
}
/**
 * 席名の正式入力条件を検証
 *  表記をtrimせず保存し、Unicode空白のみと不正UTF-8を拒否する
 */
function isSeatManagementValidName($value)
{
	return is_string($value) && function_exists('mb_strlen') &&
		preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $value) === 0 &&
		mb_strlen($value, 'UTF-8') <= 50;
}
/**
 * 確認済みwarning codeを厳格に検証
 *  未送信は空配列、重複や連想配列は失敗とする
 */
function normalizeSeatManagementConfirmedWarnings($value)
{
	if ($value === null) {
		return [];
	}
	if (!is_array($value) || !array_is_list($value)) {
		return false;
	}
	$known = [
		'seat_capacity_change_with_reservation',
		'seat_active_change_with_reservation',
		'seat_counter_area_change_with_reservation',
		'seat_type_change_with_reservation',
	];
	$result = [];
	foreach ($value as $code) {
		if (!is_string($code) || !in_array($code, $known, true) || in_array($code, $result, true)) {
			return false;
		}
		$result[] = $code;
	}
	return $result;
}
/**
 * action別のstrict POST shapeを検証
 *  店舗IDや別actionのfieldを混ぜたrequestを拒否する
 */
function normalizeSeatManagementRequest($post)
{
	if (!is_array($post) || !is_string($post['action'] ?? null)) {
		return false;
	}
	$common = ['noUpDateKey', 'csrfToken', 'action'];
	$fields = [
		'create' => ['seatName', 'seatType', 'capacity', 'counterArea'],
		'update' => ['seatId', 'seatVersion', 'seatName', 'seatType', 'capacity', 'counterArea'],
		'toggle' => ['seatId', 'seatVersion', 'isActive'],
		'delete' => ['seatId', 'seatVersion'],
		'sort' => ['seatOrder', 'orderVersion'],
	];
	$action = $post['action'];
	if (!isset($fields[$action])) {
		return false;
	}
	$allowed = array_merge($common, $fields[$action]);
	if (in_array($action, ['update', 'toggle'], true)) {
		$allowed[] = 'confirmedWarnings';
	}
	foreach ($post as $key => $value) {
		if (!is_string($key) || !in_array($key, $allowed, true)) {
			return false;
		}
	}
	foreach (array_merge($common, $fields[$action]) as $key) {
		if (!array_key_exists($key, $post)) {
			return false;
		}
		if (!in_array($key, ['seatOrder'], true) && !is_string($post[$key])) {
			return false;
		}
	}
	if ($post['noUpDateKey'] === '' || $post['csrfToken'] === '') {
		return false;
	}
	$result = [
		'action' => $action,
		'no_up_date_key' => $post['noUpDateKey'],
		'csrf_token' => $post['csrfToken'],
	];
	if (in_array($action, ['update', 'toggle', 'delete'], true)) {
		$result['seat_id'] = normalizeSeatManagementPositiveInteger($post['seatId']);
		$result['seat_version'] = $post['seatVersion'];
		if ($result['seat_id'] === null || preg_match('/\A[a-f0-9]{64}\z/D', $result['seat_version']) !== 1) {
			return false;
		}
	}
	if (in_array($action, ['create', 'update'], true)) {
		$type = $post['seatType'];
		$capacity = normalizeSeatManagementPositiveInteger($post['capacity']);
		if (!isSeatManagementValidName($post['seatName']) || !in_array($type, ['1', '2'], true) || $capacity === null) {
			return false;
		}
		$result['name'] = $post['seatName'];
		$result['type'] = (int)$type;
		$result['capacity'] = $capacity;
		$result['counter_area'] = $post['counterArea'];
		if ($result['type'] === 1 && preg_match('/\A[A-H]\z/D', $result['counter_area']) !== 1) {
			return false;
		}
	}
	if ($action === 'toggle') {
		if (!in_array($post['isActive'], ['0', '1'], true)) {
			return false;
		}
		$result['is_active'] = (int)$post['isActive'];
	}
	if (in_array($action, ['update', 'toggle'], true)) {
		$result['confirmed_warnings'] = normalizeSeatManagementConfirmedWarnings($post['confirmedWarnings'] ?? null);
		if ($result['confirmed_warnings'] === false) {
			return false;
		}
	}
	if ($action === 'sort') {
		if (preg_match('/\A[a-f0-9]{64}\z/D', $post['orderVersion']) !== 1 || !is_array($post['seatOrder']) || !array_is_list($post['seatOrder'])) {
			return false;
		}
		$result['order_version'] = $post['orderVersion'];
		$result['seat_order'] = [];
		foreach ($post['seatOrder'] as $id) {
			$id = normalizeSeatManagementPositiveInteger($id);
			if ($id === null || in_array($id, $result['seat_order'], true)) {
				return false;
			}
			$result['seat_order'][] = $id;
		}
	}
	return $result;
}
/**
 * fresh settingsの存在とguest_maxを検証
 *  不在やmaster不正を自動補正せずwriteを止める
 */
function getSeatManagementFreshGuestMax($shopId)
{
	$raw = getShopReservationSettings($shopId);
	$settings = is_array($raw) ? normalizeShopReservationSettingsData($raw) : false;
	return is_array($settings) ? $settings['guest_max'] : false;
}
/**
 * 入力席をfresh settingsと現在席からcanonicalize
 *  既存tableの超過定員は未変更時だけ保持する
 */
function normalizeSeatManagementTarget($request, $guestMax, $current = null)
{
	if (!is_int($guestMax) || $guestMax < 1) {
		return false;
	}
	$type = $request['type'];
	$capacity = $type === 1 ? 1 : $request['capacity'];
	if ($type === 2 && $capacity > $guestMax && !(
		is_array($current) && $current['type'] === 2 && $current['capacity'] === $capacity
	)) {
		return false;
	}
	return [
		'name' => $request['name'],
		'type' => $type,
		'capacity' => $capacity,
		'counter_area' => $type === 1 ? $request['counter_area'] : null,
	];
}
/**
 * fresh差分から予約影響warningを計算
 *  席名と並び順だけの変更にはwarningを付けない
 */
function getSeatManagementWarnings($changes)
{
	$codes = [
		'capacity' => 'seat_capacity_change_with_reservation',
		'is_active' => 'seat_active_change_with_reservation',
		'counter_area' => 'seat_counter_area_change_with_reservation',
		'type' => 'seat_type_change_with_reservation',
	];
	$result = [];
	foreach ($codes as $field => $code) {
		if (array_key_exists($field, $changes)) {
			$result[] = $code;
		}
	}
	return $result;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	seatManagementExit('error', '送信エラー', '送信方法が正しくありません。');
}
$request = normalizeSeatManagementRequest($_POST);
if ($request === false) {
	seatManagementExit('error', '入力エラー', '送信された項目が正しくありません。');
}
$key = $request['no_up_date_key'];
if (($_SESSION['sKey'] ?? null) !== $key || !isset($_SESSION[$key]) || !isset($_SESSION[$key]['clientKey'])) {
	seatManagementExit('error', 'セッションエラー', 'ページを再読み込みしてください。');
}
$shopIdRaw = $_SESSION['client_login']['shop_id'] ?? null;
if (is_int($shopIdRaw)) {
	$shopId = $shopIdRaw;
} else {
	$shopId = normalizeSeatManagementPositiveInteger($shopIdRaw);
}
if (!is_int($shopId) || $shopId < 1) {
	seatManagementExit('error', 'セッションエラー', '店舗情報が取得できませんでした。');
}
if (validateClientCsrfToken($request['csrf_token']) !== true) {
	seatManagementExit('error', 'セッションエラー', 'ページを再読み込みしてください。');
}
$transactionStarted = false;
$committed = false;
$wasEnabled = false;
$availabilityChanged = false;
$response = seatManagementResponse('error', '保存エラー', '処理できませんでした。ページを再読み込みしてください。');
try {
	if (DB_Transaction(1) !== true) {
		throw new RuntimeException('transaction_start_failed');
	}
	$transactionStarted = true;
	$shop = getReservationShopForUpdate($shopId);
	if (!is_array($shop) || (int)($shop['shop_id'] ?? 0) !== $shopId) {
		throw new RuntimeException('shop_lock_failed');
	}
	$wasEnabled = isReservationEnabledForShop($shopId);
	$action = $request['action'];
	if (in_array($action, ['create', 'update', 'toggle'], true)) {
		$guestMax = getSeatManagementFreshGuestMax($shopId);
		if ($guestMax === false) {
			throw new RuntimeException('settings_invalid');
		}
	}
	if (in_array($action, ['update', 'toggle', 'delete'], true)) {
		$current = getNormalSeatForManagement($shopId, $request['seat_id']);
		if ($current === false) {
			throw new RuntimeException('seat_read_failed');
		}
		if ($current === null) {
			$response = seatManagementResponse('error', '更新エラー', '席が見つかりません。ページを再読み込みしてください。');
			throw new RuntimeException('seat_missing');
		}
		$version = buildSeatManagementSeatVersion($current);
		if ($version === false || !hash_equals($version, $request['seat_version'])) {
			$response = seatManagementResponse('error', '更新エラー', '席の情報が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('seat_stale');
		}
	}
	if ($action === 'create') {
		$target = normalizeSeatManagementTarget($request, $guestMax);
		$seats = getNormalSeatsForManagement($shopId);
		if ($seats === false) {
			throw new RuntimeException('seat_list_failed');
		}
		if ($target === false) {
			$response = seatManagementResponse('error', '入力エラー', '定員が現在の予約基本設定と一致しません。');
			throw new RuntimeException('capacity_invalid');
		}
		$maxOrder = 0;
		foreach ($seats as $seat) {
			$maxOrder = max($maxOrder, $seat['sort_order']);
		}
		if ($maxOrder >= 2147483647) {
			throw new RuntimeException('sort_order_overflow');
		}
		$target['sort_order'] = $maxOrder + 1;
		if (insertNormalSeatForManagement($shopId, $target) !== true) {
			throw new RuntimeException('seat_insert_failed');
		}
		$availabilityChanged = true;
	} elseif ($action === 'update' || $action === 'toggle') {
		if ($action === 'update') {
			$target = normalizeSeatManagementTarget($request, $guestMax, $current);
			if ($target === false) {
				$response = seatManagementResponse('error', '入力エラー', '定員が現在の予約基本設定と一致しません。');
				throw new RuntimeException('capacity_invalid');
			}
		} else {
			$target = ['is_active' => $request['is_active']];
		}
		$changes = [];
		foreach ($target as $field => $value) {
			if ($current[$field] !== $value) {
				$changes[$field] = $value;
			}
		}
		if ($changes !== []) {
			$warnings = getSeatManagementWarnings($changes);
			if ($warnings !== []) {
				$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
				$hasReservation = hasSeatManagementActiveReservation($shopId, $current['id'], $today);
				if ($hasReservation === false) {
					throw new RuntimeException('reservation_read_failed');
				}
				if ($hasReservation === 1) {
					$unconfirmed = array_diff($warnings, $request['confirmed_warnings']);
					if ($unconfirmed !== []) {
						if (DB_Transaction(3) !== true) {
							throw new RuntimeException('warning_rollback_failed');
						}
						$transactionStarted = false;
						echo json_encode(seatManagementResponse('warning', '確認', '変更内容を確認してください。', true, $warnings), JSON_UNESCAPED_UNICODE);
						exit;
					}
				}
			}
			if (updateNormalSeatForManagement($shopId, $current['id'], $changes) !== true) {
				throw new RuntimeException('seat_update_failed');
			}
			$availabilityChanged = true;
		}
	} elseif ($action === 'delete') {
		$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
		$hasReservation = hasSeatManagementActiveReservation($shopId, $current['id'], $today);
		if ($hasReservation === false) {
			throw new RuntimeException('reservation_read_failed');
		}
		if ($hasReservation === 1) {
			$response = seatManagementResponse('error', '削除できません', '現在または今後の予約がある席は削除できません。');
			throw new RuntimeException('seat_delete_blocked');
		}
		if (deleteNormalSeatForManagement($shopId, $current['id']) !== true) {
			throw new RuntimeException('seat_delete_failed');
		}
		$availabilityChanged = true;
	} elseif ($action === 'sort') {
		$seats = getNormalSeatsForManagement($shopId);
		$version = $seats === false ? false : buildSeatManagementOrderVersion($seats);
		if ($version === false) {
			throw new RuntimeException('seat_list_failed');
		}
		if (!hash_equals($version, $request['order_version'])) {
			$response = seatManagementResponse('error', '更新エラー', '席の並び順が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('order_stale');
		}
		$freshIds = array_column($seats, 'id');
		if (count($freshIds) !== count($request['seat_order']) || array_diff($freshIds, $request['seat_order']) !== []) {
			$response = seatManagementResponse('error', '入力エラー', '席の並び順を確認できません。ページを再読み込みしてください。');
			throw new RuntimeException('seat_order_invalid');
		}
		if ($freshIds !== $request['seat_order']) {
			$byId = array_column($seats, null, 'id');
			foreach ($request['seat_order'] as $index => $seatId) {
				$newOrder = $index + 1;
				if ($byId[$seatId]['sort_order'] !== $newOrder && updateNormalSeatSortOrderForManagement($shopId, $seatId, $newOrder) !== true) {
					throw new RuntimeException('seat_sort_failed');
				}
			}
			$availabilityChanged = true;
		}
	}
	if (DB_Transaction(2) !== true) {
		throw new RuntimeException('commit_failed');
	}
	$transactionStarted = false;
	$committed = true;
	$response = seatManagementResponse('success', '席管理', '席情報を保存しました。');
} catch (Throwable $e) {
	if ($transactionStarted && is_object($DB_CONNECT) && method_exists($DB_CONNECT, 'inTransaction') && $DB_CONNECT->inTransaction() === true) {
		try {
			DB_Transaction(3);
		} catch (Throwable $rollbackError) {
			// 固定error responseを維持する。
		}
	}
	makeLog(['pageName' => 'proc_client04_01', 'reason' => '席管理処理失敗', 'errorMessage' => $e->getMessage()]);
}

if ($committed && $availabilityChanged) {
	syncFrontendReservationBasicJson($response, $shopId);
	syncFrontendReservationAvailabilityMonthsJson($response, $shopId, $wasEnabled, true);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
