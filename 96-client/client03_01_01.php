<?php
/*
 * [96-client/client03_01_01.php]
 *  - 【加盟店】管理画面 -
 *  受注詳細（閲覧モード）
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
$pagePrefix = 'cKey03-01-01_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== $noUpDateKey) {
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
#閲覧モード
$method = isset($_GET['method']) ? (string)$_GET['method'] : null;
if ($method !== 'readonly') {
  header("Location: ./client03_01.php");
  exit;
}
$viewModeStyle = '';
if ($method === 'readonly') {
  $viewModeStyle = 'style="pointer-events: none;"';
}
#-------------#
#店舗ID
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
} else {
  header("Location: ./logout.php");
  exit;
}

#=======#
# 店舗名
#-------#
$headerShopName = "";
if (!isset($shopData) || empty($shopData)) {
  header("Location: ./logout.php");
  exit;
} else {
  $headerShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
}

#=============#
# 受注詳細取得
#-------------#
$orderId = isset($_GET['orderId']) ? trim((string)$_GET['orderId']) : '';
if ($orderId === '' || ctype_digit($orderId) === false || (int)$orderId < 1) {
  header("Location: ./client03_01.php");
  exit;
}
$orderDetail = getShopOrderById((int)$orderId, (int)$shopId);
$orderItemsByOrderId = [];
$orderItems = [];
if (!empty($orderDetail)) {
  $orderItemsByOrderId = getShopOrderItemsByOrderIds([(int)$orderDetail['order_id']]);
  $orderItems = $orderItemsByOrderId[(int)$orderDetail['order_id']] ?? [];
}

#===============#
# 表示用helper
#---------------#
function hClientOrderDetail($value)
{
  $value = trim((string)$value);
  return htmlspecialchars(($value === '') ? '-' : $value, ENT_QUOTES, 'UTF-8');
}
function hClientOrderDetailInput($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function formatClientOrderDetailDate($value)
{
  $value = trim((string)$value);
  if ($value === '') {
    return '-';
  }
  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
  return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8') . '<i>' . htmlspecialchars(date('H:i', $timestamp), ENT_QUOTES, 'UTF-8') . '</i>';
}
function formatClientOrderDetailMoney($value)
{
  return htmlspecialchars(number_format((int)$value), ENT_QUOTES, 'UTF-8');
}

#-------------#
#表示用値
$hasOrderDetail = !empty($orderDetail);
$orderIdHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['order_id'] ?? '') : '-';
$eccubeOrderIdHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['eccube_order_id'] ?? '') : '-';
$orderNoHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['order_no'] ?? '') : '-';
$statusNameHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['eccube_order_status_name'] ?? '') : '-';
$orderedAtHtml = $hasOrderDetail ? formatClientOrderDetailDate($orderDetail['ordered_at'] ?? '') : '-';
$stockDeductedAtHtml = $hasOrderDetail ? formatClientOrderDetailDate($orderDetail['stock_deducted_at'] ?? '') : '-';
$shippedAtHtml = $hasOrderDetail ? formatClientOrderDetailDate($orderDetail['shipped_at'] ?? '') : '-';
$statusChangedAtHtml = $hasOrderDetail ? formatClientOrderDetailDate($orderDetail['status_changed_at'] ?? '') : '-';
$updatedAtHtml = $hasOrderDetail ? formatClientOrderDetailDate($orderDetail['updated_at'] ?? '') : '-';
#注文者情報
$ordererNameHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['orderer_name'] ?? '') : '-';
$ordererName01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_name01'] ?? '') : '';
$ordererName02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_name02'] ?? '') : '';
$ordererKana01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_kana01'] ?? '') : '';
$ordererKana02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_kana02'] ?? '') : '';
$ordererEmailHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_email'] ?? '') : '';
$ordererTelHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_tel'] ?? '') : '';
$ordererCompanyNameHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_company_name'] ?? '') : '';
$ordererPostalCodeHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_postal_code'] ?? '') : '';
$ordererPrefIdHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_pref_id'] ?? '') : '';
$ordererPrefNameHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_pref_name'] ?? '') : '';
$ordererAddr01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_addr01'] ?? '') : '';
$ordererAddr02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['orderer_addr02'] ?? '') : '';
$ordererMessageHtml = $hasOrderDetail ? htmlspecialchars((string)($orderDetail['orderer_message'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
#出荷先情報
$shippingNameHtml = $hasOrderDetail ? hClientOrderDetail($orderDetail['shipping_name'] ?? '') : '-';
$shippingName01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_name01'] ?? '') : '';
$shippingName02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_name02'] ?? '') : '';
$shippingKana01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_kana01'] ?? '') : '';
$shippingKana02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_kana02'] ?? '') : '';
$shippingCompanyNameHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_company_name'] ?? '') : '';
$shippingPostalCodeHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_postal_code'] ?? '') : '';
$shippingPrefIdHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_pref_id'] ?? '') : '';
$shippingPrefNameHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_pref_name'] ?? '') : '';
$shippingAddr01Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_addr01'] ?? '') : '';
$shippingAddr02Html = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_addr02'] ?? '') : '';
$shippingTelHtml = $hasOrderDetail ? hClientOrderDetailInput($orderDetail['shipping_tel'] ?? '') : '';
$deliveryFeeTotalHtml = $hasOrderDetail ? formatClientOrderDetailMoney($orderDetail['delivery_fee_total'] ?? 0) : '0';
$paymentTotalHtml = $hasOrderDetail ? formatClientOrderDetailMoney($orderDetail['payment_total'] ?? 0) : '0';
$shopNoteHtml = $hasOrderDetail ? htmlspecialchars((string)($orderDetail['note'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
$itemSubtotalTotal = 0;

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; connect-src 'self' https://zipcloud.ibsnet.co.jp; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
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
  <main class="inner-03-01-01 status-client">
    <section class="container-left-menu menu-color03">
      <div class="title">EC販売管理</div>
      <nav>
          <a href="./client03_01.php" {$client03_01_01_active}><span>受注一覧</span></a>
          <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
          <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
          <a href="./client03_05.php" {$client03_05_active}><span>規格管理</span></a>
          <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
        <a href="#"><span>集計</span></a>
        <!-- <a href="#"><span>店舗登録</span ></a> -->
      </nav>
    </section>
    <div class="main-contents menu-color03">
      <div class="block_inner">
        <h2>受注詳細</h2>
        <form name="inputForm" class="inputForm">
          <article class="block-customer-info" {$viewModeStyle}>
            <h3>注文者情報</h3>
            <section>
              <dl class="inner-left">
                <div class="box-date">
                  <dt>注文日</dt>
                  <dd>
                    <span>{$orderedAtHtml}</span>
                  </dd>
                </div>
                <div class="box-status">
                  <dt>対応状況</dt>
                  <dd>

HTML;
#検索対象ステータスが選択されている場合
$selectedStatusId = (string)$orderDetail['eccube_order_status_id'];
$selectedStatusName = '選択してください';
if (!empty($orderStatusList)) {
  foreach ($orderStatusList as $status) {
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
                    <div class="select-search-category  {$statusSelectClass}" data-selectbox style="pointer-events: none;">
                      <button type="button" class="selectbox__head" aria-expanded="false">
                        <input type="hidden" name="orderSelectCategory" value="{$selectedStatusIdHtml}" data-selectbox-hidden>
                        <span class="selectbox__value" data-selectbox-value>{$selectedStatusNameHtml}</span>
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">

HTML;
if (!empty($orderStatusList)) {
  foreach ($orderStatusList as $status) {
    $statusId = (string)$status['id'];
    $statusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
    $checked = ($statusId === $selectedStatusId) ? ' checked' : '';
    print <<<HTML
                          <li>
                            <input type="radio" name="orderSelectCategory" value="{$statusId}" id="searchCategory{$statusId}"{$checked}>
                            <label for="searchCategory{$statusId}">{$statusName}</label>
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
                <div class="box-name">
                  <dt class="is-required">お名前</dt>
                  <dd>
                    <input type="text" name="userFirstName" value="{$ordererName01Html}" id="userFirstName" class="required-item" required placeholder="姓">
                    <input type="text" name="userLastName" value="{$ordererName02Html}" id="userLastName" class="required-item" required placeholder="名">
                  </dd>
                </div>
                <div class="box-name">
                  <dt class="is-required">お名前(カナ)</dt>
                  <dd>
                    <input type="text" name="userFirstNameKana" value="{$ordererKana01Html}" id="userFirstNameKana" class="required-item" required placeholder="セイ">
                    <input type="text" name="userLastNameKana" value="{$ordererKana02Html}" id="userLastNameKana" class="required-item" required placeholder="メイ">
                  </dd>
                </div>
                <div class="box-address">
                  <dt class="is-required">住所</dt>
                  <dd>
                    <div>
                      <span>〒</span>
                      <input type="text" name="userPostalCode" value="{$ordererPostalCodeHtml}" id="userPostalCode" class="required-item" required placeholder="例：8601234">
                    </div>
                    <input type="hidden" name="ordererPrefId" value="{$ordererPrefIdHtml}" id="ordererPrefId">
                    <input type="text" name="userAddress01" value="{$ordererPrefNameHtml}" id="userAddress01" class="required-item" required placeholder="都道府県">
                    <input type="text" name="userAddress02" value="{$ordererAddr01Html}" id="userAddress02" class="required-item" required placeholder="市区町村">
                    <input type="text" name="userAddress03" value="{$ordererAddr02Html}" id="userAddress03" class="required-item" required placeholder="番地・建物名など">
                  </dd>
                </div>
                <div>
                  <dt>会社名</dt>
                  <dd>
                    <input type="text" name="userCompanyName" value="{$ordererCompanyNameHtml}" id="userCompanyName" placeholder="会社名">
                  </dd>
                </div>
              </dl>
              <dl class="inner-right">
                <div>
                  <dt>注文番号</dt>
                  <dd>
                    <span>{$orderIdHtml}</span>
                  </dd>
                </div>
                <div>
                  <dt>支払方法</dt>
                  <dd>
                    <span>クレジットカード</span>
                  </dd>
                </div>
                <div class="box-date">
                  <dt>出荷日</dt>
                  <dd>
                    <span>{$shippedAtHtml}</span>
                  </dd>
                </div>
                <div class="box-date">
                  <dt>更新日</dt>
                  <dd>
                    <span>{$updatedAtHtml}</span>
                  </dd>
                </div>
                <div>
                  <dt class="is-required">メールアドレス</dt>
                  <dd>
                    <input type="text" name="userEmail" value="{$ordererEmailHtml}" id="userEmail" class="required-item" required>
                  </dd>
                </div>
                <div>
                  <dt class="is-required">電話番号</dt>
                  <dd>
                    <input type="text" name="userTel" value="{$ordererTelHtml}" id="userTel" class="required-item" required>
                  </dd>
                </div>
                <div>
                  <dt>お問い合わせ</dt>
                  <dd><textarea name="userInquiry" id="userInquiry" readonly>{$ordererMessageHtml}</textarea></dd>
                </div>
              </dl>
            </section>
          </article>
          <article class="block-shipping-info" {$viewModeStyle}>
            <h3>出荷情報</h3>
            <section>
              <div class="box-copy">
                <button type="button"><span>注文者情報をコピー</span></button>
              </div>
              <dl class="inner-left">
                <div class="box-name">
                  <dt class="is-required">お名前</dt>
                  <dd>
                    <input type="text" name="shippingUserFirstName" value="{$shippingName01Html}" id="shippingUserFirstName" class="required-item" required placeholder="姓">
                    <input type="text" name="shippingUserLastName" value="{$shippingName02Html}" id="shippingUserLastName" class="required-item" required placeholder="名">
                    </dd>
                </div>
                <div class="box-name">
                  <dt class="is-required">お名前(カナ)</dt>
                  <dd>
                    <input type="text" name="shippingUserFirstNameKana" value="{$shippingKana01Html}" id="shippingUserFirstNameKana" class="required-item" required placeholder="セイ">
                    <input type="text" name="shippingUserLastNameKana" value="{$shippingKana02Html}" id="shippingUserLastNameKana" class="required-item" required placeholder="メイ">
                  </dd>
                </div>
                <div class="box-address">
                  <dt class="is-required">住所</dt>
                  <dd>
                    <div>
                      <span>〒</span>
                      <input type="text" name="shippingUserPostalCode" value="{$shippingPostalCodeHtml}" id="shippingUserPostalCode" class="required-item" required placeholder="例：8601234">
                    </div>
                    <input type="hidden" name="shippingPrefId" value="{$shippingPrefIdHtml}" id="shippingPrefId">
                    <input type="text" name="shippingUserAddress01" value="{$shippingPrefNameHtml}" id="shippingUserAddress01" class="required-item" required placeholder="都道府県">
                    <input type="text" name="shippingUserAddress02" value="{$shippingAddr01Html}" id="shippingUserAddress02" class="required-item" required placeholder="市区町村">
                    <input type="text" name="shippingUserAddress03" value="{$shippingAddr02Html}" id="shippingUserAddress03" class="required-item" required placeholder="番地・建物名など">
                  </dd>
                </div>
                <div>
                  <dt>会社名</dt>
                  <dd>
                    <input type="text" name="shippingUserCompanyName" value="{$shippingCompanyNameHtml}" id="shippingUserCompanyName" placeholder="会社名">
                  </dd>
                </div>
              </dl>
              <dl class="inner-right">
                <div>
                  <dt class="is-required">電話番号</dt>
                  <dd>
                    <input type="text" name="shippingUserTel" value="{$shippingTelHtml}" id="shippingUserTel" class="required-item" required placeholder="例：09012345678">
                  </dd>
                </div>
                <!-- <div>
                  <dt>お問い合わせ番号</dt>
                  <dd>
                    <input type="text" name="shippingUserInquiryNumber" id="shippingUserInquiryNumber">
                  </dd>
                </div>
                <div>
                  <dt>お届け日</dt>
                  <dd>
                    <input type="date" name="shippingUserDeliveryDate" id="shippingUserDeliveryDate">
                  </dd>
                </div>
                <div class="box-status">
                  <dt>お届け時間</dt>
                  <dd>
                    <div class="select-search-category" data-selectbox>
                      <button type="button" class="selectbox__head" aria-expanded="false">
                        <input type="hidden" name="shippingUserDeliveryTime" value="" data-selectbox-hidden>
                        <span class="selectbox__value" data-selectbox-value>選択してください</span>
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">
                          <li>
                            <input type="radio" name="shippingUserDeliveryTime" value="1" id="searchCategory01" checked>
                            <label for="searchCategory01">指定なし</label>
                          </li>
                          <li>
                            <input type="radio" name="shippingUserDeliveryTime" value="2" id="searchCategory02">
                            <label for="searchCategory02">午前</label>
                          </li>
                          <li>
                            <input type="radio" name="shippingUserDeliveryTime" value="3" id="searchCategory03">
                            <label for="searchCategory03">午後</label>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </dd>
                </div> -->
                <div>
                  <dt>出荷用メモ</dt>
                  <dd><textarea name="shippingUserShippingMemo" id="shippingUserShippingMemo"></textarea></dd>
                </div>
              </dl>
            </section>
          </article>
          <article class="block-product-info">
            <h3>商品情報</h3>
            <section>
              <ul>
                <li>
                  <div>商品名</div>
                  <div>金額</div>
                  <div>数量</div>
                  <div>小計</div>
                  <div>返品対象</div>
                </li>

HTML;
#注文商品情報詳細／料金計算
$itemSubtotalTotal = 0;
if (!empty($orderItems)) {
  foreach ($orderItems as $orderItem) {
    $itemTaxRate = isset($orderItem['tax_rate']) ? (int)$orderItem['tax_rate'] : 10;
    if ($itemTaxRate <= 0) {
      $itemTaxRate = 10;
    }
    $itemNameHtml = htmlspecialchars((string)($orderItem['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $itemUnitPrice = (int)round((int)($orderItem['unit_price'] ?? 0) * (1 + ($itemTaxRate / 100)));
    $itemSubtotal = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($itemTaxRate / 100)));
    $itemSubtotalTotal += $itemSubtotal;
    $itemUnitPriceHtml = htmlspecialchars(number_format($itemUnitPrice), ENT_QUOTES, 'UTF-8');
    $itemQuantityHtml = htmlspecialchars(number_format((int)($orderItem['quantity'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $itemSubtotalHtml = htmlspecialchars(number_format($itemSubtotal), ENT_QUOTES, 'UTF-8');
    print <<<HTML
                <li>
                  <div class="item-name">
                    <span>{$itemNameHtml}</span>
                  </div>
                  <div class="item-price">
                    <span>{$itemUnitPriceHtml}</span>
                  </div>
                  <div class="item-count">
                    <span>{$itemQuantityHtml}</span>
                  </div>
                  <div class="item-price">
                    <span>{$itemSubtotalHtml}</span>
                  </div>
                  <div class="item-return">
                    <label>
                      <input type="checkbox" name="orderUserReturn">
                    </label>
                  </div>
                </li>

HTML;
  }
} else {
  print <<<HTML
                <li>
                  <div class="item-name">
                    <span>購入商品情報なし</span>
                  </div>
                  <div class="item-price"><span>0</span></div>
                  <div class="item-count"><span>0</span></div>
                  <div class="item-price"><span>0</span></div>
                  <div class="item-return"></div>
                </li>

HTML;
}
#料金表示用に金額をエスケープ
$itemSubtotalTotalHtml = htmlspecialchars(number_format($itemSubtotalTotal), ENT_QUOTES, 'UTF-8');
$deliveryFeeTotalHtml = htmlspecialchars(number_format((int)($orderDetail['delivery_fee_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
$paymentTotalHtml = htmlspecialchars(number_format((int)($orderDetail['payment_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
print <<<HTML
              </ul>
              <dl>
                <div>
                  <dt>小計</dt>
                  <dd>{$itemSubtotalTotalHtml}</dd>
                </div>
                <div>
                  <dt>送料</dt>
                  <dd>{$deliveryFeeTotalHtml}</dd>
                </div>
                <div>
                  <dt>合計</dt>
                  <dd>{$paymentTotalHtml}</dd>
                </div>
              </dl>
            </section>
          </article>
          <article class="block-refund-process">
            <h3>返金処理</h3>
            <div class="block-inner">
              <div class="item-check">
                <label>
                  <input type="checkbox">
                </label>
                <span>返金処理を行う</span>
              </div>
              <div class="item-price">
                <input type="text">
                <span>円</span>
              </div>
              <button type="button">返金処理を行う</button>
            </div>
          </article>
          <article class="block-description" {$viewModeStyle}>
            <h3>ショップ用メモ</h3>
            <div class="block-inner">
              <textarea name="productDescription" id="productDescription">{$shopNoteHtml}</textarea>
            </div>
          </article>
          <div class="box-btn">
            <button type="button" class="btn-pdf" id="btnPdf"><span>納品書出力</span></button>
            <button type="button" class="btn-edit" id="btnEdit">編集する</button>
            <button type="button" class="btn-cancel" id="btnCancel" style="display:none;">キャンセル</button>
            <button type="button" class="btn-confirmed" id="btnSave" style="display:none;">保存する</button>
          </div>
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>受注詳細編集</p>
        <button type="button" onclick="closeModal()" class="btn-top-close"></button>
      </div>
      <div class="box-details">
        <p></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="../assets/js/form.js" defer></script>
  <script src="./assets/js/client03_01_01.js" defer></script>
</body>

</html>

HTML;
