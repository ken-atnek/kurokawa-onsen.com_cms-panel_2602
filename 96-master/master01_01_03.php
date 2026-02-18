<?php
/*
 * [96-master/master01_01_03.php]
 *  - 管理画面 -
 *  おすすめ商品登録／編集
 *
 * [初版]
 *  2026.2.18
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
#おすすめ商品情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_items.php';
#フォルダ情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_folders.php';
#写真情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_photos.php';

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'mKey01-01-03_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'master_login' && $key !== $noUpDateKey) {
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
#店舗ID（編集／削除時のみ）
$shopId = isset($_GET['shopId']) ? $_GET['shopId'] : null;
#shopId必須（店舗詳細は店舗に紐づくため）
if ($shopId === null || !is_numeric($shopId) || (int)$shopId <= 0) {
  header("Location: ./master01_01.php");
  exit;
}
#店舗IDがあればおすすめ商品情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #おすすめ商品情報
  $shopItemsData = getShopItemsData($shopId);
} else {
  #店舗情報
  $shopData = array(
    'shop_id' => '',
    'shop_name' => '',
  );
}
#新規／編集
$method = hasRecommendedItems($shopId) ? 'edit' : 'new';

#================#
# メニュータイトル
#----------------#
#メニュータイトル
$menuTitle = "おすすめ商品情報入力";
if (!isset($shopData) || empty($shopData)) {
  #店舗データが無い場合は不正アクセス：トップページへリダイレクト
  header("Location: ./master01_01.php");
  exit;
} else {
  $menuTitle = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
}

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$jsShopId = json_encode((string)$shopId, $jsonHex);
$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">
  <head>
    <meta charset="UTF-8">
    <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data: kurokawa-onsen.com; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
    <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
    <link rel="stylesheet" href="../assets/css/master01-01-03.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-01-01-03">
      <section class="container-left-menu menu-color01">
        <div class="title">店舗管理</div>
        <nav>
          <a href="./master01_01.php"><span>店舗一覧</span></a>
          <a href="./master01_02.php?method=new" class="is-active"><span>店舗登録</span></a>
        </nav>
      </section>
      <div class="main-contents menu-color01">
        <div class="block_inner">
          <h2>店舗情報(オススメ商品)</h2>
          <form name="inputForm" class="inputForm">
            <div class="head_title">{$menuTitle}</div>

HTML;
for ($slot = 1; $slot <= $recommendedItemMax; $slot++) {
  $titleNo = str_pad((string)$slot, 2, '0', STR_PAD_LEFT);
  $item = $shopItemsData['recommended'][$slot];

  $itemImagePathRaw = (string)($item['image_path'] ?? '');
  $itemImagePathEsc = htmlspecialchars($itemImagePathRaw, ENT_QUOTES, 'UTF-8');
  $itemTitleEsc = htmlspecialchars((string)($item['title'] ?? ''), ENT_QUOTES, 'UTF-8');
  $itemDescriptionEsc = htmlspecialchars((string)($item['description'] ?? ''), ENT_QUOTES, 'UTF-8');
  $itemPriceEsc = htmlspecialchars((string)($item['price_yen'] ?? ''), ENT_QUOTES, 'UTF-8');
  print <<<HTML
            <article>
              <input type="hidden" name="image_path{$slot}" value="{$itemImagePathEsc}">
              <h3>おすすめ商品{$titleNo}</h3>
              <dl>
                <div>
                  <dt class="required">商品タイトル</dt>
                  <dd>
                    <input type="text" name="item[recommended][$slot][title]" value="{$itemTitleEsc}">
                  </dd>
                </div>
                <div class="box_price">
                  <dt>価格(税込)</dt>
                  <dd>
                    <input type="number" name="item[recommended][$slot][price]" value="{$itemPriceEsc}" inputmode="numeric" min="0" step="10">
                    <span>円</span>
                  </dd>
                </div>
                <div class="box_comment">
                  <dt class="required">商品説明</dt>
                  <dd>
                    <textarea name="item[recommended][$slot][description]">{$itemDescriptionEsc}</textarea>
                  </dd>
                </div>
                <div class="box_image">
                  <dt class="required">メイン写真</dt>
                  <dd>

HTML;
  $mainImage = "";
  if ($item['image_path'] != "") {
    #画像サムネイル
    $mainImage = DOMAIN_NAME_PREVIEW . $item['image_path'];
  } else {
    #NG画像
    $mainImage =  "../assets/images/no-image.webp";
  }
  $mainImageEsc = htmlspecialchars((string)$mainImage, ENT_QUOTES, 'UTF-8');
  #JS用エスケープ
  $jsTargetImage = json_encode('image_path' . $slot, $jsonHex);
  $jsSelectAction = json_encode('selectFileModal', $jsonHex);
  $jsSelectType = json_encode('main', $jsonHex);
  $jsDeleteAction = json_encode('deleteFile', $jsonHex);
  print <<<HTML
                    <div class="check-details">
                      <div class="image">
                        <picture>
                          <source srcset="{$mainImageEsc}" id="ps_image_path{$slot}">
                          <img src="{$mainImageEsc}" id="pi_image_path{$slot}" alt="メイン画像">
                        </picture>
                      </div>
                      <div class="wrap_btn">
                        <div class="item_reload">
                          <button type="button" onclick='selectFileModal(this,{$jsSelectAction},{$jsSelectType},{$jsTargetImage},{$jsShopId},{$jsNoUpDateKey});'></button>
                        </div>
                        <div class="item_delate">
                          <button type="button" onclick='deleteFile(this,{$jsDeleteAction},{$jsSelectType},{$jsTargetImage},{$jsShopId},{$jsNoUpDateKey});'></button>
                        </div>
                      </div>
                    </div>
                  </dd>
                </div>
              </dl>
            </article>

HTML;
}

print <<<HTML
            <input type="hidden" name="action" value="sendInput">
            <input type="hidden" name="method" value="{$method}">
            <input type="hidden" name="shopId" value="{$shopId}">
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
            <div class="box-btn">
              <button type="button" class="btn-return" onclick="history.back()">戻る</button>
              <button type="button" class="btn-submit" onclick="sendInput();">登録</button>
            </div>
          </form>
          <a href="javascript:void(0)" class="link_page-back_top" onclick="history.back()">戻る</a>
          <a href="#" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!--NOTE  画像選択モーダル -->
    <article class="modal-select-image" id="modalSelectBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>画像セレクト</p>
          <button type="button" onclick="closeModal()" class="btn-top-close"></button>
        </div>
        <div class="inner-select-image">
          <div class="block_select-image">
            <section class="inner_left">
              <h4>フォルダ名</h4>
              <nav>
                <button type="button">
                  <h5></h5>
                  <span></span>
                </button>
              </nav>
            </section>
            <section class="inner_right">
              <form class="box_head">
                <h4></h4>
                <a href="#" class="item_edit"></a>
                <a href="#" class="item_delate"></a>
              </form>
              <ul><li></li></ul>
            </section>
          </div>
        </div>
      </div>
    </article>
    <!-- NOTE is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>紹介情報登録</p>
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
    <script src="../assets/js/form.js" defer></script>
    <script src="./assets/js/master01_01_03.js" defer></script>
  </body>
</html>

HTML;
