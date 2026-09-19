<?php
/*
 * [96-client/assets/function/proc_reservation_detail_save.php]
 *  - 【加盟店】管理画面 -
 *  予約詳細編集保存処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_detail_edit_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

/**
 * 予約詳細編集応答生成
 *  既存管理画面Ajaxの4field形式へ統一する
 */
function clientReservationDetailSaveResponse($status, $title, $msg)
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
function clientReservationDetailSaveExit($title, $msg)
{
	echo json_encode(clientReservationDetailSaveResponse('error', $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}

/**
 * 予約詳細page instance確認
 *  POST値と現在SESSIONのnoUpDateKeyが一致する場合だけ有効とする
 */
function clientReservationDetailSaveNoUpDateKeyIsValid($noUpDateKey, $session)
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
 * 予約詳細編集transaction実行
 *  shops mutex後のfresh stateだけをauthorityとして差分保存する
 */
function clientReservationDetailSaveExecute($shopId, $request)
{
	global $DB_CONNECT;
	$transactionStarted = false;
	$availabilityDate = null;
	$response = clientReservationDetailSaveResponse(
		'error',
		'保存エラー',
		'予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。'
	);

	try {
		if (DB_Transaction(1) !== true) {
			$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報の保存を開始できませんでした。ページを再読み込みしてください。');
		} else {
			$transactionStarted = true;
			$lockedShop = getReservationShopForUpdate($shopId);
			if ($lockedShop === false) {
				$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報を保存できませんでした。ページを再読み込みしてください。');
			} elseif ($lockedShop === null || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
				$response = clientReservationDetailSaveResponse('error', '操作できません', '店舗情報を確認できませんでした。再ログインしてください。');
			} else {
				$reservationId = $request['reservation_id'];
				$freshReservation = getReservationDetailEditForWrite($shopId, $reservationId);
				if ($freshReservation === null) {
					$response = clientReservationDetailSaveResponse('error', '操作できません', '予約情報を確認できませんでした。ページを再読み込みしてください。');
				} elseif ($freshReservation === false) {
					$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報を取得できませんでした。ページを再読み込みしてください。');
				} else {
					$freshMenuRows = getReservationDetailEditMenuRowsForWrite($shopId, $reservationId);
					$freshSettings = getShopReservationSettings($shopId);
					$menuSelectionType = is_array($freshSettings) === true
						? normalizeReservationDetailEditInteger($freshSettings['menu_selection_type'] ?? null, 0, 2)
						: null;
					$freshVersion = is_array($freshMenuRows) === true && $menuSelectionType !== null
						? buildReservationDetailEditVersion($freshReservation, $freshMenuRows, $menuSelectionType)
						: false;
					if ($freshVersion === false) {
						$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報の整合性を確認できませんでした。ページを再読み込みしてください。');
					} elseif (hash_equals($freshVersion, $request['detail_edit_version']) === false) {
						$response = clientReservationDetailSaveResponse('error', '情報が更新されています', '他の操作で予約情報が更新されています。ページを再読み込みして最新状態をご確認ください。');
					} else {
						$currentMenuRows = normalizeReservationDetailEditMenuRows($freshMenuRows, $reservationId, $freshReservation['party_size']);
						$menuPlan = $currentMenuRows === false ? false : buildReservationDetailEditMenuPlan(
							$menuSelectionType,
							$freshReservation['party_size'],
							$request['party_size'],
							$currentMenuRows,
							$request['menu_slots']
						);
						if ($menuPlan === false) {
							$response = clientReservationDetailSaveResponse('error', '入力エラー', 'メニューの指定が正しくありません。ページを再読み込みしてください。');
						} else {
							$partySizeAllowed = true;
							if ($request['party_size'] > $freshReservation['party_size'] && in_array($freshReservation['status'], [1, 2], true) === true) {
								$seatRows = getReservationDetailEditAssignedSeatRowsForWrite($shopId, $reservationId);
								$partySizeAllowed = isReservationDetailEditPartySizeAllowed(
									$freshReservation['party_size'],
									$request['party_size'],
									$freshReservation['status'],
									$seatRows
								);
							}
							if ($partySizeAllowed === false) {
								$response = clientReservationDetailSaveResponse('error', '人数を変更できません', '現在の割当席では指定された人数へ増やせません。ページを再読み込みして状態をご確認ください。');
							} else {
								$snapshotRows = [];
								if (empty($menuPlan['menu_ids']) === false) {
									$menuRowsById = getFoodMenuRowsForReservation($shopId, $menuPlan['menu_ids']);
									$snapshotResult = is_array($menuRowsById) === true
										? buildReservationMenuSnapshotRowsForRegistration($shopId, $menuPlan['snapshot_slots'], $menuRowsById, foodMenuTodayJst())
										: false;
									if (is_array($snapshotResult) === false || ($snapshotResult['success'] ?? false) !== true) {
										$menuPlan = false;
									} else {
										$snapshotRows = $snapshotResult['menu_rows'];
									}
								}
								$menuPlan = $menuPlan === false ? false : completeReservationDetailEditMenuPlan($menuPlan, $snapshotRows);
								$reservationChanges = buildReservationDetailEditReservationChanges($freshReservation, $request['reservation_data']);
								if ($menuPlan === false || $reservationChanges === false) {
									$response = clientReservationDetailSaveResponse('error', '入力エラー', '入力内容を確認できませんでした。ページを再読み込みしてください。');
								} else {
									$writeSucceeded = true;
									if (empty($reservationChanges) === false) {
										$writeSucceeded = updateReservationDetailFields($shopId, $reservationId, $reservationChanges);
									}
									if ($writeSucceeded === true && deleteReservationMenusForDetailEdit($shopId, $reservationId, $menuPlan['deletes']) !== true) {
										$writeSucceeded = false;
									}
									if ($writeSucceeded === true && updateReservationMenusForDetailEdit($shopId, $reservationId, $menuPlan['updates']) !== true) {
										$writeSucceeded = false;
									}
									if ($writeSucceeded === true && empty($menuPlan['inserts']) === false && insertReservationMenus($reservationId, $shopId, $menuPlan['inserts']) !== true) {
										$writeSucceeded = false;
									}
									$response = $writeSucceeded === true
										? clientReservationDetailSaveResponse('success', '予約詳細保存', '予約情報を保存しました。')
										: clientReservationDetailSaveResponse('error', '保存エラー', '予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。');
									if ($writeSucceeded === true && array_key_exists('party_size', $reservationChanges)) {
										$availabilityDate = $freshReservation['reservation_date'];
									}
								}
							}
						}
					}
				}
			}

			if ($response['status'] === 'success') {
				if (DB_Transaction(2) === true) {
					$transactionStarted = false;
				} else {
					$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。');
				}
			}
		}
	} catch (Throwable $e) {
		$response = clientReservationDetailSaveResponse('error', '保存エラー', '予約情報を保存できませんでした。ページを再読み込みして状態をご確認ください。');
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
			# 応答は保存失敗のまま返す
		}
	}

	if ($transactionStarted === false && $response['status'] === 'success' && $availabilityDate !== null) {
		syncFrontendReservationAvailabilityDayJson($response, $shopId, $availabilityDate);
	}

	return $response;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	clientReservationDetailSaveExit('送信エラー', '送信方法が正しくありません。');
}
if (isReservationDetailEditPostSyntaxValid($_POST) === false) {
	clientReservationDetailSaveExit('入力エラー', '送信された項目が正しくありません。');
}
if (clientReservationDetailSaveNoUpDateKeyIsValid($_POST['noUpDateKey'], $_SESSION) === false) {
	clientReservationDetailSaveExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
}
$shopId = normalizeReservationDetailEditInteger($_SESSION['client_login']['shop_id'] ?? null, 1, null);
if ($shopId === null) {
	clientReservationDetailSaveExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。');
}
if (validateClientCsrfToken($_POST['csrfToken']) !== true) {
	clientReservationDetailSaveExit('セッションエラー', '画面を再読み込みして再度操作してください。');
}
$request = normalizeReservationDetailEditPost($_POST);
if ($request === false) {
	clientReservationDetailSaveExit('入力エラー', '送信された項目が正しくありません。');
}

echo json_encode(clientReservationDetailSaveExecute($shopId, $request), JSON_UNESCAPED_UNICODE);
exit;
