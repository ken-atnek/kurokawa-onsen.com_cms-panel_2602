<?php
/*
 * [96-client/assets/function/proc_client04_04.php]
 *  - 【加盟店】管理画面 -
 *  予約カレンダー／選択日／席preview参照処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations_list.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_render_function.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

/**
 * 管理画面予約参照応答生成
 *  既存Ajaxの基本fieldへ画面instance keyを付けて返す
 */
function clientReservationReadResponse($status, $title, $msg, $noUpDateKey = '')
{
  return [
    'tag' => '',
    'status' => $status,
    'title' => $title,
    'msg' => $msg,
    'noUpDateKey' => $noUpDateKey,
  ];
}

/**
 * 管理画面予約参照エラー応答
 *  内部情報を含まないJSONを返して処理を終了する
 */
function clientReservationReadExit($title, $msg, $noUpDateKey = '')
{
  echo json_encode(clientReservationReadResponse('error', $title, $msg, $noUpDateKey), JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * 予約参照POST field確認
 *  actionごとの許可field以外が含まれていないことを確認する
 */
function clientReservationReadHasOnlyFields($post, $allowedFields)
{
  if (is_array($post) === false || is_array($allowedFields) === false) {
    return false;
  }
  foreach (array_keys($post) as $fieldName) {
    if (is_string($fieldName) === false || in_array($fieldName, $allowedFields, true) === false) {
      return false;
    }
  }
  return true;
}

/**
 * 予約参照共通master取得
 *  店舗・設定・席を取得し、DB failureと店舗不在を失敗として返す
 */
function clientReservationReadLoadBase($shopId)
{
  $shop = getReservationShopForOccupancy($shopId);
  $settings = getShopReservationSettings($shopId);
  $seats = getSeatsForReservationOccupancy($shopId);
  if ($shop === false || $shop === null || $settings === false || $seats === false) {
    return false;
  }
  return [
    'shop' => $shop,
    'settings' => $settings,
    'seats' => $seats,
  ];
}

/**
 * 席preview用の席名配列生成
 *  allocation結果の順序を維持して現在の席master名へ変換する
 */
function clientReservationReadBuildSeatNames($assignedSeatIds, $seatRows)
{
  if (is_array($assignedSeatIds) === false || is_array($seatRows) === false) {
    return null;
  }

  $seatRowsById = [];
  foreach ($seatRows as $seatRow) {
    if (is_array($seatRow) === false) {
      return null;
    }
    $seatId = normalizeReservationRegistrationInteger($seatRow['id'] ?? null, 1, null);
    if ($seatId === null || isset($seatRowsById[$seatId]) === true) {
      return null;
    }
    $seatRowsById[$seatId] = $seatRow;
  }

  $seatNames = [];
  $usedSeatIds = [];
  foreach ($assignedSeatIds as $assignedSeatId) {
    $seatId = normalizeReservationRegistrationInteger($assignedSeatId, 1, null);
    if ($seatId === null || isset($usedSeatIds[$seatId]) === true || isset($seatRowsById[$seatId]) === false) {
      return null;
    }
    $seatName = $seatRowsById[$seatId]['name'] ?? null;
    if (is_string($seatName) === false || preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/u', $seatName) !== 0) {
      return null;
    }
    $usedSeatIds[$seatId] = true;
    $seatNames[] = $seatName;
  }

  return $seatNames;
}

/**
 * 席previewの業務結果生成
 *  internal seat IDやrelocation詳細を含めず安全なfieldだけを返す
 */
function clientReservationReadPreviewResponse($noUpDateKey, $selectedDate, $partySize, $assignable, $seatNames, $relocationRequired, $allocationReason)
{
  $response = clientReservationReadResponse('success', '', '', $noUpDateKey);
  $response['selected_date'] = $selectedDate;
  $response['party_size'] = $partySize;
  $response['assignable'] = (bool)$assignable;
  $response['seat_names'] = array_values($seatNames);
  $response['relocation_required'] = (bool)$relocationRequired;
  $response['allocation_reason'] = $allocationReason;
  return $response;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  clientReservationReadExit('処理エラー', '不正なリクエストです。');
}

$noUpDateKey = is_string($_POST['noUpDateKey'] ?? null) ? $_POST['noUpDateKey'] : '';
$currentNoUpDateKey = (string)($_SESSION['sKey'] ?? '');
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
  if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
    $noUpDateKey = $currentNoUpDateKey;
  } else {
    clientReservationReadExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
  }
}

