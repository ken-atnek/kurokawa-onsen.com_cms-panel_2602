<?php
require_once __DIR__ . '/jsonExportCommon.php';

/*
 * [フロントJSON] 公開ルート取得
 */
function getFrontendPublicRootPath()
{
  $documentRoot = rtrim(DEFINE_FRONTEND_DIR_PATH, '/\\');
  if (is_dir($documentRoot . '/2603')) {
    return $documentRoot;
  }
  $parentRoot = rtrim(dirname($documentRoot), '/\\');
  if (is_dir($parentRoot . '/2603')) {
    return $parentRoot;
  }
  return $documentRoot;
}
/*
 * [フロントJSON] 商品JSON出力ディレクトリ生成
 */
function buildOnlineProductJsonDir($shopId)
{
  $shopId3 = str_pad((string)(int)$shopId, 3, '0', STR_PAD_LEFT);
  return rtrim(DEFINE_JSON_DIR_PATH, '/\\') . '/shops/products/' . $shopId3;
}
/*
 * [フロントJSON] 商品説明分割
 */
function splitProductDescriptionForJson($description)
{
  $description = (string)$description;
  if ($description === '') {
    return [];
  }
  $lines = preg_split('/\r\n|\r|\n/', $description);
  $comments = [];
  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line !== '') {
      $comments[] = $line;
    }
  }
  return $comments;
}
/*
 * [フロントJSON] 商品画像URL一覧生成
 */
function buildProductImageUrlsForJson($shopId, $productId)
{
  $images = getShopProductImages($shopId, $productId);
  if (!is_array($images)) {
    return [];
  }
  $imageUrls = [];
  foreach ($images as $img) {
    $sp = isset($img['storage_path']) ? trim((string)$img['storage_path']) : '';
    if ($sp === '') {
      continue;
    }
    $imageUrls[] = '/db/images/' . ltrim($sp, '/');
  }
  return $imageUrls;
}
/*
 * [フロントJSON] 規格値名整形
 */
function normalizeProductClassCategoryNameForJson($name)
{
  if ($name === null) {
    return null;
  }
  return preg_replace('/^【sid:\d+】/u', '', (string)$name);
}
/*
 * [フロントJSON] 商品個別JSON生成
 */
function generateProductJsonFile($shopId, $productId)
{
  $shopId = (int)$shopId;
  $productId = (int)$productId;
  if ($shopId < 1 || $productId < 1) {
    return false;
  }
  $shopId3 = str_pad((string)$shopId, 3, '0', STR_PAD_LEFT);
  $productJsonId = 'ec-' . $shopId3 . $productId;
  $productData = getShopProductData_FindById($shopId, $productId);
  if (empty($productData)) {
    return false;
  }
  $eccubeProductId = isset($productData['eccube_product_id']) && $productData['eccube_product_id'] !== null ? (int)$productData['eccube_product_id'] : null;
  $variants = getShopProductVariantsForJson($shopId, $productId);
  if (!is_array($variants)) {
    $variants = [];
  }
  if (count($variants) > 0) {
    $price = 0;
    $stock = 0;
    $standard = [];
    foreach ($variants as $v) {
      $vStockUnlimited = (int)($v['stock_unlimited'] ?? 0) === 1;
      $standard[] = [
        'variantId' => (int)($v['variant_id'] ?? 0),
        'productClassCode' => isset($v['eccube_product_class_code']) ? (string)$v['eccube_product_class_code'] : null,
        'classCategoryId1' => isset($v['class_category_id1']) ? (int)$v['class_category_id1'] : null,
        'classCategoryName1' => normalizeProductClassCategoryNameForJson($v['class_category_name1'] ?? null),
        'classCategoryId2' => isset($v['class_category_id2']) && $v['class_category_id2'] !== null ? (int)$v['class_category_id2'] : null,
        'classCategoryName2' => normalizeProductClassCategoryNameForJson($v['class_category_name2'] ?? null),
        'price' => (int)($v['price'] ?? 0),
        'stock' => $vStockUnlimited ? 999 : (int)($v['stock'] ?? 0),
        'stockUnlimited' => $vStockUnlimited,
      ];
    }
  } else {
    $stockUnlimited = (int)($productData['stock_unlimited'] ?? 0) === 1;
    $price = (int)($productData['price'] ?? 0);
    $stock = $stockUnlimited ? 999 : (int)($productData['stock'] ?? 0);
    $standard = [];
  }
  $data = [
    'id' => $productJsonId,
    'ecId' => $eccubeProductId,
    'shopId' => $shopId3,
    'title' => isset($productData['name']) ? (string)$productData['name'] : '',
    'price' => $price,
    'stock' => $stock,
    'standard' => $standard,
    'images' => buildProductImageUrlsForJson($shopId, $productId),
    'comment' => splitProductDescriptionForJson($productData['description'] ?? ''),
    'ecUrl' => rtrim(DOMAIN_NAME, '/') . '/shops/' . $shopId3 . '/products/',
  ];
  $dir = buildOnlineProductJsonDir($shopId);
  if (!file_exists($dir) && mkdir($dir, 0777, true) === false) {
    return false;
  }
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) {
    return false;
  }
  return file_put_contents($dir . '/' . $productJsonId . '.json', $json, LOCK_EX) !== false;
}
/*
 * [フロントJSON] 店舗別商品一覧JSON生成
 */
