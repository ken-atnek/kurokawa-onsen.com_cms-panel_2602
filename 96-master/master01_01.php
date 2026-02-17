<?php
/*
 * [96-master/master01_01.php]
 *  - 管理画面 -
 *  店舗一覧
 *
 * [初版]
 *  2026.2.14
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

#================#
# SESSIONチェック
#----------------#
#セッションキー
$searchConditionsSessionKey = 'searchConditions_master01_01';
$pagePrefix = 'mKey01-01_';
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

#-------------#
#検索・絞り込み条件保持用セッションチェック
$searchConditions = array();
if (isset($_SESSION[$searchConditionsSessionKey]) === false || !is_array($_SESSION[$searchConditionsSessionKey])) {
  $_SESSION[$searchConditionsSessionKey] = array(
    'shopId' => '',
    'isPublic' => '1',
  );
  #初期値セット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
} else {
  #既存セッションがあれば変数にセット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
}
#必須キーが欠けている場合は初期化（運用上は常に揃う前提）
$requiredKeys = ['shopId', 'isPublic'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'shopId' => '',
      'isPublic' => '1',
    );
    break;
  }
}
$_SESSION[$searchConditionsSessionKey] = $searchConditions;

#=============#
# 店舗一覧取得
#-------------#
#検索条件があれば適用して店舗一覧を取得
if (is_array($searchConditions) && count($searchConditions) > 0) {
  $shopsList = searchShopList($searchConditions);
} else {
  $shopsList = getShopList();
}

#検索フォーム（店名選択）用：公開/非公開に依存しない全件リスト
$shopsListAll = getShopList();

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
    <link rel="stylesheet" href="../assets/css/master01-01.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-01-01">
      <section class="container-left-menu menu-color01">
        <div class="title">店舗管理</div>
        <nav>
          <a href="./master01_01.php" {$master01_01_active}><span>店舗一覧</span></a>
          <a href="./master01_02.php?method=new" {$master01_02_active}><span>店舗登録</span></a>
        </nav>
      </section>
      <div class="main-contents menu-color01">
        <div class="block_inner">
          <h2>店舗一覧</h2>
          <form name="searchForm" class="head_search_setting">
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
            <h3>検索条件設定</h3>
            <dl>
              <div class="box_shop">
                <dt>店名</dt>
                <dd>
                  <div class="select-shop-name" data-selectbox>

HTML;
#検索対象店舗が選択されている場合
$currentShopId = '';
$currentShopName = '選択してください';
foreach ($shopsListAll as $shop) {
  if ((int)$shop['shop_id'] === (int)$searchConditions['shopId']) {
    $currentShopId = (int)$shop['shop_id'];
    $currentShopName = $shop['shop_name'];
    break;
  }
}
print <<<HTML
                    <button type="button" class="selectbox__head" aria-expanded="false">
                      <input type="hidden" name="searchShopId" value="{$currentShopId}" data-selectbox-hidden>
                      <span class="selectbox__value" data-selectbox-value>{$currentShopName}</span>
                    </button>
                    <div class="list-wrapper">
                      <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopsListAll)) {
  foreach ($shopsListAll as $shop) {
    $shopId = htmlspecialchars($shop['shop_id'], ENT_QUOTES, 'UTF-8');
    $shopName = htmlspecialchars($shop['shop_name'], ENT_QUOTES, 'UTF-8');
    #改行コードを除去して１行にまとめる
    $shopName = preg_replace('/\r\n|\r|\n/', '', $shopName);
    #checked判定
    $checked = ((int)$shopId === (int)$searchConditions['shopId']) ? 'checked' : '';
    print <<<HTML
                        <li>
                          <input type="radio" name="searchShopId" value="{$shopId}" id="shop{$shopId}" {$checked}>
                          <label for="shop{$shopId}">{$shopName}</label>
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
              <div class="box_setting">
                <dt>公開設定</dt>
                <dd>

HTML;
#checked判定
$isPrivateChecked = ($searchConditions['isPublic'] === '0') ? 'checked' : '';
$isPublicChecked = ($searchConditions['isPublic'] === '1') ? 'checked' : '';
print <<<HTML
                  <div>
                    <input type="radio" name="displayMode" value="0" id="displayMode01" {$isPrivateChecked}>
                    <label for="displayMode01">非公開</label>
                  </div>
                  <div>
                    <input type="radio" name="displayMode" value="1" id="displayMode02" {$isPublicChecked}>
                    <label for="displayMode02">公開</label>
                  </div>
                </dd>
              </div>
            </dl>
            <div class="box-btn">
              <button type="button" class="item-reset" onclick="searchConditions('reset')">リセット</button>
              <button type="button" class="item-search" onclick="searchConditions('search')">条件で検索</button>
            </div>
          </form>
          <article class="inner_search-list">
            <ul>
              <li>
                <div>ID</div>
                <div>店舗情報</div>
                <div>写真</div>
                <div>基本情報</div>
                <div>紹介</div>
                <div>おすすめ<i>商品</i></div>
                <div><span>公開状況</span><span>設定変更</span></div>
                <div>サイト<i>確認</i></div>
              </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopsList)) {
  #inline JS用エスケープ宣言
  $jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
  foreach ($shopsList as $shop) {
    $shopId = htmlspecialchars($shop['shop_id'], ENT_QUOTES, 'UTF-8');
    $shopName = htmlspecialchars($shop['shop_name'], ENT_QUOTES, 'UTF-8');
    #改行を除去して１行にまとめる
    $shopName = preg_replace('/\r\n|\r|\n/', '', $shopName);
    $shopTel = htmlspecialchars($shop['tel'], ENT_QUOTES, 'UTF-8');
    $shopFax = htmlspecialchars($shop['fax'], ENT_QUOTES, 'UTF-8');
    #公開状態
    $isPublic = ($shop['is_public'] == 1) ? 'is-active' : 'is-inactive';
    $statusLabel = ($shop['is_public'] == 1) ? '公開中' : '非公開';
    $changeStatus = ($shop['is_public'] == 1) ? '0' : '1';
    $changeStatusLabel = ($shop['is_public'] == 1) ? '非公開へ' : '公開へ';
    #サイト確認URL
    $websiteUrl = htmlspecialchars($shop['website_url'], ENT_QUOTES, 'UTF-8');
    $websiteUrl = ($websiteUrl !== '') ? $websiteUrl : 'javascript:void(0);';
    #inline JS用エスケープ（属性崩壊・注入対策）
    $shopIdJs = json_encode($shopId, $jsonHex);
    $shopNameJs = json_encode($shopName, $jsonHex);
    $changeStatusJs = json_encode($changeStatus, $jsonHex);
    print <<<HTML
              <li>
                <div class="id">{$shopId}</div>
                <div class="wrap_shop-info">
                  <div class="name">{$shopName}</div>
                  <div class="tel">
                    <span>{$shopTel}</span>
                  </div>
                  <div class="fax">
                    <span>{$shopFax}</span>
                  </div>
                </div>
                <div class="item_photo">
                  <a href="#"></a>
                </div>
                <div class="item_edit">
                  <a href="./master01_02.php?method=edit&shopId={$shopId}"></a>
                </div>
                <div class="item_edit">
                  <a href="#"></a>
                </div>
                <div class="item_edit">
                  <a href="#"></a>
                </div>
                <div class="item_status">
                  <!-- NOTE ↑公開中→[is-active] / 非公開→[is-inactive] -->
                  <div class="status {$isPublic}">
                    <span></span>
                  </div>
                  <div class="btn">
                    <button type="button" onclick='changeStatus({$shopIdJs},{$shopNameJs},{$changeStatusJs})'>{$changeStatusLabel}</button>
                  </div>
                </div>
                <div class="item_site">
                  <a href="{$websiteUrl}" target="_blank"></a>
                </div>
              </li>

HTML;
  }
}
print <<<HTML
            </ul>
          </article>
          <a href="./grp02_01.php" class="link_page-back_bottom">戻る</a>
          <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!-- NOTE is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>公開設定変更</p>
          <button type="button" onclick="closeModal()" class="btn-top-close"></button>
        </div>
        <div class="box-details">
          <p></p>
          <div class="box-btn">
            <button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>
          </div>
        </div>
      </div>
    </article>
    <script src="../assets/js/common.js" defer></script>
    <script src="../assets/js/modal.js" defer></script>
    <script src="./assets/js/master01_01.js" defer></script>
  </body>
</html>

HTML;