$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
if (is_int($sessionShopId) === true) {
  $shopId = $sessionShopId;
} elseif (is_string($sessionShopId) === true && preg_match('/\A[1-9][0-9]*\z/D', $sessionShopId) === 1) {
  $shopId = (int)$sessionShopId;
} else {
  clientReservationReadExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。', $noUpDateKey);
}
if ($shopId < 1) {
  clientReservationReadExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。', $noUpDateKey);
}

$action = $_POST['action'] ?? null;
if (is_string($action) === false || in_array($action, ['readMonth', 'readDate', 'previewSeat'], true) === false) {
  clientReservationReadExit('入力エラー', '送信内容を確認してください。', $noUpDateKey);
}
$allowedFields = $action === 'readMonth'
  ? ['noUpDateKey', 'action', 'target_month']
  : ($action === 'readDate'
    ? ['noUpDateKey', 'action', 'selected_date']
    : ['noUpDateKey', 'action', 'selected_date', 'party_size']);
if (clientReservationReadHasOnlyFields($_POST, $allowedFields) === false) {
  clientReservationReadExit('入力エラー', '送信内容を確認してください。', $noUpDateKey);
}

$targetMonth = null;
$range = null;
$selectedDate = null;
$partySize = null;
if ($action === 'readMonth') {
  $targetMonth = normalizeReservationReadTargetMonth($_POST['target_month'] ?? null);
  $range = $targetMonth === null ? null : buildReservationReadCalendarRange($targetMonth);
  if ($range === null) {
    clientReservationReadExit('入力エラー', '対象月を確認してください。', $noUpDateKey);
  }
} else {
  $selectedDate = normalizeReservationReadSelectedDate($_POST['selected_date'] ?? null);
  if ($selectedDate === null) {
    clientReservationReadExit('入力エラー', '対象日を確認してください。', $noUpDateKey);
  }
  if ($action === 'previewSeat') {
    $partySize = normalizeReservationRegistrationInteger($_POST['party_size'] ?? null, 1, 4);
    if ($partySize === null) {
      clientReservationReadExit('入力エラー', '予約人数を確認してください。', $noUpDateKey);
    }
    if ($selectedDate < date('Y-m-d')) {
      clientReservationReadExit('入力エラー', '対象日を確認してください。', $noUpDateKey);
    }
  }
}

$baseData = clientReservationReadLoadBase($shopId);
if ($baseData === false) {
  clientReservationReadExit('取得エラー', '予約情報を取得できませんでした。', $noUpDateKey);
}

