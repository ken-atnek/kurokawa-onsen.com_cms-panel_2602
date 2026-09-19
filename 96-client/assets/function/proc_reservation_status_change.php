<?php
/*
 * [96-client/assets/function/proc_reservation_status_change.php]
 *  - 【加盟店】管理画面 -
 *  予約ステータス変更処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

/**
 * 予約ステータス変更応答生成
 *  既存管理画面Ajaxの4field形式へ統一する
 */
function clientReservationStatusChangeResponse($status, $title, $msg)
{
	return [
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
	];
}

/**
 * transaction開始前エラー応答
 *  安全なJSONを返して処理を終了する
 */
function clientReservationStatusChangeExit($title, $msg)
{
	echo json_encode(clientReservationStatusChangeResponse('error', $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * 予約ステータス変更POST検証
 *  許可された4fieldの文字列だけを受け付ける
 */
function clientReservationStatusChangeValidatePost($post)
{
	$allowedKeys = ['noUpDateKey', 'csrfToken', 'reservationId', 'reservationStatus'];
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
 * 予約ステータス変更用正整数変換
 *  ASCII decimalをPHP整数範囲内で検証する
 */
function clientReservationStatusChangePositiveInteger($value)
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

/**
 * semantic status変換
 *  許可された4値だけをDB statusへ変換する
 */
function clientReservationStatusChangeTargetStatus($value)
{
	$statusMap = [
		'confirmed' => 1,
		'visited' => 2,
		'canceled' => 3,
		'noShow' => 4,
	];
	return is_string($value) === true && isset($statusMap[$value]) === true ? $statusMap[$value] : null;
}

/**
 * 予約詳細page instance確認
 *  POST値が現在SESSIONのnoUpDateKeyと一致する場合だけ有効とする
 */
function clientReservationStatusChangeNoUpDateKeyIsValid($noUpDateKey, $session)
{
	if (is_string($noUpDateKey) === false || $noUpDateKey === '' || is_array($session) === false) {
		return false;
	}
	$currentNoUpDateKey = $session['sKey'] ?? null;
	return is_string($currentNoUpDateKey) === true &&
		$currentNoUpDateKey !== '' &&
		$noUpDateKey === $currentNoUpDateKey &&
		isset($session[$noUpDateKey]) === true;
}

/**
 * 保存済みstatusとcancelled_at整合性確認
 *  不整合rowは自動修復せずfail-closedとする
 */
function clientReservationStatusChangeStoredRowIsConsistent($reservation)
{
	if (is_array($reservation) === false) {
		return false;
	}
	$status = $reservation['status'] ?? null;
	$cancelledAt = $reservation['cancelled_at'] ?? null;
	if (in_array($status, [1, 2], true) === true) {
		return $cancelledAt === null;
	}
	if (in_array($status, [3, 4], true) === true) {
		return isReservationDbDateTimeStringForReservations($cancelledAt);
	}
	return false;
}

/**
 * 更新後cancelled_at決定
 *  初回キャンセル日時を設定しキャンセル種別訂正では既存値を維持する
 */
function clientReservationStatusChangeBuildCancelledAt($currentStatus, $targetStatus, $currentCancelledAt, $changedAt = null)
{
	if ($currentStatus === $targetStatus) {
		return $currentCancelledAt;
	}
	if (in_array($currentStatus, [1, 2], true) === true && in_array($targetStatus, [3, 4], true) === true) {
		return isReservationDbDateTimeStringForReservations($changedAt) === true ? $changedAt : false;
	}
	if (in_array($currentStatus, [3, 4], true) === true && in_array($targetStatus, [3, 4], true) === true) {
		return $currentCancelledAt;
	}
	if (in_array($currentStatus, [1, 2], true) === true && in_array($targetStatus, [1, 2], true) === true) {
		return null;
	}
	return false;
}

/**
 * 予約ステータス変更transaction実行
 *  shops mutex後のfresh予約だけをauthorityとして更新する
 */
function clientReservationStatusChangeExecute($shopId, $reservationId, $targetStatus)
{
	global $DB_CONNECT;
	$transactionStarted = false;
	$availabilityDate = null;
	$response = clientReservationStatusChangeResponse(
		'error',
		'更新エラー',
		'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
	);

	try {
		if (DB_Transaction(1) !== true) {
			$response = clientReservationStatusChangeResponse(
				'error',
				'更新エラー',
				'予約ステータスの変更を開始できませんでした。ページを再読み込みして状態をご確認ください。'
			);
		} else {
			$transactionStarted = true;
			$lockedShop = getReservationShopForUpdate($shopId);
			if ($lockedShop === false) {
				$response = clientReservationStatusChangeResponse(
					'error',
					'更新エラー',
					'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
				);
			} elseif ($lockedShop === null || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
				$response = clientReservationStatusChangeResponse(
					'error',
					'操作できません',
					'予約情報を確認できませんでした。ページを再読み込みしてください。'
				);
			} else {
				$freshReservation = getReservationStatusForWrite($shopId, $reservationId);
				if ($freshReservation === false) {
					$response = clientReservationStatusChangeResponse(
						'error',
						'更新エラー',
						'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
					);
				} elseif ($freshReservation === null) {
					$response = clientReservationStatusChangeResponse(
						'error',
						'操作できません',
						'予約情報を確認できませんでした。ページを再読み込みしてください。'
					);
				} elseif (clientReservationStatusChangeStoredRowIsConsistent($freshReservation) === false) {
					$response = clientReservationStatusChangeResponse(
						'error',
						'更新エラー',
						'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
					);
				} else {
					$currentStatus = $freshReservation['status'];
					if (isAllowedReservationStatusTransition($currentStatus, $targetStatus) === false) {
						$response = clientReservationStatusChangeResponse(
							'error',
							'操作できません',
							'最新の予約状態ではこのステータスへ変更できません。ページを再読み込みしてください。'
						);
					} elseif ($currentStatus === $targetStatus) {
						$response = clientReservationStatusChangeResponse('success', '予約ステータス変更', '予約ステータスを確認しました。');
					} else {
						$changedAt = in_array($currentStatus, [1, 2], true) === true && in_array($targetStatus, [3, 4], true) === true
							? date('Y-m-d H:i:s')
							: null;
						$cancelledAt = clientReservationStatusChangeBuildCancelledAt(
							$currentStatus,
							$targetStatus,
							$freshReservation['cancelled_at'],
							$changedAt
						);
						if ($cancelledAt === false || updateReservationStatus($shopId, $reservationId, $targetStatus, $cancelledAt) !== true) {
							$response = clientReservationStatusChangeResponse(
								'error',
								'更新エラー',
								'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
							);
						} else {
							$response = clientReservationStatusChangeResponse('success', '予約ステータス変更', '予約ステータスを変更しました。');
							$availabilityDate = $freshReservation['reservation_date'];
						}
					}
				}
			}

			if ($response['status'] === 'success') {
				if (DB_Transaction(2) === true) {
					$transactionStarted = false;
				} else {
					$response = clientReservationStatusChangeResponse(
						'error',
						'更新エラー',
						'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
					);
				}
			}
		}
	} catch (Throwable $e) {
		$response = clientReservationStatusChangeResponse(
			'error',
			'更新エラー',
			'予約ステータスを更新できませんでした。ページを再読み込みして状態をご確認ください。'
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

	if ($transactionStarted === false && $response['status'] === 'success' && $availabilityDate !== null) {
		syncFrontendReservationAvailabilityDayJson($response, $shopId, $availabilityDate);
	}

	return $response;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	clientReservationStatusChangeExit('送信エラー', '送信方法が正しくありません。');
}
if (clientReservationStatusChangeValidatePost($_POST) === false) {
	clientReservationStatusChangeExit('入力エラー', '送信された項目が正しくありません。');
}

$noUpDateKey = $_POST['noUpDateKey'];
if (clientReservationStatusChangeNoUpDateKeyIsValid($noUpDateKey, $_SESSION) === false) {
	clientReservationStatusChangeExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
}

$shopId = normalizeReservationDbIntegerForReservations($_SESSION['client_login']['shop_id'] ?? null, 1, null);
if ($shopId === null) {
	clientReservationStatusChangeExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。');
}
if (validateClientCsrfToken($_POST['csrfToken']) !== true) {
	clientReservationStatusChangeExit('セッションエラー', '画面を再読み込みして再度操作してください。');
}

$reservationId = clientReservationStatusChangePositiveInteger($_POST['reservationId']);
$targetStatus = clientReservationStatusChangeTargetStatus($_POST['reservationStatus']);
if ($reservationId === null || $targetStatus === null) {
	clientReservationStatusChangeExit('入力エラー', '予約ステータスの指定が正しくありません。');
}

echo json_encode(
	clientReservationStatusChangeExecute($shopId, $reservationId, $targetStatus),
	JSON_UNESCAPED_UNICODE
);
exit;
