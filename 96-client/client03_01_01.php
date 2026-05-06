<?php
/*
 * [96-client/client03_01_01.php]
 *  - 【加盟店】管理画面 -
 *  受注詳細
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
#新規／編集
$method = isset($_GET['method']) ? $_GET['method'] : null;
#モードチェック
if ($method === null || ($method !== 'readonly')) {
  #不正アクセス：トップページへリダイレクト
  header("Location: ./client03_01.php");
  exit;
}
#表示スタイル
$viewModeStyle = "";
if ($method === 'readonly') {
  $viewModeStyle = 'style="pointer-events: none; opacity: 0.6;"';
}
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

#=============#
# 購入商品詳細
#-------------#
#受注ID
$orderId = isset($_GET['orderId']) ? $_GET['orderId'] : null;
#受注IDがあれば受注情報取得
$orderDetail = [];
if ($orderId !== null) {
  #受注情報
  $orderDetail = getShopOrderDetail($orderId);
} else {
  #受注ID無し：一覧ページへリダイレクト
  header("Location: ./client03_01.php");
  exit;
}
#-------------#
#受注情報があれば表示用情報生成
if (!empty($orderDetail)) {
}

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
        <form>
          <article class="block-customer-info" {$viewModeStyle}>
            <h3>注文者情報</h3>
            <section>
              <dl class="inner-left">
                <div class="box-date">
                  <dt>注文日</dt>
                  <dd>
                    <span>2026/04/04<i>10:30</i></span>
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
                    <div class="select-search-category  {$statusSelectClass}" data-selectbox>
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
                    <input type="text" name="userFirstName" value="" id="userFirstName" placeholder="姓">
                    <input type="text" name="userLastName" value="" id="userLastName" placeholder="名">
                  </dd>
                </div>
                <div class="box-name">
                  <dt class="is-required">お名前(カナ)</dt>
                  <dd>
                    <input type="text" name="userFirstNameKana" value="" id="userFirstNameKana" placeholder="セイ">
                    <input type="text" name="userLastNameKana" value="" id="userLastNameKana" placeholder="メイ">
                  </dd>
                </div>
                <div class="box-address">
                  <dt class="is-required">住所</dt>
                  <dd>
                    <div>
                      <span>〒</span>
                      <input type="text" name="userPostalCode" id="userPostalCode" placeholder="例：8601234">
                    </div>
                    <input type="text" name="userAddress01" id="userAddress01" placeholder="都道府県">
                    <input type="text" name="userAddress02" id="userAddress02" placeholder="市区町村">
                    <input type="text" name="userAddress03" id="userAddress03" placeholder="番地・建物名など">
                  </dd>
                </div>
                <div>
                  <dt>会社名</dt>
                  <dd>
                    <input type="text" name="userCompanyName" value="" id="userCompanyName" placeholder="会社名">
                  </dd>
                </div>
              </dl>
              <dl class="inner-right">
                <div>
                  <dt>注文番号</dt>
                  <dd>
                    <span>#000123</span>
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
                    <span>2026/04/04<i>10:30</i></span>
                  </dd>
                </div>
                <div class="box-date">
                  <dt>更新日</dt>
                  <dd>
                    <span>2026/04/04<i>10:30</i></span>
                  </dd>
                </div>
                <div>
                  <dt class="is-required">メールアドレス</dt>
                  <dd>
                    <input type="text" name="userEmail" id="userEmail">
                  </dd>
                </div>
                <div>
                  <dt class="is-required">電話番号</dt>
                  <dd>
                    <input type="text" name="userTel" id="userTel">
                  </dd>
                </div>
                <div>
                  <dt>お問い合わせ</dt>
                  <dd><textarea name="userInquiry" id="userInquiry"></textarea></dd>
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
                    <input type="text" name="sendOtherUserName" id="sendOtherUserName" placeholder="姓">
                    <input type="text" name="sendOtherUserName" id="sendOtherUserName" placeholder="名">
                    </dd>
                </div>
                <div class="box-name">
                  <dt class="is-required">お名前(カナ)</dt>
                  <dd>
                    <input type="text" name="sendOtherUserNameKana" id="sendOtherUserNameKana" placeholder="セイ">
                    <input type="text" name="sendOtherUserNameKana" id="sendOtherUserNameKana" placeholder="メイ">
                  </dd>
                </div>
                <div class="box-address">
                  <dt class="is-required">住所</dt>
                  <dd>
                    <div>
                      <span>〒</span>
                      <input type="text" name="sendOtherUserPostalCode" id="sendOtherUserPostalCode" placeholder="例：8601234">
                    </div>
                    <input type="text" name="sendOtherUserAddress01" id="sendOtherUserAddress01" placeholder="市区町村">
                    <input type="text" name="sendOtherUserAddress02" id="sendOtherUserAddress02" placeholder="番地・建物名など">
                    <input type="text" name="sendOtherUserAddress03" id="sendOtherUserAddress03" placeholder="建物名など">
                  </dd>
                </div>
                <div>
                  <dt>会社名</dt>
                  <dd>
                    <input type="text" name="sendOtherUserCompanyName" id="sendOtherUserCompanyName" placeholder="会社名">
                  </dd>
                </div>
              </dl>
              <dl class="inner-right">
                <div>
                  <dt class="is-required">電話番号</dt>
                  <dd>
                    <input type="text" name="sendOtherUserTel" id="sendOtherUserTel">
                  </dd>
                </div>
                <div>
                  <dt>お問い合わせ番号</dt>
                  <dd>
                    <input type="text" name="sendOtherUserInquiryNumber" id="sendOtherUserInquiryNumber">
                  </dd>
                </div>
                <div>
                  <dt>お届け日</dt>
                  <dd>
                    <input type="date" name="sendOtherUserDeliveryDate" id="sendOtherUserDeliveryDate">
                  </dd>
                </div>
                <div class="box-status">
                  <dt>お届け時間</dt>
                  <dd>
                    <div class="select-search-category" data-selectbox>
                      <button type="button" class="selectbox__head" aria-expanded="false">
                        <input type="hidden" name="sendOtherUserDeliveryTime" value="" data-selectbox-hidden>
                        <span class="selectbox__value" data-selectbox-value>選択してください</span>
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">
                          <li>
                            <input type="radio" name="sendOtherUserDeliveryTime" value="1" id="searchCategory01" checked>
                            <label for="searchCategory01">指定なし</label>
                          </li>
                          <li>
                            <input type="radio" name="sendOtherUserDeliveryTime" value="2" id="searchCategory02">
                            <label for="searchCategory02">午前</label>
                          </li>
                          <li>
                            <input type="radio" name="sendOtherUserDeliveryTime" value="3" id="searchCategory03">
                            <label for="searchCategory03">午後</label>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </dd>
                </div>
                <div>
                  <dt>出荷用メモ</dt>
                  <dd><textarea name="sendOtherUserShippingMemo" id="sendOtherUserShippingMemo"></textarea></dd>
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



                <li>
                  <div class="item-name">
                    <span>彩のジェラートCUBE<i>
                        フレーバー： チョコ / サイズ： 32mm × 32mm</i></span>
                  </div>
                  <div class="item-price">
                    <span>1200</span>
                  </div>
                  <div class="item-count">
                    <span>12</span>
                  </div>
                  <div class="item-price">
                    <span>12000</span>
                  </div>
                  <div class="item-return">
                    <label>
                      <input type="checkbox" name="sendOtherUserReturn">
                    </label>
                  </div>
                </li>
                <li>
                  <div class="item-name">
                    <span>彩のジェラートCUBE<i>
                        フレーバー： チョコ / サイズ： 32mm × 32mm</i></span>
                  </div>
                  <div class="item-price">
                    <span>1200</span>
                  </div>
                  <div class="item-count">
                    <span>12</span>
                  </div>
                  <div class="item-price">
                    <span>12000</span>
                  </div>
                  <div class="item-return">
                    <label>
                      <input type="checkbox" name="sendOtherUserReturn">
                    </label>
                  </div>
                </li>
                <li>
                  <div class="item-name">
                    <span>彩のジェラートCUBE<i>
                        フレーバー： チョコ / サイズ： 32mm × 32mm</i></span>
                  </div>
                  <div class="item-price">
                    <span>1200</span>
                  </div>
                  <div class="item-count">
                    <span>12</span>
                  </div>
                  <div class="item-price">
                    <span>12000</span>
                  </div>
                  <div class="item-return">
                    <label>
                      <input type="checkbox" name="sendOtherUserReturn">
                    </label>
                  </div>
                </li>



              </ul>


              <dl>
                <div>
                  <dt>小計</dt>
                  <dd>6,200</dd>
                </div>
                <div>
                  <dt>送料</dt>
                  <dd>500</dd>
                </div>
                <div>
                  <dt>合計</dt>
                  <dd>6,700</dd>
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
              <textarea name="productDescription" id="productDescription"></textarea>
            </div>
          </article>
          <div class="box-btn">
            <button type="button" class="btn-pdf"><span>納品書出力</span></button>

HTML;
if ($method !== 'readonly') {
  print <<<HTML
            <button type="button" class="btn-edit">編集する</button>

HTML;
} else {
  print <<<HTML
            <button type="button" class="btn-cancel">キャンセル</button>
            <button type="button" class="btn-confirmed">保存する</button>

HTML;
}
print <<<HTML
          </div>
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <script src="../assets/js/common.js" defer></script>
</body>

</html>

HTML;
