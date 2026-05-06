<?php
/*
 * [96-client/client03_03.php]
 *  - 【加盟店】管理画面 -
 *  商品登録
 *
 * [初版]
 *  2026.4.27
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
$pagePrefix = 'cKey03-03_';
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
if ($method === null || ($method !== 'new' && $method !== 'edit')) {
  #不正アクセス：トップページへリダイレクト
  header("Location: ./client03_02.php");
  exit;
}
#-------------#
#店舗ID（編集／削除時のみ）
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
#商品ID（編集／削除時のみ）
$productId = null;
if ($method === 'edit') {
  $productId = filter_input(INPUT_GET, 'productId', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
  ]);
  if (!$productId) {
    header("Location: ./client03_02.php");
    exit;
  }
}
$productData = array();
$productVariantCount = 0;
$productVariantDisplayList = array();
$productImages = array();
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
  #商品情報
  if ($method === 'edit') {
    if ($productId !== null) {
      $productData = getShopProductData_FindById($shopId, $productId);
      if (!$productData) {
        header("Location: ./client03_02.php");
        exit;
      }
      $productVariantList = getShopProductVariants($shopId, $productId);
      $productVariantCount = is_array($productVariantList) ? count($productVariantList) : 0;
      $productVariantDisplayList = getShopProductVariantDisplayList($shopId, $productId);
      if (!is_array($productVariantDisplayList)) {
        $productVariantDisplayList = array();
      }
      $productImages = getShopProductImages($shopId, $productId);
      if (!is_array($productImages)) {
        $productImages = array();
      }
    } else {
      #不正アクセス：ログインページへリダイレクト
      header("Location: ./logout.php");
      exit;
    }
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
# カテゴリ一覧
#------------#
$itemCategoryList = getShopItemCategories($shopId);

#================#
# メニュータイトル
#----------------#
#メニュータイトル
$menuTitle = "商品登録";
if ($method === 'new') {
  $menuTitle = "新規商品登録";
} elseif ($method === 'edit') {
  if (!isset($shopData) || empty($shopData)) {
    #店舗データが無い場合は不正アクセス：トップページへリダイレクト
    header("Location: ./client03_02.php");
    exit;
  } else {
    $menuTitle = "商品情報編集";
  }
}

#=======#
# 商品ID
#-------#
#新規登録時は最新のIDに+1した値を仮でセット
$nextProductId = 1;
if ($method === 'new') {
  $maxProductId = getShopMaxProductId($shopId);
  if ($maxProductId !== null && is_numeric($maxProductId) && (int)$maxProductId > 0) {
    $nextProductId = (int)$maxProductId + 1;
  }
}
#商品名
$productClassSettingUrl = './client03_03_01.php';
if ($method === 'edit' && $productId !== null) {
  $productClassSettingUrl = './client03_03_01.php?productId=' . rawurlencode((string)$productId);
}
$productClassSettingUrlHtml = htmlspecialchars($productClassSettingUrl, ENT_QUOTES, 'UTF-8');
$productName = isset($productData['name']) ? htmlspecialchars($productData['name'], ENT_QUOTES, 'UTF-8') : '';
#checked判定
#「公開」「非公開」
$productDisplayFlg01Checked = '';
$productDisplayFlg02Checked = '';
if ($method === 'new') {
  $productDisplayFlg01Checked = 'checked="checked"';
} else {
  $val = isset($productData['status']) ? (int)$productData['status'] : 1;
  $productDisplayFlg01Checked = ($val === 1) ? 'checked="checked"' : '';
  $productDisplayFlg02Checked = ($val !== 1) ? 'checked="checked"' : '';
}
#規格「使用する」「使用しない」
$specUsageFlg01Checked = '';
$specUsageFlg02Checked = '';
if ($method === 'new') {
  $specUsageFlg01Checked = 'checked="checked"';
} else {
  $specUsageFlg01Checked = ($productVariantCount === 0) ? 'checked="checked"' : '';
  $specUsageFlg02Checked = ($productVariantCount > 0) ? 'checked="checked"' : '';
}
#税率：「10%」「8%」
$taxRate10Checked = '';
$taxRate8Checked = '';
if ($method === 'new') {
  $taxRate10Checked = 'checked="checked"';
} else {
  $taxRate = isset($productData['tax_rate']) ? (int)$productData['tax_rate'] : 10;
  $taxRate10Checked = ($taxRate === 10) ? 'checked="checked"' : '';
  $taxRate8Checked = ($taxRate === 8) ? 'checked="checked"' : '';
}
#温度帯：「常温」「冷蔵」「冷凍」
$productTempType = isset($productData['temp_type']) ? $productData['temp_type'] : 'normal';
#販売価格
$salePrice = isset($productData['price']) ? htmlspecialchars($productData['price'], ENT_QUOTES, 'UTF-8') : '';
#在庫数
$stockQuantity = isset($productData['stock']) ? htmlspecialchars($productData['stock'], ENT_QUOTES, 'UTF-8') : '';
#在庫無制限チェック
$stockUnlimitedChecked = isset($productData['stock_unlimited']) && (int)$productData['stock_unlimited'] === 1 ? 'checked="checked"' : '';
#-------------#
if (!function_exists('buildProductImageDisplayName')) {
  /**
   * 商品画像の表示用ファイル名を生成
   */
  function buildProductImageDisplayName($fileName)
  {
    return preg_replace('/^(product_\d+_\d+)_\d{14}_[a-f0-9]+\.(jpg|jpeg|png|webp)$/i', '$1.$2', $fileName);
  }
}
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
    <link rel="stylesheet" href="../assets/css/master03-03.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-03-03 status-client">
      <section class="container-left-menu menu-color03">
        <div class="title">EC販売管理</div>
        <nav>
          <a href="./client03_01.php" {$client03_01_active}><span>受注一覧</span></a>
          <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
          <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
          <a href="./client03_05.php" {$client03_05_01_active}><span>規格管理</span></a>
          <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
          <a href="#"><span>集計</span></a>
          <!-- <a href="#"><span>店舗登録</span ></a> -->
        </nav>
      </section>
      <div class="main-contents menu-color03">
        <div class="block_inner">
          <h2>{$menuTitle}</h2>
          <form name="inputForm" class="inputForm">
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
            <input type="hidden" name="method" value="{$method}">
            <input type="hidden" name="shopId" value="{$shopId}">
            <article class="block-basic-info">
              <h3>基本情報</h3>
              <div class="box-date">

