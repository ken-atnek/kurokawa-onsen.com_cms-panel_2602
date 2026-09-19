<?php
/*
 * [96-client/assets/function/proc_client04_05.php]
 *  - 【加盟店】予約一覧read処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_list_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations_list.php';

date_default_timezone_set('Asia/Tokyo');

/**
 * 予約一覧JSON応答
 *  管理画面Ajax共通形式で応答して処理を終了する
 */
function respondClientReservationList($responseData)
{
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($responseData, JSON_UNESCAPED_UNICODE);
  exit;
}
/**
 * 予約一覧POST allow-list確認
 *  一覧read contract外のfieldを拒否する
 */
function hasOnlyClientReservationListPostFields($postData)
{
  if (is_array($postData) === false) {
    return false;
  }
  $allowedFields = [
    'noUpDateKey',
    'searchVisitStartDay',
    'searchVisitEndDay',
    'searchReceptionStartDay',
    'searchReceptionEndDay',
    'searchCustomerName',
    'searchCustomerTel',
    'searchReservationRoute',
    'searchReservationStatus',
    'pageNumber',
  ];
  foreach (array_keys($postData) as $fieldName) {
    if (is_string($fieldName) === false || in_array($fieldName, $allowedFields, true) === false) {
      return false;
    }
  }
  return true;
}
/**
 * 予約一覧POST文字列取得
 *  必須fieldのscalar stringをtrimして最大文字数内で返す
 */
function getClientReservationListPostString($postData, $fieldName, $maxLength)
{
  if (is_array($postData) === false || array_key_exists($fieldName, $postData) === false || is_string($postData[$fieldName]) === false) {
    return null;
  }
  $value = trim($postData[$fieldName]);
  $length = mb_strlen($value, 'UTF-8');
  if ($length === false || $length > $maxLength) {
    return null;
  }
  return $value;
}
/**
 * 予約一覧POST日付取得
 *  emptyまたはY-m-d形式の実在日付だけを返す
 */
function getClientReservationListPostDate($postData, $fieldName)
{
  $value = getClientReservationListPostString($postData, $fieldName, 10);
  if ($value === null || ($value !== '' && isReservationReadDbDateString($value) === false)) {
    return null;
  }
  return $value;
}
/**
 * 予約一覧POST page番号取得
 *  ASCII decimalの正整数だけをintへ変換する
 */
function getClientReservationListPostPageNumber($postData)
{
  if (is_array($postData) === false || array_key_exists('pageNumber', $postData) === false || is_string($postData['pageNumber']) === false) {
    return null;
  }
  $pageNumberRaw = $postData['pageNumber'];
  if (preg_match('/\A[1-9][0-9]*\z/D', $pageNumberRaw) !== 1) {
    return null;
  }
  $pageNumber = filter_var($pageNumberRaw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
  ]);
  return $pageNumber === false ? null : (int)$pageNumber;
}

$makeTag = [
  'tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
  'noUpDateKey' => '',
  'total_items' => 0,
  'total_pages' => 0,
  'page_number' => 1,
  'pager' => '',
];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'リクエストエラー';
  $makeTag['msg'] = '不正なリクエストです。ページを再読み込みしてください。';
  respondClientReservationList($makeTag);
}
if (hasOnlyClientReservationListPostFields($_POST) === false) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '入力エラー';
  $makeTag['msg'] = '送信内容が不正です。ページを再読み込みしてください。';
  respondClientReservationList($makeTag);
}
$postedNoUpDateKey = getClientReservationListPostString($_POST, 'noUpDateKey', 128);
$currentNoUpDateKey = isset($_SESSION['sKey']) && is_string($_SESSION['sKey']) ? $_SESSION['sKey'] : '';
if ($postedNoUpDateKey === null || $postedNoUpDateKey === '' || isset($_SESSION[$postedNoUpDateKey]) === false) {
  if ($currentNoUpDateKey === '' || isset($_SESSION[$currentNoUpDateKey]) === false) {
    $makeTag['status'] = 'error';
    $makeTag['title'] = 'セッションエラー';
    $makeTag['msg'] = 'セッションが切れました。ページを再読み込みしてください。';
    respondClientReservationList($makeTag);
  }
  $postedNoUpDateKey = $currentNoUpDateKey;
}
$makeTag['noUpDateKey'] = $postedNoUpDateKey;

$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
if ((is_int($sessionShopId) === false && (is_string($sessionShopId) === false || ctype_digit($sessionShopId) === false)) || (int)$sessionShopId < 1) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。再ログインしてください。';
  respondClientReservationList($makeTag);
}
$shopId = (int)$sessionShopId;

