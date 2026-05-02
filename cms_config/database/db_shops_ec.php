<?php
/*
 * [商品一覧検索]
 *  引数
 *   $shopId：店舗ID
 *   $searchConditions：検索条件配列
 *   $pageNumber      ：ページ番号
 *   $displayNumber   ：表示件数
 */
function searchShopProductList($shopId = null, $searchConditions = [], $pageNumber = 1, $displayNumber = 10)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return [];
		}
		$pageNumber = (int)$pageNumber;
		$displayNumber = (int)$displayNumber;
		if ($pageNumber < 1) {
			$pageNumber = 1;
		}
		if ($displayNumber < 1) {
			$displayNumber = 10;
		}
		$offset = ($pageNumber - 1) * $displayNumber;
		$where = [
			'p.shop_id = :shop_id',
		];
		$params = [
			':shop_id' => [(int)$shopId, PDO::PARAM_INT],
		];
		$searchProduct = isset($searchConditions['searchProduct']) ? trim((string)$searchConditions['searchProduct']) : '';
		if ($searchProduct !== '') {
			if (is_numeric($searchProduct)) {
				$where[] = '(p.product_id = :product_id OR p.name LIKE :search_product)';
				$params[':product_id'] = [(int)$searchProduct, PDO::PARAM_INT];
			} else {
				$where[] = 'p.name LIKE :search_product';
			}
			$params[':search_product'] = ['%' . $searchProduct . '%', PDO::PARAM_STR];
		}
		$searchCategory = isset($searchConditions['searchCategory']) ? trim((string)$searchConditions['searchCategory']) : '';
		if ($searchCategory !== '' && is_numeric($searchCategory) && (int)$searchCategory > 0) {
			$where[] = 'p.category_id = :category_id';
			$params[':category_id'] = [(int)$searchCategory, PDO::PARAM_INT];
		}
		$displayFlg = isset($searchConditions['displayFlg']) ? (string)$searchConditions['displayFlg'] : '';
		if ($displayFlg === '1') {
			$where[] = 'p.status = :status';
			$params[':status'] = [1, PDO::PARAM_INT];
		} else if ($displayFlg === '2') {
			$where[] = 'p.status = :status';
			$params[':status'] = [0, PDO::PARAM_INT];
		}
		$strSQL = "
			SELECT
				p.*,
				(
					SELECT
						COUNT(*)
					FROM
						shop_product_variants AS v
					WHERE
						v.shop_id = p.shop_id
						AND v.product_id = p.product_id
				) AS variant_count,
				pi.storage_path AS main_image_storage_path
			FROM
				shop_products AS p
				LEFT JOIN shop_product_images AS pi
					ON pi.shop_id = p.shop_id
					AND pi.product_id = p.product_id
					AND pi.sort_order = 1
			WHERE
				" . implode("\n				AND ", $where) . "
			ORDER BY
				p.updated_at DESC,
				p.product_id DESC
			LIMIT :limit OFFSET :offset
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		foreach ($params as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->bindValue(':limit', $displayNumber, PDO::PARAM_INT);
		$newStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$newStmt->execute();
		$productList = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $productList ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [商品一覧件数取得]
 *  引数
 *   $shopId          ：店舗ID
 *   $searchConditions：検索条件配列
 */
function countShopProductList($shopId = null, $searchConditions = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return 0;
		}
		$where = [
			'shop_id = :shop_id',
		];
		$params = [
			':shop_id' => [(int)$shopId, PDO::PARAM_INT],
		];
		$searchProduct = isset($searchConditions['searchProduct']) ? trim((string)$searchConditions['searchProduct']) : '';
		if ($searchProduct !== '') {
			if (is_numeric($searchProduct)) {
				$where[] = '(product_id = :product_id OR name LIKE :search_product)';
				$params[':product_id'] = [(int)$searchProduct, PDO::PARAM_INT];
			} else {
				$where[] = 'name LIKE :search_product';
			}
			$params[':search_product'] = ['%' . $searchProduct . '%', PDO::PARAM_STR];
		}
		$searchCategory = isset($searchConditions['searchCategory']) ? trim((string)$searchConditions['searchCategory']) : '';
		if ($searchCategory !== '' && is_numeric($searchCategory) && (int)$searchCategory > 0) {
			$where[] = 'category_id = :category_id';
			$params[':category_id'] = [(int)$searchCategory, PDO::PARAM_INT];
		}
		$displayFlg = isset($searchConditions['displayFlg']) ? (string)$searchConditions['displayFlg'] : '';
		if ($displayFlg === '1') {
			$where[] = 'status = :status';
			$params[':status'] = [1, PDO::PARAM_INT];
		} else if ($displayFlg === '2') {
			$where[] = 'status = :status';
			$params[':status'] = [0, PDO::PARAM_INT];
		}
		$strSQL = "
			SELECT
				COUNT(*) AS cnt
			FROM
				shop_products
			WHERE
				" . implode("\n				AND ", $where) . "
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($params as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return isset($row['cnt']) ? (int)$row['cnt'] : 0;
	} catch (PDOException $e) {
		return 0;
	}
}
/*
 * [shop_products] 公開中・有効商品数取得
 */
