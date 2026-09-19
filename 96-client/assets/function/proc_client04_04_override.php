<?php
/*
 * [96-client/assets/function/proc_client04_04_override.php]
 *  - 【加盟店】管理画面 -
 *  予約カレンダー日次状態変更処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_calender.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations_list.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_calendar_override_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

/**
 * 日次状態変更応答生成
 *  read・write共通fieldへ統一する
 */
function clientReservationCalendarOverrideResponse($status, $title, $msg, $tag, $noUpDateKey, $selectedDate)
{
	return [
		'tag' => $tag,
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'noUpDateKey' => $noUpDateKey,
		'selected_date' => $selectedDate,
	];
}

/**
 * transaction開始前エラー応答
 *  共通shapeのJSONを返して終了する
 */
function clientReservationCalendarOverrideExit($title, $msg, $noUpDateKey = '', $selectedDate = '')
{
	echo json_encode(
		clientReservationCalendarOverrideResponse('error', $title, $msg, '', $noUpDateKey, $selectedDate),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

/**
 * 日次状態変更POST fieldを確認
 *  許可された4fieldの文字列だけを受け付ける
 */
function clientReservationCalendarOverrideValidatePost($post)
{
	$allowedKeys = ['action', 'selected_date', 'noUpDateKey', 'csrfToken'];
	if (is_array($post) === false || count($post) !== count($allowedKeys)) {
		return false;
	}
	foreach (array_keys($post) as $key) {
		if (is_string($key) === false || in_array($key, $allowedKeys, true) === false) {
			return false;
		}
	}
	foreach ($allowedKeys as $key) {
		if (array_key_exists($key, $post) === false || is_string($post[$key]) === false) {
			return false;
		}
	}

	return true;
}

/**
 * 選択日のfresh action stateを取得
 *  DB read結果を共通pure helperへ渡す
 */
function clientReservationCalendarOverrideLoadState($shopId, $selectedDate, $today)
{
	$shop = getReservationShopForOccupancy($shopId);
	$settings = getShopReservationSettings($shopId);
	$override = getReservationCalendarOverride($shopId, $selectedDate);
	$reservationRows = getReservationWithSeatsReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
	if ($shop === false || $settings === false || $override === false || $reservationRows === false) {
		return false;
	}

	$shopEligibility = buildReservationReadShopEligibility($shop, $settings);
	$reservationsByDate = buildReservationReadReservationIndex($reservationRows);
	if (is_array($shopEligibility) === false || $reservationsByDate === false) {
		return false;
	}

	$overrideStatusType = null;
	if ($override !== null) {
		$overrideStatusType = normalizeReservationRegistrationInteger($override['status_type'] ?? null, 1, 3);
		if ($overrideStatusType === null) {
			return false;
		}
	}

	return buildReservationCalendarOverrideActionState(
		$selectedDate,
		$today,
		$shopEligibility['eligible'] ?? null,
		is_array($shop) ? ($shop['closed_weekdays'] ?? null) : null,
		$overrideStatusType,
		array_values($reservationsByDate[$selectedDate] ?? [])
	);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	clientReservationCalendarOverrideExit('送信エラー', '送信方法が正しくありません。');
}
if (clientReservationCalendarOverrideValidatePost($_POST) === false) {
	clientReservationCalendarOverrideExit('入力エラー', '送信された項目が正しくありません。');
}

$action = $_POST['action'];
$allowedActions = ['readActions', 'setStopped', 'setShopHoliday', 'setAvailable', 'restoreRegularHoliday'];
if (in_array($action, $allowedActions, true) === false) {
	clientReservationCalendarOverrideExit('入力エラー', '指定された操作が正しくありません。');
}

$noUpDateKey = $_POST['noUpDateKey'];
$currentNoUpDateKey = (string)($_SESSION['sKey'] ?? '');
if (
	$noUpDateKey === '' ||
	$currentNoUpDateKey === '' ||
	$noUpDateKey !== $currentNoUpDateKey ||
	isset($_SESSION[$noUpDateKey]) === false
) {
	clientReservationCalendarOverrideExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
}

$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
$shopId = normalizeReservationRegistrationInteger($sessionShopId, 1, null);
if ($shopId === null) {
	clientReservationCalendarOverrideExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。', $noUpDateKey);
}
if (validateClientCsrfToken($_POST['csrfToken']) !== true) {
	clientReservationCalendarOverrideExit('セッションエラー', '画面を再読み込みして再度操作してください。', $noUpDateKey);
}

$selectedDate = normalizeReservationReadSelectedDate($_POST['selected_date']);
if ($selectedDate === null) {
	clientReservationCalendarOverrideExit('入力エラー', '対象日を正しく選択してください。', $noUpDateKey);
}
$today = date('Y-m-d');

if ($action === 'readActions') {
	$state = clientReservationCalendarOverrideLoadState($shopId, $selectedDate, $today);
	$tag = is_array($state) ? clientReservationCalendarOverrideRenderActionsTag($state) : null;
	if (is_string($tag) === false) {
		clientReservationCalendarOverrideExit(
			'取得できません',
			'日次操作を確認できませんでした。画面を再読み込みしてください。',
			$noUpDateKey,
			$selectedDate
		);
	}

	echo json_encode(
		clientReservationCalendarOverrideResponse('success', '', '', $tag, $noUpDateKey, $selectedDate),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

if ($selectedDate < $today) {
	clientReservationCalendarOverrideExit('操作できません', '過去日の予約受付状態は変更できません。', $noUpDateKey, $selectedDate);
}

$transactionStarted = false;
$response = clientReservationCalendarOverrideResponse(
	'error',
	'更新エラー',
	'予約受付状態を変更できませんでした。',
	'',
	$noUpDateKey,
	$selectedDate
);
try {
	if (DB_Transaction(1) !== true) {
		$response = clientReservationCalendarOverrideResponse(
			'error', '更新エラー', '予約受付状態の変更を開始できませんでした。', '', $noUpDateKey, $selectedDate
		);
	} else {
		$transactionStarted = true;
		$lockedShop = getReservationShopForUpdate($shopId);
		if ($lockedShop === false) {
			$response = clientReservationCalendarOverrideResponse(
				'error', '更新エラー', '店舗の予約情報を確認できませんでした。', '', $noUpDateKey, $selectedDate
			);
		} elseif ($lockedShop === null || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
			$response = clientReservationCalendarOverrideResponse(
				'error', '操作できません', '店舗の予約情報を確認できませんでした。', '', $noUpDateKey, $selectedDate
			);
		} else {
			$freshToday = date('Y-m-d');
			$state = clientReservationCalendarOverrideLoadState($shopId, $selectedDate, $freshToday);
			if (is_array($state) === false) {
				$response = clientReservationCalendarOverrideResponse(
					'error', '更新エラー', '最新の予約情報を確認できませんでした。', '', $noUpDateKey, $selectedDate
				);
			} elseif (($writeOperation = buildReservationCalendarOverrideWriteOperation($state, $action)) === null) {
				if (($state['is_past'] ?? false) === true) {
					$msg = '過去日の予約受付状態は変更できません。';
				} elseif ($action === 'setShopHoliday' && ($state['active_reservation_count'] ?? 0) > 0) {
					$msg = '予約が登録されているため、この日を店休日にできません。';
				} else {
					$msg = '現在の状態ではこの操作を行えません。';
				}
				$response = clientReservationCalendarOverrideResponse(
					'error', '操作できません', $msg, '', $noUpDateKey, $selectedDate
				);
			} else {
				$writeResult = false;
				if (($writeOperation['operation'] ?? null) === 'upsert') {
					$writeResult = upsertReservationCalendarOverride(
						$shopId,
						$selectedDate,
						$writeOperation['status_type'] ?? null
					);
				} elseif (($writeOperation['operation'] ?? null) === 'delete') {
					$writeResult = deleteReservationCalendarOverride($shopId, $selectedDate);
				}

				if ($writeResult !== true) {
					$response = clientReservationCalendarOverrideResponse(
						'error', '更新エラー', '予約受付状態を変更できませんでした。', '', $noUpDateKey, $selectedDate
					);
				} else {
					$response = clientReservationCalendarOverrideResponse(
						'success', '予約受付状態変更', '予約受付状態を変更しました。', '', $noUpDateKey, $selectedDate
					);
				}
			}
		}

		if ($response['status'] === 'success') {
			if (DB_Transaction(2) === true) {
				$transactionStarted = false;
			} else {
				$response = clientReservationCalendarOverrideResponse(
					'error', '更新エラー', '予約受付状態を変更できませんでした。', '', $noUpDateKey, $selectedDate
				);
			}
		}
	}
} catch (Throwable $e) {
	$response = clientReservationCalendarOverrideResponse(
		'error', '更新エラー', '予約受付状態を変更できませんでした。', '', $noUpDateKey, $selectedDate
	);
}

if (
	$transactionStarted === true &&
	is_object($DB_CONNECT) === true &&
	method_exists($DB_CONNECT, 'inTransaction') === true &&
	$DB_CONNECT->inTransaction() === true
) {
	try {
		DB_Transaction(3);
	} catch (Throwable $e) {
		# 応答は更新失敗のまま返す
	}
}

if ($transactionStarted === false && $response['status'] === 'success') {
	syncFrontendReservationAvailabilityDayJson($response, $shopId, $selectedDate);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