function generateProductIndexJson($shopId)
{
  $shopId = (int)$shopId;
  if ($shopId < 1) {
    return false;
  }
  $shopId3 = str_pad((string)$shopId, 3, '0', STR_PAD_LEFT);
  $products = getShopProductListForJson($shopId);
  if (!is_array($products)) {
    $products = [];
  }
  $list = [];
  foreach ($products as $product) {
    $pid = (int)($product['product_id'] ?? 0);
    if ($pid < 1) {
      continue;
    }
    $productJsonId = 'ec-' . $shopId3 . $pid;
    $images = getShopProductImages($shopId, $pid);
    $mainImage = '';
    if (is_array($images)) {
      foreach ($images as $img) {
        if ((int)($img['sort_order'] ?? 0) === 1) {
          $sp = isset($img['storage_path']) ? trim((string)$img['storage_path']) : '';
          if ($sp !== '') {
            $mainImage = '/db/images/' . ltrim($sp, '/');
          }
          break;
        }
      }
    }
    $variants = getShopProductVariantsForJson($shopId, $pid);
    if (is_array($variants) && count($variants) > 0) {
      $minPrice = null;
      foreach ($variants as $v) {
        $vp = (int)($v['price'] ?? 0);
        if ($minPrice === null || $vp < $minPrice) {
          $minPrice = $vp;
        }
      }
      $displayPrice = $minPrice !== null ? $minPrice : 0;
    } else {
      $displayPrice = (int)($product['price'] ?? 0);
    }
    $list[] = [
      'id' => $productJsonId,
      'title' => isset($product['name']) ? (string)$product['name'] : '',
      'image' => $mainImage,
      'price' => $displayPrice,
    ];
  }
  $dir = buildOnlineProductJsonDir($shopId);
  if (!file_exists($dir) && mkdir($dir, 0777, true) === false) {
    return false;
  }
  $json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) {
    return false;
  }
  return file_put_contents($dir . '/index.json', $json, LOCK_EX) !== false;
}
/*
 * [フロントJSON] 商品JSON同期書き出し
 */
function syncFrontendProductJson(&$makeTag, $shopId, $productId)
{
  $okProduct = generateProductJsonFile($shopId, $productId);
  $okIndex = generateProductIndexJson($shopId);
  if ($okProduct !== true || $okIndex !== true) {
    appendFrontendJsonWarningMessage($makeTag);
    logFrontendJsonError('product_json_export_failed', $shopId, $productId, [
      'product_json' => $okProduct,
      'index_json' => $okIndex,
    ]);
    return false;
  }
  return true;
}
