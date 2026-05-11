<?php
/*
 * [96-master/master03_06_01.php]
 *  - 管理画面 -
 *  店舗集計詳細
 *
 * [初版]
 *  2026.5.9
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#受注情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';

#================#
# SESSIONチェック
#----------------#
#セッションキー
$searchConditionsSessionKey = 'searchConditions_master03_06_01';
$pagePrefix = 'mKey03-06-01_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  #他ページの検索条件はページ移動時に破棄（このページの条件のみ保持）
  $isSearchConditionsKey = ($key === $searchConditionsSessionKey);
  if ($key !== 'sKey' && $key !== 'master_login' && $key !== $noUpDateKey && $isSearchConditionsKey === false) {
    unset($_SESSION[$key]);
  }
}
#セッション本体の初期化
$_SESSION[$noUpDateKey] = array();
#アカウントキー
$_SESSION[$noUpDateKey]['masterKey'] = $_SESSION['master_login']['account_id'];
#データ取得エラー
if ($_SESSION[$noUpDateKey]['masterKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}

#=============#
# POSTチェック
#-------------#
#店舗ID
$shopId = isset($_GET['shopId']) ? (string)$_GET['shopId'] : '';
#店舗IDがあれば店舗情報取得
if (preg_match('/^[1-9][0-9]*$/', $shopId) === 1) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  if ($shopData === null) {
    #店舗情報が存在しない場合は一覧にリダイレクト
    header("Location: ./master03_06.php");
    exit;
  }
} else {
  #店舗IDがない場合は一覧にリダイレクト
  header("Location: ./master03_06.php");
  exit;
}

#==============#
# 表示開始年月日
#--------------#
$startYear = date('Y', time());
$startMonth = date('m', time());
#-------------#
#検索・絞り込み条件保持用セッションチェック
$searchConditions = array();
if (isset($_SESSION[$searchConditionsSessionKey]) === false || !is_array($_SESSION[$searchConditionsSessionKey])) {
  $_SESSION[$searchConditionsSessionKey] = array(
    'searchYear' => $startYear,
    'searchMonth' => $startMonth,
  );
  #初期値セット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
} else {
  #既存セッションがあれば変数にセット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
}
#GET指定がある場合はセッション値より優先
if (isset($_GET['searchYear'])) {
  $searchConditions['searchYear'] = (string)$_GET['searchYear'];
}
if (isset($_GET['searchMonth'])) {
  $searchConditions['searchMonth'] = (string)$_GET['searchMonth'];
}
#必須キーが欠けている場合は初期化（運用上は常に揃う前提）
$requiredKeys = ['searchYear', 'searchMonth'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'searchYear' => isset($searchConditions['searchYear']) ? (string)$searchConditions['searchYear'] : '',
      'searchMonth' => isset($searchConditions['searchMonth']) ? (string)$searchConditions['searchMonth'] : '',
    );
    break;
  }
}
#検索年月バリデーション
$targetYear = isset($searchConditions['searchYear']) ? (string)$searchConditions['searchYear'] : '';
$targetMonth = isset($searchConditions['searchMonth']) ? (string)$searchConditions['searchMonth'] : '';
if (preg_match('/^[0-9]{4}$/', $targetYear) !== 1) {
  $targetYear = (string)$startYear;
}
$targetYearInt = (int)$targetYear;
if ($targetYearInt < (int)$startAggregateYear || $targetYearInt > (int)$startYear) {
  $targetYear = (string)$startYear;
}
if (preg_match('/^(?:[1-9]|1[0-2])$/', $targetMonth) !== 1) {
  $targetMonth = (string)((int)$startMonth);
}
$searchConditions['searchYear'] = $targetYear;
$searchConditions['searchMonth'] = (string)((int)$targetMonth);
$_SESSION[$searchConditionsSessionKey] = $searchConditions;

#==================#
# 店舗ごとの集計取得
#------------------#
#検索条件があれば適用して店舗集計一覧を取得
if (is_array($searchConditions) && count($searchConditions) > 0) {
  $targetYear = isset($searchConditions['searchYear']) ? (int)$searchConditions['searchYear'] : (int)$startYear;
  $targetMonth = isset($searchConditions['searchMonth']) ? (int)$searchConditions['searchMonth'] : (int)$startMonth;
  $shopsOrderData = getShopMonthlyOrderSummaryByShopId($shopId, $targetYear, $targetMonth);
} else {
  $shopsOrderData = array();
}

#=============#
# 注文一覧取得
#-------------#
$pageNo = isset($_GET['pageNo']) && preg_match('/^[1-9][0-9]*$/', (string)$_GET['pageNo']) ? (int)$_GET['pageNo'] : 1;
$displayNumber = isset($_GET['displayNumber']) ? (int)$_GET['displayNumber'] : (int)$initialDisplayNumber;
if (in_array($displayNumber, (array)$displayNumberList, true) === false) {
  $displayNumber = (int)$initialDisplayNumber;
}
#月内の注文総件数
$shopMonthlyOrderCount = countShopMonthlyOrdersByShopId((int)$shopId, (int)$targetYear, (int)$targetMonth);
$totalPages = (int)ceil((int)$shopMonthlyOrderCount / (int)$displayNumber);
if ($totalPages < 1) {
  $totalPages = 1;
}
if ($pageNo > $totalPages) {
  $pageNo = $totalPages;
}
$offset = ($pageNo - 1) * $displayNumber;
#月内の注文一覧
$shopMonthlyOrders = searchShopMonthlyOrdersByShopId((int)$shopId, (int)$targetYear, (int)$targetMonth, (int)$displayNumber, (int)$offset);
#注文ID配列を作成
$shopOrderIds = array();
if (is_array($shopMonthlyOrders) && count($shopMonthlyOrders) > 0) {
  foreach ($shopMonthlyOrders as $shopMonthlyOrder) {
    $orderId = isset($shopMonthlyOrder['order_id']) ? (int)$shopMonthlyOrder['order_id'] : 0;
    if ($orderId > 0) {
      $shopOrderIds[] = $orderId;
    }
  }
}
#注文商品の取得と注文IDごとの配列化
$shopOrderItemsByOrderId = array();
if (count($shopOrderIds) > 0) {
  $shopOrderItemsByOrderId = getShopOrderItemsByOrderIds($shopOrderIds);
}

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
#-------------#
#XSS対策：エスケープ処理
$escShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');

#===============#
# 表示用helper
#---------------#
/**
 * 文字列をHTMLエスケープして返す
 *  値が空の場合は '-' を返す
 */
