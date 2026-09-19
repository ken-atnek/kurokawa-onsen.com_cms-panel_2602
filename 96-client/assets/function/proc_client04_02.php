<?php
/*
 * [96-client/assets/function/proc_client04_02.php]
 *  予約基本設定保存
 */

require_once dirname(__DIR__, 3) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_calender.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

/**
 * 予約基本設定保存response生成
 *  既存admin responseへ確認・保存後warning情報をadditiveに付与する
 */
function clientReservationSettingsResponse($status, $title, $msg, $requiresConfirmation = false, $warningCodes = [], $postCommitWarnings = [])
{
	return [
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'requiresConfirmation' => $requiresConfirmation,
		'warningCodes' => $warningCodes,
		'postCommitWarnings' => $postCommitWarnings,
	];
}
/**
 * 予約基本設定保存をJSON応答して終了
 *  内部情報を含まない固定messageだけを返す
 */
function clientReservationSettingsExit($status, $title, $msg)
{
	echo json_encode(clientReservationSettingsResponse($status, $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}
/**
 * ASCII decimal整数を正式候補から正規化
 *  小数、指数、符号付き表現は受け付けない
 */
function normalizeClientReservationSettingsEnum($value, $allowedValues)
{
	if (is_string($value) === false || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
		return null;
	}
	$normalized = (int)$value;
	return in_array($normalized, $allowedValues, true) === true ? $normalized : null;
}
/**
 * checkbox配列を曜日番号配列へ正規化
 *  連番配列、string、unique、0～6だけを許可して昇順化する
 */
function normalizeClientReservationSettingsClosedWeekdays($value)
{
	if ($value === null) {
		return [];
	}
	if (is_array($value) === false || array_is_list($value) === false) {
		return false;
	}
	$weekdays = [];
	foreach ($value as $weekday) {
		if (is_string($weekday) === false || preg_match('/\A[0-6]\z/D', $weekday) !== 1) {
			return false;
		}
		$weekday = (int)$weekday;
		if (in_array($weekday, $weekdays, true) === true) {
			return false;
		}
		$weekdays[] = $weekday;
	}
	sort($weekdays, SORT_NUMERIC);
	return $weekdays;
}
/**
 * 保存済みclosed_weekdaysを厳格に正規化
 *  invalid JSON、非連番、重複、不正型をfail-closedにする
 */
function normalizeClientReservationSettingsStoredClosedWeekdays($value)
{
	if (is_string($value) === false) {
		return false;
	}
	$decoded = json_decode($value, true);
	if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false || array_is_list($decoded) === false) {
		return false;
	}
	$normalized = [];
	foreach ($decoded as $weekday) {
		if (is_int($weekday) === false || $weekday < 0 || $weekday > 6 || in_array($weekday, $normalized, true) === true) {
			return false;
		}
		$normalized[] = $weekday;
	}
	$sorted = $normalized;
	sort($sorted, SORT_NUMERIC);
	return $normalized === $sorted ? $normalized : false;
}
/**
 * 確認済みwarning code配列を検証
 *  既知codeだけの連番・string・unique配列を返す
 */
function normalizeClientReservationSettingsConfirmedWarnings($value)
{
	if ($value === null) {
		return [];
	}
	if (is_array($value) === false || array_is_list($value) === false) {
		return false;
	}
	$allowedCodes = ['guest_max_decrease_oversized_seat', 'guest_max_increase', 'reservation_disabled'];
	$warnings = [];
	foreach ($value as $warningCode) {
		if (
			is_string($warningCode) === false ||
			in_array($warningCode, $allowedCodes, true) === false ||
			in_array($warningCode, $warnings, true) === true
		) {
			return false;
		}
		$warnings[] = $warningCode;
	}
	return $warnings;
}
/**
 * 予約基本設定POSTを正式保存shapeへ正規化
 *  strict allow-listと各fieldの値域・相関を一括検証する
 */
function normalizeClientReservationSettingsRequest($postData)
{
	if (is_array($postData) === false) {
		return false;
	}
	$allowedKeys = [
		'noUpDateKey',
		'csrfToken',
		'reservationEnabled',
		'menuSelectionType',
		'acceptStartDaysBefore',
		'acceptEndDaysBefore',
		'guestMin',
		'guestMax',
		'closedWeekdays',
		'confirmedWarnings',
	];
	foreach (array_keys($postData) as $key) {
		if (is_string($key) === false || in_array($key, $allowedKeys, true) === false) {
			return false;
		}
	}
	foreach (['noUpDateKey', 'csrfToken', 'reservationEnabled', 'menuSelectionType', 'acceptStartDaysBefore', 'acceptEndDaysBefore', 'guestMin', 'guestMax'] as $requiredKey) {
		if (array_key_exists($requiredKey, $postData) === false || is_string($postData[$requiredKey]) === false) {
			return false;
		}
	}
	$reservationEnabled = normalizeClientReservationSettingsEnum($postData['reservationEnabled'], [0, 1]);
	$menuSelectionType = normalizeClientReservationSettingsEnum($postData['menuSelectionType'], [0, 1, 2]);
	$acceptStartRaw = $postData['acceptStartDaysBefore'];
	$acceptStartDaysBefore = $acceptStartRaw === 'unlimited' ? null : normalizeClientReservationSettingsEnum($acceptStartRaw, [90, 60, 30]);
	$acceptEndDaysBefore = normalizeClientReservationSettingsEnum($postData['acceptEndDaysBefore'], [0, 1, 3, 7]);
	$guestMin = normalizeClientReservationSettingsEnum($postData['guestMin'], [1, 2, 3, 4]);
	$guestMax = normalizeClientReservationSettingsEnum($postData['guestMax'], [1, 2, 3, 4]);
	$closedWeekdays = normalizeClientReservationSettingsClosedWeekdays($postData['closedWeekdays'] ?? null);
	$confirmedWarnings = normalizeClientReservationSettingsConfirmedWarnings($postData['confirmedWarnings'] ?? null);
	if (
		$reservationEnabled === null ||
		$menuSelectionType === null ||
		($acceptStartRaw !== 'unlimited' && $acceptStartDaysBefore === null) ||
		$acceptEndDaysBefore === null ||
		$guestMin === null ||
		$guestMax === null ||
		$guestMin > $guestMax ||
		($acceptStartDaysBefore !== null && $acceptStartDaysBefore < $acceptEndDaysBefore) ||
		$closedWeekdays === false ||
		$confirmedWarnings === false
	) {
		return false;
	}
	return [
		'no_up_date_key' => $postData['noUpDateKey'],
		'csrf_token' => $postData['csrfToken'],
		'settings' => [
			'reservation_enabled' => $reservationEnabled,
			'menu_selection_type' => $menuSelectionType,
			'accept_start_days_before' => $acceptStartDaysBefore,
			'accept_end_days_before' => $acceptEndDaysBefore,
			'guest_min' => $guestMin,
			'guest_max' => $guestMax,
		],
		'closed_weekdays' => $closedWeekdays,
		'confirmed_warnings' => $confirmedWarnings,
	];
}
/**
 * fresh stateから保存前warningを計算
 *  settings行不存在時は変更warningを返さない
 */
function getClientReservationSettingsPreSaveWarnings($currentSettings, $newSettings, $oversizedSeats)
{
	if ($currentSettings === null) {
		return [];
	}
	$warnings = [];
	if ($newSettings['guest_max'] < $currentSettings['guest_max'] && is_array($oversizedSeats) === true && empty($oversizedSeats) === false) {
		$warnings[] = 'guest_max_decrease_oversized_seat';
	}
	if ($newSettings['guest_max'] > $currentSettings['guest_max']) {
		$warnings[] = 'guest_max_increase';
	}
	if ($currentSettings['reservation_enabled'] === 1 && $newSettings['reservation_enabled'] === 0) {
		$warnings[] = 'reservation_disabled';
	}
	return $warnings;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	clientReservationSettingsExit('error', '送信エラー', '送信方法が正しくありません。');
}
$request = normalizeClientReservationSettingsRequest($_POST);
if ($request === false) {
	clientReservationSettingsExit('error', '入力エラー', '送信された項目が正しくありません。');
}
$noUpDateKey = $request['no_up_date_key'];
if ($noUpDateKey === '' || ($_SESSION['sKey'] ?? null) !== $noUpDateKey || isset($_SESSION[$noUpDateKey]) === false) {
	clientReservationSettingsExit('error', 'セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
}
if (validateClientCsrfToken($request['csrf_token']) !== true) {
	clientReservationSettingsExit('error', 'セッションエラー', '画面を再読み込みして再度操作してください。');
}
$shopIdRaw = $_SESSION['client_login']['shop_id'] ?? null;
if ((is_int($shopIdRaw) === false && (is_string($shopIdRaw) === false || ctype_digit($shopIdRaw) === false)) || (int)$shopIdRaw < 1) {
	clientReservationSettingsExit('error', 'セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。');
}
$shopId = (int)$shopIdRaw;
$settingsData = $request['settings'];
$transactionStarted = false;
$committed = false;
$wasEnabled = false;
$closedWeekdaysChanged = false;
$response = clientReservationSettingsResponse('error', '保存エラー', '予約基本設定を保存できませんでした。ページを再読み込みしてください。');
try {
	if (DB_Transaction(1) !== true) {
		throw new RuntimeException('transaction_start_failed');
	}
	$transactionStarted = true;
	$lockedShop = getReservationShopForUpdate($shopId);
	if (is_array($lockedShop) === false || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
		throw new RuntimeException('shop_lock_failed');
	}
	$wasEnabled = isReservationEnabledForShop($shopId);
	$freshShop = getReservationShopForOccupancy($shopId);
	if (is_array($freshShop) === false || (int)($freshShop['shop_id'] ?? 0) !== $shopId) {
		throw new RuntimeException('shop_read_failed');
	}
	$currentClosedWeekdays = normalizeClientReservationSettingsStoredClosedWeekdays($freshShop['closed_weekdays'] ?? null);
	if ($currentClosedWeekdays === false) {
		throw new RuntimeException('closed_weekdays_invalid');
	}
	$currentSettingsRaw = getShopReservationSettings($shopId);
	if ($currentSettingsRaw === false) {
		throw new RuntimeException('settings_read_failed');
	}
	$currentSettings = $currentSettingsRaw === null ? null : normalizeShopReservationSettingsData($currentSettingsRaw);
	if ($currentSettingsRaw !== null && $currentSettings === false) {
		throw new RuntimeException('settings_invalid');
	}
	$oversizedSeats = getNormalSeatsExceedingCapacity($shopId, $settingsData['guest_max']);
	$activeMenuCount = getActiveFoodMenuCountForReservation($shopId);
	if ($oversizedSeats === false || $activeMenuCount === false) {
		throw new RuntimeException('master_read_failed');
	}
	$warnings = getClientReservationSettingsPreSaveWarnings($currentSettings, $settingsData, $oversizedSeats);
	$unconfirmedWarnings = array_values(array_diff($warnings, $request['confirmed_warnings']));
	if (empty($unconfirmedWarnings) === false) {
		if (DB_Transaction(3) !== true) {
			throw new RuntimeException('warning_rollback_failed');
		}
		$transactionStarted = false;
		$response = clientReservationSettingsResponse('warning', '確認', '変更内容を確認してください。', true, $warnings, []);
		echo json_encode($response, JSON_UNESCAPED_UNICODE);
		exit;
	}
	if ($currentSettings === null) {
		if (insertShopReservationSettings($shopId, $settingsData) !== true) {
			throw new RuntimeException('settings_insert_failed');
		}
	} else {
		$changedFields = [];
		foreach (array_keys($settingsData) as $field) {
			if ($currentSettings[$field] !== $settingsData[$field]) {
				$changedFields[] = $field;
			}
		}
		if (updateShopReservationSettings($shopId, $settingsData, $changedFields) !== true) {
			throw new RuntimeException('settings_update_failed');
		}
	}
	$closedWeekdaysChanged = $currentClosedWeekdays !== $request['closed_weekdays'];
	if ($closedWeekdaysChanged === true) {
		$closedWeekdaysJson = json_encode($request['closed_weekdays'], JSON_UNESCAPED_UNICODE);
		$dbFiledData = [
			'closed_weekdays' => [':closed_weekdays', $closedWeekdaysJson, 0],
			'updated_at' => [':updated_at', date('Y-m-d H:i:s'), 0],
		];
		$dbFiledValue = ['shop_id' => [':shop_id', $shopId, 1]];
		if (SQL_Process($DB_CONNECT, 'shops', $dbFiledData, $dbFiledValue, 2, 2) != 1) {
			throw new RuntimeException('closed_weekdays_update_failed');
		}
		$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
		if (deleteObsoleteReservationCalendarReleaseOverrides($shopId, $request['closed_weekdays'], $today) !== true) {
			throw new RuntimeException('calendar_cleanup_failed');
		}
	}
	if (DB_Transaction(2) !== true) {
		throw new RuntimeException('commit_failed');
	}
	$transactionStarted = false;
	$committed = true;
	$postCommitWarnings = [];
	if ($settingsData['menu_selection_type'] === 2 && $activeMenuCount === 0) {
		$postCommitWarnings[] = 'menu_required_no_active_menu';
	}
	$response = clientReservationSettingsResponse('success', '予約基本設定', '予約基本設定を保存しました。', false, [], $postCommitWarnings);
} catch (Throwable $e) {
	if ($transactionStarted === true && is_object($DB_CONNECT) === true && method_exists($DB_CONNECT, 'inTransaction') === true && $DB_CONNECT->inTransaction() === true) {
		try {
			DB_Transaction(3);
		} catch (Throwable $rollbackError) {
			# 保存失敗responseを維持する
		}
	}
	makeLog([
		'pageName' => 'proc_client04_02',
		'reason' => '予約基本設定保存失敗',
		'errorMessage' => $e->getMessage(),
	]);
}

if ($committed) {
	syncFrontendReservationBaseJson($response, $shopId);
	syncFrontendReservationAvailabilityMonthsJson($response, $shopId, $wasEnabled, $closedWeekdaysChanged);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
