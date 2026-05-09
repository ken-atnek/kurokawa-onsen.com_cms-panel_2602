<?php
/*
 * [96-master/master03_06.php]
 *  - 管理画面 -
 *  集計店舗一覧
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
$searchConditionsSessionKey = 'searchConditions_master03_06';
$pagePrefix = 'mKey03-06_';
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

#==============#
# 表示開始年月日
#--------------#
$nowTime = time();
$startYear = date('Y', time());
$startMonth = date('m', time());
#-------------#
#検索・絞り込み条件保持用セッションチェック
$searchConditions = array();
if (isset($_SESSION[$searchConditionsSessionKey]) === false || !is_array($_SESSION[$searchConditionsSessionKey])) {
  $_SESSION[$searchConditionsSessionKey] = array(
    'searchYear' => (int)$startYear,
    'searchMonth' => (int)$startMonth,
  );
  #初期値セット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
} else {
  #既存セッションがあれば変数にセット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
}
#必須キーが欠けている場合は初期化（運用上は常に揃う前提）
$requiredKeys = ['searchYear', 'searchMonth'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'searchYear' => isset($searchConditions['searchYear']) ? (int)$searchConditions['searchYear'] : (int)$startYear,
      'searchMonth' => isset($searchConditions['searchMonth']) ? (int)$searchConditions['searchMonth'] : (int)$startMonth,
    );
    break;
  }
}
#GET指定の年/月を読み取り、許容範囲外は現在年月へフォールバックする
$getSearchYear = isset($_GET['searchYear']) ? (string)$_GET['searchYear'] : '';
$getSearchMonth = isset($_GET['searchMonth']) ? (string)$_GET['searchMonth'] : '';
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
$_SESSION[$searchConditionsSessionKey] = $searchConditions;

#================#
# 店舗集計一覧取得
#----------------#
#検索条件があれば適用して店舗集計一覧を取得
if (is_array($searchConditions) && count($searchConditions) > 0) {
  $targetYear = isset($searchConditions['searchYear']) ? (int)$searchConditions['searchYear'] : (int)$startYear;
  $targetMonth = isset($searchConditions['searchMonth']) ? (int)$searchConditions['searchMonth'] : (int)$startMonth;
  $shopsOrderList = getMasterShopsOrderSummaryByMonth($targetYear, $targetMonth);
} else {
  $shopsOrderList = array();
}

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
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/master03-06.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-03-06 status-master">
    <section class="container-left-menu menu-color03">
      <div class="title">EC販売管理</div>
      <nav>
        <a href="./master03_01.php" {$master03_01_active}><span>受注一覧</span></a>
        <a href="./master03_06.php" {$master03_06_active}><span>集計</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color03">
      <div class="block_inner">
        <h2>集計管理</h2>
        <form name="searchForm" class="inner_search-list">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
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
            <button type="button" class="btn_print">
              <span>印刷用HTML</span>
            </button>
          </div>
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
                <a href="./master03_01_01.php?shopId={$shopId}&searchYear={$searchConditions['searchYear']}&searchMonth={$searchConditions['searchMonth']}"></a>
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
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/master03_06.js" defer></script>
</body>

</html>

HTML;