function countShopPublicProducts($shopId)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return 0;
	}
	try {
		$strSQL = "
			SELECT
				COUNT(*) AS cnt
			FROM
				shop_products
			WHERE
				shop_id = :shop_id
				AND status = 1
				AND is_active = 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return isset($row['cnt']) ? (int)$row['cnt'] : 0;
	} catch (PDOException $e) {
		return 0;
	}
}
/*
 * [shop_products] JSON用公開商品一覧取得
 */
/*
 * [shop_product_images] 商品画像一覧取得
 */
function getShopProductImages($shopId = null, $productId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) return [];
	if ($productId === null || is_numeric($productId) === false || (int)$productId < 1) return [];
	try {
		$strSQL = "
			SELECT
				pi.*
			FROM
				shop_product_images AS pi
				INNER JOIN shop_products AS p
					ON p.shop_id = pi.shop_id
					AND p.product_id = pi.product_id
			WHERE
				pi.shop_id = :shop_id
				AND pi.product_id = :product_id
			ORDER BY
				pi.sort_order ASC,
				pi.image_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_product_images] 商品画像全置換
 */
function replaceShopProductImages($shopId, $productId, $images)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) return false;
	if ($productId === null || is_numeric($productId) === false || (int)$productId < 1) return false;
	if (!is_array($images)) return false;
	try {
		$strSQL = "
			DELETE FROM
				shop_product_images
			WHERE
				shop_id = :shop_id
				AND product_id = :product_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		$newStmt->execute();
		$newStmt->closeCursor();
		if (empty($images)) {
			return true;
		}
		$strSQL = "
			INSERT INTO
				shop_product_images (
					shop_id,
					product_id,
					storage_path,
					sort_order,
					created_at,
					updated_at
				)
			VALUES (
				:shop_id,
				:product_id,
				:storage_path,
				:sort_order,
				NOW(),
				NOW()
			)
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($images as $image) {
			$storagePath = isset($image['storage_path']) ? trim((string)$image['storage_path']) : '';
			$sortOrder = isset($image['sort_order']) ? (int)$image['sort_order'] : 0;
			if ($storagePath === '' || $sortOrder < 1) {
				continue;
			}
			$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
			$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
			$newStmt->bindValue(':storage_path', $storagePath, PDO::PARAM_STR);
			$newStmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
			$newStmt->execute();
		}
		$newStmt->closeCursor();
		return true;
	} catch (PDOException $e) {
		return false;
	}
}

function getShopProductListForJson($shopId)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) return [];
	try {
		$strSQL = "
			SELECT
				product_id,
				name,
				price
			FROM
				shop_products
			WHERE
				shop_id = :shop_id
				AND status = 1
				AND is_active = 1
			ORDER BY
				product_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_product_variants] JSON用有効バリアント一覧取得
 */
