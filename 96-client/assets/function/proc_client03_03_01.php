<?php
/*
 * [96-client/assets/function/proc_client03_03_01.php]
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

function buildVariantCombinationKey($classCategoryId1, $classCategoryId2)
{
  return (int)$classCategoryId1 . ':' . ($classCategoryId2 === null ? 0 : (int)$classCategoryId2);
}
/*
 * [EC-CUBE GraphQL 文字列エスケープ]
 */
function buildEccubeGraphqlString_v01($value)
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
function appendEccubeWarningMessage_v01(&$makeTag)
{
  $makeTag['status'] = 'warning';
  if (strpos((string)$makeTag['msg'], 'EC-CUBE連携に失敗しました。') === false) {
    $makeTag['msg'] .= '<br>EC-CUBE連携に失敗しました。';
  }
}
/*
 * [EC-CUBE商品連携] GraphQL引数組み立て
 */
function buildEccubeProductMutationArgs_v01($args)
{
  return implode("\n    ", $args);
}
/*
 * [EC-CUBE商品連携] shop_products EC-CUBE商品ID更新
 */
function updateShopProductEccubeProductId_v01($shopId, $productId, $eccubeProductId)
{
  global $DB_CONNECT;
  $dbFiledData = array();
  $dbFiledData['eccube_product_id'] = array(':eccube_product_id', (int)$eccubeProductId, 1);
  $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
  $dbFiledValue = array();
  $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
  $dbFiledValue['product_id'] = array(':product_id', $productId, 1);
  return SQL_Process($DB_CONNECT, 'shop_products', $dbFiledData, $dbFiledValue, 2, 2);
}
/*
 * [EC-CUBE商品連携] 規格あり商品連携
 */
