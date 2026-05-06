
<?php
/*
 * [96-client/client03_02.php]
 *  - 【加盟店】管理画面 -
 *  商品一覧
 *
 * [初版]
 *  2026.4.23
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
$searchConditionsSessionKey = 'searchConditions_client03_02';
$pagePrefix = 'cKey03-02_';
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
    'searchProduct' => '',
    'searchCategory' => '',
    'displayFlg' => '0',
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
$requiredKeys = ['searchProduct', 'searchCategory', 'displayFlg', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'searchProduct' => isset($searchConditions['searchProduct']) ? (string)$searchConditions['searchProduct'] : '',
      'searchCategory' => isset($searchConditions['searchCategory']) ? (string)$searchConditions['searchCategory'] : '',
      'displayFlg' => isset($searchConditions['displayFlg']) ? (string)$searchConditions['displayFlg'] : '',
      'displayNumber' => isset($searchConditions['displayNumber']) ? (int)$searchConditions['displayNumber'] : $initialDisplayNumber,
      'pageNumber' => isset($searchConditions['pageNumber']) ? (int)$searchConditions['pageNumber'] : 1
    );
    break;
  }
}
$searchConditions['displayFlg'] = ($searchConditions['displayFlg'] === '') ? '0' : (string)$searchConditions['displayFlg'];
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
#-------------#
#表示件数ページ・表示件数設定
$displayNumber = isset($searchConditions['displayNumber']) ? intval($searchConditions['displayNumber']) : $initialDisplayNumber;
$pageNumber = isset($searchConditions['pageNumber']) ? intval($searchConditions['pageNumber']) : 1;
if ($displayNumber < 1) {
  $displayNumber = $initialDisplayNumber;
}
#商品数取得：検索結果
$totalItems = countShopProductList($shopId, $searchConditions);
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
# 商品一覧
#---------#
$shopProductList = searchShopProductList($shopId, $searchConditions, $pageNumber, $displayNumber);

#============#
# カテゴリ一覧
#------------#
$itemCategoryList = getShopItemCategories($shopId);
$searchProductHtml = htmlspecialchars($searchConditions['searchProduct'] ?? '', ENT_QUOTES, 'UTF-8');
$selectedCategoryId = isset($searchConditions['searchCategory']) ? (string)$searchConditions['searchCategory'] : '';
$selectedCategoryName = '選択してください';
if (!empty($itemCategoryList)) {
  foreach ($itemCategoryList as $itemCategory) {
    if ((string)$itemCategory['category_id'] === $selectedCategoryId) {
      $selectedCategoryName = (string)$itemCategory['name'];
      break;
    }
  }
}
$selectedCategoryIdHtml = htmlspecialchars($selectedCategoryId, ENT_QUOTES, 'UTF-8');
$selectedCategoryNameHtml = htmlspecialchars($selectedCategoryName, ENT_QUOTES, 'UTF-8');
$categorySelectClass = ($selectedCategoryId !== '') ? ' is-selected' : '';
$totalItemsHtml = htmlspecialchars((string)$totalItems, ENT_QUOTES, 'UTF-8');
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(加盟店)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data: https://kurokawa-onsen.com; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/master03-02.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-03-02 status-client">
    <section class="container-left-menu menu-color03">
      <div class="title">EC販売管理</div>
      <nav>
        <a href="./client03_01.php" {$client03_01_active}><span>受注一覧</span></a>
        <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
        <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
        <a href="./client03_05.php" {$client03_05_active}><span>規格管理</span></a>
        <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
        <a href="#"><span>集計</span></a>
        <!-- <a href="#"><span>店舗登録</span></a> -->
      </nav>
    </section>
    <div class="main-contents menu-color03">
      <div class="block_inner">
        <h2>商品一覧</h2>
        <form name="searchForm" class="head_search_setting">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <h3>検索条件設定</h3>
          <dl>
            <div class="box-name">
              <dt>商品名・商品ID</dt>
              <dd>
                <input type="text" name="searchProduct" value="{$searchProductHtml}" placeholder="商品名・商品IDを入力">
              </dd>
            </div>
            <div class="box-category">
              <dt>カテゴリ</dt>
              <dd>
                <div class="select-search-category{$categorySelectClass}" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false" >
                    <input type="hidden" name="select-search-category" value="{$selectedCategoryIdHtml}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$selectedCategoryNameHtml}</span>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($itemCategoryList)) {
  foreach ($itemCategoryList as $itemCategory) {
    $itemCategoryID = htmlspecialchars($itemCategory['category_id'], ENT_QUOTES, 'UTF-8');
    $itemCategoryName = htmlspecialchars($itemCategory['name'], ENT_QUOTES, 'UTF-8');
    $categoryChecked = ((string)$itemCategory['category_id'] === $selectedCategoryId) ? ' checked' : '';
    print <<<HTML
                      <li>
                        <input type="radio" name="select-search-category" value="{$itemCategoryID}" id="searchCategory{$itemCategoryID}"{$categoryChecked}>
                        <label for="searchCategory{$itemCategoryID}">{$itemCategoryName}</label>
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
                  </div>
                </div>
              </dd>
            </div>
            <div class="box-status">
              <dt>公開状態</dt>
              <dd>

HTML;
#checked判定
$isAllChecked = ($searchConditions['displayFlg'] === '0') ? 'checked' : '';
$isPublicChecked = ($searchConditions['displayFlg'] === '1') ? 'checked' : '';
$isPrivateChecked = ($searchConditions['displayFlg'] === '2') ? 'checked' : '';
print <<<HTML
                <div>
                  <input type="radio" name="displayFlg" id="displayFlg_01" value="0" {$isAllChecked}>
                  <label for="displayFlg_01">全て</label>
                </div>
                <div>
                  <input type="radio" name="displayFlg" id="displayFlg_02" value="1" {$isPublicChecked}>
                  <label for="displayFlg_02">公開</label>
                </div>
                <div>
                  <input type="radio" name="displayFlg" id="displayFlg_03" value="2" {$isPrivateChecked}>
                  <label for="displayFlg_03">非公開</label>
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
          <div class="wrap_pager-block">
            <h3>検索結果<span>-<i id="search-result-count">{$totalItemsHtml}件</i>が該当</span></h3>
            <div class="select-display-number" data-selectbox>

HTML;
#表示件数格納用変数を初期化
$currentDisplayNumber = isset($displayNumber) ? $displayNumber : $initialDisplayNumber;
#表示数が選択されている場合
foreach ($displayNumberList as $displayNumber) {
  if ($displayNumber === (int)$searchConditions['displayNumber']) {
    $currentDisplayNumber = $displayNumber;
    break;
  }
}
print <<<HTML
              <button type="button" class="selectbox__head" aria-expanded="false">
                <input type="hidden" name="displayNumber" value="{$currentDisplayNumber}" data-selectbox-hidden>
                <span class="selectbox__value" data-selectbox-value>{$currentDisplayNumber}</span>
              </button>
              <div class="list-wrapper">
                <ul class="selectbox__panel">

HTML;
#表示件数選択リストループで差し込む
foreach ($displayNumberList as $number) {
  $checked = ($number === (int)$searchConditions['displayNumber']) ? ' checked' : '';
  print <<<HTML
                  <li>
                    <input type="radio" name="displayNumber" id="display{$number}" value="{$number}" {$checked} onchange="searchConditions('search')">
                    <label for="display{$number}">{$number}</label>
                  </li>

HTML;
}
print <<<HTML
                </ul>
              </div>
            </div>
          </div>
          <ul>
            <li>
              <div>ID</div>
              <div>画像</div>
              <div>商品名</div>
              <div>価格</div>
              <div>在庫数</div>
              <div>公開状態</div>
              <div>登録日</div>
              <div>更新日</div>
              <div>確認</div>
            </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopProductList)) {
  #inline JS用エスケープ宣言
  $jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
  foreach ($shopProductList as $product) {
    #店舗ID：ゼロ埋め3桁
    $productShopId = str_pad($product['shop_id'], 3, '0', STR_PAD_LEFT);
    #商品情報
    $productId = htmlspecialchars($product['product_id'], ENT_QUOTES, 'UTF-8');
    $productName = htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
    $editUrl = './client03_03.php?method=edit&productId=' . rawurlencode((string)$product['product_id']);
    $editUrlHtml = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
    $imageNoImagePath = '../assets/images/no-image.webp';
    $imageSrc = $imageNoImagePath;
    $storagePath = isset($product['main_image_storage_path']) ? trim((string)$product['main_image_storage_path']) : '';
    $storagePath = ltrim($storagePath, '/');
    if ($storagePath !== '' && strpos($storagePath, '..') === false) {
      $imageSrc = rtrim(DOMAIN_NAME, '/') . '/db/images/' . $storagePath;
    }
    $imageSrcHtml = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');
    $variantCount = isset($product['variant_count']) ? (int)$product['variant_count'] : 0;
    $isPriceStockUnset = ((int)$product['price'] === 0 && $product['stock'] === null);
    if ($variantCount > 0 && $isPriceStockUnset) {
      $productPriceWithTax = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
      $productStock = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
    } elseif ($variantCount > 0) {
      #税込価格
      #$taxRate = ($product['tax_rate'] / 100) ?? 0;
      #$productPriceWithTax = (int)round($product['price'] * (1 + $taxRate));
      $productPriceWithTax = (int)round($product['price']);
      $productPriceWithTax = htmlspecialchars(number_format($productPriceWithTax) . '～', ENT_QUOTES, 'UTF-8');
      $productStock = htmlspecialchars('規格確認', ENT_QUOTES, 'UTF-8');
    } elseif ($isPriceStockUnset) {
      $productPriceWithTax = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
      $productStock = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
    } else {
      #税込価格
      #$taxRate = ($product['tax_rate'] / 100) ?? 0;
      #$productPriceWithTax = (int)round($product['price'] * (1 + $taxRate));
      $productPriceWithTax = (int)round($product['price']);
      $productPriceWithTax = htmlspecialchars(number_format($productPriceWithTax), ENT_QUOTES, 'UTF-8');
      #在庫数
      if ((int)$product['stock_unlimited'] === 1) {
        $productStock = '無制限';
      } else {
        $productStock = ($product['stock'] === null) ? '-' : htmlspecialchars((string)$product['stock'], ENT_QUOTES, 'UTF-8');
      }
    }
    #公開状態
    $isPublic = ((int)$product['status'] === 1) ? 'is-active' : 'is-inactive';
    $statusLabel = ((int)$product['status'] === 1) ? '公開中' : '非公開';
    #登録日・更新日
    $createdAtYMD = date('Y/m/d', strtotime($product['created_at']));
    $createdAtTime = date('H:i', strtotime($product['created_at']));
    $createdAtHtml = htmlspecialchars($createdAtYMD, ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars($createdAtTime, ENT_QUOTES, 'UTF-8') . '</i>';
    $updatedAtYMD = date('Y/m/d', strtotime($product['updated_at']));
    $updatedAtTime = date('H:i', strtotime($product['updated_at']));
    $updatedAtHtml = htmlspecialchars($updatedAtYMD, ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars($updatedAtTime, ENT_QUOTES, 'UTF-8') . '</i>';
    #更新日が登録日と同じなら登録日のみ表示
    if ($createdAtYMD === $updatedAtYMD) {
      $updatedAtHtml = '-';
    }
    #inline JS用エスケープ（属性崩壊・注入対策）
    $productIdJs = json_encode($productId, $jsonHex);
    #サイト確認URL
    $websiteUrl = 'https://kurokawa-onsen.com/shops/' . $productShopId . '/products/' . $productIdJs;
    $websiteUrl = htmlspecialchars($websiteUrl, ENT_QUOTES, 'UTF-8');
    print <<<HTML
            <li>
              <div class="item-id">
                <span>{$productId}</span>
              </div>
              <div class="item-image">
                <picture>
                  <source src="{$imageSrcHtml}">
                  <img src="{$imageSrcHtml}" alt="{$productName}">
                </picture>
              </div>
              <div class="item-name">
                <a href="{$editUrlHtml}"></a>
                <span>{$productName}</span>
              </div>
              <div class="item-price">
                <span>{$productPriceWithTax}</span>
              </div>
              <div class="item-stock">
                <span>{$productStock}</span>
              </div>
              <div class="item-status">
                <span class="{$isPublic}">{$statusLabel}</span>
              </div>
              <div class="item-date">
                <span>{$createdAtHtml}</span>
              </div>
              <div class="item-date">
                <span>{$updatedAtHtml}</span>
              </div>
              <div class="item-check">
                <a href="{$websiteUrl}" target="_blank" data-tooltip="表示サイト確認" aria-label="表示サイト確認"></a>
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
        <p>条件に一致する商品はありません。</p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="./assets/js/client03_02.js" defer></script>
</body>

</html>

HTML;