function getShopProductVariantsForJson($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) return [];
	if ($productId === null || is_numeric($productId) === false || (int)$productId < 1) return [];
	try {
		$strSQL = "
			SELECT
				v.variant_id,
				v.eccube_product_class_code,
				v.class_category_id1,
				c1.name AS class_category_name1,
				v.class_category_id2,
				c2.name AS class_category_name2,
				v.price,
				v.stock,
				v.stock_unlimited
			FROM
				shop_product_variants AS v
				LEFT JOIN shop_class_categories AS c1
					ON v.class_category_id1 = c1.class_category_id
					AND v.shop_id = c1.shop_id
				LEFT JOIN shop_class_categories AS c2
					ON v.class_category_id2 = c2.class_category_id
					AND v.shop_id = c2.shop_id
			WHERE
				v.product_id = :product_id
				AND v.shop_id = :shop_id
				AND v.is_active = 1
			ORDER BY
				v.variant_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}

function getShopItemCategories($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null) {
			#SQL定義
			$strSQL = "
				SELECT
					category_id,
					shop_id,
					eccube_category_id,
					parent_id,
					name,
					sort_order,
					is_active,
					created_at,
					updated_at
				FROM
					shop_categories
				WHERE
					shop_id = :shop_id
				ORDER BY
					sort_order ASC,
					category_id ASC
			";
		} else {
			#店舗IDが指定されていない場合
			return null;
		}
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$categories = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $categories ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [ECカテゴリ重複チェック（rootのみ）]
 *  引数
 *   $shopId：店舗ID
 *   $name  ：カテゴリ名
 *  応答
 *   true  ：存在する
 *   false ：存在しない
 *   null  ：エラー
 */