function hOrderDetail($value)
{
  $value = trim((string)$value);
  return htmlspecialchars(($value === '') ? '-' : $value, ENT_QUOTES, 'UTF-8');
}
/**
 * 金額を数値フォーマットしてHTMLエスケープして返す
 */
function formatOrderDetailMoney($value)
{
  return htmlspecialchars(number_format((int)$value), ENT_QUOTES, 'UTF-8');
}
/**
 * 日付フォーマット
 */
function formatOrderDate($value)
{
  $value = trim((string)$value);
  if ($value === '') {
    return '-';
  }
  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
  #return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars(date('H:i', $timestamp), ENT_QUOTES, 'UTF-8') . '</i>';
  return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8');
}
$rebateRateLabelHtml = htmlspecialchars((string)$rebateRateLabel, ENT_QUOTES, 'UTF-8');
$backUrl = './master03_06.php?searchYear=' . rawurlencode((string)$targetYear) . '&searchMonth=' . rawurlencode((string)$targetMonth);
$backUrlHtml = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');
#月合計の表示用データ
$summaryOrderCount = (int)($shopsOrderData['order_count'] ?? 0);
$summarySalesTotal = (int)($shopsOrderData['sales_total'] ?? 0);
$summaryProductTotal = (int)($shopsOrderData['product_total'] ?? 0);
$summaryRebateAmount = (int)round($summaryProductTotal * (float)$rebateRate);
$summaryOrderCountHtml = htmlspecialchars(number_format($summaryOrderCount), ENT_QUOTES, 'UTF-8');
$summarySalesTotalHtml = formatOrderDetailMoney($summarySalesTotal);
$summaryProductTotalHtml = formatOrderDetailMoney($summaryProductTotal);
$summaryRebateAmountHtml = formatOrderDetailMoney($summaryRebateAmount);
$displayNumberHtml = htmlspecialchars((string)$displayNumber, ENT_QUOTES, 'UTF-8');

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/master03-06.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-03-06-01 status-master">
    <section class="container-left-menu menu-color03">
      <div class="title">EC販売管理</div>
      <nav>
        <a href="./master03_01.php" {$master03_01_active}><span>受注一覧</span></a>
        <a href="./master03_06.php" {$master03_06_01_active}><span>集計</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color03">
      <div class="block_inner">
        <h2><span>{$escShopName}</span>集計管理</h2>
        <form name="searchForm" class="inner-summary-list">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <input type="hidden" name="shopId" value="{$shopId}">
          <input type="hidden" name="displayNumber" value="{$displayNumberHtml}">
          <h3><span id="aggregateTargetMonthLabel">{$searchConditions['searchYear']}年{$searchConditions['searchMonth']}月</span>集計一覧</h3>
          <div class="inner-head">