function syncEccubeProductVariants_v01(&$makeTag, $shopId, $productId)
{
  $productData = getShopProductData_FindById($shopId, $productId);
  if (empty($productData)) {
    appendEccubeWarningMessage_v01($makeTag);
    return;
  }
  $eccubeCurrentProductId = isset($productData['eccube_product_id']) ? (int)$productData['eccube_product_id'] : 0;
  $shopData = getShops_FindById($shopId);
  $eccubeSaleTypeId = isset($shopData['eccube_sale_type_id']) ? (int)$shopData['eccube_sale_type_id'] : 0;
  if ($eccubeSaleTypeId < 1) {
    appendEccubeWarningMessage_v01($makeTag);
    return;
  }
  $taxRate = isset($productData['tax_rate']) ? (int)$productData['tax_rate'] : 10;
  if ($taxRate <= 0) {
    $taxRate = 10;
  }
  $eccubeStatusId = (isset($productData['status']) && (int)$productData['status'] === 1) ? 1 : 2;
  $eccubeCategoryId = null;
  $outerCategoryId = isset($productData['category_id']) ? (int)$productData['category_id'] : 0;
  if ($outerCategoryId > 0) {
    $categoryData = getShopItemCategoryDetails($shopId, $outerCategoryId);
    $eccubeCategoryId = isset($categoryData['eccube_category_id']) ? (int)$categoryData['eccube_category_id'] : 0;
    if ($eccubeCategoryId < 1) {
      appendEccubeWarningMessage_v01($makeTag);
      return;
    }
  }
  $variantDisplayList = getShopProductVariantDisplayList($shopId, $productId);
  if (empty($variantDisplayList)) {
    appendEccubeWarningMessage_v01($makeTag);
    return;
  }
  $graphqlVariants = array();
  $eccubeVariantError = false;
  foreach ($variantDisplayList as $variant) {
    $classCategoryId1 = isset($variant['class_category_id1']) ? (int)$variant['class_category_id1'] : 0;
    $classCategoryId2 = (isset($variant['class_category_id2']) && $variant['class_category_id2'] !== null) ? (int)$variant['class_category_id2'] : null;
    $localVariantId = isset($variant['variant_id']) ? (int)$variant['variant_id'] : 0;
    if ($localVariantId < 1) {
      $eccubeVariantError = true;
      break;
    }
    $price = isset($variant['price']) ? (int)$variant['price'] : 0;
    if ($price < 1) {
      $eccubeVariantError = true;
      break;
    }
    $stock = (isset($variant['stock']) && $variant['stock'] !== null) ? (int)$variant['stock'] : null;
    $stockUnlimited = (isset($variant['stock_unlimited']) && (int)$variant['stock_unlimited'] === 1) ? 1 : 0;
    if ($stockUnlimited !== 1 && $stock === null) {
      $eccubeVariantError = true;
      break;
    }
    $classify1 = getShopItemClassifyDetails($shopId, $classCategoryId1);
    $eccubeClassCategoryId1 = isset($classify1['eccube_class_category_id']) ? (int)$classify1['eccube_class_category_id'] : 0;
    if ($eccubeClassCategoryId1 < 1) {
      $eccubeVariantError = true;
      break;
    }
    $eccubeClassCategoryId2 = null;
    if ($classCategoryId2 !== null && $classCategoryId2 > 0) {
      $classify2 = getShopItemClassifyDetails($shopId, $classCategoryId2);
      $eccubeClassCategoryId2 = isset($classify2['eccube_class_category_id']) ? (int)$classify2['eccube_class_category_id'] : 0;
      if ($eccubeClassCategoryId2 < 1) {
        $eccubeVariantError = true;
        break;
      }
    }
    $existingCode = (isset($variant['eccube_product_class_code']) && $variant['eccube_product_class_code'] !== null) ? trim((string)$variant['eccube_product_class_code']) : '';
    $variantCode = ($existingCode !== '') ? $existingCode : ('S' . $shopId . 'P' . $productId . 'V' . $localVariantId);
    $graphqlVariants[] = [
      'local_variant_id' => $localVariantId,
      'eccube_class_category_id1' => $eccubeClassCategoryId1,
      'eccube_class_category_id2' => $eccubeClassCategoryId2,
      'code' => $variantCode,
      'price' => $price,
      'stock' => $stock,
      'stock_unlimited' => $stockUnlimited,
    ];
  }
  if ($eccubeVariantError || empty($graphqlVariants)) {
    appendEccubeWarningMessage_v01($makeTag);
    return;
  }
  $args = array();
  $args[] = 'name: ' . buildEccubeGraphqlString_v01(isset($productData['name']) ? $productData['name'] : '');
  $args[] = 'sale_type_id: ' . $eccubeSaleTypeId;
  $args[] = 'status_id: ' . $eccubeStatusId;
  $args[] = 'tax_rate: ' . $taxRate;
  if (isset($productData['temp_type']) && $productData['temp_type'] !== null && $productData['temp_type'] !== '') {
    $args[] = 'temp_type: ' . buildEccubeGraphqlString_v01($productData['temp_type']);
  }
  if (isset($productData['length_cm']) && $productData['length_cm'] !== null) {
    $args[] = 'length_cm: ' . (int)$productData['length_cm'];
  }
  if (isset($productData['width_cm']) && $productData['width_cm'] !== null) {
    $args[] = 'width_cm: ' . (int)$productData['width_cm'];
  }
  if (isset($productData['height_cm']) && $productData['height_cm'] !== null) {
    $args[] = 'height_cm: ' . (int)$productData['height_cm'];
  }
  if (isset($productData['weight_g']) && $productData['weight_g'] !== null) {
    $args[] = 'weight_g: ' . (int)$productData['weight_g'];
  }
  $args[] = 'description: ' . buildEccubeGraphqlString_v01(isset($productData['description']) ? $productData['description'] : '');
  if ($eccubeCategoryId !== null) {
    $args[] = 'category_id: ' . (int)$eccubeCategoryId;
  }
  $variantParts = array();
  foreach ($graphqlVariants as $gv) {
    $variantArgs = array();
    $variantArgs[] = 'class_category_id1: ' . (int)$gv['eccube_class_category_id1'];
    if ($gv['eccube_class_category_id2'] !== null) {
      $variantArgs[] = 'class_category_id2: ' . (int)$gv['eccube_class_category_id2'];
    }
    $variantArgs[] = 'code: ' . buildEccubeGraphqlString_v01($gv['code']);
    $variantPriceExcludingTax = convertTaxIncludedToExcludedPrice($gv['price'], $taxRate);
    $variantArgs[] = 'price02: ' . (int)$variantPriceExcludingTax;
    if ((int)$gv['stock_unlimited'] === 1) {
      $variantArgs[] = 'stock_unlimited: true';
    } else {
      $variantArgs[] = 'stock: ' . (int)$gv['stock'];
    }
    $variantParts[] = "{\n      " . implode("\n      ", $variantArgs) . "\n    }";
  }
  $args[] = "variants: [\n    " . implode(",\n    ", $variantParts) . "\n  ]";
  try {
    if ($eccubeCurrentProductId < 1) {
      $query = "mutation {\n  CreateProductMutation(\n    " . buildEccubeProductMutationArgs_v01($args) . "\n  ) {\n    id\n  }\n}";
      $result = eccube_api_call($query);
      $eccubeNewProductId = $result['CreateProductMutation']['id'] ?? null;
      if ($eccubeNewProductId === null || (int)$eccubeNewProductId < 1) {
        appendEccubeWarningMessage_v01($makeTag);
        return;
      }
      if (updateShopProductEccubeProductId_v01($shopId, $productId, (int)$eccubeNewProductId) != 1) {
        appendEccubeWarningMessage_v01($makeTag);
        return;
      }
    } else {
      array_unshift($args, 'product_id: ' . $eccubeCurrentProductId);
      $query = "mutation {\n  UpdateProductMutation(\n    " . buildEccubeProductMutationArgs_v01($args) . "\n  ) {\n    id\n  }\n}";
      $result = eccube_api_call($query);
      if (is_array($result) === false || array_key_exists('UpdateProductMutation', $result) === false) {
        appendEccubeWarningMessage_v01($makeTag);
        return;
      }
    }
    foreach ($graphqlVariants as $gv) {
      if ((int)$gv['local_variant_id'] > 0) {
        if (updateShopProductVariant((int)$gv['local_variant_id'], $shopId, ['eccube_product_class_code' => $gv['code']]) === false) {
          appendEccubeWarningMessage_v01($makeTag);
          return;
        }
      }
    }
  } catch (Exception $e) {
    appendEccubeWarningMessage_v01($makeTag);
  }
}

