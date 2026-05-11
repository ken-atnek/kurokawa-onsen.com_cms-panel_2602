<?php
/*
 * [shop_order_detail.php]
 *  - 集計詳細ページ 印刷用HTML -
 *  集計詳細ページ用HTMLを返すAPI
 *
 */

require_once '../../../cms_config/common/define.php';
require_once '../../../cms_config/common/set_function.php';
require_once '../../../cms_config/common/set_contents.php';
require_once '../../../cms_config/database/set_db.php';
require_once '../../../cms_config/database/db_accounts.php';
require_once '../../../cms_config/database/db_shops.php';
require_once '../../../cms_config/database/db_orders.php';

#=============#
# POSTチェック
#-------------#
#店舗ID
$shopId = isset($_REQUEST['shopId']) ? (string)$_REQUEST['shopId'] : '';
$printMode = isset($_REQUEST['printMode']) ? (string)$_REQUEST['printMode'] : '';
#master/client 権限分岐
if ($printMode === 'master') {
  require_once '../../../cms_config/master/start_processing.php';
  if (preg_match('/^[1-9][0-9]*$/', $shopId) !== 1) {
    http_response_code(403);
    exit;
  }
  $shopData = getShops_FindById($shopId);
  if ($shopData === null) {
    http_response_code(403);
    exit;
  }
} elseif ($printMode === 'client') {
  require_once '../../../cms_config/client/start_processing.php';
  $sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
  if ($sessionShopId === null || is_numeric($sessionShopId) === false || (int)$sessionShopId <= 0) {
    http_response_code(403);
    exit;
  }
  $sessionShopId = (int)$sessionShopId;
  if ($shopId !== '' && (preg_match('/^[1-9][0-9]*$/', $shopId) !== 1 || (int)$shopId !== $sessionShopId)) {
    http_response_code(403);
    exit;
  }
  #店舗IDがあれば店舗情報取得
  $shopId = (string)$sessionShopId;
  $shopData = getShops_FindById($shopId);
  if ($shopData === null) {
    http_response_code(403);
    exit;
  }
} else {
  http_response_code(403);
  exit;
}

#==============#
# 表示開始年月日
#--------------#
$startYear = date('Y', time());
$startMonth = date('m', time());
#検索・絞り込み条件保持用変数
$searchConditions = array(
  'searchYear' => (int)$startYear,
  'searchMonth' => (int)$startMonth,
);
#-------------#
#GET指定の年/月を読み取り、許容範囲外は現在年月へフォールバックする
$getSearchYear = isset($_REQUEST['searchYear']) ? (string)$_REQUEST['searchYear'] : '';
$getSearchMonth = isset($_REQUEST['searchMonth']) ? (string)$_REQUEST['searchMonth'] : '';
if ($getSearchYear !== '' || $getSearchMonth !== '') {
  if (preg_match('/^[0-9]{4}$/', $getSearchYear) === 1) {
    $getYear = (int)$getSearchYear;
    if ($getYear >= (int)$startAggregateYear && $getYear <= (int)$startYear) {
      $searchConditions['searchYear'] = $getYear;
    } else {
      $searchConditions['searchYear'] = (int)$startYear;
    }
  } else {
    $searchConditions['searchYear'] = (int)$startYear;
  }
  if (preg_match('/^(?:[1-9]|1[0-2])$/', $getSearchMonth) === 1) {
    $searchConditions['searchMonth'] = (int)$getSearchMonth;
  } else {
    $searchConditions['searchMonth'] = (int)$startMonth;
  }
}

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
$pageNo = 1;
$displayNumber = 1;
#月内の注文総件数
$shopMonthlyOrderCount = countShopMonthlyOrdersByShopId((int)$shopId, (int)$targetYear, (int)$targetMonth);
$displayNumber = max(1, (int)$shopMonthlyOrderCount);
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
  <link rel="icon" type="image/svg+xml" href="../../../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../../../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../../../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../../../assets/css/print03-06.css">
</head>

<body>
  <main class="inner-03-06-01">
    <div class="print-contents">
      <h2><span>{$escShopName}</span>集計管理</h2>
      <article class="inner-summary-list">
        <h3><span id="aggregateTargetMonthLabel">{$searchConditions['searchYear']}年{$searchConditions['searchMonth']}月</span>集計一覧</h3>
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
      </article>
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
    </div>
  </main>
  <script src="../../../assets/js/common.js" defer></script>
</body>

</html>

HTML;