HTML;
#検索対象年が選択されている場合
$selectedSearchYear = (string)$searchConditions['searchYear'];
$selectedSearchYearName = '選択してください';
for ($y = (int)$startYear; $y >= (int)$startAggregateYear; $y--) {
  if ((string)$y === $selectedSearchYear) {
    $selectedSearchYearName = preg_replace('/\r\n|\r|\n/', '', (string)$y);
    break;
  }
}
$selectedSearchYearHtml = htmlspecialchars($selectedSearchYear, ENT_QUOTES, 'UTF-8');
$selectedSearchYearNameHtml = htmlspecialchars($selectedSearchYearName, ENT_QUOTES, 'UTF-8');
$searchYearSelectClass = ($selectedSearchYear !== '') ? ' is-selected' : '';
print <<<HTML
            <div class="select-year {$searchYearSelectClass}" data-selectbox>
              <button type="button" class="selectbox__head" aria-expanded="false">
                <input type="hidden" name="searchYear" value="{$selectedSearchYearHtml}" data-selectbox-hidden>
                <span class="selectbox__value" data-selectbox-value>{$selectedSearchYearNameHtml}年</span>
              </button>
              <div class="list-wrapper">
                <ul class="selectbox__panel">

HTML;
for ($y = (int)$startYear; $y >= (int)$startAggregateYear; $y--) {
  $yearNameHtml = htmlspecialchars($y, ENT_QUOTES, 'UTF-8');
  #checked判定
  $checked = ((string)$y === $selectedSearchYear) ? ' checked' : '';
  print <<<HTML
                  <li>
                    <input type="radio" name="searchYear" value="{$yearNameHtml}" id="number{$yearNameHtml}" {$checked}>
                    <label for="number{$yearNameHtml}">{$yearNameHtml}年</label>
                  </li>

HTML;
}
print <<<HTML
                </ul>
              </div>
            </div>

HTML;
#検索対象月が選択されている場合
$selectedSearchMonth = (int)$searchConditions['searchMonth'];
$selectedSearchMonthName = '選択してください';
for ($m = 1; $m <= 12; $m++) {
  if ((int)$m === $selectedSearchMonth) {
    $selectedSearchMonthName = preg_replace('/\r\n|\r|\n/', '', (int)$m);
    break;
  }
}
$selectedSearchMonthHtml = htmlspecialchars($selectedSearchMonth, ENT_QUOTES, 'UTF-8');
$selectedSearchMonthNameHtml = htmlspecialchars($selectedSearchMonthName, ENT_QUOTES, 'UTF-8');
$searchMonthSelectClass = ($selectedSearchMonth !== '') ? ' is-selected' : '';
print <<<HTML
            <div class="select-month {$searchMonthSelectClass}" data-selectbox>
              <button type="button" class="selectbox__head" aria-expanded="false">
                <input type="hidden" name="searchMonth" value="{$selectedSearchMonthHtml}" data-selectbox-hidden>
                <span class="selectbox__value" data-selectbox-value>{$selectedSearchMonthNameHtml}月</span>
              </button>
              <div class="list-wrapper">
                <ul class="selectbox__panel">

