<?php
/*
 * [96-client/assets/function/proc_client03_03.php]
 *  - 【加盟店】管理画面 -
 *  商品登録／編集 処理
 *
 * [初版]
 *  2026.4.27
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** JSON出力ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeProductJson.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeShopJson.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ EC-CUBE API 共通クライアント ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/api/eccube/eccube_api.php';
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
# 応答用タグ初期化
#----------------#
$makeTag = array(
  'tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
);

#=============#
# POSTチェック
#-------------#
#セッションキー（画面インスタンス識別）
$noUpDateKey = isset($_POST['noUpDateKey']) ? (string)$_POST['noUpDateKey'] : '';
$currentNoUpDateKey = isset($_SESSION['sKey']) ? (string)$_SESSION['sKey'] : '';
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
  if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
    $noUpDateKey = $currentNoUpDateKey;
  } else {
    header('Content-Type: application/json; charset=UTF-8');
    $makeTag['status'] = 'error';
    $makeTag['title'] = 'セッションエラー';
    $makeTag['msg'] = 'セッションが切れました。<br>ページを再読み込みしてください。';
    echo json_encode($makeTag);
    exit;
  }
}
#-------------#
#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : null;
#確認／修正／登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#店舗ID
$shopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId <= 0) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。<br>再ログインしてください。';
  echo json_encode($makeTag);
  exit;
}
$shopId = (int)$shopId;
#-------------#
#商品ID
$productIdRaw = isset($_POST['productId']) ? trim((string)$_POST['productId']) : '';
$productId = null;
#商品名
$productName = isset($_POST['productName']) ? trim($_POST['productName']) : null;
#カテゴリ
$categoryIdRaw = isset($_POST['select-search-category']) ? trim((string)$_POST['select-search-category']) : '';
$categoryId = null;
#公開状態
$displayFlgRaw = isset($_POST['productDisplayFlg']) ? trim((string)$_POST['productDisplayFlg']) : '1';
$displayFlg = 1;
#規格
$specUsageFlgRaw = isset($_POST['specUsageFlg_01']) ? trim((string)$_POST['specUsageFlg_01']) : '1';
$specUsageFlg = 1;
#税区分
$taxRateRaw = '10';
if (isset($_POST['taxRateFlg_01'])) {
  $taxRateRaw = trim((string)$_POST['taxRateFlg_01']);
} elseif (isset($_POST['taxRateFlg'])) {
  $taxRateRaw = trim((string)$_POST['taxRateFlg']);
}
$taxRate = 10;
#価格
$salePriceRaw = isset($_POST['salePrice']) ? trim((string)$_POST['salePrice']) : '';
$salePrice = null;
#在庫数
$stockQuantityRaw = isset($_POST['stockQuantity']) ? trim((string)$_POST['stockQuantity']) : '';
$stockQuantity = null;
$stockUnlimitedRaw = isset($_POST['stockUnlimited']) ? trim((string)$_POST['stockUnlimited']) : '0';
$stockUnlimited = 0;
#温度帯
$tempType = isset($_POST['temperatureZone']) ? trim($_POST['temperatureZone']) : null;
#商品説明
$description = isset($_POST['productDescription']) ? trim($_POST['productDescription']) : '';
#商品仕様
$sizeLengthRaw = isset($_POST['sizeLength']) ? trim((string)$_POST['sizeLength']) : '';
$sizeWidthRaw = isset($_POST['sizeWidth']) ? trim((string)$_POST['sizeWidth']) : '';
$sizeHeightRaw = isset($_POST['sizeHeight']) ? trim((string)$_POST['sizeHeight']) : '';
$weightGRaw = isset($_POST['weight']) ? trim((string)$_POST['weight']) : '';
$sizeLength = null;
$sizeWidth = null;
$sizeHeight = null;
$weightG = null;
#-------------#
#画像アップロード先（セッション領域）
if (isset($_POST['up_image_area'])) {
  $imageUploadSessionKeys = $_POST['up_image_area'];
  if (!is_array($imageUploadSessionKeys)) {
    $imageUploadSessionKeys = array($imageUploadSessionKeys);
  }
} else {
  $imageUploadSessionKeys = array();
}
$targetImageUploadSessionKey = $imageUploadSessionKeys[0] ?? '';

#============#
# カテゴリ一覧
#------------#
$itemCategoryList = getShopItemCategories($shopId);

/*
 * [EC-CUBE GraphQL 文字列エスケープ]
 */
function buildEccubeGraphqlString($value)
{
  $encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    throw new Exception('EC-CUBE連携用の文字列変換に失敗しました。');
  }
  return $encoded;
}
/*
 * [EC-CUBE商品連携] 税込価格から税抜価格へ変換
 */
if (!function_exists('convertTaxIncludedToExcludedPrice')) {
  function convertTaxIncludedToExcludedPrice($priceIncludingTax, $taxRate)
  {
    $priceIncludingTax = (int)$priceIncludingTax;
    $taxRate = (int)$taxRate;

    if ($priceIncludingTax < 1) {
      return 0;
    }

    if ($taxRate <= 0) {
      return $priceIncludingTax;
    }

    return (int)round($priceIncludingTax / (1 + ($taxRate / 100)));
  }
}
/*
 * [EC-CUBE連携warning応答設定]
 */
function appendEccubeWarningMessage(&$makeTag)
{
  $makeTag['status'] = 'warning';
  if (strpos((string)$makeTag['msg'], 'EC-CUBE連携に失敗しました。') === false) {
    $makeTag['msg'] .= '<br>EC-CUBE連携に失敗しました。';
  }
}
/*
 * [EC-CUBE商品連携] GraphQL引数組み立て
 */
function buildEccubeProductMutationArgs($args)
{
  return implode("\n    ", $args);
}
/*
 * [EC-CUBE商品連携] shop_products EC-CUBE連携情報更新
 */
function updateShopProductEccubeInfo($shopId, $productId, $eccubeProductId = null, $eccubeProductClassCode = null)
{
  global $DB_CONNECT;
  $dbFiledData = array();
  if ($eccubeProductId !== null) {
    $dbFiledData['eccube_product_id'] = array(':eccube_product_id', (int)$eccubeProductId, 1);
  }
  if ($eccubeProductClassCode !== null) {
    $dbFiledData['eccube_product_class_code'] = array(':eccube_product_class_code', $eccubeProductClassCode, 0);
  }
  $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
  $dbFiledValue = array();
  $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
  $dbFiledValue['product_id'] = array(':product_id', $productId, 1);
  return SQL_Process($DB_CONNECT, 'shop_products', $dbFiledData, $dbFiledValue, 2, 2);
}
/*
 * [EC-CUBE商品連携] 共通商品引数セット
 */