function isShopItemCategoryNameExistsRoot($shopId = null, $name = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				category_id
			FROM
				shop_categories
			WHERE
				shop_id = :shop_id
				AND parent_id IS NULL
				AND name = :name
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [ECカテゴリ重複チェック（rootのみ／自分除外）]
 *  引数
 *   $shopId           ：店舗ID
 *   $name             ：カテゴリ名
 *   $excludeCategoryId：除外するカテゴリID
 *  応答
 *   true  ：存在する
 *   false ：存在しない
 *   null  ：エラー
 */
function isShopItemCategoryNameExistsRootExcludeCategoryId($shopId = null, $name = null, $excludeCategoryId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($excludeCategoryId === null || is_numeric($excludeCategoryId) === false || (int)$excludeCategoryId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				category_id
			FROM
				shop_categories
			WHERE
				shop_id = :shop_id
				AND parent_id IS NULL
				AND name = :name
				AND category_id <> :exclude_category_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		$newStmt->bindValue(':exclude_category_id', (int)$excludeCategoryId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品カテゴリ sort_order 採番（rootのみ）]
 *  引数
 *   $shopId：店舗ID
 *  応答
 *   int  ：次の sort_order
 *   null ：エラー
 */
function getNextShopItemCategorySortOrderRoot($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				COALESCE(MAX(sort_order), 0) + 1 AS next_sort
			FROM
				shop_categories
			WHERE
				shop_id = :shop_id
				AND parent_id IS NULL
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		if (!empty($row) && isset($row['next_sort']) && is_numeric($row['next_sort'])) {
			return (int)$row['next_sort'];
		}
		return 1;
	} catch (PDOException $e) {
		return null;
	}
}
/**
 * 店舗の最大商品IDを取得する
 *
 * @param int $shopId
 * @return int|null 最大product_id、商品がない場合はnull
 */
function getShopMaxProductId(int $shopId): ?int
{
	global $DB_CONNECT;
	try {
		$newStmt = $DB_CONNECT->prepare(
			'SELECT MAX(product_id) AS max_id FROM shop_products WHERE shop_id = :shop_id'
		);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false || $row['max_id'] === null) {
			return null;
		}
		return (int)$row['max_id'];
	} catch (Exception $e) {
		return null;
	}
}
/*
 * [商品情報取得]
 *  引数
 *   $shopId   ：店舗ID
 *   $productId：商品ID
 */
function getShopProductData_FindById($shopId = null, $productId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($productId === null || is_numeric($productId) === false || (int)$productId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				*
			FROM
				shop_products
			WHERE
				shop_id = :shop_id
				AND product_id = :product_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$product = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $product ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品カテゴリ詳細取得]
 *  引数
 *   $shopId：店舗ID
 *   $categoryId：カテゴリID
 */
function getShopItemCategoryDetails($shopId = null, $categoryId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null && $categoryId !== null) {
			#SQL定義
			$strSQL = "
				SELECT
					category_id,
					shop_id,
					eccube_category_id,
					parent_id,
					name,
					sort_order,
					is_active,
					created_at,
					updated_at
				FROM
					shop_categories
				WHERE
					shop_id = :shop_id AND category_id = :category_id
				ORDER BY
					sort_order ASC,
					category_id ASC
			";
		} else {
			#店舗IDまたはカテゴリIDが指定されていない場合
			return null;
		}
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$category = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $category ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品紐付きカテゴリ存在チェック]
 *  引数
 *   $shopId    ：店舗ID
 *   $categoryId：カテゴリID
 *  応答
 *   true  ：紐付き商品あり
 *   false ：紐付き商品なし
 *   null  ：エラー
 */
function hasShopProductsByCategoryId($shopId = null, $categoryId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($categoryId === null || is_numeric($categoryId) === false || (int)$categoryId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				1
			FROM
				shop_products
			WHERE
				shop_id = :shop_id
				AND category_id = :category_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':category_id', (int)$categoryId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetchColumn();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return ($row !== false);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格一覧取得]
 *  引数
 *   $shopId：店舗ID
 */
function getShopItemSpecifications($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null) {
			#SQL定義
			$strSQL = "
				SELECT
					cn.class_name_id AS specification_id,
					cn.shop_id,
					cn.eccube_class_name_id,
					cn.name,
					cn.backend_name,
					cn.sort_order,
					cn.is_active,
					cn.created_at,
					cn.updated_at,
					COUNT(cc.class_category_id) AS class_category_count
				FROM
					shop_class_names cn
					LEFT JOIN shop_class_categories cc
						ON cc.shop_id = cn.shop_id
						AND cc.class_name_id = cn.class_name_id
				WHERE
					cn.shop_id = :shop_id
				GROUP BY
					cn.class_name_id,
					cn.shop_id,
					cn.eccube_class_name_id,
					cn.name,
					cn.backend_name,
					cn.sort_order,
					cn.is_active,
					cn.created_at,
					cn.updated_at
				ORDER BY
					cn.sort_order ASC,
					cn.class_name_id ASC
			";
		} else {
			#店舗IDが指定されていない場合
			return null;
		}
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$categories = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $categories ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格重複チェック（自分除外なし）]
 */
function isShopItemSpecificationNameExistsRoot($shopId = null, $name = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_name_id
			FROM
				shop_class_names
			WHERE
				shop_id = :shop_id
				AND name = :name
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格重複チェック（自分除外）]
 */
function isShopItemSpecificationNameExistsRootExcludeSpecificationId($shopId = null, $name = null, $excludeSpecificationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($excludeSpecificationId === null || is_numeric($excludeSpecificationId) === false || (int)$excludeSpecificationId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_name_id
			FROM
				shop_class_names
			WHERE
				shop_id = :shop_id
				AND name = :name
				AND class_name_id <> :exclude_specification_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		$newStmt->bindValue(':exclude_specification_id', (int)$excludeSpecificationId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格 sort_order 採番]
 */
function getNextShopItemSpecificationSortOrderRoot($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				COALESCE(MAX(sort_order), 0) + 1 AS next_sort
			FROM
				shop_class_names
			WHERE
				shop_id = :shop_id
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		if (!empty($row) && isset($row['next_sort']) && is_numeric($row['next_sort'])) {
			return (int)$row['next_sort'];
		}
		return 1;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格詳細取得]
 */
function getShopItemSpecificationDetails($shopId = null, $specificationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || $specificationId === null) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_name_id AS specification_id,
				shop_id,
				eccube_class_name_id,
				name,
				backend_name,
				sort_order,
				is_active,
				created_at,
				updated_at
			FROM
				shop_class_names
			WHERE
				shop_id = :shop_id
				AND class_name_id = :specification_id
			ORDER BY
				sort_order ASC,
				class_name_id ASC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $row ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [規格値存在チェック]
 */
function hasShopClassCategoriesBySpecificationId($shopId = null, $specificationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($specificationId === null || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				1
			FROM
				shop_class_categories
			WHERE
				shop_id = :shop_id
				AND class_name_id = :specification_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetchColumn();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return ($row !== false);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格分類一覧取得]
 *  引数
 *   $shopId：店舗ID
 *   $specificationId：規格ID
 */
function getShopItemClassify($shopId = null, $specificationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || $specificationId === null) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				cc.class_category_id AS classify_id,
				cc.shop_id,
				cc.class_name_id AS specification_id,
				cc.eccube_class_category_id,
				cc.name,
				cc.backend_name,
				cc.sort_order,
				cc.is_active,
				cc.created_at,
				cc.updated_at,
				COUNT(pv.variant_id) AS class_category_count
			FROM
				shop_class_categories cc
				LEFT JOIN shop_product_variants pv
					ON pv.shop_id = cc.shop_id
					AND (pv.class_category_id1 = cc.class_category_id OR pv.class_category_id2 = cc.class_category_id)
			WHERE
				cc.shop_id = :shop_id
				AND cc.class_name_id = :specification_id
			GROUP BY
				cc.class_category_id,
				cc.shop_id,
				cc.class_name_id,
				cc.eccube_class_category_id,
				cc.name,
				cc.backend_name,
				cc.sort_order,
				cc.is_active,
				cc.created_at,
				cc.updated_at
			ORDER BY
				cc.sort_order ASC,
				cc.class_category_id ASC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$categories = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $categories ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格分類重複チェック（自分除外なし）]
 */
function isShopItemClassifyNameExistsRoot($shopId = null, $specificationId = null, $name = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($specificationId === null || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_category_id
			FROM
				shop_class_categories
			WHERE
				shop_id = :shop_id
				AND class_name_id = :specification_id
				AND name = :name
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格分類重複チェック（自分除外）]
 */
function isShopItemClassifyNameExistsRootExcludeClassifyId($shopId = null, $specificationId = null, $name = null, $excludeClassifyId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($specificationId === null || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
			return null;
		}
		if ($excludeClassifyId === null || is_numeric($excludeClassifyId) === false || (int)$excludeClassifyId <= 0) {
			return null;
		}
		$name = is_string($name) ? trim($name) : '';
		if ($name === '') {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_category_id
			FROM
				shop_class_categories
			WHERE
				shop_id = :shop_id
				AND class_name_id = :specification_id
				AND name = :name
				AND class_category_id <> :exclude_classify_id
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', $name, PDO::PARAM_STR);
		$newStmt->bindValue(':exclude_classify_id', (int)$excludeClassifyId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return !empty($row);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格分類 sort_order 採番]
 */
function getNextShopItemClassifySortOrderRoot($shopId = null, $specificationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId <= 0) {
			return null;
		}
		if ($specificationId === null || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				COALESCE(MAX(sort_order), 0) + 1 AS next_sort
			FROM
				shop_class_categories
			WHERE
				shop_id = :shop_id
				AND class_name_id = :specification_id
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':specification_id', (int)$specificationId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		if (!empty($row) && isset($row['next_sort']) && is_numeric($row['next_sort'])) {
			return (int)$row['next_sort'];
		}
		return 1;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [商品規格分類詳細取得]
 */
function getShopItemClassifyDetails($shopId = null, $classifyId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || $classifyId === null) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				class_category_id AS classify_id,
				shop_id,
				class_name_id AS specification_id,
				eccube_class_category_id,
				name,
				backend_name,
				sort_order,
				is_active,
				created_at,
				updated_at
			FROM
				shop_class_categories
			WHERE
				shop_id = :shop_id
				AND class_category_id = :classify_id
			ORDER BY
				sort_order ASC,
				class_category_id ASC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':classify_id', (int)$classifyId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $row ?: null;
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [分類値紐付き存在チェック]
 */
function hasShopClassCategoriesByClassifyId($shopId = null, $classifyId = null)
{
	global $DB_CONNECT;
	try {
		if ($classifyId === null || is_numeric($classifyId) === false || (int)$classifyId <= 0) {
			return null;
		}
		#SQL定義
		$strSQL = "
			SELECT
				1
			FROM
				shop_product_variants
			WHERE
				(class_category_id1 = :classify_id1 OR class_category_id2 = :classify_id2)
			LIMIT 1
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':classify_id1', (int)$classifyId, PDO::PARAM_INT);
		$newStmt->bindValue(':classify_id2', (int)$classifyId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$row = $newStmt->fetchColumn();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return ($row !== false);
	} catch (PDOException $e) {
		return null;
	}
}
/*
 * [shop_product_variants] バリアント一覧取得
 */
function getShopProductVariants($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return [];
	if ($productId === null || !is_numeric($productId) || (int)$productId < 1) return [];
	try {
		#SQL定義
		$strSQL = "
			SELECT 
				* 
			FROM 
				shop_product_variants 
			WHERE 
				shop_id = :shop_id AND product_id = :product_id 
			ORDER BY 
				variant_id DESC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_product_variants] 商品バリアント表示一覧取得
 */
function getShopProductVariantDisplayList($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return [];
	if ($productId === null || !is_numeric($productId) || (int)$productId < 1) return [];
	try {
		#SQL定義
		$strSQL = "
			SELECT
				v.*,
				c1.name AS class_category_name1,
				c2.name AS class_category_name2,
				c1.class_name_id AS class_name_id1,
				c2.class_name_id AS class_name_id2,
				c1.class_name_id AS specification_id1,
				c2.class_name_id AS specification_id2
			FROM
				shop_product_variants AS v
				LEFT JOIN shop_class_categories AS c1
					ON c1.shop_id = v.shop_id
					AND c1.class_category_id = v.class_category_id1
				LEFT JOIN shop_class_categories AS c2
					ON c2.shop_id = v.shop_id
					AND c2.class_category_id = v.class_category_id2
			WHERE
				v.shop_id = :shop_id
				AND v.product_id = :product_id
				AND v.is_active = 1
			ORDER BY
				v.variant_id ASC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [shop_product_variants] 商品バリアント登録
 */
function insertShopProductVariant($shopId, $productId, $data)
{
	global $DB_CONNECT;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return false;
	if ($productId === null || !is_numeric($productId) || (int)$productId < 1) return false;
	if (!is_array($data)) return false;
	if (!isset($data['class_category_id1']) || $data['class_category_id1'] === null) return false;
	if (!isset($data['price']) || $data['price'] === null) return false;
	$class_category_id2 = isset($data['class_category_id2']) ? $data['class_category_id2'] : null;
	if ($class_category_id2 === '') {
		$class_category_id2 = null;
	}
	$price = $data['price'];
	$stock = array_key_exists('stock', $data) ? $data['stock'] : null;
	if ($stock === '') {
		$stock = null;
	}
	$stock_unlimited = isset($data['stock_unlimited']) && $data['stock_unlimited'] ? 1 : 0;
	$is_active = isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1;
	try {
		#SQL定義
		$strSQL = "
			INSERT INTO 
				shop_product_variants (
					product_id, 
					shop_id, 
					class_category_id1, 
					class_category_id2, 
					price, stock, 
					stock_unlimited, 
					is_active, 
					created_at, 
					updated_at
				) 
			VALUES (
				:product_id, 
				:shop_id, 
				:class_category_id1, 
				:class_category_id2, 
				:price, 
				:stock, 
				:stock_unlimited, 
				:is_active, 
				NOW(), 
				NOW()
			)
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':class_category_id1', (int)$data['class_category_id1'], PDO::PARAM_INT);
		if ($class_category_id2 === null) {
			$newStmt->bindValue(':class_category_id2', null, PDO::PARAM_NULL);
		} else {
			$newStmt->bindValue(':class_category_id2', (int)$class_category_id2, PDO::PARAM_INT);
		}
		$newStmt->bindValue(':price', (int)$price, PDO::PARAM_INT);
		if ($stock === null) {
			$newStmt->bindValue(':stock', null, PDO::PARAM_NULL);
		} else {
			$newStmt->bindValue(':stock', (int)$stock, PDO::PARAM_INT);
		}
		$newStmt->bindValue(':stock_unlimited', $stock_unlimited, PDO::PARAM_INT);
		$newStmt->bindValue(':is_active', $is_active, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$lastId = (int)$DB_CONNECT->lastInsertId();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return $lastId;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_product_variants] バリアント1件更新
 */
function updateShopProductVariant($variantId, $shopId, $data)
{
	global $DB_CONNECT;
	if ($variantId === null || !is_numeric($variantId) || (int)$variantId < 1) return false;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return false;
	if (!is_array($data) || empty($data)) return false;
	$allowed = ['price', 'stock', 'stock_unlimited', 'is_active', 'eccube_product_class_id', 'eccube_product_class_code'];
	$fields = [];
	$params = [];
	foreach ($allowed as $col) {
		if (array_key_exists($col, $data)) {
			$fields[] = "$col = :$col";
			$params[$col] = $data[$col];
		}
	}
	if (empty($fields)) return false;
	try {
		#SQL定義
		$strSQL = "UPDATE shop_product_variants SET " . implode(", ", $fields) . ", updated_at = NOW() WHERE variant_id = :variant_id AND shop_id = :shop_id";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($params as $col => $val) {
			#変数バインド
			if ($col === 'stock' || $col === 'eccube_product_class_id' || $col === 'eccube_product_class_code') {
				if ($val === null) {
					$newStmt->bindValue(":$col", null, PDO::PARAM_NULL);
				} else {
					if ($col === 'stock' || $col === 'eccube_product_class_id') {
						$newStmt->bindValue(":$col", (int)$val, PDO::PARAM_INT);
					} else {
						$newStmt->bindValue(":$col", $val, PDO::PARAM_STR);
					}
				}
			} else if ($col === 'stock_unlimited' || $col === 'is_active') {
				$newStmt->bindValue(":$col", $val ? 1 : 0, PDO::PARAM_INT);
			} else if ($col === 'price') {
				$newStmt->bindValue(":$col", (int)$val, PDO::PARAM_INT);
			}
		}
		$newStmt->bindValue(':variant_id', (int)$variantId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return true;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_product_variants] 商品バリアント全件削除
 */
function deleteShopProductVariantsByProductId($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return false;
	if ($productId === null || !is_numeric($productId) || (int)$productId < 1) return false;
	try {
		#SQL定義
		$strSQL = "
			DELETE FROM 
				shop_product_variants 
			WHERE 
				shop_id = :shop_id AND product_id = :product_id
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return true;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [shop_product_variants] 商品バリアント全件非活性化
 */
function deactivateShopProductVariantsByProductId($shopId, $productId)
{
	global $DB_CONNECT;
	if ($shopId === null || !is_numeric($shopId) || (int)$shopId < 1) return false;
	if ($productId === null || !is_numeric($productId) || (int)$productId < 1) return false;
	try {
		#SQL定義
		$strSQL = "
			UPDATE
				shop_product_variants
			SET
				is_active = 0,
				updated_at = NOW()
			WHERE
				shop_id = :shop_id
				AND product_id = :product_id
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':product_id', (int)$productId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#ステートメントクローズ
		$newStmt->closeCursor();
		return true;
	} catch (PDOException $e) {
		return false;
	}
}