HTML;
for ($m = 1; $m <= 12; $m++) {
  $monthNameHtml = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
  #checked判定
  $checked = ($m === $selectedSearchMonth) ? ' checked' : '';
  print <<<HTML
                  <li>
                    <input type="radio" name="searchMonth" value="{$monthNameHtml}" id="month{$monthNameHtml}" {$checked}>
                    <label for="month{$monthNameHtml}">{$monthNameHtml}月</label>
                  </li>

HTML;
}
print <<<HTML
                </ul>
              </div>
            </div>
            <button type="button" class="btn_submit" onclick="searchConditions('search')">表示</button>
            <button type="button" class="btn_print" onclick="openPrintHtml()">
              <span>印刷用HTML</span>
            </button>
          </div>
          <ul id="setMasterShopsOrder">
            <li>
              <div>注文日・番号</div>
              <div>お客様情報</div>
              <div>商品</div>
              <div>個数</div>
              <div>小計</div>
              <div>進捗状況/変更日</div>
            </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopMonthlyOrders)) {
  $zIndexNo = count($shopMonthlyOrders);
  foreach ($shopMonthlyOrders as $orderIndex => $orderData) {
    $orderId = (int)($orderData['order_id'] ?? 0);
    $orderDate = formatOrderDate($orderData['ordered_at'] ?? '');
    $orderNo = hOrderDetail($orderData['order_no'] ?? '');
    $orderName = hOrderDetail($orderData['orderer_name'] ?? '');
    $orderPostalCode = hOrderDetail($orderData['orderer_postal_code'] ?? '');
    $orderAddress01 = hOrderDetail(($orderData['orderer_pref_name'] ?? '') . ($orderData['orderer_addr01'] ?? ''));
    $orderAddress02 = hOrderDetail($orderData['orderer_addr02'] ?? '');
    $orderTel = hOrderDetail($orderData['orderer_tel'] ?? '');
    $orderEmail = hOrderDetail($orderData['orderer_email'] ?? '');
    $deliveryFee = formatOrderDetailMoney($orderData['delivery_fee_total'] ?? 0);
    $returnedDeliveryStyle = ((int)($orderData['eccube_order_status_id'] ?? 0) === 9) ? ' style="text-decoration: line-through; color: #979797;"' : '';
    $paymentTotal = formatOrderDetailMoney($orderData['payment_total'] ?? 0);
    $goodsRowsHtml = '';
    $orderItems = isset($shopOrderItemsByOrderId[$orderId]) ? $shopOrderItemsByOrderId[$orderId] : array();
    foreach ($orderItems as $orderItem) {
      $goodsName = hOrderDetail($orderItem['product_name'] ?? '');
      $goodsQty = hOrderDetail($orderItem['quantity'] ?? 0);
      $itemTaxRate = isset($orderItem['tax_rate']) ? (int)$orderItem['tax_rate'] : 10;
      if ($itemTaxRate <= 0) {
        $itemTaxRate = 10;
      }
      $itemSubtotalIncludingTax = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($itemTaxRate / 100)));
      $goodsSubtotal = formatOrderDetailMoney($itemSubtotalIncludingTax);
      $currentItemStatus = trim((string)($orderItem['current_item_status'] ?? ''));
      $returnedItemStyle = ($currentItemStatus === 'returned_full') ? ' style="text-decoration: line-through; color: #979797;"' : '';
      $goodsRowsHtml .= <<<HTML
                  <li>
                    <div class="goods-name" {$returnedItemStyle}>{$goodsName}</div>
                    <div class="goods-pieces" {$returnedItemStyle}>{$goodsQty}</div>
                    <div class="goods-price" {$returnedItemStyle}><span>{$goodsSubtotal}</span></div>
                  </li>

HTML;
    }
    if ($goodsRowsHtml === '') {
      $goodsRowsHtml = <<<HTML
                  <li>
                    <div class="goods-name">商品情報なし</div>
                    <div class="goods-pieces">0</div>
                    <div class="goods-price"><span>0</span></div>
                  </li>

HTML;
    }
    print <<<HTML
            <li>
              <div class="item-date">
                <span>{$orderDate}</span><i>{$orderNo}</i>
              </div>
              <div class="wrap-customer-info">
                <div class="inner-customer">
                  <div class="name">{$orderName}</div>
                  <div class="address">
                    <span>{$orderAddress01}</span>
                    <span>{$orderAddress02}</span>
                  </div>
                  <div class="tel">
                    <span>{$orderTel}</span>
                  </div>
                  <div class="mail">
                    <a href="mailto:{$orderEmail}">{$orderEmail}</a>
                  </div>
                </div>
              </div>
              <div class="wrap-goods">
                <ul>
                  {$goodsRowsHtml}
                </ul>
                <div class="item-shipping">
                  <div class="title">送料</div>
                  <div class="shipping-price" {$returnedDeliveryStyle}><span>{$deliveryFee}</span></div>
                </div>
                <div class="item-price">
                  <span>{$paymentTotal}</span>
                </div>
              </div>

