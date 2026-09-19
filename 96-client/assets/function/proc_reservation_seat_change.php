<?php
/*
 * [96-client/assets/function/proc_reservation_seat_change.php]
 * 予約実席変更処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_calender.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

function clientReservationSeatChangeResponse($status, $title, $msg, $refreshRequired = false)
{
	return [
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'refreshRequired' => $refreshRequired === true,
	];
}

function clientReservationSeatChangeExit($title, $msg, $refreshRequired = false)
{
	echo json_encode(
		clientReservationSeatChangeResponse('error', $title, $msg, $refreshRequired),
		JSON_UNESCAPED_UNICODE
	);
	exit;
}

function clientReservationSeatChangeValidatePost($post)
{
	$allowedKeys = ['noUpDateKey', 'csrfToken', 'reservationId', 'seatChangeVersion', 'targetSeatIds'];
	if (is_array($post) === false || count($post) !== count($allowedKeys)) {
		return false;
	}
	foreach ($allowedKeys as $key) {
		if (array_key_exists($key, $post) === false || is_string($post[$key]) === false) {
			return false;
		}
	}
	foreach (array_keys($post) as $key) {
		if (is_string($key) === false || in_array($key, $allowedKeys, true) === false) {
			return false;
		}
	}
	return true;
}

function clientReservationSeatChangeNoUpDateKeyIsValid($noUpDateKey, $session)
{
	if (is_string($noUpDateKey) === false || $noUpDateKey === '' || is_array($session) === false) {
		return false;
	}
	$currentNoUpDateKey = $session['sKey'] ?? null;
	return is_string($currentNoUpDateKey) === true
		&& $currentNoUpDateKey !== ''
		&& hash_equals($currentNoUpDateKey, $noUpDateKey)
		&& isset($session[$noUpDateKey]) === true;
}

function clientReservationSeatChangePositiveInteger($value)
{
	if (is_string($value) === false || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
		return null;
	}
	$digits = ltrim($value, '0');
	$digits = $digits === '' ? '0' : $digits;
	$phpIntMax = (string)PHP_INT_MAX;
	if (strlen($digits) > strlen($phpIntMax) || (strlen($digits) === strlen($phpIntMax) && strcmp($digits, $phpIntMax) > 0)) {
		return null;
	}
	$value = (int)$digits;
	return $value > 0 ? $value : null;
}

function clientReservationSeatChangeSeatIds($value)
{
	if (is_string($value) === false || preg_match('/\A[0-9]+(?:,[0-9]+){0,3}\z/D', $value) !== 1) {
		return null;
	}
	$seatIds = [];
	foreach (explode(',', $value) as $seatIdValue) {
		$seatId = clientReservationSeatChangePositiveInteger($seatIdValue);
		if ($seatId === null || isset($seatIds[$seatId]) === true) {
			return null;
		}
		$seatIds[$seatId] = $seatId;
	}
	return array_values($seatIds);
}

function clientReservationSeatChangeExecute($shopId, $reservationId, $seatChangeVersion, $targetSeatIds)
{
	global $DB_CONNECT;
	$transactionStarted = false;
	$availabilityDate = null;
	$response = clientReservationSeatChangeResponse(
		'error',
		'更新エラー',
		'割当席を変更できませんでした。ページを再読み込みして状態をご確認ください。',
		true
	);

	try {
		if (DB_Transaction(1) !== true) {
			$response = clientReservationSeatChangeResponse('error', '更新エラー', '席変更を開始できませんでした。ページを再読み込みしてください。', true);
		} else {
			$transactionStarted = true;
			$lockedShop = getReservationShopForUpdate($shopId);
			if ($lockedShop === false) {
				$response = clientReservationSeatChangeResponse('error', '更新エラー', '割当席を変更できませんでした。ページを再読み込みしてください。', true);
			} elseif ($lockedShop === null || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
				$response = clientReservationSeatChangeResponse('error', '操作できません', '店舗情報を確認できませんでした。再ログインしてください。', true);
			} else {
				$freshReservation = getReservationSeatChangeForWrite($shopId, $reservationId);
				if ($freshReservation === false) {
					$response = clientReservationSeatChangeResponse('error', '更新エラー', '予約情報を確認できませんでした。ページを再読み込みしてください。', true);
				} elseif ($freshReservation === null) {
					$response = clientReservationSeatChangeResponse('error', '操作できません', '予約情報を確認できませんでした。ページを再読み込みしてください。', true);
				} elseif (in_array($freshReservation['status'], [1, 2], true) === false) {
					$response = clientReservationSeatChangeResponse('error', '操作できません', '最新の予約状態では席を変更できません。ページを再読み込みしてください。', true);
				} elseif ($freshReservation['reservation_date'] < (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')) {
					$response = clientReservationSeatChangeResponse('error', '操作できません', '過去日の割当席は変更できません。ページを再読み込みしてください。', true);
				} elseif (validateReservationSeatChangeRowsForWrite($shopId, $reservationId) !== true) {
					$response = clientReservationSeatChangeResponse('error', '操作できません', '現在の割当席情報に異常があるため変更できません。ページを再読み込みしてください。', true);
				} else {
					$occupancyState = buildReservationOccupancyStateFromDb($shopId, $freshReservation['reservation_date']);
					$currentReservation = is_array($occupancyState)
						? ($occupancyState['reservationsById'][$reservationId] ?? null)
						: null;
					if (is_array($currentReservation) === false) {
						$response = clientReservationSeatChangeResponse('error', '更新エラー', '現在の割当席を確認できませんでした。ページを再読み込みしてください。', true);
					} elseif ((bool)($currentReservation['has_temp_move'] ?? false) === true) {
						$response = clientReservationSeatChangeResponse('error', '操作できません', '仮の席へ移動中の予約です。最新の状態を確認してください。', true);
					} else {
						$freshVersion = makeReservationSeatChangeVersion(
							$reservationId,
							$freshReservation['reservation_date'],
							$freshReservation['party_size'],
							$freshReservation['status'],
							$currentReservation['seat_ids'] ?? [],
							false
						);
						if ($freshVersion === null || hash_equals($freshVersion, $seatChangeVersion) === false) {
							$response = clientReservationSeatChangeResponse('error', '席変更', "席の状況が変更されました。\n移動先を選び直してください。", true);
						} else {
							$candidates = buildReservationSeatChangeCandidates($occupancyState, $reservationId);
							$targetSeatIdSet = $targetSeatIds;
							sort($targetSeatIdSet, SORT_NUMERIC);
							$selectedSeatIds = null;
							foreach (is_array($candidates) ? $candidates : [] as $candidate) {
								$candidateSeatIds = array_values(array_map('intval', $candidate['seat_ids'] ?? []));
								$candidateSeatIdSet = $candidateSeatIds;
								sort($candidateSeatIdSet, SORT_NUMERIC);
								if ($candidateSeatIdSet === $targetSeatIdSet) {
									$selectedSeatIds = $candidateSeatIds;
									break;
								}
							}
							if ($selectedSeatIds === null) {
								$response = clientReservationSeatChangeResponse('error', '席変更', "席の状況が変更されました。\n移動先を選び直してください。", true);
							} elseif (replaceReservationSeats($reservationId, $shopId, $selectedSeatIds) !== true) {
								$response = clientReservationSeatChangeResponse('error', '更新エラー', '割当席を変更できませんでした。ページを再読み込みしてください。', true);
							} else {
								$response = clientReservationSeatChangeResponse('success', '席変更', '割当席を変更しました。');
								$availabilityDate = $freshReservation['reservation_date'];
							}
						}
					}
				}
			}

			if ($response['status'] === 'success') {
				if (DB_Transaction(2) === true) {
					$transactionStarted = false;
				} else {
					$response = clientReservationSeatChangeResponse('error', '更新エラー', '割当席を変更できませんでした。ページを再読み込みしてください。', true);
				}
			}
		}
	} catch (Throwable $e) {
		$response = clientReservationSeatChangeResponse('error', '更新エラー', '割当席を変更できませんでした。ページを再読み込みして状態をご確認ください。', true);
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

	if ($transactionStarted === false && $response['status'] === 'success' && $availabilityDate !== null) {
		syncFrontendReservationAvailabilityDayJson($response, $shopId, $availabilityDate);
	}

	return $response;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	clientReservationSeatChangeExit('送信エラー', '送信方法が正しくありません。');
}
if (clientReservationSeatChangeValidatePost($_POST) === false) {
	clientReservationSeatChangeExit('入力エラー', '送信された項目が正しくありません。');
}

$noUpDateKey = $_POST['noUpDateKey'];
if (clientReservationSeatChangeNoUpDateKeyIsValid($noUpDateKey, $_SESSION) === false) {
	clientReservationSeatChangeExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。', true);
}
if (validateClientCsrfToken($_POST['csrfToken']) !== true) {
	clientReservationSeatChangeExit('セッションエラー', '画面を再読み込みして再度操作してください。', true);
}

$shopId = normalizeReservationDbIntegerForReservations($_SESSION['client_login']['shop_id'] ?? null, 1, null);
$reservationId = clientReservationSeatChangePositiveInteger($_POST['reservationId']);
$seatChangeVersion = $_POST['seatChangeVersion'];
$targetSeatIds = clientReservationSeatChangeSeatIds($_POST['targetSeatIds']);
if ($shopId === null) {
	clientReservationSeatChangeExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。', true);
}
if ($reservationId === null || preg_match('/\A[0-9a-f]{64}\z/D', $seatChangeVersion) !== 1 || $targetSeatIds === null) {
	clientReservationSeatChangeExit('入力エラー', '席変更の指定が正しくありません。', true);
}

echo json_encode(
	clientReservationSeatChangeExecute($shopId, $reservationId, $seatChangeVersion, $targetSeatIds),
	JSON_UNESCAPED_UNICODE
);
exit;