function buildEccubeProductBaseArgs($productName, $eccubeSaleTypeId, $eccubeStatusId, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $categoryId)
{
  $args = array();
  $args[] = 'name: ' . buildEccubeGraphqlString($productName);
  $args[] = 'sale_type_id: ' . (int)$eccubeSaleTypeId;
  $args[] = 'status_id: ' . (int)$eccubeStatusId;
  $args[] = 'tax_rate: ' . (int)$taxRate;
  if ($tempType !== null && $tempType !== '') {
    $args[] = 'temp_type: ' . buildEccubeGraphqlString($tempType);
  }
  if ($sizeLength !== null) {
    $args[] = 'length_cm: ' . (int)$sizeLength;
  }
  if ($sizeWidth !== null) {
    $args[] = 'width_cm: ' . (int)$sizeWidth;
  }
  if ($sizeHeight !== null) {
    $args[] = 'height_cm: ' . (int)$sizeHeight;
  }
  if ($weightG !== null) {
    $args[] = 'weight_g: ' . (int)$weightG;
  }
  $args[] = 'description: ' . buildEccubeGraphqlString($description);
  if ($categoryId !== null) {
    $args[] = 'category_id: ' . (int)$categoryId;
  }
  return $args;
}
/*
 * [EC-CUBE商品連携] 商品基本情報連携
 */
function syncEccubeProductBasicInfo(&$makeTag, $method, $specUsageFlg, $shopId, $productId, $productName, $displayFlg, $salePrice, $stockQuantity, $stockUnlimited, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $categoryId)
{
  if ($method === 'new' && $specUsageFlg === 2) {
    return;
  }
  $shopData = getShops_FindById($shopId);
  $eccubeSaleTypeId = isset($shopData['eccube_sale_type_id']) ? (int)$shopData['eccube_sale_type_id'] : 0;
  if ($eccubeSaleTypeId < 1) {
    appendEccubeWarningMessage($makeTag);
    return;
  }
  $eccubeStatusId = ($displayFlg === 1) ? 1 : 2;
  $eccubeCategoryId = null;
  if ($categoryId !== null && (int)$categoryId > 0) {
    $categoryData = getShopItemCategoryDetails($shopId, $categoryId);
    $eccubeCategoryId = isset($categoryData['eccube_category_id']) ? (int)$categoryData['eccube_category_id'] : null;
    if ($eccubeCategoryId < 1) {
      appendEccubeWarningMessage($makeTag);
      return;
    }
  }
  try {
    if ($method === 'new' && $specUsageFlg === 1) {
      $eccubeProductClassCode = 'S' . $shopId . 'P' . $productId;
      $args = buildEccubeProductBaseArgs($productName, $eccubeSaleTypeId, $eccubeStatusId, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $eccubeCategoryId);
      $args[] = 'code: ' . buildEccubeGraphqlString($eccubeProductClassCode);
      $priceExcludingTax = convertTaxIncludedToExcludedPrice($salePrice, $taxRate);
      $args[] = 'price02: ' . (int)$priceExcludingTax;
      if ($stockUnlimited === 1) {
        $args[] = 'stock_unlimited: true';
      } else {
        $args[] = 'stock: ' . (int)$stockQuantity;
      }
      $query = "mutation {\n  CreateProductMutation(\n    " . buildEccubeProductMutationArgs($args) . "\n  ) {\n    id\n  }\n}";
      $result = eccube_api_call($query);
      $eccubeProductId = $result['CreateProductMutation']['id'] ?? null;
      if ($eccubeProductId === null || (int)$eccubeProductId < 1) {
        appendEccubeWarningMessage($makeTag);
        return;
      }
      if (updateShopProductEccubeInfo($shopId, $productId, (int)$eccubeProductId, $eccubeProductClassCode) != 1) {
        appendEccubeWarningMessage($makeTag);
      }
      return;
    }
    $currentProductData = getShopProductData_FindById($shopId, $productId);
    if (empty($currentProductData)) {
      appendEccubeWarningMessage($makeTag);
      return;
    }
    $args = buildEccubeProductBaseArgs($productName, $eccubeSaleTypeId, $eccubeStatusId, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $eccubeCategoryId);
    if ($specUsageFlg === 1) {
      $eccubeProductClassCode = isset($currentProductData['eccube_product_class_code']) ? trim((string)$currentProductData['eccube_product_class_code']) : '';
      $eccubeProductId = isset($currentProductData['eccube_product_id']) ? (int)$currentProductData['eccube_product_id'] : 0;
      if ($eccubeProductClassCode !== '') {
        array_unshift($args, 'lookup_code: ' . buildEccubeGraphqlString($eccubeProductClassCode));
      } elseif ($eccubeProductId > 0) {
        array_unshift($args, 'product_id: ' . $eccubeProductId);
      } else {
        appendEccubeWarningMessage($makeTag);
        return;
      }
      $priceExcludingTax = convertTaxIncludedToExcludedPrice($salePrice, $taxRate);
      $args[] = 'price02: ' . (int)$priceExcludingTax;
      if ($stockUnlimited === 1) {
        $args[] = 'stock_unlimited: true';
      } else {
        $args[] = 'stock: ' . (int)$stockQuantity;
      }
    } else {
      $eccubeProductId = isset($currentProductData['eccube_product_id']) ? (int)$currentProductData['eccube_product_id'] : 0;
      if ($eccubeProductId < 1) {
        appendEccubeWarningMessage($makeTag);
        return;
      }
      array_unshift($args, 'product_id: ' . $eccubeProductId);
    }
    $query = "mutation {\n  UpdateProductMutation(\n    " . buildEccubeProductMutationArgs($args) . "\n  ) {\n    id\n  }\n}";
    $result = eccube_api_call($query);
    if (is_array($result) === false || array_key_exists('UpdateProductMutation', $result) === false) {
      appendEccubeWarningMessage($makeTag);
    }
  } catch (Exception $e) {
    appendEccubeWarningMessage($makeTag);
  }
}
/*
 * [EC-CUBE商品画像連携] 商品画像同期
 */
function syncEccubeProductImages(&$makeTag, $shopId, $productId)
{
  $productData = getShopProductData_FindById($shopId, $productId);
  $eccubeProductId = isset($productData['eccube_product_id']) ? (int)$productData['eccube_product_id'] : 0;
  if ($eccubeProductId < 1) {
    appendEccubeWarningMessage($makeTag);
    return;
  }
  $images = getShopProductImages($shopId, $productId);
  if (!is_array($images)) {
    $images = [];
  }
  $imageArgs = [];
  foreach ($images as $image) {
    $storagePath = isset($image['storage_path']) ? ltrim(trim((string)$image['storage_path']), '/') : '';
    $sortOrder = isset($image['sort_order']) ? (int)$image['sort_order'] : 0;
    if ($storagePath === '' || $sortOrder < 1) {
      continue;
    }
    $imageArgs[] = '{ storage_path: ' . buildEccubeGraphqlString($storagePath) . ', sort_order: ' . $sortOrder . ' }';
  }
  $imagesArgStr = '[' . implode(', ', $imageArgs) . ']';
  $query = "mutation {\n  SyncProductImagesMutation(\n    eccube_product_id: " . (int)$eccubeProductId . "\n    images: " . $imagesArgStr . "\n  ) {\n    success\n    error\n  }\n}";
  try {
    $result = eccube_api_call($query);
    $success = $result['SyncProductImagesMutation']['success'] ?? false;
    if ($success !== true) {
      appendEccubeWarningMessage($makeTag);
    }
  } catch (Exception $e) {
    appendEccubeWarningMessage($makeTag);
  }
}
/**
 * tmpファイル掃除（存在すれば削除）
 */
