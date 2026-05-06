<?php
/*
 * [96-client/client03_01.php]
 *  - 加盟店管理画面 -
 *  受注一覧
 *
 * [初版]
 *  2026.5.4
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#店舗情報（EC関連）
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';
#受注情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';

#================#
# SESSIONチェック
#----------------#
#セッションキー
$searchConditionsSessionKey = 'searchConditions_client03_01';
$pagePrefix = 'cKey03-01_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== $noUpDateKey && $key !== $searchConditionsSessionKey) {
    unset($_SESSION[$key]);
  }
}
#セッション本体の初期化
$_SESSION[$noUpDateKey] = array();
#アカウントキー
$_SESSION[$noUpDateKey]['clientKey'] = $_SESSION['client_login']['account_id'];
#データ取得エラー
if ($_SESSION[$noUpDateKey]['clientKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}

#=============#
# POSTチェック
#-------------#
#店舗ID（編集／削除時のみ）
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
} else {
  #不正アクセス：ログインページへリダイレクト
  header("Location: ./logout.php");
  exit;
}

#=======#
# 店舗名
#-------#
$headerShopName = "";
if (!isset($shopData) || empty($shopData)) {
  #店舗データが無い場合は不正アクセス：ログインページへリダイレクト
  header("Location: ./logout.php");
  exit;
} else {
  $headerShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
}

#-------------#
#検索・絞り込み条件保持用セッションチェック
$searchConditions = array();
if (isset($_SESSION[$searchConditionsSessionKey]) === false || !is_array($_SESSION[$searchConditionsSessionKey])) {
  #セッション無し：初期化
  $_SESSION[$searchConditionsSessionKey] = array(
    'searchStatus' => '',
    'orderDateFrom' => '',
    'orderDateTo' => '',
    'orderNo' => '',
    'ordererName' => '',
    'ordererEmail' => '',
    'ordererTel' => '',
    'displayNumber' => $initialDisplayNumber,
    'pageNumber' => 1
  );
  #初期値セット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
} else {
  #既存セッションがあれば変数にセット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
}
#必須キーが欠けている場合は初期化（運用上は常に揃う前提）
$requiredKeys = ['searchStatus', 'orderDateFrom', 'orderDateTo', 'orderNo', 'ordererName', 'ordererEmail', 'ordererTel', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'searchStatus' => isset($searchConditions['searchStatus']) ? (string)$searchConditions['searchStatus'] : '',
      'orderDateFrom' => isset($searchConditions['orderDateFrom']) ? (string)$searchConditions['orderDateFrom'] : '',
      'orderDateTo' => isset($searchConditions['orderDateTo']) ? (string)$searchConditions['orderDateTo'] : '',
      'orderNo' => isset($searchConditions['orderNo']) ? (string)$searchConditions['orderNo'] : '',
      'ordererName' => isset($searchConditions['ordererName']) ? (string)$searchConditions['ordererName'] : '',
      'ordererEmail' => isset($searchConditions['ordererEmail']) ? (string)$searchConditions['ordererEmail'] : '',
      'ordererTel' => isset($searchConditions['ordererTel']) ? (string)$searchConditions['ordererTel'] : '',
      'displayNumber' => isset($searchConditions['displayNumber']) ? (int)$searchConditions['displayNumber'] : $initialDisplayNumber,
      'pageNumber' => isset($searchConditions['pageNumber']) ? (int)$searchConditions['pageNumber'] : 1
    );
    break;
  }
}
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
$allowedListStatusIds = ['1', '4', '5'];
if ((string)($searchConditions['searchStatus'] ?? '') !== '' && in_array((string)$searchConditions['searchStatus'], $allowedListStatusIds, true) === false) {
  $searchConditions['searchStatus'] = '';
  $_SESSION[$searchConditionsSessionKey] = $searchConditions;
}
#-------------#
#表示件数ページ・表示件数設定
$displayNumber = isset($searchConditions['displayNumber']) ? intval($searchConditions['displayNumber']) : $initialDisplayNumber;
$pageNumber = isset($searchConditions['pageNumber']) ? intval($searchConditions['pageNumber']) : 1;
if ($displayNumber < 1) {
  $displayNumber = $initialDisplayNumber;
}
#受注数取得：検索結果
$totalItems = countShopOrderList($searchConditions, $shopId);
$totalItemsHtml = htmlspecialchars((string)$totalItems, ENT_QUOTES, 'UTF-8');
#総件数（ページャー用）
$totalPages = (int)ceil($totalItems / $displayNumber);
if ($totalPages < 1) {
  $totalPages = 1;
}
if ($pageNumber < 1) {
  $pageNumber = 1;
} elseif ($pageNumber > $totalPages) {
  $pageNumber = $totalPages;
}
$searchConditions['pageNumber'] = $pageNumber;
$_SESSION[$searchConditionsSessionKey] = $searchConditions;

#=========#
# 受注一覧
#---------#
$orderList = searchShopOrderList($searchConditions, $pageNumber, $displayNumber, $shopId);
$orderIds = array_map(function ($order) {
  return (int)($order['order_id'] ?? 0);
}, $orderList);
$orderItemsByOrderId = getShopOrderItemsByOrderIds($orderIds);
$orderNoHtml = htmlspecialchars($searchConditions['orderNo'], ENT_QUOTES, 'UTF-8');
$ordererNameHtml = htmlspecialchars($searchConditions['ordererName'], ENT_QUOTES, 'UTF-8');
$ordererEmailHtml = htmlspecialchars($searchConditions['ordererEmail'], ENT_QUOTES, 'UTF-8');
$ordererTelHtml = htmlspecialchars($searchConditions['ordererTel'], ENT_QUOTES, 'UTF-8');
$orderDateFromHtml = htmlspecialchars($searchConditions['orderDateFrom'], ENT_QUOTES, 'UTF-8');
$orderDateToHtml = htmlspecialchars($searchConditions['orderDateTo'], ENT_QUOTES, 'UTF-8');

#===============#
# 日付フォーマット
#---------------#
function formatClientOrderDate($value)
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

#***** タグ生成開始 *****#
function getOrderStatusNameForList($statusId, $orderStatusList)
{
  foreach ($orderStatusList as $status) {
    if ((string)$status['id'] === (string)$statusId) {
      return preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
    }
  }
  return '';
}

print <<<HTML
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光旅館協同組合｜コントロールパネル(加盟店)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/master03-01.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-03-01 status-client">
    <section class="container-left-menu menu-color03">
      <div class="title">EC販売管理</div>
      <nav>
        <a href="./client03_01.php" {$client03_01_active}><span>受注一覧</span></a>
        <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
        <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
        <a href="./client03_05.php" {$client03_05_active}><span>規格管理</span></a>
        <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color03">
      <div class="block_inner">
        <h2>受注一覧</h2>
        <form name="searchForm" class="head_search_setting">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <h3>検索条件設定</h3>
          <dl>
            <div class="box-status">
              <dt>ステータス</dt>
              <dd>

HTML;
#検索対象ステータスが選択されている場合
$selectedStatusId = (string)$searchConditions['searchStatus'];
if ($selectedStatusId !== '' && in_array($selectedStatusId, $allowedListStatusIds, true) === false) {
  $selectedStatusId = '';
}
$selectedStatusName = '選択してください';
if (!empty($orderStatusList)) {
  foreach ($orderStatusList as $status) {
    if (in_array((string)$status['id'], $allowedListStatusIds, true) === false) {
      continue;
    }
    if ((string)$status['id'] === $selectedStatusId) {
      $selectedStatusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
      break;
    }
  }
}
$selectedStatusIdHtml = htmlspecialchars($selectedStatusId, ENT_QUOTES, 'UTF-8');
$selectedStatusNameHtml = htmlspecialchars($selectedStatusName, ENT_QUOTES, 'UTF-8');
$statusSelectClass = ($selectedStatusId !== '') ? ' is-selected' : '';
print <<<HTML
                <div class="select-search-status {$statusSelectClass}" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="searchStatus" value="{$selectedStatusIdHtml}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$selectedStatusNameHtml}</span>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
if (!empty($orderStatusList)) {
  foreach ($orderStatusList as $status) {
    $statusId = (string)$status['id'];
    if (in_array($statusId, $allowedListStatusIds, true) === false) {
      continue;
    }
    $statusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
    $checked = ($statusId === $selectedStatusId) ? ' checked' : '';
    print <<<HTML
                      <li>
                        <input type="radio" name="searchStatus" value="{$statusId}" id="searchStatus{$statusId}"{$checked}>
                        <label for="searchStatus{$statusId}">{$statusName}</label>
                      </li>

HTML;
  }
}
print <<<HTML
                    </ul>
                  </div>
                </div>
              </dd>
            </div>
            <div class="box-date">
              <dt>注文日</dt>
              <dd>
                <div class="wrap-period">
                  <input type="date" name="searchOrderDateFrom" value="{$orderDateFromHtml}">
                  <span>〜</span>
                  <input type="date" name="searchOrderDateTo" value="{$orderDateToHtml}">
                </div>
              </dd>
            </div>
            <div><dt>注文番号</dt><dd><input type="text" name="searchOrderNo" value="{$orderNoHtml}" placeholder="注文番号"></dd></div>
            <div class="box-name"><dt>注文者名</dt><dd><input type="text" name="searchOrdererName" value="{$ordererNameHtml}" placeholder="注文者名"></dd></div>
            <div class="box-mail"><dt>メールアドレス</dt><dd><input type="text" name="searchOrdererEmail" value="{$ordererEmailHtml}" placeholder="メールアドレス"></dd></div>
            <div class="box-tel"><dt>電話番号</dt><dd><input type="text" name="searchOrdererTel" value="{$ordererTelHtml}" placeholder="電話番号"></dd></div>
          </dl>
          <div class="box-btn">
            <button type="button" class="item-reset" onclick="searchConditions('reset')">リセット</button>
            <button type="button" class="item-search" onclick="searchConditions('search')">条件で検索</button>
          </div>
        </form>
        <article class="inner_search-list">
          <ul>
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
if (!empty($orderList)) {
  $zIndexNo = count($orderList);
  foreach ($orderList as $orderKey => $order) {
    $orderId = htmlspecialchars((string)($order['order_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $eccubeOrderId = htmlspecialchars((string)($order['eccube_order_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $orderNo = htmlspecialchars((string)($order['order_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererName = htmlspecialchars((string)($order['orderer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererEmail = htmlspecialchars((string)($order['orderer_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererTel = htmlspecialchars((string)($order['orderer_tel'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererPostalCode = htmlspecialchars((string)($order['orderer_postal_code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererPrefName = htmlspecialchars((string)($order['orderer_pref_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererAddr01 = htmlspecialchars((string)($order['orderer_addr01'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererAddr02 = htmlspecialchars((string)($order['orderer_addr02'] ?? ''), ENT_QUOTES, 'UTF-8');
    $shopName = htmlspecialchars((string)($order['shop_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $totalQuantity = htmlspecialchars(number_format((int)($order['total_quantity'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $itemSubtotal = htmlspecialchars(number_format((int)($order['item_subtotal'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $deliveryFeeTotal = htmlspecialchars(number_format((int)($order['delivery_fee_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $paymentTotal = htmlspecialchars(number_format((int)($order['payment_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $orderedAt = formatClientOrderDate($order['ordered_at'] ?? '');
    $updatedAt = formatClientOrderDate($order['updated_at'] ?? '');
    $statusChangedAt = (array_key_exists('status_changed_at', $order)) ? formatClientOrderDate($order['status_changed_at'] ?? '') : '-';
    $shippedAt = formatClientOrderDate($order['shipped_at'] ?? '');
    $displayChangedAt = $statusChangedAt;
    $stockDeductedAt = formatClientOrderDate($order['stock_deducted_at'] ?? '');
    print <<<HTML
            <li>
              <div class="item-date">
                <span>{$orderedAt}</span><i>{$orderNo}</i>
              </div>
              <div class="wrap-customer-info">
                <div class="inner-customer">
                  <div class="name"><a href="./client03_01_01.php?method=readonly&orderId={$orderId}" style="font-size:inherit;">{$ordererName}</a></div>
                  <div class="address">
                    <span>{$ordererPostalCode} {$ordererPrefName}{$ordererAddr01}</span>
                    <span>{$ordererAddr02}</span>
                  </div>
                  <div class="tel">
                    <span>{$ordererTel}</span>
                  </div>
                  <div class="mail">
                    <a href="mailto:{$ordererEmail}">{$ordererEmail}</a>
                  </div>
                </div>
              </div>
              <div class="wrap-goods">
                <ul>

HTML;
    #購入商品情報
    $orderItems = $orderItemsByOrderId[(int)($order['order_id'] ?? 0)] ?? [];
    if (!empty($orderItems)) {
      foreach ($orderItems as $orderItem) {
        $itemProductName = htmlspecialchars((string)($orderItem['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $itemQuantity = htmlspecialchars(number_format((int)($orderItem['quantity'] ?? 0)), ENT_QUOTES, 'UTF-8');
        $itemTaxRate = isset($orderItem['tax_rate']) ? (int)$orderItem['tax_rate'] : 10;
        if ($itemTaxRate <= 0) {
          $itemTaxRate = 10;
        }
        $itemSubtotalIncludingTax = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($itemTaxRate / 100)));
        $itemSubtotal = htmlspecialchars(number_format($itemSubtotalIncludingTax), ENT_QUOTES, 'UTF-8');
        print <<<HTML
                  <li>
                    <div class="goods-name">{$itemProductName}</div>
                    <div class="goods-pieces">{$itemQuantity}</div>
                    <div class="goods-price"><span>{$itemSubtotal}</span></div>
                  </li>

HTML;
      }
    } else {
      print <<<HTML
                  <li>
                    <div class="goods-name">購入商品情報なし</div>
                    <div class="goods-pieces">0</div>
                    <div class="goods-price"><span>0</span></div>
                  </li>

HTML;
    }
    print <<<HTML
                </ul>
                <div class="item-shipping">
                  <div class="title">送料</div>
                  <div class="shipping-price"><span>{$deliveryFeeTotal}</span></div>
                </div>
                <div class="item-price">
                  <span>{$paymentTotal}</span>
                </div>
              </div>

HTML;
    $targetOrderStatusId = (string)$order['eccube_order_status_id'];
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
      $targetOrderStatusName = (string)($order['eccube_order_status_name'] ?? '-');
    }
    $targetOrderStatusIdHtml = htmlspecialchars($targetOrderStatusId, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusNameHtml = htmlspecialchars($targetOrderStatusName, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusClass = ($targetOrderStatusId !== '') ? ' is-selected' : '';
    $canChangeStatus = in_array((int)$targetOrderStatusId, [1, 4, 5], true);
    print <<<HTML
              <div class="wrap-status">
                <div class="apply-status{$targetOrderStatusClass}" data-selectbox data-order-id="{$orderId}" data-current-status="{$targetOrderStatusIdHtml}">

HTML;
    if ($canChangeStatus === false) {
      print <<<HTML
                  <span class="selectbox__value">{$targetOrderStatusNameHtml}</span>
                </div>

HTML;
    } else {
      #受注受付ステータスが選択されている場合
      #Liのz-index設定
      $zIndexStyle = 'style="z-index:' . ($zIndexNo - $orderKey) . ';"';
      print <<<HTML
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="List01Status" value="{$targetOrderStatusIdHtml}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$targetOrderStatusNameHtml}</span>
                    <i></i>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
      if (!empty($orderStatusList)) {
        foreach ($orderStatusList as $status) {
          $statusId = (string)$status['id'];
          if (in_array($statusId, $allowedListStatusIds, true) === false) {
            continue;
          }
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
            case '99':
              $statusClass = 'status-completed';
              break;
          }
          print <<<HTML
                      <!-- NOTE  インラインでz-indexを付与 -->
                      <li {$zIndexStyle}>
                        <input type="radio" name="orderStatus{$orderKey}" value="{$statusId}" id="list{$orderKey}-{$statusId}" data-order-status-radio {$checked}>
                        <label for="list{$orderKey}-{$statusId}" class="{$statusClass}">{$statusName}</label>
                      </li>

HTML;
        }
      }
      print <<<HTML
                    </ul>
                  </div>
                </div>

HTML;
    }
    if ($displayChangedAt !== '-') {
      print <<<HTML
                  <span class="date">{$displayChangedAt}</span>

HTML;
    }
    print <<<HTML
              </div>
              <div class="box-note">
                <span>メモ</span>
                <textarea></textarea>
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
print makePagerBoxTag((int)$pageNumber, (int)$totalPages, $pagerDisplayMax, 'movePage');
print <<<HTML
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>検索結果</p>
        <button type="button" onclick="closeModal()" class="btn-top-close"></button>
      </div>
      <div class="box-details">
        <p>条件に一致する受注はありません。</p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="./assets/js/client03_01.js" defer></script>
</body>

</html>

HTML;