#***** タグ生成開始 *****#
switch ($action) {
  #***** 登録（規格保存は未実装） *****#
  case 'sendInput': {
      $validationErrors = [];
      $validatedVariants = [];
      $activeCount = 0;
      $variants = isset($_POST['variants']) && is_array($_POST['variants']) ? $_POST['variants'] : [];
      if ($productIdRaw === '' || ctype_digit($productIdRaw) === false || (int)$productIdRaw < 1) {
        $validationErrors[] = '商品IDの指定が不正です。';
      } else {
        $productId = (int)$productIdRaw;
      }
      if (empty($variants)) {
        $validationErrors[] = '保存する規格情報がありません。';
      }
      $product = null;
      if (empty($validationErrors)) {
        $product = getShopProductData_FindById($shopId, $productId);
        if (empty($product)) {
          $validationErrors[] = '商品が見つかりません。';
        }
      }
      if (empty($validationErrors)) {
        foreach ($variants as $index => $variant) {
          if (!is_array($variant)) {
            $validationErrors[] = '規格行' . ((int)$index + 1) . 'の指定が不正です。';
            continue;
          }
          $rowNumber = ((int)$index + 1);
          $classCategoryId1Raw = isset($variant['class_category_id1']) ? trim((string)$variant['class_category_id1']) : '';
          $classCategoryId1 = 0;
          $classCategoryId2 = null;
          $classCategoryId2Raw = isset($variant['class_category_id2']) ? trim((string)$variant['class_category_id2']) : '';
          $price = 0;
          $stockUnlimitedRaw = isset($variant['stock_unlimited']) ? trim((string)$variant['stock_unlimited']) : '0';
          $stockUnlimited = 0;
          $stock = null;
          $isActiveRaw = isset($variant['is_active']) ? trim((string)$variant['is_active']) : '0';
          $isActive = 0;
          if ($stockUnlimitedRaw === '' || ctype_digit($stockUnlimitedRaw) === false || in_array((int)$stockUnlimitedRaw, [0, 1], true) === false) {
            $validationErrors[] = '規格行' . $rowNumber . 'の在庫無制限の指定が不正です。';
          } else {
            $stockUnlimited = (int)$stockUnlimitedRaw;
          }
          if ($isActiveRaw === '' || ctype_digit($isActiveRaw) === false || in_array((int)$isActiveRaw, [0, 1], true) === false) {
            $validationErrors[] = '規格行' . $rowNumber . 'の有効状態の指定が不正です。';
          } else {
            $isActive = (int)$isActiveRaw;
          }
          if ($classCategoryId1Raw === '' || ctype_digit($classCategoryId1Raw) === false || (int)$classCategoryId1Raw < 1) {
            $validationErrors[] = '規格行' . $rowNumber . 'の分類1が不正です。';
          } else {
            $classCategoryId1 = (int)$classCategoryId1Raw;
            $classify1 = getShopItemClassifyDetails($shopId, $classCategoryId1);
            if (empty($classify1)) {
              $validationErrors[] = '規格行' . $rowNumber . 'の分類1が見つかりません。';
            }
          }
          if ($classCategoryId2Raw !== '') {
            if (ctype_digit($classCategoryId2Raw) === false || (int)$classCategoryId2Raw < 1) {
              $validationErrors[] = '規格行' . $rowNumber . 'の分類2が不正です。';
            } else {
              $classCategoryId2 = (int)$classCategoryId2Raw;
              $classify2 = getShopItemClassifyDetails($shopId, $classCategoryId2);
              if (empty($classify2)) {
                $validationErrors[] = '規格行' . $rowNumber . 'の分類2が見つかりません。';
              }
            }
          }
          if ($classCategoryId2 !== null && $classCategoryId1 === $classCategoryId2) {
            $validationErrors[] = '規格行' . $rowNumber . 'の分類1と分類2に同じ分類は指定できません。';
          }
          $priceRaw = isset($variant['price']) ? trim((string)$variant['price']) : '';
          if ($isActive === 1) {
            $activeCount++;
            if ($priceRaw === '' || ctype_digit($priceRaw) === false || (int)$priceRaw < 1) {
              $validationErrors[] = '規格行' . $rowNumber . 'の販売価格は1以上の整数で入力してください。';
            } else {
              $price = (int)$priceRaw;
            }
          } else {
            if ($priceRaw === '') {
              $price = 0;
            } else if (ctype_digit($priceRaw) === false) {
              $validationErrors[] = '規格行' . $rowNumber . 'の販売価格は整数で入力してください。';
            } else {
              $price = (int)$priceRaw;
            }
          }
          if ($stockUnlimited === 0) {
            $stockRaw = isset($variant['stock']) ? trim((string)$variant['stock']) : '';
            if ($isActive === 1) {
              if ($stockRaw === '' || ctype_digit($stockRaw) === false) {
                $validationErrors[] = '規格行' . $rowNumber . 'の在庫数は0以上の整数で入力してください。';
              } else {
                $stock = (int)$stockRaw;
              }
            } else {
              if ($stockRaw === '') {
                $stock = null;
              } else if (ctype_digit($stockRaw) === false) {
                $validationErrors[] = '規格行' . $rowNumber . 'の在庫数は0以上の整数で入力してください。';
              } else {
                $stock = (int)$stockRaw;
              }
            }
          }
          $validatedVariants[] = [
            'class_category_id1' => $classCategoryId1,
            'class_category_id2' => $classCategoryId2,
            'price' => $price,
            'stock' => $stock,
            'stock_unlimited' => $stockUnlimited,
            'is_active' => $isActive,
          ];
        }
      }
      if (empty($validationErrors) && $activeCount < 1) {
        $validationErrors[] = '有効な規格行を1件以上設定してください。';
      }
      if (!empty($validationErrors)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = implode("\n", $validationErrors);
        break;
      }
      $existingVariantMap = [];
      $existingVariants = getShopProductVariants($shopId, $productId);
      foreach ($existingVariants as $existingVariant) {
        $existingClassCategoryId1 = isset($existingVariant['class_category_id1']) ? (int)$existingVariant['class_category_id1'] : 0;
        $existingClassCategoryId2 = (isset($existingVariant['class_category_id2']) && $existingVariant['class_category_id2'] !== null) ? (int)$existingVariant['class_category_id2'] : null;
        $existingKey = buildVariantCombinationKey($existingClassCategoryId1, $existingClassCategoryId2);
        if (!isset($existingVariantMap[$existingKey])) {
          $existingVariantMap[$existingKey] = $existingVariant;
        }
      }
      try {
        $dbCompleteFlg = true;
        $savedCount = 0;
        $result = DB_Transaction(1);
        if ($result == false) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = '登録エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          break;
        }
        if (deactivateShopProductVariantsByProductId($shopId, $productId) === false) {
          $dbCompleteFlg = false;
        }
        if ($dbCompleteFlg === true) {
          foreach ($validatedVariants as $variantData) {
            $variantKey = buildVariantCombinationKey($variantData['class_category_id1'], $variantData['class_category_id2']);
            if (isset($existingVariantMap[$variantKey])) {
              $existingVariantId = isset($existingVariantMap[$variantKey]['variant_id']) ? (int)$existingVariantMap[$variantKey]['variant_id'] : 0;
              $updateData = [
                'price' => $variantData['price'],
                'stock' => $variantData['stock'],
                'stock_unlimited' => $variantData['stock_unlimited'],
                'is_active' => $variantData['is_active'],
              ];
              if ($existingVariantId < 1 || updateShopProductVariant($existingVariantId, $shopId, $updateData) === false) {
                $dbCompleteFlg = false;
                break;
              }
            } else {
              $insertId = insertShopProductVariant($shopId, $productId, $variantData);
              if ($insertId === false) {
                $dbCompleteFlg = false;
                break;
              }
            }
            $savedCount++;
          }
        }
        if ($dbCompleteFlg === true) {
          $latestVariants = getShopProductVariants($shopId, $productId);
          $activeVariants = [];
          foreach ($latestVariants as $latestVariant) {
            if (isset($latestVariant['is_active']) && (int)$latestVariant['is_active'] === 1) {
              $activeVariants[] = $latestVariant;
            }
          }
          $repPrice = 0;
          $repStock = null;
          $repStockUnlimited = 0;
          if (!empty($activeVariants)) {
            $repPrice = null;
            $allStockUnlimited = true;
            $stockTotal = 0;
            foreach ($activeVariants as $activeVariant) {
              $variantPrice = isset($activeVariant['price']) ? (int)$activeVariant['price'] : 0;
              if ($repPrice === null || $variantPrice < $repPrice) {
                $repPrice = $variantPrice;
              }
              $variantStockUnlimited = isset($activeVariant['stock_unlimited']) && (int)$activeVariant['stock_unlimited'] === 1 ? 1 : 0;
              if ($variantStockUnlimited !== 1) {
                $allStockUnlimited = false;
                $stockTotal += isset($activeVariant['stock']) ? (int)$activeVariant['stock'] : 0;
              }
            }
            $repPrice = ($repPrice === null) ? 0 : $repPrice;
            $repStockUnlimited = $allStockUnlimited ? 1 : 0;
            $repStock = ($repStockUnlimited === 1) ? null : $stockTotal;
          }
          $dbFiledData = array();
          $dbFiledData['price'] = array(':price', $repPrice, 1);
          $dbFiledData['stock'] = array(':stock', $repStock, $repStock === null ? 2 : 1);
          $dbFiledData['stock_unlimited'] = array(':stock_unlimited', $repStockUnlimited, 1);
          $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
          $dbFiledValue = array();
          $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
          $dbFiledValue['product_id'] = array(':product_id', $productId, 1);
          $dbSuccessFlg = SQL_Process($DB_CONNECT, 'shop_products', $dbFiledData, $dbFiledValue, 2, 2);
          if ($dbSuccessFlg != 1) {
            $dbCompleteFlg = false;
          }
        }
        if ($dbCompleteFlg === true) {
          DB_Transaction(2);
          $makeTag['status'] = 'success';
          $makeTag['title'] = '商品規格登録';
          $makeTag['msg'] = '規格の登録が完了しました。';
          $makeTag['product_id'] = $productId;
          $makeTag['variant_count'] = $savedCount;
          syncEccubeProductVariants_v01($makeTag, $shopId, $productId);
          syncFrontendProductJson($makeTag, $shopId, $productId);
        } else {
          DB_Transaction(3);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '登録エラー';
          $makeTag['msg'] = '規格の保存に失敗しました。<br>ページを再読み込みしてください。';
        }
      } catch (Exception $e) {
        DB_Transaction(3);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '規格の保存に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** buildVariants: 組み合わせHTML生成 *****#
  case 'buildVariants': {
      # POST から値取得
      $spec1Raw = isset($_POST['selectVariable01']) ? trim((string)$_POST['selectVariable01']) : '';
      $spec2Raw = isset($_POST['selectVariable02']) ? trim((string)$_POST['selectVariable02']) : '';
      $spec1Id = 0;
      $spec2Id = null;
      if ($spec1Raw !== '' && ctype_digit($spec1Raw) && (int)$spec1Raw > 0) {
        $spec1Id = (int)$spec1Raw;
      }
      if ($spec2Raw !== '') {
        if (ctype_digit($spec2Raw) === false || (int)$spec2Raw < 1) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = '入力エラー';
          $makeTag['msg'] = '規格2の指定が不正です。';
          echo json_encode($makeTag);
          exit;
        }
        $spec2Id = (int)$spec2Raw;
      }
      # バリデーション
      if ($productIdRaw === '' || ctype_digit($productIdRaw) === false || (int)$productIdRaw < 1) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '不正な商品IDです。';
        echo json_encode($makeTag);
        exit;
      }
      $productId = (int)$productIdRaw;
      if ($spec1Id < 1) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '規格1を選択してください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($spec2Id !== null && $spec1Id === $spec2Id) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '規格1と規格2に同じ規格は選択できません。';
        echo json_encode($makeTag);
        exit;
      }
      # 商品存在チェック
      $product = getShopProductData_FindById($shopId, $productId);
      if (empty($product)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['msg'] = '商品が見つかりません。';
        echo json_encode($makeTag);
        exit;
      }
      # 規格存在チェック
      $spec1 = getShopItemSpecificationDetails($shopId, $spec1Id);
      if (empty($spec1)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['msg'] = '規格1が見つかりません。';
        echo json_encode($makeTag);
        exit;
      }
      $spec2 = null;
      if ($spec2Id !== null) {
        $spec2 = getShopItemSpecificationDetails($shopId, $spec2Id);
        if (empty($spec2)) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['msg'] = '規格2が見つかりません。';
          echo json_encode($makeTag);
          exit;
        }
      }
      # 分類一覧取得
      $classify1List = getShopItemClassify($shopId, $spec1Id);
      if (!is_array($classify1List) || count($classify1List) === 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['msg'] = '規格1に分類が登録されていません。';
        echo json_encode($makeTag);
        exit;
      }
      $classify2List = array();
      if ($spec2Id !== null) {
        $classify2List = getShopItemClassify($shopId, $spec2Id);
        if (!is_array($classify2List) || count($classify2List) === 0) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['msg'] = '規格2に分類が登録されていません。';
          echo json_encode($makeTag);
          exit;
        }
      }
      # 既存バリアント取得
      $existingVariants = getShopProductVariants($shopId, $productId);
      $existingVariantMap = [];
      foreach ($existingVariants as $existingVariant) {
        $existingClassCategoryId1 = isset($existingVariant['class_category_id1']) ? (int)$existingVariant['class_category_id1'] : 0;
        $existingClassCategoryId2 = (isset($existingVariant['class_category_id2']) && $existingVariant['class_category_id2'] !== null) ? (int)$existingVariant['class_category_id2'] : null;
        $existingKey = buildVariantCombinationKey($existingClassCategoryId1, $existingClassCategoryId2);
        if (!isset($existingVariantMap[$existingKey])) {
          $existingVariantMap[$existingKey] = $existingVariant;
        }
      }
      # 分類ID抽出ヘルパー
      function getClassifyIdFromRow($row)
      {
        if (!is_array($row)) return 0;
        if (isset($row['class_category_id'])) return (int)$row['class_category_id'];
        if (isset($row['classify_id'])) return (int)$row['classify_id'];
        return 0;
      }
      # HTML生成
      $tag = '';
      $escapedNoUpDateKey = htmlspecialchars((string)$noUpDateKey, ENT_QUOTES, 'UTF-8');
      $escapedProductId = htmlspecialchars((string)$productId, ENT_QUOTES, 'UTF-8');
      $escapedSpec1Id = htmlspecialchars((string)$spec1Id, ENT_QUOTES, 'UTF-8');
      $escapedSpec2Id = $spec2Id !== null ? htmlspecialchars((string)$spec2Id, ENT_QUOTES, 'UTF-8') : '';
      $spec1Name = htmlspecialchars(isset($spec1['name']) ? $spec1['name'] : '', ENT_QUOTES, 'UTF-8');
      $spec2Name = $spec2 !== null ? htmlspecialchars(isset($spec2['name']) ? $spec2['name'] : '', ENT_QUOTES, 'UTF-8') : null;
      $tag .= '<form class="block-details" name="blockDetailsForm">';
      $tag .= '<input type="hidden" name="noUpDateKey" value="' . $escapedNoUpDateKey . '">';
      $tag .= '<input type="hidden" name="productId" value="' . $escapedProductId . '">';
      $tag .= '<input type="hidden" name="spec1Id" id="hidden-spec1Id" value="' . $escapedSpec1Id . '">';
      $tag .= '<input type="hidden" name="spec2Id" id="hidden-spec2Id" value="' . $escapedSpec2Id . '">';
      $tag .= '<div class="inner-top">';
      # 件数は後で数える
      $combCount = 0;
      if ($spec2Id === null) {
        $combCount = count($classify1List);
      } else {
        $combCount = count($classify1List) * count($classify2List);
      }
      $tag .= '<p><span id="variant-count">' . htmlspecialchars((string)$combCount, ENT_QUOTES, 'UTF-8') . '</span>件の組み合わせがあります。</p>';
      $tag .= '<button type="button" id="copy-first-row-btn"><span>1行目を全ての行に複製</span></button>';
      $tag .= '</div>'; // inner-top
      $tag .= '<ul id="variant-table-body">';
      # header
      $tag .= '<li class="list-header">';
      $tag .= '<div>有効</div>';
      $tag .= '<div>' . $spec1Name . '</div>';
      if ($spec2Name !== null) {
        $tag .= '<div>' . $spec2Name . '</div>';
      }
      $tag .= '<div style="padding-left: 3rem">在庫数</div>';
      $tag .= '<div style="align-items: center">販売価格</div>';
      $tag .= '</li>';
      # 組み合わせ行生成
      $index = 0;
      if ($spec2Id === null) {
        foreach ($classify1List as $c1) {
          $classify1Id = getClassifyIdFromRow($c1);
          $classify1Name = htmlspecialchars(isset($c1['name']) ? $c1['name'] : '', ENT_QUOTES, 'UTF-8');
          # 既存バリアント検索
          $variantKey = buildVariantCombinationKey($classify1Id, null);
          $matched = isset($existingVariantMap[$variantKey]) ? $existingVariantMap[$variantKey] : null;
          $variantId = $matched && isset($matched['variant_id']) ? (int)$matched['variant_id'] : '';
          $isActive = ($matched === null) ? 1 : (isset($matched['is_active']) ? (int)$matched['is_active'] : 1);
          $stock = ($matched !== null && isset($matched['stock'])) ? $matched['stock'] : '';
          $stockUnlimited = ($matched !== null && isset($matched['stock_unlimited']) && (int)$matched['stock_unlimited'] === 1) ? 1 : 0;
          $stockReadonly = ($stockUnlimited === 1) ? ' readonly' : '';
          $price = '';
          if ($matched !== null && isset($matched['price']) && (int)$matched['price'] > 0) {
            $price = (int)$matched['price'];
          }
          $tag .= '<li data-class-category-id1="' . htmlspecialchars((string)$classify1Id, ENT_QUOTES, 'UTF-8') . '" data-class-category-id2="" data-variant-id="' . htmlspecialchars((string)$variantId, ENT_QUOTES, 'UTF-8') . '">';
          $tag .= '<input type="hidden" name="variants[' . $index . '][variant_id]" value="' . htmlspecialchars((string)$variantId, ENT_QUOTES, 'UTF-8') . '">';
          $tag .= '<input type="hidden" name="variants[' . $index . '][class_category_id1]" value="' . htmlspecialchars((string)$classify1Id, ENT_QUOTES, 'UTF-8') . '">';
          $tag .= '<input type="hidden" name="variants[' . $index . '][class_category_id2]" value="">';
          $tag .= '<div class="item-toggle"><div class="wrap-toggle-button" data-tooltip-on="有効中 | 無効にする" data-tooltip-off="無効中 | 有効にする"><label class="toggle-button"><input type="checkbox" name="variants[' . $index . '][is_active]" value="1" class="is-active-cb"' . ($isActive === 1 ? ' checked' : '') . '></label></div></div>';
          $tag .= '<div class="item-name"><p>' . $classify1Name . '</p></div>';
          $tag .= '<div class="item-stock"><div class="number"><input type="number" min="0" name="variants[' . $index . '][stock]" class="stock-input" value="' . htmlspecialchars((string)$stock, ENT_QUOTES, 'UTF-8') . '"' . $stockReadonly . '></div>';
          $tag .= '<div class="check-box"><input type="checkbox" name="variants[' . $index . '][stock_unlimited]" id="stockUnlimitedRow' . $index . '" value="1" class="stock-unlimited-cb"' . ($stockUnlimited === 1 ? ' checked' : '') . '><label for="stockUnlimitedRow' . $index . '"> 無制限</label></div></div>';
          $tag .= '<div class="item-price"><input type="number" min="0" name="variants[' . $index . '][price]" class="price-input" value="' . htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') . '"><span>円</span></div>';
          $tag .= '</li>';
          $index++;
        }
      } else {
        foreach ($classify1List as $c1) {
          $classify1Id = getClassifyIdFromRow($c1);
          $classify1Name = htmlspecialchars(isset($c1['name']) ? $c1['name'] : '', ENT_QUOTES, 'UTF-8');
          foreach ($classify2List as $c2) {
            $classify2Id = getClassifyIdFromRow($c2);
            $classify2Name = htmlspecialchars(isset($c2['name']) ? $c2['name'] : '', ENT_QUOTES, 'UTF-8');
            $variantKey = buildVariantCombinationKey($classify1Id, $classify2Id);
            $matched = isset($existingVariantMap[$variantKey]) ? $existingVariantMap[$variantKey] : null;
            $variantId = $matched && isset($matched['variant_id']) ? (int)$matched['variant_id'] : '';
            $isActive = ($matched === null) ? 1 : (isset($matched['is_active']) ? (int)$matched['is_active'] : 1);
            $stock = ($matched !== null && isset($matched['stock'])) ? $matched['stock'] : '';
            $stockUnlimited = ($matched !== null && isset($matched['stock_unlimited']) && (int)$matched['stock_unlimited'] === 1) ? 1 : 0;
            $stockReadonly = ($stockUnlimited === 1) ? ' readonly' : '';
            $price = '';
            if ($matched !== null && isset($matched['price']) && (int)$matched['price'] > 0) {
              $price = (int)$matched['price'];
            }
            $tag .= '<li data-class-category-id1="' . htmlspecialchars((string)$classify1Id, ENT_QUOTES, 'UTF-8') . '" data-class-category-id2="' . htmlspecialchars((string)$classify2Id, ENT_QUOTES, 'UTF-8') . '" data-variant-id="' . htmlspecialchars((string)$variantId, ENT_QUOTES, 'UTF-8') . '">';
            $tag .= '<input type="hidden" name="variants[' . $index . '][variant_id]" value="' . htmlspecialchars((string)$variantId, ENT_QUOTES, 'UTF-8') . '">';
            $tag .= '<input type="hidden" name="variants[' . $index . '][class_category_id1]" value="' . htmlspecialchars((string)$classify1Id, ENT_QUOTES, 'UTF-8') . '">';
            $tag .= '<input type="hidden" name="variants[' . $index . '][class_category_id2]" value="' . htmlspecialchars((string)$classify2Id, ENT_QUOTES, 'UTF-8') . '">';
            $tag .= '<div class="item-toggle"><div class="wrap-toggle-button" data-tooltip-on="有効中 | 無効にする" data-tooltip-off="無効中 | 有効にする"><label class="toggle-button"><input type="checkbox" name="variants[' . $index . '][is_active]" value="1" class="is-active-cb"' . ($isActive === 1 ? ' checked' : '') . '></label></div></div>';
            $tag .= '<div class="item-name"><p>' . $classify1Name . '</p></div>';
            $tag .= '<div class="item-name"><p>' . $classify2Name . '</p></div>';
            $tag .= '<div class="item-stock"><div class="number"><input type="number" min="0" name="variants[' . $index . '][stock]" class="stock-input" value="' . htmlspecialchars((string)$stock, ENT_QUOTES, 'UTF-8') . '"' . $stockReadonly . '></div>';
            $tag .= '<div class="check-box"><input type="checkbox" name="variants[' . $index . '][stock_unlimited]" id="stockUnlimitedRow' . $index . '" value="1" class="stock-unlimited-cb"' . ($stockUnlimited === 1 ? ' checked' : '') . '><label for="stockUnlimitedRow' . $index . '"> 無制限</label></div></div>';
            $tag .= '<div class="item-price"><input type="number" min="0" name="variants[' . $index . '][price]" class="price-input" value="' . htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') . '"><span>円</span></div>';
            $tag .= '</li>';
            $index++;
          }
        }
      }
      $tag .= '</ul>';
      $tag .= '<div class="box-btn"><button type="button" class="btn-confirmed" onclick="sendInput();">登録</button></div>';
      $tag .= '</form>';
      $makeTag['status'] = 'success';
      $makeTag['msg'] = '商品規格の組み合わせを生成しました。';
      $makeTag['tag'] = $tag;
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