function cleanupFiles(array $paths): void
{
  foreach ($paths as $p) {
    if (!is_string($p) || $p === '') {
      continue;
    }
    if (file_exists($p) && is_file($p)) {
      @unlink($p);
    }
  }
}
/**
 * 画像拡張子からMIMEタイプを推定
 */
function imageMimeTypeFromExt(string $ext): string
{
  $ext = strtolower($ext);
  switch ($ext) {
    case 'jpg':
    case 'jpeg':
      return 'image/jpeg';
    case 'png':
      return 'image/png';
    case 'webp':
      return 'image/webp';
    default:
      return '';
  }
}
/**
 * 配列からパス指定で値を取得する
 * @param array $data 対象配列
 * @param array $path キー配列
 * @param mixed $default デフォルト値
 * @return mixed パスで辿った値（存在しない場合はdefault）
 */
function arrayGetByPath(array $data, array $path, $default = '')
{
  $current = $data;
  foreach ($path as $key) {
    if (!is_array($current) || !array_key_exists($key, $current)) {
      return $default;
    }
    $current = $current[$key];
  }
  return $current;
}
/**
 * 配列にパス指定で値をセットする
 * @param array &$data 対象配列（参照渡し）
 * @param array $path キー配列
 * @param mixed $value セットする値
 */
function arraySetByPath(array &$data, array $path, $value): void
{
  $ref = &$data;
  $lastIndex = count($path) - 1;
  foreach ($path as $i => $key) {
    if ($i === $lastIndex) {
      $ref[$key] = $value;
      return;
    }
    if (!isset($ref[$key]) || !is_array($ref[$key])) {
      $ref[$key] = [];
    }
    $ref = &$ref[$key];
  }
}
/**
 * DB保存URL（/db/images/...）をファイル実体パスへ変換
 */
function publicImagePathToFullPath(string $publicPath): string
{
  $publicPath = (string)$publicPath;
  if ($publicPath === '') {
    return '';
  }
  $prefix = '/db/images/';
  if (strpos($publicPath, $prefix) !== 0) {
    return '';
  }
  $rel = ltrim(substr($publicPath, strlen($prefix)), '/');
  if ($rel === '') {
    return '';
  }
  return rtrim(DEFINE_FILE_DIR_PATH, '/\\') . '/' . str_replace('\\', '/', $rel);
}
/**
 * 商品画像 storage_path の基準ディレクトリを取得
 */
function productImageStorageRootDir(): string
{
  $baseDir = rtrim(DEFINE_FILE_DIR_PATH, '/\\');
  if (basename($baseDir) === 'shops') {
    return dirname($baseDir);
  }
  return $baseDir;
}
/**
 * 商品画像 storage_path をファイル実体パスへ変換
 */
function productImageStoragePathToFullPath(string $storagePath): string
{
  $storagePath = ltrim(trim($storagePath), '/');
  if ($storagePath === '' || strpos($storagePath, '..') !== false) {
    return '';
  }
  return rtrim(productImageStorageRootDir(), '/\\') . '/' . str_replace('\\', '/', $storagePath);
}
/**
 * 商品画像の表示用ファイル名を生成
 */
function buildProductImageDisplayName($fileName)
{
  return preg_replace('/^(product_\d+_\d+)_\d{14}_[a-f0-9]+\.(jpg|jpeg|png|webp)$/i', '$1.$2', $fileName);
}
/**
 * 商品画像ファイル名の最大連番を取得
 */
function getMaxProductImageFileNumber($productId, array $paths)
{
  $maxNumber = 0;
  $productId = (int)$productId;
  $pattern = '/^product_' . preg_quote((string)$productId, '/') . '_(\d+)_\d{14}_[a-f0-9]+\.(jpg|jpeg|png|webp)$/i';
  foreach ($paths as $path) {
    $fileName = basename((string)$path);
    if (preg_match($pattern, $fileName, $matches)) {
      $num = isset($matches[1]) ? (int)$matches[1] : 0;
      if ($num > $maxNumber) {
        $maxNumber = $num;
      }
    }
  }
  return $maxNumber;
}
/**
 * 商品画像プレビュータグ生成
 */
function buildProductImagePreviewTag($imageSrc, $mimeType, $fileName = '')
{
  $imageSrcHtml = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');
  $mimeTypeHtml = htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8');
  $realFileName = (string)$fileName;
  $displayFileName = buildProductImageDisplayName($realFileName);
  $realFileNameHtml = htmlspecialchars($realFileName, ENT_QUOTES, 'UTF-8');
  $displayFileNameHtml = htmlspecialchars($displayFileName, ENT_QUOTES, 'UTF-8');
  return <<<HTML
                  <li data-name="{$displayFileNameHtml}" data-file-name="{$realFileNameHtml}">
                    <button type="button" class="btn-delate"></button>
                    <picture>
                      <source src="{$imageSrcHtml}" type="{$mimeTypeHtml}">
                      <img src="{$imageSrcHtml}" alt="商品画像">
                    </picture>
                  </li>

HTML;
}
if (!function_exists('initImageSessionFromDB')) {
  /**
   * DB登録済み商品画像をアップロードセッションへ読み込む
   */
  function initImageSessionFromDB($targetImageUploadSessionKey, $productId)
  {
    $shopId = $_SESSION['client_login']['shop_id'] ?? null;
    if ($targetImageUploadSessionKey === '' || $shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId < 1 || (int)$productId < 1) {
      return;
    }
    if (isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey])) {
      return;
    }
    $_SESSION[$targetImageUploadSessionKey] = [];
    $images = getShopProductImages((int)$shopId, (int)$productId);
    foreach ($images as $image) {
      $storagePath = isset($image['storage_path']) ? ltrim(trim((string)$image['storage_path']), '/') : '';
      if ($storagePath === '' || strpos($storagePath, '..') !== false) {
        continue;
      }
      $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
      $_SESSION[$targetImageUploadSessionKey][] = [
        'kind' => 'db',
        'path' => $storagePath,
        'preview' => rtrim(DOMAIN_NAME, '/') . '/db/images/' . $storagePath,
        'name' => basename($storagePath),
        'type' => imageMimeTypeFromExt($ext),
      ];
    }
  }
}
if (!function_exists('buildPreviewTagsFromSession')) {
  /**
   * アップロードセッションからプレビュータグを再生成
   */
  function buildPreviewTagsFromSession($targetImageUploadSessionKey)
  {
    if ($targetImageUploadSessionKey === '' || !isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
      return '';
    }
    $tag = '';
    foreach ($_SESSION[$targetImageUploadSessionKey] as $info) {
      if (!is_array($info)) {
        continue;
      }
      if (isset($info['kind']) && $info['kind'] === 'db') {
        $storagePath = isset($info['path']) ? ltrim(trim((string)$info['path']), '/') : '';
        if ($storagePath === '' || strpos($storagePath, '..') !== false) {
          continue;
        }
        $src = rtrim(DOMAIN_NAME, '/') . '/db/images/' . $storagePath;
        $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
        $tag .= buildProductImagePreviewTag($src, imageMimeTypeFromExt($ext), basename($storagePath));
      } else {
        $src = isset($info['preview']) ? (string)$info['preview'] : '';
        $name = isset($info['name']) ? (string)$info['name'] : '';
        if ($src === '' && $name !== '') {
          $src = '/tmp_upload/' . $name;
        }
        if ($src === '') {
          continue;
        }
        $ext = strtolower(pathinfo($name !== '' ? $name : $src, PATHINFO_EXTENSION));
        $tag .= buildProductImagePreviewTag($src, imageMimeTypeFromExt($ext), $name);
      }
    }
    return $tag;
  }
}
/**
 * 商品画像ドラフトを本保存へ確定
 */