if ($action === 'previewSeat') {
  $shopEligibility = buildReservationReadShopEligibility($baseData['shop'], $baseData['settings']);
  if ($shopEligibility === false) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }
  if (($shopEligibility['eligible'] ?? false) !== true) {
    $response = clientReservationReadPreviewResponse(
      $noUpDateKey,
      $selectedDate,
      $partySize,
      false,
      [],
      false,
      'unavailable'
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (
    $partySize < (int)$shopEligibility['guest_min'] ||
    $partySize > (int)$shopEligibility['guest_max']
  ) {
    clientReservationReadExit('入力エラー', '予約人数を確認してください。', $noUpDateKey);
  }

  $reservationWithSeatRows = getReservationWithSeatsReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
  $overrideRows = getReservationCalendarReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
  if ($reservationWithSeatRows === false || $overrideRows === false) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }

  $seatsById = buildReservationReadSeatIndex($baseData['seats']);
  $reservationsByDate = buildReservationReadReservationIndex($reservationWithSeatRows);
  $overridesByDate = buildReservationReadOverrideIndex($overrideRows);
  if ($seatsById === false || $reservationsByDate === false || $overridesByDate === false) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }

  $occupancyState = buildReservationReadOccupancyState(
    $shopId,
    $selectedDate,
    normalizeRegularHolidays($baseData['shop']['closed_weekdays'] ?? null),
    $seatsById,
    $reservationsByDate[$selectedDate] ?? [],
    $overridesByDate[$selectedDate] ?? null
  );
  if ($occupancyState === null) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }

  $assignment = checkAndAssignSeat($shopId, $selectedDate, $partySize, 'simulate', $occupancyState);
  $assignable = $assignment['assignable'] ?? null;
  $assignedSeatIds = $assignment['assigned_seat_ids'] ?? null;
  $relocations = $assignment['relocations'] ?? null;
  $reason = $assignment['reason'] ?? null;
  if (is_bool($assignable) === false || is_array($assignedSeatIds) === false || is_array($relocations) === false) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }

  if ($assignable === true) {
    $seatNames = clientReservationReadBuildSeatNames($assignedSeatIds, $baseData['seats']);
    if ($reason !== null || $seatNames === null || empty($seatNames) === true) {
      clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
    }
    $response = clientReservationReadPreviewResponse(
      $noUpDateKey,
      $selectedDate,
      $partySize,
      true,
      $seatNames,
      empty($relocations) === false,
      'assignable'
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
  }

  $businessReasons = ['full', 'regular_holiday', 'shop_holiday', 'acceptance_stopped'];
  if (empty($assignedSeatIds) === false || empty($relocations) === false || in_array($reason, $businessReasons, true) === false) {
    clientReservationReadExit('取得エラー', '席割当を確認できませんでした。', $noUpDateKey);
  }
  $response = clientReservationReadPreviewResponse(
    $noUpDateKey,
    $selectedDate,
    $partySize,
    false,
    [],
    false,
    $reason
  );
  echo json_encode($response, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'readMonth') {
  $reservationWithSeatRows = getReservationWithSeatsReadRowsByDateRange($shopId, $range['grid_start_date'], $range['grid_end_date']);
  $overrideRows = getReservationCalendarReadRowsByDateRange($shopId, $range['grid_start_date'], $range['grid_end_date']);
  if ($reservationWithSeatRows === false || $overrideRows === false) {
    clientReservationReadExit('取得エラー', '予約情報を取得できませんでした。', $noUpDateKey);
  }
  $readData = buildReservationReadDays(
    $shopId,
    $range['dates'],
    $baseData['shop'],
    $baseData['settings'],
    $baseData['seats'],
    $reservationWithSeatRows,
    $overrideRows
  );
  $tag = $readData === false
    ? null
    : clientReservationReadRenderCalendarTag(
      $range,
      $readData['days'],
      null,
      $readData['shop_eligibility']['eligible']
    );
  if ($readData === false || $tag === null) {
    clientReservationReadExit('取得エラー', '予約情報を取得できませんでした。', $noUpDateKey);
  }

  $response = clientReservationReadResponse('success', '', '', $noUpDateKey);
  $response['tag'] = $tag;
  $response['target_month'] = $targetMonth;
  $response['shop_eligible'] = $readData['shop_eligibility']['eligible'];
  $response['shop_unavailable_reason'] = $readData['shop_eligibility']['reason'];
  echo json_encode($response, JSON_UNESCAPED_UNICODE);
  exit;
}

$reservationWithSeatRows = getReservationWithSeatsReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
$reservationMenuRows = getReservationMenuReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
$overrideRows = getReservationCalendarReadRowsByDateRange($shopId, $selectedDate, $selectedDate);
if ($reservationWithSeatRows === false || $reservationMenuRows === false || $overrideRows === false) {
  clientReservationReadExit('取得エラー', '予約情報を取得できませんでした。', $noUpDateKey);
}
$readData = buildReservationReadDays(
  $shopId,
  [$selectedDate],
  $baseData['shop'],
  $baseData['settings'],
  $baseData['seats'],
  $reservationWithSeatRows,
  $overrideRows,
  $reservationMenuRows
);
$selectedDay = $readData === false ? null : ($readData['days'][$selectedDate] ?? null);
$tag = $selectedDay === null ? null : clientReservationReadRenderListTag($selectedDay['reservations']);
$statusTag = $selectedDay === null
  ? null
  : clientReservationReadRenderStatusTag($selectedDay, $readData['shop_eligibility']['eligible']);
if ($readData === false || $selectedDay === null || $tag === null || $statusTag === null) {
  clientReservationReadExit('取得エラー', '予約情報を取得できませんでした。', $noUpDateKey);
}

$response = clientReservationReadResponse('success', '', '', $noUpDateKey);
$response['tag'] = $tag;
$response['status_tag'] = $statusTag;
$response['selected_date'] = $selectedDate;
$response['availability_status'] = $selectedDay['availability_status'];
$response['shop_eligible'] = $readData['shop_eligibility']['eligible'];
$response['shop_unavailable_reason'] = $readData['shop_eligibility']['reason'];
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