$visitStartDay = getClientReservationListPostDate($_POST, 'searchVisitStartDay');
$visitEndDay = getClientReservationListPostDate($_POST, 'searchVisitEndDay');
$receptionStartDay = getClientReservationListPostDate($_POST, 'searchReceptionStartDay');
$receptionEndDay = getClientReservationListPostDate($_POST, 'searchReceptionEndDay');
$customerName = getClientReservationListPostString($_POST, 'searchCustomerName', 101);
$customerTel = getClientReservationListPostString($_POST, 'searchCustomerTel', 20);
$reservationRoute = getClientReservationListPostString($_POST, 'searchReservationRoute', 16);
$reservationStatus = getClientReservationListPostString($_POST, 'searchReservationStatus', 16);
$pageNumber = getClientReservationListPostPageNumber($_POST);

if (
  $visitStartDay === null || $visitEndDay === null || $receptionStartDay === null || $receptionEndDay === null
  || $customerName === null || $customerTel === null || $reservationRoute === null || $reservationStatus === null || $pageNumber === null
) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '入力エラー';
  $makeTag['msg'] = '検索条件を確認してください。';
  respondClientReservationList($makeTag);
}
if (($visitStartDay !== '' && $visitEndDay !== '' && $visitStartDay > $visitEndDay)
  || ($receptionStartDay !== '' && $receptionEndDay !== '' && $receptionStartDay > $receptionEndDay)
) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '入力エラー';
  $makeTag['msg'] = '期間の開始日は終了日以前の日付を指定してください。';
  respondClientReservationList($makeTag);
}

$routeMap = [
  'all' => null,
  'web' => 1,
  'tel' => 2,
  'other' => 3,
];
$statusMap = [
  'all' => null,
  'confirmed' => 1,
  'visited' => 2,
  'canceled' => 3,
  'noShow' => 4,
];
if (array_key_exists($reservationRoute, $routeMap) === false || array_key_exists($reservationStatus, $statusMap) === false) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '入力エラー';
  $makeTag['msg'] = '予約経路または予約ステータスを確認してください。';
  respondClientReservationList($makeTag);
}

$searchConditions = [
  'visit_start_day' => $visitStartDay,
  'visit_end_day' => $visitEndDay,
  'reception_start_day' => $receptionStartDay,
  'reception_end_day' => $receptionEndDay,
  'customer_name' => $customerName,
  'customer_tel' => $customerTel,
  'reservation_route' => $routeMap[$reservationRoute],
  'reservation_status' => $statusMap[$reservationStatus],
];

$totalItems = countReservationListRows($shopId, $searchConditions);
if ($totalItems === false) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '取得エラー';
  $makeTag['msg'] = '予約一覧を取得できませんでした。時間をおいて再度お試しください。';
  respondClientReservationList($makeTag);
}

$pageSize = 10;
$totalPages = $totalItems === 0 ? 0 : (int)ceil($totalItems / $pageSize);
$pageNumber = $totalPages === 0 ? 1 : min($pageNumber, $totalPages);
$reservationRows = searchReservationListRows($shopId, $searchConditions, $pageNumber);
if ($reservationRows === false) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '取得エラー';
  $makeTag['msg'] = '予約一覧を取得できませんでした。時間をおいて再度お試しください。';
  respondClientReservationList($makeTag);
}

$reservationIds = [];
foreach ($reservationRows as $reservationRow) {
  $reservationIds[] = (int)($reservationRow['reservation_id'] ?? 0);
}
$menuRows = getReservationListMenuRowsByReservationIds($shopId, $reservationIds);
if ($menuRows === false) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '取得エラー';
  $makeTag['msg'] = '予約一覧を取得できませんでした。時間をおいて再度お試しください。';
  respondClientReservationList($makeTag);
}

$listTag = clientReservationListRenderTag($reservationRows, $menuRows);
if ($listTag === null) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '表示エラー';
  $makeTag['msg'] = '予約一覧を表示できませんでした。ページを再読み込みしてください。';
  respondClientReservationList($makeTag);
}

$makeTag['tag'] = $listTag;
$makeTag['status'] = 'success';
$makeTag['total_items'] = $totalItems;
$makeTag['total_pages'] = $totalPages;
$makeTag['page_number'] = $pageNumber;
$makeTag['pager'] = makePagerBoxTag($pageNumber, max(1, $totalPages), $pagerDisplayMax, 'movePage');
respondClientReservationList($makeTag);