function finalizeProductImages($targetImageUploadSessionKey, $shopId, $productId, &$copiedFiles, &$tmpFilesToDelete, &$filesToDeleteAfterCommit)
{
  if ($targetImageUploadSessionKey === '') {
    return true;
  }
  if (!isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
    initImageSessionFromDB($targetImageUploadSessionKey, $productId);
  }
  $sessionImages = isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey]) ? array_values($_SESSION[$targetImageUploadSessionKey]) : [];
  $imageOrderRaw = isset($_POST['image_order']) && is_array($_POST['image_order']) ? $_POST['image_order'] : [];
  if (!empty($imageOrderRaw) && count($imageOrderRaw) === count($sessionImages)) {
    $reordered = [];
    $usedIndexes = [];
    foreach ($imageOrderRaw as $orderedFileName) {
      $orderedFileName = basename((string)$orderedFileName);
      foreach ($sessionImages as $idx => $sessionImage) {
        if (in_array($idx, $usedIndexes, true) || !is_array($sessionImage)) {
          continue;
        }
        $sessionFileName = '';
        if (isset($sessionImage['kind']) && $sessionImage['kind'] === 'db') {
          $sessionFileName = basename((string)($sessionImage['path'] ?? ''));
        } else {
          $sessionFileName = basename((string)($sessionImage['name'] ?? ''));
        }
        if ($sessionFileName === $orderedFileName) {
          $reordered[] = $sessionImage;
          $usedIndexes[] = $idx;
          break;
        }
      }
    }
    if (count($reordered) === count($sessionImages)) {
      $sessionImages = $reordered;
    }
  }
  if (count($sessionImages) > 8) {
    return false;
  }
  $oldImages = getShopProductImages($shopId, $productId);
  $oldPaths = [];
  foreach ($oldImages as $oldImage) {
    if (isset($oldImage['storage_path']) && trim((string)$oldImage['storage_path']) !== '') {
      $oldPaths[] = ltrim(trim((string)$oldImage['storage_path']), '/');
    }
  }
  $nextImageFileNumber = getMaxProductImageFileNumber($productId, $oldPaths) + 1;
  $shopDir = str_pad((string)(int)$shopId, 3, '0', STR_PAD_LEFT);
  $storageBase = 'shops/' . $shopDir . '/ec/products/' . (int)$productId;
  $saveDir = rtrim(productImageStorageRootDir(), '/\\') . '/' . $storageBase;
  if (!file_exists($saveDir) && mkdir($saveDir, 0777, true) === false) {
    return false;
  }
  $imagesForDb = [];
  $keptPaths = [];
  $allowed = ['jpg', 'jpeg', 'png', 'webp'];
  foreach ($sessionImages as $idx => $info) {
    if (!is_array($info)) {
      return false;
    }
    $sortOrder = $idx + 1;
    if (isset($info['kind']) && $info['kind'] === 'db') {
      $storagePath = isset($info['path']) ? ltrim(trim((string)$info['path']), '/') : '';
      if ($storagePath === '' || strpos($storagePath, '..') !== false) {
        return false;
      }
      $imagesForDb[] = ['storage_path' => $storagePath, 'sort_order' => $sortOrder];
      $keptPaths[] = $storagePath;
      continue;
    }
    $tmpPath = isset($info['tmp_name']) ? (string)$info['tmp_name'] : '';
    $name = isset($info['name']) ? (string)$info['name'] : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($tmpPath === '' || file_exists($tmpPath) === false || is_file($tmpPath) === false || in_array($ext, $allowed, true) === false) {
      return false;
    }
    $fileNumber = $nextImageFileNumber;
    $nextImageFileNumber++;
    $fileName = 'product_' . (int)$productId . '_' . $fileNumber . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $fullPath = $saveDir . '/' . $fileName;
    if (copy($tmpPath, $fullPath) === false) {
      return false;
    }
    $copiedFiles[] = $fullPath;
    $tmpFilesToDelete[] = $tmpPath;
    $storagePath = $storageBase . '/' . $fileName;
    $imagesForDb[] = ['storage_path' => $storagePath, 'sort_order' => $sortOrder];
    $keptPaths[] = $storagePath;
  }
  if (replaceShopProductImages($shopId, $productId, $imagesForDb) === false) {
    return false;
  }
  foreach ($oldPaths as $oldPath) {
    if (!in_array($oldPath, $keptPaths, true)) {
      $fullPath = productImageStoragePathToFullPath($oldPath);
      if ($fullPath !== '') {
        $filesToDeleteAfterCommit[] = $fullPath;
      }
    }
  }
  $pendingKey = (string)$productId;
  if (isset($_SESSION['image_pending_deletes'][$pendingKey]) && is_array($_SESSION['image_pending_deletes'][$pendingKey])) {
    foreach ($_SESSION['image_pending_deletes'][$pendingKey] as $pendingPath) {
      $pendingPath = ltrim(trim((string)$pendingPath), '/');
      if ($pendingPath !== '' && strpos($pendingPath, '..') === false && !in_array($pendingPath, $keptPaths, true)) {
        $fullPath = productImageStoragePathToFullPath($pendingPath);
        if ($fullPath !== '') {
          $filesToDeleteAfterCommit[] = $fullPath;
        }
      }
    }
  }
  unset($_SESSION[$targetImageUploadSessionKey]);
  unset($_SESSION['image_pending_deletes'][$pendingKey]);
  return true;
}

