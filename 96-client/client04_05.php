<?php
/*
 * [96-client/client04_05.php]
 * 【加盟店】予約一覧
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_list_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations_list.php';

$pagePrefix = 'cKey04-05_';
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = array();
$_SESSION[$noUpDateKey]['clientKey'] = $_SESSION['client_login']['account_id'];
if ($_SESSION[$noUpDateKey]['clientKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}

$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
if ((is_int($shopId) === true || (is_string($shopId) === true && ctype_digit($shopId) === true)) && (int)$shopId > 0) {
  $shopId = (int)$shopId;
  $shopData = getShops_FindById($shopId);
  $accountData = accounts_FindById(null, $shopId);
} else {
  header("Location: ./logout.php");
  exit;
}

$headerShopName = "";
$headerShopType = "";
if (!isset($shopData) || empty($shopData)) {
  header("Location: ./logout.php");
  exit;
} else {
  $headerShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
  $headerShopType = htmlspecialchars($shopData['shop_type'], ENT_QUOTES, 'UTF-8');
}

/* 予約一覧初期実データ取得 */
$initialSearchConditions = [
  'visit_start_day' => '',
  'visit_end_day' => '',
  'reception_start_day' => '',
  'reception_end_day' => '',
  'customer_name' => '',
  'customer_tel' => '',
  'reservation_route' => null,
  'reservation_status' => null,
];
$reservationListPageNumber = 1;
$reservationListTotalItems = countReservationListRows($shopId, $initialSearchConditions);
$reservationListHasError = ($reservationListTotalItems === false);
$reservationListRows = [];
$reservationListMenuRows = [];

if ($reservationListHasError === false) {
  $reservationListRows = searchReservationListRows($shopId, $initialSearchConditions, $reservationListPageNumber);
  $reservationListHasError = ($reservationListRows === false);
}
if ($reservationListHasError === false) {
  $reservationListIds = [];
  foreach ($reservationListRows as $reservationListRow) {
    $reservationListIds[] = (int)($reservationListRow['reservation_id'] ?? 0);
  }
  $reservationListMenuRows = getReservationListMenuRowsByReservationIds($shopId, $reservationListIds);
  $reservationListHasError = ($reservationListMenuRows === false);
}
if ($reservationListHasError === true) {
  $reservationListTotalItems = 0;
  $reservationListTotalPages = 0;
  $reservationListTag = clientReservationListRenderErrorTag();
} else {
  $reservationListTotalPages = $reservationListTotalItems === 0 ? 0 : (int)ceil($reservationListTotalItems / 10);
  $reservationListTag = clientReservationListRenderTag($reservationListRows, $reservationListMenuRows);
  if ($reservationListTag === null) {
    $reservationListHasError = true;
    $reservationListTotalItems = 0;
    $reservationListTotalPages = 0;
    $reservationListTag = clientReservationListRenderErrorTag();
  }
}
if ($reservationListHasError === true) {
  $reservationListPagerTag = '<div class="box-pager"></div>';
} else {
  $reservationListPagerTag = makePagerBoxTag($reservationListPageNumber, max(1, $reservationListTotalPages), $pagerDisplayMax, 'movePage');
}
$noUpDateKeyHtml = htmlspecialchars($noUpDateKey, ENT_QUOTES, 'UTF-8');

print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(加盟店)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/client04-05.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-05 status-client">
    <section class="container-left-menu menu-color04">
      <div class="title">飲食店予約</div>
      <nav>
        <a href="./client04_01.php" {$client04_01_active}><span>席管理</span></a>
        <a href="./client04_02.php" {$client04_02_active}><span>予約基本設定</span></a>
        <a href="./client04_03.php" {$client04_03_active}><span>食事メニュー管理</span></a>
        <a href="./client04_04.php" {$client04_04_active}><span>予約カレンダー</span></a>
        <a href="./client04_05.php" {$client04_05_active}><span>予約一覧</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color04">
      <div class="block_inner">
        <h2>予約一覧</h2>
        <article class="block-search">
          <h3>検索条件</h3>
          <form name="reservationListSearchForm">
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKeyHtml}">
            <dl>
              <div class="box-date" style="border-right: 1px solid #e8e7e2">
                <dt>来店日</dt>
                <dd>
                  <div class="item-periode">
                    <input type="date" name="searchVisitStartDay" />
                    <i>〜</i>
                    <input type="date" name="searchVisitEndDay" />
                  </div>
                </dd>
              </div>
              <div class="box-date">
                <dt>受付日</dt>
                <dd>
                  <div class="item-periode">
                    <input type="date" name="searchReceptionStartDay" />
                    <i>〜</i>
                    <input type="date" name="searchReceptionEndDay" />
                  </div>
                </dd>
              </div>
              <div class="box-name" style="border-right: 1px solid #e8e7e2">
                <dt>お客様名</dt>
                <dd>
                    <input type="text" name="searchCustomerName" maxlength="101" />
                </dd>
              </div>
              <div class="box-name" style="border-right: 1px solid #e8e7e2">
                <dt>電話番号</dt>
                <dd>
                    <input type="text" name="searchCustomerTel" maxlength="20" />
                </dd>
              </div>
              <div class="box-check">
                <dt>予約経路</dt>
                <dd>
                  <div>
                    <input type="radio" name="searchReservationRoute" id="searchReservationRouteAll" value="all" checked="checked" /><label for="searchReservationRouteAll">すべて</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationRoute" id="searchReservationRouteWeb" value="web" /><label for="searchReservationRouteWeb">Web</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationRoute" id="searchReservationRouteTel" value="tel" /><label for="searchReservationRouteTel">電話</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationRoute" id="searchReservationRouteOther" value="other" /><label for="searchReservationRouteOther">その他</label>
                  </div>
                </dd>
              </div>
              <div class="box-check">
                <dt>予約ステータス</dt>
                <dd>
                  <div>
                    <input type="radio" name="searchReservationStatus" id="searchReservationStatusAll" value="all" checked="checked" /><label for="searchReservationStatusAll">全て</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationStatus" id="searchReservationStatusConfirmed" value="confirmed" /><label for="searchReservationStatusConfirmed">確定</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationStatus" id="searchReservationStatusVisited" value="visited" /><label for="searchReservationStatusVisited">来店済み</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationStatus" id="searchReservationStatusCanceled" value="canceled" /><label for="searchReservationStatusCanceled">キャンセル</label>
                  </div>
                  <div>
                    <input type="radio" name="searchReservationStatus" id="searchReservationStatusNoShow" value="noShow" /><label for="searchReservationStatusNoShow">無断キャンセル</label>
                  </div>
                </dd>
              </div>
            </dl>
            <div class="box-btn">
              <button type="button" class="btn-cancel" id="reservationListResetButton">条件クリア</button>
              <button type="submit" class="btn-search" id="reservationListSearchButton"><span>検索</span></button>
            </div>
          </form>
        </article>
        <article class="block-search-list">
          {$reservationListTag}
          {$reservationListPagerTag}
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/client04_05.js" defer></script>
</body>
</html>

HTML;