HTML;
#登録日あれば表示
if (isset($productData['created_at']) && $productData['created_at'] != '') {
  $createdAt = date('Y年m月d日', htmlspecialchars(strtotime($productData['created_at']), ENT_QUOTES, 'UTF-8'));
  print <<<HTML
                <div>
                  <h4>登録日</h4>
                  <span>{$createdAt}</span>
                </div>

HTML;
}
#更新日あれば表示
if (isset($productData['updated_at']) && $productData['updated_at'] != '') {
  $updatedAt = date('Y年m月d日', htmlspecialchars(strtotime($productData['updated_at']), ENT_QUOTES, 'UTF-8'));
  print <<<HTML
                <div>
                  <h4>更新日</h4>
                  <span>{$updatedAt}</span>
                </div>

HTML;
}
print <<<HTML
              </div>
              <dl>
                <div>
                  <dt>商品ID</dt>
                  <dd>

HTML;
if ($method === 'new') {
  print <<<HTML
                    <input type="hidden" name="productId" value="{$nextProductId}">
                    <span>{$nextProductId}</span>

HTML;
} else {
  $productId = isset($productData['product_id']) ? $productData['product_id'] : $productId;
  print <<<HTML
                    <input type="hidden" name="productId" value="{$productId}">
                    <span>{$productId}</span>

HTML;
}
print <<<HTML
                  </dd>
                </div>
                <div>
                  <dt>店舗名</dt>
                  <dd>{$headerShopName}</dd>
                </div>
                <div class="box-name">
                  <dt class="is-required">商品名</dt>
                  <dd>
                    <input type="text" name="productName" value="{$productName}" id="productName" class="required-item" placeholder="商品名を入力" required maxlength="255">
                  </dd>
                </div>
                <div class="box-category">
                  <dt>カテゴリ</dt>
                  <dd>
                    <div class="select-search-category" data-selectbox>
                      <button type="button" class="selectbox__head" aria-expanded="false">