HTML;
    #Liのz-index設定
    $zIndexStyle = 'style="z-index:' . ($zIndexNo - $orderIndex) . ';"';
    $targetOrderStatusId = (string)$orderData['eccube_order_status_id'];
    $targetOrderStatusName = '';
    if (!empty($orderStatusList)) {
      foreach ($orderStatusList as $status) {
        if ((string)$status['id'] === $targetOrderStatusId) {
          $targetOrderStatusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
          break;
        }
      }
    }
    if ($targetOrderStatusName === '') {
      $targetOrderStatusName = (string)($orderData['eccube_order_status_name'] ?? '-');
    }
    $targetOrderStatusIdHtml = htmlspecialchars($targetOrderStatusId, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusNameHtml = htmlspecialchars($targetOrderStatusName, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusClass = ($targetOrderStatusId !== '') ? ' is-selected' : '';
    $statusDate = formatOrderDate($orderData['status_changed_at'] ?? '');
    print <<<HTML
              <div class="wrap-status">
                <div class="apply-status {$targetOrderStatusClass}" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="List{$orderIndex}Status" value="{$targetOrderStatusIdHtml}" data-selectbox-hidden />
                    <span class="selectbox__value" data-selectbox-value>{$targetOrderStatusNameHtml}</span>
                    <i></i>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
    if (!empty($orderStatusList)) {
      foreach ($orderStatusList as $status) {
        $statusId = (string)$status['id'];
        $statusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
        #checked判定
        $checked = ($statusId === $targetOrderStatusId) ? ' checked' : '';
        #ステータスごとにクラス付与
        $statusClass = '';
        switch ($statusId) {
          case '1':
            $statusClass = 'status-registered';
            break;
          case '4':
            $statusClass = 'status-preparing';
            break;
          case '5':
            $statusClass = 'status-shipped';
            break;
          case '9':
            $statusClass = 'status-completed';
            break;
        }
        print <<<HTML
                      <!-- NOTE  インラインでz-indexを付与 -->
                      <li {$zIndexStyle}>
                        <input type="radio" name="orderStatus{$orderIndex}" value="{$statusId}" id="list{$orderIndex}-{$statusId}" data-order-status-radio {$checked}>
                        <label for="list{$orderIndex}-{$statusId}" class="{$statusClass}">{$statusName}</label>
                      </li>

HTML;
      }
    }
    print <<<HTML
                    </ul>
                  </div>
                </div>
                <span class="date">{$statusDate}</span>
              </div>
            </li>

HTML;
  }
} else {
  print <<<HTML
            <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">
              <div>該当するデータが存在しません。</div>
            </li>

HTML;
}
print <<<HTML
          </ul>

HTML;
#ページャー表示
print makePagerBoxTag((int)$pageNo, (int)$totalPages, $pagerDisplayMax, 'movePage');
print <<<HTML
        </form>
        <article id="setShopMonthlySummary" class="block-total-price">
          <h3>合計</h3>
          <ul>
            <li>
              <div></div>
              <div>注文件数</div>
              <div>売上合計</div>
              <div>商品合計</div>
              <div>協会R({$rebateRateLabelHtml})</div>
            </li>
            <li>
              <div class="item-name">
                <span><em>合計</em></span>
              </div>
              <div class="item-count">
                <span><em>{$summaryOrderCountHtml}</em></span>
              </div>
              <div class="item-price">
                <span><em>{$summarySalesTotalHtml}</em></span>
              </div>
              <div class="item-price">
                <span><em>{$summaryProductTotalHtml}</em></span>
              </div>
              <div class="item-rebate">
                <span><em>{$summaryRebateAmountHtml}</em></span>
              </div>
            </li>
          </ul>
          <p>※協会Rは商品合計をもとに算出しており、個別の合計と誤差が生じる場合があります。</p>
        </article>
        <a href="{$backUrlHtml}" class="link_page-back_bottom">戻る</a>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/master03_06_01.js" defer></script>
</body>

</html>

HTML;