#***** タグ生成開始 *****#
switch ($action) {
  #***** ページ離脱・リロード時：アップロードドラフト破棄（tmp_upload + session） *****#
  case 'discardUploadDraft': {
      // up_image_area[] で指定されたセッション領域のみ破棄
      if (!isset($imageUploadSessionKeys) || !is_array($imageUploadSessionKeys)) {
        $imageUploadSessionKeys = [];
      }
      $pathsToDelete = [];
      foreach ($imageUploadSessionKeys as $sKey) {
        if (!is_string($sKey) || $sKey === '') {
          continue;
        }
        if (isset($_SESSION[$sKey]) && is_array($_SESSION[$sKey])) {
          foreach ($_SESSION[$sKey] as $row) {
            if (!is_array($row)) {
              continue;
            }
            $tmp = $row['tmp_name'] ?? '';
            if (is_string($tmp) && $tmp !== '') {
              $pathsToDelete[] = $tmp;
            }
          }
        }
        unset($_SESSION[$sKey]);
      }
      cleanupFiles($pathsToDelete);
      $makeTag['status'] = 'success';
      $makeTag['title'] = 'discarded';
      $makeTag['msg'] = '';
      header('Content-Type: application/json');
      echo json_encode($makeTag);
      exit;
    }
    break;
  #***** 画像プレビューチェック（ドラッグ＆ドロップ/ファイル選択アップロード） *****#
  case 'preUploadImage': {
      #エリア名が未指定の場合はエラー応答
      if (empty($targetImageUploadSessionKey)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = 'アップロードエリア名が指定されていません。';
        echo json_encode($makeTag);
        exit;
      }
      $makeTag['file_url'] = '';
      $makeTag['file_name'] = '';
      $upImageMode = isset($_POST['up_image_mode']) ? (string)$_POST['up_image_mode'] : '';
      $jobIdForDraft = isset($_POST['jobId']) ? (int)$_POST['jobId'] : 0;
      if ($jobIdForDraft > 0) {
        initImageSessionFromDB($targetImageUploadSessionKey, $jobIdForDraft);
      }
      if (isset($_FILES['images_tmp']) && is_uploaded_file($_FILES['images_tmp']['tmp_name'])) {
        $file = $_FILES['images_tmp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed)) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = 'アップロード失敗';
          $makeTag['msg'] = '許可されていないファイル形式です。';
        } else if ((int)$file['size'] > 5 * 1024 * 1024) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = 'アップロード失敗';
          $makeTag['msg'] = 'ファイルサイズは5MB以内にしてください。';
        } else {
          #一時保存先
          $tmpDir = __DIR__ . '/../../../tmp_upload/';
          if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
          $uniqueName = 'image_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
          $savePath = $tmpDir . $uniqueName;
          $previewPath = DEFINE_PREVIEW_IMAGE_DIR_PATH . '/' . $uniqueName;
          if (move_uploaded_file($file['tmp_name'], $savePath)) {
            #onlyモードの場合は、既存（DB/tmp）を置換扱いにしてドラフトを1枚に揃える
            if ($upImageMode === 'only' && isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey]) && !empty($_SESSION[$targetImageUploadSessionKey])) {
              foreach ($_SESSION[$targetImageUploadSessionKey] as $oldInfo) {
                if (!is_array($oldInfo)) {
                  continue;
                }
                if (isset($oldInfo['tmp_name']) && is_string($oldInfo['tmp_name']) && $oldInfo['tmp_name'] !== '' && file_exists($oldInfo['tmp_name'])) {
                  @unlink($oldInfo['tmp_name']);
                } elseif (isset($oldInfo['path']) && is_string($oldInfo['path']) && $oldInfo['path'] !== '' && $jobIdForDraft > 0) {
                  if (!isset($_SESSION['image_pending_deletes'])) {
                    $_SESSION['image_pending_deletes'] = [];
                  }
                  $k = (string)$jobIdForDraft;
                  if (!isset($_SESSION['image_pending_deletes'][$k]) || !is_array($_SESSION['image_pending_deletes'][$k])) {
                    $_SESSION['image_pending_deletes'][$k] = [];
                  }
                  $_SESSION['image_pending_deletes'][$k][] = $oldInfo['path'];
                }
              }
              $_SESSION[$targetImageUploadSessionKey] = [];
            }
            #セッションにファイル情報を保存
            if (!isset($_SESSION[$targetImageUploadSessionKey])) {
              $_SESSION[$targetImageUploadSessionKey] = [];
            }
            $_SESSION[$targetImageUploadSessionKey][] = [
              'kind' => 'tmp',
              'tmp_name' => $savePath,
              'preview' => $previewPath,
              'name' => $uniqueName,
              'original' => $file['name'],
              'type' => $file['type'],
              'size' => $file['size'],
              'uploaded_at' => time(),
            ];
            $makeTag['status'] = 'success';
            $makeTag['file_url'] = '/tmp_upload/' . $uniqueName;
            $makeTag['file_name'] = $uniqueName;
            #sourceタグ用MIMEタイプ設定
            $mimeType = imageMimeTypeFromExt($ext);
            #プレビュー用タグ生成
            $makeTag['tag'] .= buildProductImagePreviewTag($previewPath, $mimeType, $uniqueName);
          } else {
            $makeTag['status'] = 'error';
            $makeTag['title'] = 'アップロード失敗';
            $makeTag['msg'] = 'ファイルの保存に失敗しました。';
          }
        }
      } else {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = 'ファイルがアップロードされていません。';
      }
      header('Content-Type: application/json');
      echo json_encode($makeTag);
      exit;
    }
    #***** 画像入れ替え（プレビューからの入れ替え） *****#
  case 'replaceUploadImage': {
      #エリア名が未指定の場合はエラー応答
      if (empty($targetImageUploadSessionKey)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = 'アップロードエリア名が指定されていません。';
        echo json_encode($makeTag);
        exit;
      }
      $replaceIndex = isset($_POST['replace_index']) ? intval($_POST['replace_index']) : null;
      $makeTag['file_url'] = '';
      $makeTag['file_name'] = '';
      $file = isset($_FILES['images_tmp']) ? $_FILES['images_tmp'] : null;
      $ext = $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';
      $allowed = ['jpg', 'jpeg', 'png', 'webp'];
      if ($file && is_uploaded_file($file['tmp_name']) && (int)$file['size'] > 5 * 1024 * 1024) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = 'ファイルサイズは5MB以内にしてください。';
      } else if ($replaceIndex !== null && $file && is_uploaded_file($file['tmp_name']) && in_array($ext, $allowed)) {
        $jobIdForDraft = isset($_POST['jobId']) ? (int)$_POST['jobId'] : 0;
        if ($jobIdForDraft > 0) {
          initImageSessionFromDB($targetImageUploadSessionKey, $jobIdForDraft);
        }
        if (!isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
          $_SESSION[$targetImageUploadSessionKey] = [];
        }
        if (!isset($_SESSION[$targetImageUploadSessionKey][$replaceIndex])) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = 'アップロード失敗';
          $makeTag['msg'] = '入れ替え対象がありません。';
        } else {
          $tmpDir = __DIR__ . '/../../../tmp_upload/';
          if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
          $uniqueName = 'image_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
          $savePath = $tmpDir . $uniqueName;
          $previewPath = DEFINE_PREVIEW_IMAGE_DIR_PATH . '/' . $uniqueName;
          if (move_uploaded_file($file['tmp_name'], $savePath)) {
            $old = $_SESSION[$targetImageUploadSessionKey][$replaceIndex];
            if (is_array($old)) {
              if (isset($old['tmp_name']) && is_string($old['tmp_name']) && $old['tmp_name'] !== '' && file_exists($old['tmp_name'])) {
                @unlink($old['tmp_name']);
              } elseif (isset($old['path']) && is_string($old['path']) && $old['path'] !== '' && $jobIdForDraft > 0) {
                if (!isset($_SESSION['image_pending_deletes'])) {
                  $_SESSION['image_pending_deletes'] = [];
                }
                $k = (string)$jobIdForDraft;
                if (!isset($_SESSION['image_pending_deletes'][$k]) || !is_array($_SESSION['image_pending_deletes'][$k])) {
                  $_SESSION['image_pending_deletes'][$k] = [];
                }
                $_SESSION['image_pending_deletes'][$k][] = $old['path'];
              }
            }
            $_SESSION[$targetImageUploadSessionKey][$replaceIndex] = [
              'kind' => 'tmp',
              'tmp_name' => $savePath,
              'preview' => $previewPath,
              'name' => $uniqueName,
              'original' => $file['name'],
              'type' => $file['type'],
              'size' => $file['size'],
              'uploaded_at' => time(),
            ];
            $makeTag['status'] = 'success';
            $makeTag['file_url'] = '/tmp_upload/' . $uniqueName;
            $makeTag['file_name'] = $uniqueName;
          } else {
            $makeTag['status'] = 'error';
            $makeTag['title'] = 'アップロード失敗';
            $makeTag['msg'] = 'ファイルの保存に失敗しました。';
          }
        }
      } else {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = '入れ替え対象がありません。';
      }
      #プレビュータグ再生成（セッションのドラフト状態から）
      $makeTag['tag'] = buildPreviewTagsFromSession($targetImageUploadSessionKey);
      header('Content-Type: application/json');
      echo json_encode($makeTag);
      exit;
    }
    #***** 画像削除（プレビュー or 本体からの削除） *****#
  case 'deleteUploadImage': {
      #エリア名が未指定の場合はエラー応答
      if (empty($targetImageUploadSessionKey)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = 'アップロードエリア名が指定されていません。';
        echo json_encode($makeTag);
        exit;
      }
      $fileName = isset($_POST['file_name']) ? $_POST['file_name'] : '';
      $jobIdForDraft = isset($_POST['jobId']) ? (int)$_POST['jobId'] : 0;
      if ($jobIdForDraft > 0) {
        initImageSessionFromDB($targetImageUploadSessionKey, $jobIdForDraft);
      }
      if ($fileName === '') {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '削除失敗';
        $makeTag['msg'] = '削除対象が指定されていません。';
      } else {
        if (!isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
          $_SESSION[$targetImageUploadSessionKey] = [];
        }
        $found = false;
        foreach ($_SESSION[$targetImageUploadSessionKey] as $idx => $info) {
          if (!is_array($info)) {
            continue;
          }
          $isTmp = (isset($info['name']) && is_string($info['name']) && $info['name'] !== '');
          $isDb = (isset($info['path']) && is_string($info['path']) && $info['path'] !== '');
          $match = false;
          if ($isTmp && $info['name'] === $fileName) {
            $match = true;
          } elseif ($isDb) {
            $base = pathinfo((string)$info['path'])['basename'] ?? '';
            if ($base === $fileName) {
              $match = true;
            }
          }
          if (!$match) {
            continue;
          }
          if ($isTmp && isset($info['tmp_name']) && is_string($info['tmp_name']) && $info['tmp_name'] !== '' && file_exists($info['tmp_name'])) {
            @unlink($info['tmp_name']);
          } elseif ($isDb && $jobIdForDraft > 0) {
            if (!isset($_SESSION['image_pending_deletes'])) {
              $_SESSION['image_pending_deletes'] = [];
            }
            $k = (string)$jobIdForDraft;
            if (!isset($_SESSION['image_pending_deletes'][$k]) || !is_array($_SESSION['image_pending_deletes'][$k])) {
              $_SESSION['image_pending_deletes'][$k] = [];
            }
            $_SESSION['image_pending_deletes'][$k][] = $info['path'];
          }
          array_splice($_SESSION[$targetImageUploadSessionKey], $idx, 1);
          $found = true;
          break;
        }
        if ($found) {
          $makeTag['status'] = 'success';
        } else {
          $makeTag['status'] = 'error';
          $makeTag['title'] = '削除失敗';
          $makeTag['msg'] = '削除対象がありません。';
        }
      }
      header('Content-Type: application/json');
      echo json_encode($makeTag);
      exit;
    }
    break;
  #***** 商品画像のみ保存 *****#
  case 'saveProductImages': {
      if ($method !== 'edit') {
        $makeTag['status'] = 'success';
        $makeTag['title'] = '画像保存';
        $makeTag['msg'] = '';
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($makeTag);
        exit;
      }
      $saveImageProductIdRaw = isset($_POST['productId']) ? trim((string)$_POST['productId']) : '';
      if ($saveImageProductIdRaw === '' || ctype_digit($saveImageProductIdRaw) === false || (int)$saveImageProductIdRaw < 1) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像保存エラー';
        $makeTag['msg'] = '商品IDの指定が不正です。';
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($makeTag);
        exit;
      }
      $saveImageProductId = (int)$saveImageProductIdRaw;
      $productData = getShopProductData_FindById($shopId, $saveImageProductId);
      if (empty($productData)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像保存エラー';
        $makeTag['msg'] = '商品情報が取得できませんでした。';
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($makeTag);
        exit;
      }
      $copiedImageFiles = [];
      $tmpImageFilesToDelete = [];
      $imageFilesToDeleteAfterCommit = [];
      try {
        $result = DB_Transaction(1);
        if ($result == false) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = '画像保存エラー';
          $makeTag['msg'] = '画像の保存に失敗しました。<br>ページを再読み込みしてください。';
        } elseif (finalizeProductImages($targetImageUploadSessionKey, $shopId, $saveImageProductId, $copiedImageFiles, $tmpImageFilesToDelete, $imageFilesToDeleteAfterCommit) === true) {
          DB_Transaction(2);
          cleanupFiles($tmpImageFilesToDelete);
          cleanupFiles(array_values(array_unique($imageFilesToDeleteAfterCommit)));
          $makeTag['status'] = 'success';
          $makeTag['title'] = '画像保存';
          $makeTag['msg'] = '画像を保存しました。';
          syncEccubeProductImages($makeTag, $shopId, $saveImageProductId);
          syncFrontendProductJson($makeTag, $shopId, $saveImageProductId);
        } else {
          DB_Transaction(3);
          cleanupFiles($copiedImageFiles);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '画像保存エラー';
          $makeTag['msg'] = '画像の保存に失敗しました。<br>ページを再読み込みしてください。';
        }
      } catch (Exception $e) {
        DB_Transaction(3);
        cleanupFiles($copiedImageFiles);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像保存エラー';
        $makeTag['msg'] = '画像の保存に失敗しました。<br>ページを再読み込みしてください。';
      }
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode($makeTag);
      exit;
    }
    break;
  #***** 登録 *****#
  case 'sendInput': {
      #----------------------------
      # 新規追加／編集
      #----------------------------
      if ($method !== 'new' && $method !== 'edit') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #----------------------------
      # サーバーサイドバリデーション
      #----------------------------
      $validationErrors = [];
      if ($categoryIdRaw !== '') {
        if (ctype_digit($categoryIdRaw) === false || (int)$categoryIdRaw < 1) {
          $validationErrors[] = 'カテゴリの指定が不正です。';
        } else {
          $categoryId = (int)$categoryIdRaw;
          $categoryData = getShopItemCategoryDetails($shopId, $categoryId);
          if (empty($categoryData)) {
            $validationErrors[] = '指定されたカテゴリが見つかりません。';
          }
        }
      }
      if ($displayFlgRaw === '' || ctype_digit($displayFlgRaw) === false || in_array((int)$displayFlgRaw, [1, 2], true) === false) {
        $validationErrors[] = '公開状態の指定が不正です。';
      } else {
        $displayFlg = (int)$displayFlgRaw;
      }
      if ($specUsageFlgRaw === '' || ctype_digit($specUsageFlgRaw) === false || in_array((int)$specUsageFlgRaw, [1, 2], true) === false) {
        $validationErrors[] = '商品規格の指定が不正です。';
      } else {
        $specUsageFlg = (int)$specUsageFlgRaw;
      }
      if ($taxRateRaw === '' || ctype_digit($taxRateRaw) === false || in_array((int)$taxRateRaw, [8, 10], true) === false) {
        $validationErrors[] = '税区分の指定が不正です。';
      } else {
        $taxRate = (int)$taxRateRaw;
      }
      if ($stockUnlimitedRaw === '' || ctype_digit($stockUnlimitedRaw) === false || in_array((int)$stockUnlimitedRaw, [0, 1], true) === false) {
        $validationErrors[] = '在庫無制限の指定が不正です。';
      } else {
        $stockUnlimited = (int)$stockUnlimitedRaw;
      }
      if ($productName === null || $productName === '') {
        $validationErrors[] = '商品名を入力してください。';
      } elseif (mb_strlen($productName) > 255) {
        $validationErrors[] = '商品名は255文字以内で入力してください。';
      }
      if ($specUsageFlg === 1) {
        if ($salePriceRaw === '' || ctype_digit($salePriceRaw) === false || (int)$salePriceRaw < 1) {
          $validationErrors[] = '販売価格は1以上の整数で入力してください。';
        } else {
          $salePrice = (int)$salePriceRaw;
        }
        if ($stockUnlimited === 1) {
          $stockQuantity = null;
        } else {
          if ($stockQuantityRaw === '' || ctype_digit($stockQuantityRaw) === false) {
            $validationErrors[] = '在庫数は0以上の整数で入力してください。';
          } else {
            $stockQuantity = (int)$stockQuantityRaw;
          }
        }
      }
      if ($tempType === null || $tempType === '') {
        $validationErrors[] = '温度帯を選択してください。';
      } elseif (in_array($tempType, ['normal', 'cool', 'frozen'], true) === false) {
        $validationErrors[] = '温度帯の指定が不正です。';
      }
      if ($sizeLengthRaw !== '') {
        if (ctype_digit($sizeLengthRaw) === false) {
          $validationErrors[] = '縦は0以上の整数で入力してください。';
        } else {
          $sizeLength = (int)$sizeLengthRaw;
        }
      }
      if ($sizeWidthRaw !== '') {
        if (ctype_digit($sizeWidthRaw) === false) {
          $validationErrors[] = '横は0以上の整数で入力してください。';
        } else {
          $sizeWidth = (int)$sizeWidthRaw;
        }
      }
      if ($sizeHeightRaw !== '') {
        if (ctype_digit($sizeHeightRaw) === false) {
          $validationErrors[] = '高さは0以上の整数で入力してください。';
        } else {
          $sizeHeight = (int)$sizeHeightRaw;
        }
      }
      if ($weightGRaw !== '') {
        if (ctype_digit($weightGRaw) === false) {
          $validationErrors[] = '重量は0以上の整数で入力してください。';
        } else {
          $weightG = (int)$weightGRaw;
        }
      }
      if ($method === 'edit') {
        if ($productIdRaw === '' || ctype_digit($productIdRaw) === false || (int)$productIdRaw < 1) {
          $validationErrors[] = '商品IDの指定が不正です。';
        } else {
          $productId = (int)$productIdRaw;
        }
      }
      if (!empty($validationErrors)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = implode("\n", $validationErrors);
        echo json_encode($makeTag);
        exit;
      }
      #更新開始
      $copiedImageFiles = [];
      $tmpImageFilesToDelete = [];
      $imageFilesToDeleteAfterCommit = [];
      try {
        $savedProductId = null;
        $status = ($displayFlg === 1) ? 1 : 0;
        $currentDateTime = date("Y-m-d H:i:s");
        #トランザクション開始
        # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
        $result = DB_Transaction(1);
        if ($result == false) {
          #エラーログ出力
          $data = [
            'pageName' => 'proc_client03_03',
            'reason' => 'トランザクション開始失敗',
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '登録エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          header('Content-Type: application/json');
          echo json_encode($makeTag);
          exit;
        } else {
          #DB登録結果フラグ：初期化
          $dbCompleteFlg = true;
          if ($method === 'new') {
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット
            $dbFiledData['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledData['category_id'] = array(':category_id', $categoryId, ($categoryId === null) ? 2 : 1);
            $dbFiledData['tax_rate'] = array(':tax_rate', $taxRate, 1);
            $dbFiledData['name'] = array(':name', $productName, 0);
            $dbFiledData['description'] = array(':description', $description, 0);
            if ($specUsageFlg === 1) {
              $dbFiledData['price'] = array(':price', $salePrice, 1);
              $dbFiledData['stock'] = array(':stock', $stockQuantity, ($stockQuantity === null) ? 2 : 1);
              $dbFiledData['stock_unlimited'] = array(':stock_unlimited', $stockUnlimited, 1);
            } else {
              #規格別価格・在庫は後続の shop_product_variants で管理する
              $dbFiledData['price'] = array(':price', 0, 1);
              $dbFiledData['stock'] = array(':stock', 0, 1);
              $dbFiledData['stock_unlimited'] = array(':stock_unlimited', 0, 1);
            }
            $dbFiledData['temp_type'] = array(':temp_type', $tempType, 0);
            $dbFiledData['length_cm'] = array(':length_cm', $sizeLength, ($sizeLength === null) ? 2 : 1);
            $dbFiledData['width_cm'] = array(':width_cm', $sizeWidth, ($sizeWidth === null) ? 2 : 1);
            $dbFiledData['height_cm'] = array(':height_cm', $sizeHeight, ($sizeHeight === null) ? 2 : 1);
            $dbFiledData['weight_g'] = array(':weight_g', $weightG, ($weightG === null) ? 2 : 1);
            $dbFiledData['status'] = array(':status', $status, 1);
            $dbFiledData['is_active'] = array(':is_active', 1, 1);
            $dbFiledData['created_at'] = array(':created_at', $currentDateTime, 0);
            $dbFiledData['updated_at'] = array(':updated_at', $currentDateTime, 0);
            #更新用キー：初期化
            $dbFiledValue = array();
            #処理モード：[1].新規追加｜[2].更新｜[3].削除
            $processFlg = 1;
            #実行モード：[1].トランザクション｜[2].即実行
            $exeFlg = 2;
            #DB更新
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_products", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
            if ($dbSuccessFlg == 1) {
              $savedProductId = (int)$DB_CONNECT->lastInsertId();
            }
          } else {
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット：編集
            $dbFiledData['category_id'] = array(':category_id', $categoryId, ($categoryId === null) ? 2 : 1);
            $dbFiledData['tax_rate'] = array(':tax_rate', $taxRate, 1);
            $dbFiledData['name'] = array(':name', $productName, 0);
            $dbFiledData['description'] = array(':description', $description, 0);
            if ($specUsageFlg === 1) {
              $dbFiledData['price'] = array(':price', $salePrice, 1);
              $dbFiledData['stock'] = array(':stock', $stockQuantity, ($stockQuantity === null) ? 2 : 1);
              $dbFiledData['stock_unlimited'] = array(':stock_unlimited', $stockUnlimited, 1);
            }
            $dbFiledData['temp_type'] = array(':temp_type', $tempType, 0);
            $dbFiledData['length_cm'] = array(':length_cm', $sizeLength, ($sizeLength === null) ? 2 : 1);
            $dbFiledData['width_cm'] = array(':width_cm', $sizeWidth, ($sizeWidth === null) ? 2 : 1);
            $dbFiledData['height_cm'] = array(':height_cm', $sizeHeight, ($sizeHeight === null) ? 2 : 1);
            $dbFiledData['weight_g'] = array(':weight_g', $weightG, ($weightG === null) ? 2 : 1);
            $dbFiledData['status'] = array(':status', $status, 1);
            $dbFiledData['updated_at'] = array(':updated_at', $currentDateTime, 0);
            #更新用キー：初期化
            $dbFiledValue = array();
            $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledValue['product_id'] = array(':product_id', $productId, 1);
            #実行モード：[1].トランザクション｜[2].即実行
            $processFlg = 2;
            #DB更新
            $exeFlg = 2;
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_products", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
          }
          if ($dbSuccessFlg != 1) {
            #エラーログ出力
            $data = [
              'pageName' => 'proc_client03_03',
              'reason' => ($method === 'new') ? '新規商品登録失敗' : '商品更新失敗',
              'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
            ];
            makeLog($data);
            $dbCompleteFlg = false;
          }
          if ($dbCompleteFlg == true) {
            $imageProductId = ($method === 'new') ? $savedProductId : $productId;
            if ($imageProductId === null || (int)$imageProductId < 1 || finalizeProductImages($targetImageUploadSessionKey, $shopId, (int)$imageProductId, $copiedImageFiles, $tmpImageFilesToDelete, $imageFilesToDeleteAfterCommit) === false) {
              $dbCompleteFlg = false;
            }
          }
          #全ての処理成功
          if ($dbCompleteFlg == true) {
            #DBコミット
            # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
            DB_Transaction(2);
            cleanupFiles($tmpImageFilesToDelete);
            cleanupFiles(array_values(array_unique($imageFilesToDeleteAfterCommit)));
            #応答用タグセット
            $makeTag['status'] = 'success';
            if ($method === 'new') {
              $makeTag['title'] = '新規商品登録';
              $makeTag['msg'] = '登録が完了しました。';
              $makeTag['product_id'] = $savedProductId;
              $makeTag['spec_usage_flg'] = $specUsageFlg;
              if ($savedProductId !== null && (int)$savedProductId > 0) {
                syncEccubeProductBasicInfo($makeTag, $method, $specUsageFlg, $shopId, (int)$savedProductId, $productName, $displayFlg, $salePrice, $stockQuantity, $stockUnlimited, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $categoryId);
                if ($specUsageFlg !== 2) {
                  syncEccubeProductImages($makeTag, $shopId, (int)$savedProductId);
                }
                syncFrontendProductJson($makeTag, $shopId, (int)$savedProductId);
                syncFrontendShopDetailJson($makeTag, $shopId);
              }
            } else {
              $makeTag['title'] = '商品情報編集';
              $makeTag['msg'] = '更新が完了しました。';
              $makeTag['product_id'] = $productId;
              $makeTag['spec_usage_flg'] = $specUsageFlg;
              syncEccubeProductBasicInfo($makeTag, $method, $specUsageFlg, $shopId, $productId, $productName, $displayFlg, $salePrice, $stockQuantity, $stockUnlimited, $taxRate, $tempType, $sizeLength, $sizeWidth, $sizeHeight, $weightG, $description, $categoryId);
              syncEccubeProductImages($makeTag, $shopId, $productId);
              syncFrontendProductJson($makeTag, $shopId, $productId);
              syncFrontendShopDetailJson($makeTag, $shopId);
            }
          } else {
            #失敗時はROLLBACK
            DB_Transaction(3);
            cleanupFiles($copiedImageFiles);
            if ($makeTag['status'] === '') {
              $makeTag['status'] = 'error';
              $makeTag['title'] = '登録エラー';
              $makeTag['msg'] = '登録処理に失敗しました。<br>ページを再読み込みしてください。';
            }
          }
        }
      } catch (Exception $e) {
        #ROLLBACK
        DB_Transaction(3);
        cleanupFiles($copiedImageFiles);
        #エラーログ出力
        $data = [
          'pageName' => 'proc_client03_03',
          'reason' => '新規商品登録例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '登録処理に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** デフォルト *****#
  default: {
      header('Content-Type: application/json; charset=UTF-8');
      $makeTag['status'] = 'error';
      $makeTag['title'] = '不正なリクエスト';
      $makeTag['msg'] = '不正な操作です。<br>ページを再読み込みしてください。';
    }
    break;
}
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