HTML;
#編集モードでカテゴリが選択されていたら
if ($method === 'edit' && isset($productData['category_id']) && $productData['category_id'] != '') {
  #選択中のカテゴリ
  foreach ($itemCategoryList as $itemCategory) {
    if ($productData['category_id'] == $itemCategory['category_id']) {
      $itemCategoryID = htmlspecialchars($itemCategory['category_id'], ENT_QUOTES, 'UTF-8');
      $itemCategoryName = htmlspecialchars($itemCategory['name'], ENT_QUOTES, 'UTF-8');
      print <<<HTML
                        <input type="hidden" name="select-search-category" value="{$itemCategoryID}" data-selectbox-hidden>
                        <span class="selectbox__value" data-selectbox-value>{$itemCategoryName}</span>

HTML;
    }
  }
} else {
  print <<<HTML
                        <input type="hidden" name="select-search-category" value="" data-selectbox-hidden>
                        <span class="selectbox__value" data-selectbox-value>選択してください</span>

HTML;
}
print <<<HTML
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($itemCategoryList)) {
  foreach ($itemCategoryList as $itemCategory) {
    $itemCategoryID = htmlspecialchars($itemCategory['category_id'], ENT_QUOTES, 'UTF-8');
    $itemCategoryName = htmlspecialchars($itemCategory['name'], ENT_QUOTES, 'UTF-8');
    #checked判定
    $categoryChecked = $itemCategory['category_id'] == (isset($productData['category_id']) ? $productData['category_id'] : '') ? 'checked="checked"' : '';
    print <<<HTML
                          <li>
                            <input type="radio" name="select-search-category" value="{$itemCategoryID}" id="searchCategory{$itemCategoryID}" {$categoryChecked}>
                            <label for="searchCategory{$itemCategoryID}">{$itemCategoryName}</label>
                          </li>

HTML;
  }
} else {
  print <<<HTML
                          <li>
                            <input type="radio" name="select-search-category" value="1" id="searchCategory01">
                            <label for="searchCategory01">商品カテゴリが未設定です</label>
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
                    <div>
                      <input type="radio" name="productDisplayFlg" id="productDisplayFlg_01" value="1" {$productDisplayFlg01Checked}>
                      <label for="productDisplayFlg_01">公開</label>
                    </div>
                    <div>
                      <input type="radio" name="productDisplayFlg" id="productDisplayFlg_02" value="2" {$productDisplayFlg02Checked}>
                      <label for="productDisplayFlg_02">非公開</label>
                    </div>
                  </dd>
                </div>
              </dl>
            </article>
            <article class="block-image">
              <h3>商品画像</h3>
              <div class="block-inner">
                <div class="select-image" id="js-dragDrop-productImage">
                  <h4>画像をここにドラッグ＆ドロップ</h4>
                  <span>または</span>
                  <input type="file" name="images_tmp" id="js-fileElem-productImage" multiple accept="image/*" style="display:none">
                  <input type="hidden" name="upload_image_mode" value="multiple" id="js-uploadImageMode-productImage">
                  <input type="hidden" name="upload_image_area" value="product_image" id="js-uploadImageArea-productImage">
                  <input type="hidden" name="up_image_area[]" value="product_image">
                  <input type="hidden" name="send_php" value="proc_client03_03.php">
                  <input type="hidden" name="jobId" value="{$productId}">
                  <button type="button" id="js-fileSelect-productImage">＋ファイルを選択</button>
                  <span>JPG / PNG / WebP｜最大5MB｜最大8枚</span>
                  <!-- NOTE 警告用表示 -->
                  <div class="wrap-caution" id="js-fileError-productImage" style="display: none;">
                    <h5>ファイルサイズが大きすぎます</h5>
                    <p>画像の容量を圧縮してしてから再度アップロードしてください。</p>
                  </div>
                </div>
                <ul class="selected-image-list" id="js-previewBlock-productImage">

HTML;
#登録画像リスト表示
if (isset($productImages) && is_array($productImages) && count($productImages) > 0) {
  #登録画像リスト展開
  foreach ($productImages as $info) {
    $storagePath = isset($info['storage_path']) ? trim((string)$info['storage_path']) : '';
    $storagePath = ltrim($storagePath, '/');
    if ($storagePath === '' || strpos($storagePath, '..') !== false) {
      continue;
    }
    $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
    switch ($ext) {
      case 'jpg':
      case 'jpeg':
        $mimeType = 'image/jpeg';
        break;
      case 'png':
        $mimeType = 'image/png';
        break;
      case 'webp':
        $mimeType = 'image/webp';
        break;
      default:
        $mimeType = '';
        break;
    }
    $previewPath = rtrim(DOMAIN_NAME, '/') . '/db/images/' . $storagePath;
    $previewPathHtml = htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8');
    $mimeTypeHtml = htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8');
    $realFileName = basename($storagePath);
    $displayFileName = buildProductImageDisplayName($realFileName);
    $realFileNameHtml = htmlspecialchars($realFileName, ENT_QUOTES, 'UTF-8');
    $displayFileNameHtml = htmlspecialchars($displayFileName, ENT_QUOTES, 'UTF-8');
    print <<<HTML
                  <li data-name="{$displayFileNameHtml}" data-file-name="{$realFileNameHtml}">
                    <button type="button" class="btn-delate"></button>
                    <picture>
                      <source src="{$previewPathHtml}" type="{$mimeTypeHtml}">
                      <img src="{$previewPathHtml}" alt="商品画像">
                    </picture>
                  </li>

HTML;
  }
}
#商品詳細情報
$productDescription = isset($productData['description']) ? htmlspecialchars($productData['description'], ENT_QUOTES, 'UTF-8') : '';
$sizeLength = isset($productData['length_cm']) ? htmlspecialchars($productData['length_cm'], ENT_QUOTES, 'UTF-8') : '';
$sizeWidth = isset($productData['width_cm']) ? htmlspecialchars($productData['width_cm'], ENT_QUOTES, 'UTF-8') : '';
$sizeHeight = isset($productData['height_cm']) ? htmlspecialchars($productData['height_cm'], ENT_QUOTES, 'UTF-8') : '';
$weight = isset($productData['weight_g']) ? htmlspecialchars($productData['weight_g'], ENT_QUOTES, 'UTF-8') : '';
print <<<HTML
                </ul>
              </div>
            </article>
            <article class="block-description">
              <h3>商品説明</h3>
              <div class="block-inner">
                <textarea name="productDescription" id="productDescription">{$productDescription}</textarea>
              </div>
            </article>
            <article class="block-spec">
              <h3>商品仕様</h3>
              <dl>
                <div class="box-size">
                  <dt class="is-required">サイズ</dt>
                  <dd>
                    <div>
                      <h4>縦</h4>
                      <input type="text" name="sizeLength" value="{$sizeLength}" id="sizeLength" class="required-item" required><span>cm</span>
                    </div>
                    <div>
                      <h4>横</h4>
                      <input type="text" name="sizeWidth" value="{$sizeWidth}" id="sizeWidth" class="required-item" required><span>cm</span>
                    </div>
                    <div>
                      <h4>高さ</h4>
                      <input type="text" name="sizeHeight" value="{$sizeHeight}" id="sizeHeight" class="required-item" required><span>cm</span>
                    </div>
                  </dd>
                </div>
                <div class="box-weight">
                  <dt class="is-required">重さ</dt>
                  <dd>
                    <input type="text" name="weight" value="{$weight}" id="weight" class="required-item" required><span>g</span>
                  </dd>
                </div>
                <div class="box-temp">
                  <dt>温度帯</dt>
                  <dd>

HTML;
#商品温度帯設定ループ
foreach ($temperatureZoneList as $temperatureZone) {
  $temperatureZoneId = htmlspecialchars($temperatureZone['id'], ENT_QUOTES, 'UTF-8');
  $temperatureZoneName = htmlspecialchars($temperatureZone['name'], ENT_QUOTES, 'UTF-8');
  #checked判定
  $temperatureZoneChecked = ($productTempType === $temperatureZone['id']) ? 'checked="checked"' : '';
  print <<<HTML
                    <div>
                      <input type="radio" name="temperatureZone" id="temperatureZone_{$temperatureZoneId}" value="{$temperatureZoneId}" {$temperatureZoneChecked}>
                      <label for="temperatureZone_{$temperatureZoneId}">{$temperatureZoneName}</label>
                    </div>

HTML;
}
print <<<HTML
                  </dd>
                </div>
              </dl>
            </article>
            <article class="block-price">
              <h3>価格・在庫</h3>

HTML;
print <<<HTML
              <dl>
                <div class="box-radio">
                  <dt>商品規格</dt>
                  <dd>
                    <div>
                      <input type="radio" name="specUsageFlg_01" id="specUsageFlg_01_01" value="1" {$specUsageFlg01Checked}>
                      <label for="specUsageFlg_01_01">使用しない</label>
                    </div>
                    <div>
                      <input type="radio" name="specUsageFlg_01" id="specUsageFlg_01_02" value="2" {$specUsageFlg02Checked}>
                      <label for="specUsageFlg_01_02" >使用する(規格ごとに設定)</label>
                    </div>
                  </dd>
                </div>
                <div class="box-radio">
                  <dt>税区分</dt>
                  <dd>
                    <div>
                      <input type="radio" name="taxRateFlg" id="taxRateFlg_01_01" value="10" {$taxRate10Checked}>
                      <label for="taxRateFlg_01_01"> 10%（標準税率） </label>
                    </div>
                    <div>
                      <input type="radio" name="taxRateFlg" id="taxRateFlg_01_02" value="8" {$taxRate8Checked}>
                      <label for="taxRateFlg_01_02"> 8%（軽減税率）</label>
                    </div>
                  </dd>
                </div>
                <div class="box-price">
                  <dt class="is-required">販売価格</dt>
                  <dd>
                    <input type="text" id="salePrice" name="salePrice" value="{$salePrice}" ><span>円</span>
                  </dd>
                </div>
                <div class="box-stock">
                  <dt class="is-required">在庫数</dt>
                  <dd>
                    <div>
                      <input type="text" id="stockQuantity" name="stockQuantity" value="{$stockQuantity}"><span>個</span>
                    </div>
                    <div>
                      <input type="checkbox" name="stockUnlimited" id="stockUnlimited" value="1" {$stockUnlimitedChecked}>
                      <label for="stockUnlimited"> 無制限</label>
                    </div>
                  </dd>
                </div>
                <div class="box-lock">
                  <dt>販売価格</dt>
                  <dd>商品規格で設定します</dd>
                </div>
                <div class="box-lock">
                  <dt>在庫数</dt>
                  <dd>商品規格で設定します</dd>
                </div>
              </dl>
            </article>
            <article class="block-variant">
              <h3>商品規格</h3>
              <ul>
                <li>
                  <div>規格1</div>
                  <div>規格2</div>
                  <div style="align-items: center">在庫数</div>
                  <div style="align-items: center">価格</div>
                </li>
HTML;
if (!empty($productVariantDisplayList)) {
  foreach ($productVariantDisplayList as $variant) {
    $classCategoryName1 = htmlspecialchars(isset($variant['class_category_name1']) ? $variant['class_category_name1'] : '', ENT_QUOTES, 'UTF-8');
    $classCategoryName2 = isset($variant['class_category_name2']) && $variant['class_category_name2'] !== '' ? $variant['class_category_name2'] : '-';
    $classCategoryName2 = htmlspecialchars($classCategoryName2, ENT_QUOTES, 'UTF-8');
    if (isset($variant['stock_unlimited']) && (int)$variant['stock_unlimited'] === 1) {
      $stockText = '無制限';
    } elseif (array_key_exists('stock', $variant) && $variant['stock'] !== null) {
      $stockText = (string)$variant['stock'];
    } else {
      $stockText = '未設定';
    }
    $stockTextHtml = htmlspecialchars($stockText, ENT_QUOTES, 'UTF-8');
    $price = isset($variant['price']) ? (int)$variant['price'] : 0;
    $priceText = ($price > 0) ? number_format($price) : '未設定';
    $priceTextHtml = htmlspecialchars($priceText, ENT_QUOTES, 'UTF-8');
    print <<<HTML
                <li>
                  <div class="item-name">
                    <span>{$classCategoryName1}</span>
                  </div>
                  <div class="item-name">
                    <span>{$classCategoryName2}</span>
                  </div>
                  <div class="item-stock">
                    <span>{$stockTextHtml}</span>
                  </div>
                  <div class="item-price">
                    <span>{$priceTextHtml}</span>
                  </div>
                </li>

HTML;
  }
} else {
  print <<<HTML
                <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">
                  <div>設定済みの商品規格はありません。</div>
                </li>

HTML;
}
print <<<HTML
              </ul>
              <a href="{$productClassSettingUrlHtml}" onclick="goToProductClassSetting(event);">この商品の規格を確認</a>
            </article>
            <div class="box-btn">
              <button type="button" class="btn-cancel" onclick="location.href='./client03_02.php';">商品一覧</button>
              <button type="button" class="btn-confirmed" onclick="sendInput();">保存する</button>
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
          <p>商品情報</p>
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
    <script src="../assets/js/dropZone.js" defer></script>
    <script src="./assets/js/client03_03.js" defer></script>
    <script>
    //複数アップロードエリア対応：ID命名規則に従い全領域を初期化
    document.addEventListener('DOMContentLoaded', function() {
      //対象となるアップロードエリアのIDリスト：areaIdsを増やすことで複数アップロードエリア対応可能
      const areaIds = ['productImage'];
      areaIds.forEach(function(area) {
        let drop = document.getElementById('js-dragDrop-' + area);
        let btn = document.getElementById('js-fileSelect-' + area);
        let input = document.getElementById('js-fileElem-' + area);
        let inputMode = document.getElementById('js-uploadImageMode-' + area);
        let inputArea = document.getElementById('js-uploadImageArea-' + area);
        let preview = document.getElementById('js-previewBlock-' + area);
        let error = document.getElementById('js-fileError-' + area);
        // 1枚登録モード時はドラッグ＆ドロップエリアを非表示
        if (inputMode && inputMode.value === 'only' && drop) {
            const liCount = preview.querySelectorAll('li').length;
            if (liCount >= 1) {
              drop.classList.add('is-active');
            } else {
              drop.classList.remove('is-active');
            }
        }
        if (drop && btn && input && preview) {
          initDropZone({
            dropZone: drop,
            selectFileButton: btn,
            fileInput: input,
            inputMode: inputMode,
            inputArea: inputArea,
            previewBlock: preview,
            fileError: error
          });
        }
      });
    });
    </script>
  </body>
</html>

HTML;
