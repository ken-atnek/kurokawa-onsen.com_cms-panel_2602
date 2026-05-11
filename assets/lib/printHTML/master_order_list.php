<?php
/*
 * [master_order_list.php]
 *  - 集計一覧ページ 印刷用HTML -
 *  集計一覧ページ用HTMLを返すAPI
 *
 */

require_once '../../../cms_config/common/define.php';
require_once '../../../cms_config/common/set_function.php';
require_once '../../../cms_config/common/set_contents.php';
require_once '../../../cms_config/database/set_db.php';
require_once '../../../cms_config/master/start_processing.php';
require_once '../../../cms_config/database/db_accounts.php';
require_once '../../../cms_config/database/db_shops.php';
require_once '../../../cms_config/database/db_orders.php';

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
    }
  }
  if (preg_match('/^(?:[1-9]|1[0-2])$/', $getSearchMonth) === 1) {
    $searchConditions['searchMonth'] = (int)$getSearchMonth;
  }
}

#================#
# 店舗集計一覧取得
#----------------#
#検索条件があれば適用して店舗集計一覧を取得
$targetYear = isset($searchConditions['searchYear']) ? (int)$searchConditions['searchYear'] : (int)$startYear;
$targetMonth = isset($searchConditions['searchMonth']) ? (int)$searchConditions['searchMonth'] : (int)$startMonth;
$shopsOrderList = getMasterShopsOrderSummaryByMonth($targetYear, $targetMonth);

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
$rebateRateLabelHtml = htmlspecialchars((string)$rebateRateLabel, ENT_QUOTES, 'UTF-8');

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

  <main class="inner-03-06">
    <div class="print-contents">
      <h2>集計管理</h2>
      <article class="inner_search-list">
        <h3><span id="aggregateTargetMonthLabel">{$searchConditions['searchYear']}年{$searchConditions['searchMonth']}月</span>集計一覧</h3>
        <ul id="setMasterShopsOrder">
          <li>
            <div>ID</div>
            <div>施設名</div>
            <div>注文件数</div>
            <div>売上合計</div>
            <div>商品合計</div>
            <div>協会R({$rebateRateLabelHtml})</div>
          </li>

HTML;
#総注文件数
$sumOrderItems = 0;
#総売上合計
$sumTotalSales = 0;
#総商品売上合計
$sumItemSales = 0;
#協会R合計
$sumSalesRebate = 0;
if (!empty($shopsOrderList)) {
  foreach ($shopsOrderList as $shopOrder) {
    #店舗ID
    $shopId = htmlspecialchars($shopOrder['shop_id'], ENT_QUOTES, 'UTF-8');
    #店舗名
    $statusNameHtml = hOrderDetail($shopOrder['shop_name'] ?? '');
    #注文件数
    $orderItems = (int)($shopOrder['order_count'] ?? 0);
    $orderItemsHtml = htmlspecialchars(number_format($orderItems), ENT_QUOTES, 'UTF-8');
    #売上合計
    $totalSales = (int)($shopOrder['sales_total'] ?? 0);
    $totalSalesHtml = formatOrderDetailMoney($totalSales);
    #商品合計
    $itemSales = (int)($shopOrder['product_total'] ?? 0);
    $itemSalesHtml = formatOrderDetailMoney($itemSales);
    #協会R
    $salesRebate = (int)round($itemSales * $rebateRate);
    $salesRebateHtml = formatOrderDetailMoney($salesRebate);
    #--------------#
    #合計加算
    $sumOrderItems = $sumOrderItems + $orderItems;
    $sumTotalSales = $sumTotalSales + $totalSales;
    $sumItemSales = $sumItemSales + $itemSales;
    $sumSalesRebate = $sumSalesRebate + $salesRebate;
    print <<<HTML
          <li>
            <div class="item-id">
              <span>{$shopId}</span>
            </div>
            <div class="item-name">
              <span>{$statusNameHtml}</span>
            </div>
            <div class="item-count">
              <span>{$orderItemsHtml}</span>
            </div>
            <div class="item-price">
              <span>{$totalSalesHtml}</span>
            </div>
            <div class="item-price">
              <span>{$itemSalesHtml}</span>
            </div>
            <div class="item-price">
              <span>{$salesRebateHtml}</span>
            </div>
          </li>

HTML;
  }
} else {
  print <<<HTML
          <li>
            <div class="item-id">
              <span></span>
            </div>
            <div class="item-name">
              <span>対象店舗なし</span>
            </div>
            <div class="item-count">
              <span>0</span>
            </div>
            <div class="item-price">
              <span>0</span>
            </div>
            <div class="item-price">
              <span>0</span>
            </div>
            <div class="item-price">
              <span>0</span>
            </div>
          </li>

HTML;
}
#表示用加工
$sumOrderItemsHtml = htmlspecialchars(number_format($sumOrderItems), ENT_QUOTES, 'UTF-8');
$sumTotalSalesHtml = formatOrderDetailMoney($sumTotalSales);
$sumItemSalesHtml = formatOrderDetailMoney($sumItemSales);
$sumSalesRebateHtml = formatOrderDetailMoney($sumSalesRebate);
print <<<HTML
          <li>
            <div class="item-id"></div>
            <div class="item-name">
              <span><em>合計</em></span>
            </div>
            <div class="item-count">
              <span><em>{$sumOrderItemsHtml}</em></span>
            </div>
            <div class="item-price">
              <span><em>{$sumTotalSalesHtml}</em></span>
            </div>
            <div class="item-price">
              <span><em>{$sumItemSalesHtml}</em></span>
            </div>
            <div class="item-rebate">
              <span><em>{$sumSalesRebateHtml}</em></span>
            </div>
          </li>
        </ul>
      </article>
    </div>
  </main>
</body>

</html>

HTML;
