<?php
/*
 * [96-client/client03_03_01.php]
 *  - 【加盟店】管理画面 -
 *  商品規格設定
 *
 * [初版]
 *  2026.4.28
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
#***** 定数・関数宣言ファイル：インクルード *****#
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
$pagePrefix = 'cKey03-03_01_';
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
#店舗ID（編集／削除時のみ）
#shopId取得
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
#productIdバリデーション強化
$productId = filter_input(INPUT_GET, 'productId', FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
if (!$productId) {
  header('Location: ./client03_03.php');
  exit;
}
$productData = array();
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
  #商品情報
  if ($productId !== null) {
    $productData = getShopProductData_FindById($shopId, $productId);
    if (empty($productData)) {
      header('Location: ./client03_03.php');
      exit;
    }
  } else {
    #不正アクセス：ログインページへリダイレクト
    header("Location: ./logout.php");
    exit;
  }
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

#============#
# 規格設定一覧
#------------#
# 規格一覧取得（画面表示用）
$specificationList = getShopItemSpecifications($shopId);
if (!is_array($specificationList)) {
  $specificationList = array();
}
# 既存バリアント一覧取得（編集表示用）
$productVariantList = getShopProductVariants($shopId, $productId);
if (!is_array($productVariantList)) {
  $productVariantList = array();
}
$productVariantDisplayList = getShopProductVariantDisplayList($shopId, $productId);
if (!is_array($productVariantDisplayList)) {
  $productVariantDisplayList = array();
}
$selectedSpec1Id = 0;
$selectedSpec2Id = 0;
$selectedSpec1Name = '規格1を選択してください';
$selectedSpec2Name = '規格2を選択してください';
if (!empty($productVariantDisplayList)) {
  $firstVariant = $productVariantDisplayList[0];
  $selectedSpec1Id = isset($firstVariant['specification_id1']) ? (int)$firstVariant['specification_id1'] : 0;
  $selectedSpec2Id = isset($firstVariant['specification_id2']) ? (int)$firstVariant['specification_id2'] : 0;
  foreach ($specificationList as $spec) {
    $specId = isset($spec['specification_id']) ? (int)$spec['specification_id'] : 0;
    if ($specId === $selectedSpec1Id) {
      $selectedSpec1Name = isset($spec['name']) ? $spec['name'] : $selectedSpec1Name;
    }
    if ($specId === $selectedSpec2Id) {
      $selectedSpec2Name = isset($spec['name']) ? $spec['name'] : $selectedSpec2Name;
    }
  }
}
# 表示用エスケープ変数
$productNameHtml = htmlspecialchars(isset($productData['name']) ? $productData['name'] : '', ENT_QUOTES, 'UTF-8');
$productIdHtml = htmlspecialchars((string)$productId, ENT_QUOTES, 'UTF-8');
$selectedSpec1IdHtml = htmlspecialchars((string)$selectedSpec1Id, ENT_QUOTES, 'UTF-8');
$selectedSpec2IdValue = ($selectedSpec2Id > 0) ? (string)$selectedSpec2Id : '';
$selectedSpec2IdHtml = htmlspecialchars($selectedSpec2IdValue, ENT_QUOTES, 'UTF-8');
$selectedSpec1NameHtml = htmlspecialchars($selectedSpec1Name, ENT_QUOTES, 'UTF-8');
$selectedSpec2NameHtml = htmlspecialchars($selectedSpec2Name, ENT_QUOTES, 'UTF-8');
$autoBuildVariants = !empty($productVariantDisplayList) ? 1 : 0;
$autoBuildVariantsHtml = htmlspecialchars((string)$autoBuildVariants, ENT_QUOTES, 'UTF-8');
$variantCount = count($productVariantList);

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

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
    <link rel="stylesheet" href="../assets/css/master03-03.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-03-03-01 status-client">
      <section class="container-left-menu menu-color03">
        <div class="title">EC販売管理</div>
        <nav>
          <a href="#"><span>受注一覧</span></a>
          <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
          <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
          <a href="./client03_05.php" {$client03_05_01_active}><span>規格管理</span></a>
          <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
          <a href="#"><span>集計</span></a>
        </nav>
      </section>
      <div class="main-contents menu-color03">
        <div class="block_inner">
          <h2>商品規格登録</h2>
          <form name="blockHeadForm" class="block-head">
            <h3>商品規格</h3>
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
            <input type="hidden" name="productId" value="{$productIdHtml}">
            <input type="hidden" name="autoBuildVariants" value="{$autoBuildVariantsHtml}">
            <div class="inner-top">
              <span class="item-name">{$productNameHtml}</span>
              <button type="button" onclick="buildProductVariants('buildVariants');">商品規格の設定</button>
            </div>
            <ul class="variable-list">
              <li class="list-item">
                <div class="select-variable01" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="selectVariable01" value="{$selectedSpec1IdHtml}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$selectedSpec1NameHtml}</span>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($specificationList)) {
  #選択中の規格情報
  foreach ($specificationList as $specKey => $spec) {
    #ユニークキー生成(ゼロ埋め3桁)
    $spec01Index = sprintf('%03d', $specKey + 1);
    $specId = isset($spec['specification_id']) ? (int)$spec['specification_id'] : 0;
    $checked = ($specId === $selectedSpec1Id) ? ' checked' : '';
    $specName = htmlspecialchars(isset($spec['name']) ? $spec['name'] : '', ENT_QUOTES, 'UTF-8');
    print <<<HTML
                      <li>
                        <input type="radio" name="selectVariable01" value="{$specId}" id="selectVariable01Option{$spec01Index}"{$checked}>
                        <label for="selectVariable01Option{$spec01Index}">{$specName}</label>
                      </li>

HTML;
  }
}
print <<<HTML
                    </ul>
                  </div>
                </div>
              </li>
              <li class="list-item">
                <div class="select-variable01" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="selectVariable02" value="{$selectedSpec2IdHtml}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$selectedSpec2NameHtml}</span>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($specificationList)) {
  #選択中の規格情報
  foreach ($specificationList as $specKey => $spec) {
    #ユニークキー生成(ゼロ埋め3桁)
    $spec02Index = sprintf('%03d', $specKey + 1);
    $specId = isset($spec['specification_id']) ? (int)$spec['specification_id'] : 0;
    $checked = ($specId === $selectedSpec2Id) ? ' checked' : '';
    $specName = htmlspecialchars(isset($spec['name']) ? $spec['name'] : '', ENT_QUOTES, 'UTF-8');
    print <<<HTML
                      <li>
                        <input type="radio" name="selectVariable02" value="{$specId}" id="selectVariable02Option{$spec02Index}"{$checked}>
                        <label for="selectVariable02Option{$spec02Index}">{$specName}</label>
                      </li>

HTML;
  }
}
print <<<HTML
                    </ul>
                  </div>
                </div>
              </li>
            </ul>
          </form>
          <form class="block-details" name="blockDetailsForm">
            <input type="hidden" name="productId" value="{$productIdHtml}">
            <input type="hidden" name="spec1Id" id="hidden-spec1Id" value="">
            <input type="hidden" name="spec2Id" id="hidden-spec2Id" value="">
            <div class="inner-top">
              <p><span id="variant-count">0</span>件の組み合わせがあります。</p>
              <button type="button" id="copy-first-row-btn"><span>1行目を全ての行に複製</span></button>
            </div>
            <ul id="variant-table-body">
            </ul>
            <div class="box-btn">
              <button type="button" class="btn-confirmed" onclick="sendInput();">登録</button>
            </div>
          </form>
          <a href="javascript:void(0);" class="link_page-back_top" onclick="location.href='./client03_03.php?method=edit&productId={$productIdHtml}';">戻る</a>
          <a href="javascript:void(0);" class="link_page-back_bottom" onclick="location.href='./client03_03.php?method=edit&productId={$productIdHtml}';">戻る</a>
          <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>商品規格</p>
          <button type="button" onclick="closeModal()" class="btn-top-close"></button>
        </div>
        <div class="box-details">
          <p></p>
          <div class="box-btn">
            <button type="button" class="btn-cancel">キャンセル</button>
            <button type="button" class="btn-confirm">はい</button>
          </div>
        </div>
      </div>
    </article>
    <script src="../assets/js/common.js" defer></script>
    <script src="../assets/js/modal.js" defer></script>
    <script src="../assets/js/form.js" defer></script>
    <script src="./assets/js/client03_03_01.js" defer></script>
  </body>
</html>

HTML;
