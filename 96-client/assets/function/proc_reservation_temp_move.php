<?php
/*
 * [96-client/assets/function/proc_reservation_temp_move.php]
 * 予約席移動（仮）処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_calender.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');

/**
 * 仮移動endpoint共通response生成
 *  既存管理画面JSON contractへrefresh要否を付けて返す
 */
function clientReservationTempMoveResponse($status, $title, $msg, $refreshRequired = false)
{
	return ['tag' => '', 'status' => $status, 'title' => $title, 'msg' => $msg, 'refreshRequired' => $refreshRequired === true];
}

/**
 * 仮移動endpointをerror終了
 *  transaction開始前のvalidation errorを統一形式で返す
 */
function clientReservationTempMoveExit($title, $msg, $refreshRequired = false)
{
	echo json_encode(clientReservationTempMoveResponse('error', $title, $msg, $refreshRequired), JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * action別POST allow-listを検証
 *  restoreDuplicateTempではversionと移動先を明示的に受け付けない
 */
function clientReservationTempMoveValidatePost($post)
{
	if (is_array($post) === false || is_string($post['action'] ?? null) === false) {
		return false;
	}
	$action = $post['action'];
	$allowed = $action === 'restoreDuplicateTemp'
		? ['noUpDateKey', 'csrfToken', 'action', 'reservationId']
		: ($action === 'commit'
			? ['noUpDateKey', 'csrfToken', 'action', 'reservationId', 'seatChangeVersion', 'targetSeatIds']
			: ['noUpDateKey', 'csrfToken', 'action', 'reservationId', 'seatChangeVersion']);
	if (in_array($action, ['start', 'commit', 'restore', 'restoreDuplicateTemp'], true) === false || count($post) !== count($allowed)) {
		return false;
	}
	foreach ($allowed as $key) {
		if (array_key_exists($key, $post) === false || is_string($post[$key]) === false) {
			return false;
		}
	}
	foreach (array_keys($post) as $key) {
		if (is_string($key) === false || in_array($key, $allowed, true) === false) {
			return false;
		}
	}
	return true;
}

/**
 * 仮移動用正整数を正規化
 *  ASCII数字だけをPHP整数範囲内で受け付ける
 */
function clientReservationTempMovePositiveInteger($value)
{
	if (is_string($value) === false || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
		return null;
	}
	$digits = ltrim($value, '0');
	$digits = $digits === '' ? '0' : $digits;
	if (strlen($digits) > strlen((string)PHP_INT_MAX) || (strlen($digits) === strlen((string)PHP_INT_MAX) && strcmp($digits, (string)PHP_INT_MAX) > 0)) {
		return null;
	}
	$value = (int)$digits;
	return $value > 0 ? $value : null;
}

/**
 * 仮移動用席ID setを正規化
 *  commitの1〜4件comma区切り重複なしだけを受け付ける
 */
function clientReservationTempMoveSeatIds($value)
{
	if (is_string($value) === false || preg_match('/\A[0-9]+(?:,[0-9]+){0,3}\z/D', $value) !== 1) {
		return null;
	}
	$seatIds = [];
	foreach (explode(',', $value) as $item) {
		$seatId = clientReservationTempMovePositiveInteger($item);
		if ($seatId === null || isset($seatIds[$seatId])) {
			return null;
		}
		$seatIds[$seatId] = $seatId;
	}
	return array_values($seatIds);
}

/**
 * fresh予約席行を仮移動contractへ正規化
 *  実席重複・master不在・不正flagを拒否しtemp重複だけを別状態として返す
 */
function clientReservationTempMoveAnalyzeRows($rows)
{
	if (is_array($rows) === false || empty($rows)) {
		return null;
	}
	$reservationSeatIds = [];
	$normalSeatIds = [];
	$tempSeatIds = [];
	foreach ($rows as $row) {
		$reservationSeatId = normalizeReservationDbIntegerForReservations($row['reservation_seat_id'] ?? null, 1, null);
		$seatId = normalizeReservationDbIntegerForReservations($row['seat_id'] ?? null, 1, null);
		$matchedSeatId = normalizeReservationDbIntegerForReservations($row['matched_seat_id'] ?? null, 1, null);
		$isTempMove = normalizeReservationDbIntegerForReservations($row['is_temp_move'] ?? null, 0, 1);
		if ($reservationSeatId === null || $seatId === null || $matchedSeatId !== $seatId || $isTempMove === null || isset($reservationSeatIds[$reservationSeatId])) {
			return null;
		}
		$reservationSeatIds[$reservationSeatId] = true;
		if ($isTempMove === 1) {
			$tempSeatIds[] = $seatId;
		} elseif (isset($normalSeatIds[$seatId])) {
			return null;
		} else {
			$normalSeatIds[$seatId] = $seatId;
		}
	}
	if (empty($normalSeatIds)) {
		return null;
	}
	return ['normal_seat_ids' => array_values($normalSeatIds), 'temp_seat_ids' => $tempSeatIds, 'temp_master_count' => count(array_unique($tempSeatIds)), 'temp_count' => count($tempSeatIds)];
}

/**
 * 席移動（仮）actionをtransaction実行
 *  mutex下のfresh stateだけをauthorityとして開始・確定・復元する
 */
function clientReservationTempMoveExecute($shopId, $reservationId, $action, $seatChangeVersion, $targetSeatIds)
{
	global $DB_CONNECT;
	$transactionStarted = false;
	$availabilityDate = null;
	$response = clientReservationTempMoveResponse('error', '更新エラー', '席移動を更新できませんでした。ページを再読み込みしてください。', true);
	try {
		if (DB_Transaction(1) !== true) {
			return $response;
		}
		$transactionStarted = true;
		$lockedShop = getReservationShopForUpdate($shopId);
		$freshReservation = is_array($lockedShop) ? getReservationSeatChangeForWrite($shopId, $reservationId) : false;
		if (!is_array($lockedShop) || !is_array($freshReservation)) {
			$response = clientReservationTempMoveResponse('error', '更新エラー', '店舗または予約情報を確認できませんでした。', true);
		} elseif ($action !== 'restoreDuplicateTemp' && in_array($freshReservation['status'], [1, 2], true) === false) {
			$response = clientReservationTempMoveResponse('error', '操作できません', '最新の予約状態では席移動を操作できません。', true);
		} elseif ($action === 'restoreDuplicateTemp' && in_array($freshReservation['status'], [1, 2, 3, 4], true) === false) {
			$response = clientReservationTempMoveResponse('error', '操作できません', '最新の予約状態を確認できませんでした。', true);
		} elseif (in_array($action, ['start', 'commit'], true) && $freshReservation['reservation_date'] < (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')) {
			$response = clientReservationTempMoveResponse('error', '操作できません', '過去日の席移動は操作できません。', true);
		} else {
			$rows = getReservationSeatRowsForTempMoveWrite($shopId, $reservationId);
			$seatState = clientReservationTempMoveAnalyzeRows($rows);
			$storeState = getReservationTempMoveGuardState($shopId);
			$occupancyState = buildReservationOccupancyStateFromDb($shopId, $freshReservation['reservation_date']);
			$currentReservation = is_array($occupancyState) ? ($occupancyState['reservationsById'][$reservationId] ?? null) : null;
			if ($seatState === null || $storeState === false || !is_array($currentReservation)) {
				$response = clientReservationTempMoveResponse('error', '操作できません', '現在の割当席情報に異常があるため操作できません。', true);
			} elseif ($action === 'restoreDuplicateTemp') {
				if (
					($storeState['has_invalid_reference'] ?? true) === true ||
					($storeState['temp_reservation_count'] ?? 0) !== 1 ||
					($storeState['guard_reservation_id'] ?? 0) !== $reservationId ||
					$seatState['temp_count'] < 2 ||
					$seatState['temp_master_count'] !== 1 ||
					deleteReservationTempMoveRows($shopId, $reservationId, $seatState['temp_count']) !== true
				) {
					$response = clientReservationTempMoveResponse('error', '操作できません', '重複した仮移動状態を解消できませんでした。', true);
				} else {
					$response = clientReservationTempMoveResponse('success', '席移動', '元の席に戻しました。');
				}
			} else {
				$freshVersion = makeReservationSeatChangeVersion($reservationId, $freshReservation['reservation_date'], $freshReservation['party_size'], $freshReservation['status'], $currentReservation['seat_ids'] ?? [], $seatState['temp_count'] > 0);
				if ($freshVersion === null || hash_equals($freshVersion, $seatChangeVersion) === false) {
					$response = clientReservationTempMoveResponse('error', '席移動', "席の状況が変更されました。\n移動先を選び直してください。", true);
				} elseif ($action === 'start') {
					if ($seatState['temp_count'] !== 0 || ($storeState['active'] ?? true) === true) {
						$response = clientReservationTempMoveResponse('error', '操作できません', 'この店舗では別の席移動が進行中です。最新の状態を確認してください。', true);
					} else {
						$tempSeatId = ensureReservationTempMoveSeat($shopId);
						if (!is_int($tempSeatId) || $tempSeatId < 1 || insertReservationSeats($reservationId, $shopId, [$tempSeatId]) !== true) {
							$response = clientReservationTempMoveResponse('error', '更新エラー', '仮の席への移動を開始できませんでした。', true);
						} else {
							$response = clientReservationTempMoveResponse('success', '席移動', '仮の席へ移動しました。');
						}
					}
				} elseif (
					$seatState['temp_count'] !== 1 ||
					$seatState['temp_master_count'] !== 1 ||
					($storeState['has_invalid_reference'] ?? true) === true ||
					($storeState['temp_reservation_count'] ?? 0) !== 1 ||
					($storeState['guard_reservation_id'] ?? 0) !== $reservationId
				) {
					$response = clientReservationTempMoveResponse('error', '操作できません', '仮移動状態を確認できませんでした。', true);
				} elseif ($action === 'restore') {
					if (deleteReservationTempMoveRows($shopId, $reservationId, 1) === true) {
						$response = clientReservationTempMoveResponse('success', '席移動', '元の席に戻しました。');
					}
				} else {
					$candidateState = $occupancyState;
					$candidateState['reservationsById'][$reservationId]['has_temp_move'] = false;
					$candidates = buildReservationSeatChangeCandidates($candidateState, $reservationId);
					$targetSet = $targetSeatIds;
					sort($targetSet, SORT_NUMERIC);
					$selected = null;
					foreach (is_array($candidates) ? $candidates : [] as $candidate) {
						$candidateIds = array_values(array_map('intval', $candidate['seat_ids'] ?? []));
						$comparison = $candidateIds;
						sort($comparison, SORT_NUMERIC);
						if ($comparison === $targetSet) { $selected = $candidateIds; break; }
					}
					if ($selected === null) {
						$response = clientReservationTempMoveResponse('error', '席移動', "席の状況が変更されました。\n移動先を選び直してください。", true);
					} elseif (replaceReservationSeats($reservationId, $shopId, $selected) === true) {
						$response = clientReservationTempMoveResponse('success', '席移動', '移動先の席を確定しました。');
						$availabilityDate = $freshReservation['reservation_date'];
					}
				}
			}
		}
		if ($response['status'] === 'success' && DB_Transaction(2) === true) {
			$transactionStarted = false;
		} elseif ($response['status'] === 'success') {
			$response = clientReservationTempMoveResponse('error', '更新エラー', '席移動を確定できませんでした。', true);
		}
	} catch (Throwable $e) {
		$response = clientReservationTempMoveResponse('error', '更新エラー', '席移動を更新できませんでした。ページを再読み込みしてください。', true);
	}
	if ($transactionStarted && is_object($DB_CONNECT) && method_exists($DB_CONNECT, 'inTransaction') && $DB_CONNECT->inTransaction()) {
		try { DB_Transaction(3); } catch (Throwable $e) { }
	}
	if (!$transactionStarted && $response['status'] === 'success' && $availabilityDate !== null) {
		syncFrontendReservationAvailabilityDayJson($response, $shopId, $availabilityDate);
	}
	return $response;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || clientReservationTempMoveValidatePost($_POST) === false) {
	clientReservationTempMoveExit('入力エラー', '送信された項目が正しくありません。');
}
$noUpDateKey = $_POST['noUpDateKey'];
if (($currentKey = $_SESSION['sKey'] ?? null) === null || !is_string($currentKey) || !hash_equals($currentKey, $noUpDateKey) || !isset($_SESSION[$noUpDateKey])) {
	clientReservationTempMoveExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。', true);
}
if (validateClientCsrfToken($_POST['csrfToken']) !== true) {
	clientReservationTempMoveExit('セッションエラー', '画面を再読み込みして再度操作してください。', true);
}
$shopId = normalizeReservationDbIntegerForReservations($_SESSION['client_login']['shop_id'] ?? null, 1, null);
$reservationId = clientReservationTempMovePositiveInteger($_POST['reservationId']);
$action = $_POST['action'];
$seatChangeVersion = $action === 'restoreDuplicateTemp' ? null : $_POST['seatChangeVersion'];
$targetSeatIds = $action === 'commit' ? clientReservationTempMoveSeatIds($_POST['targetSeatIds']) : null;
if ($shopId === null || $reservationId === null || ($action !== 'restoreDuplicateTemp' && preg_match('/\A[0-9a-f]{64}\z/D', $seatChangeVersion) !== 1) || ($action === 'commit' && $targetSeatIds === null)) {
	clientReservationTempMoveExit('入力エラー', '席移動の指定が正しくありません。');
}
echo json_encode(clientReservationTempMoveExecute($shopId, $reservationId, $action, $seatChangeVersion, $targetSeatIds), JSON_UNESCAPED_UNICODE);
exit;
