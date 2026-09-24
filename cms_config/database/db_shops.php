<?php
/*
 * [店舗一覧取得]
 */
function getShopList()
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "
			SELECT 
				shop_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
				postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
				lunch_open_time, lunch_close_time, lunch_note, 
				dinner_open_time, dinner_close_time, dinner_note, 
				business_hours_types, 
				regular_holiday_display, closed_weekdays, sort_order, created_at 
			FROM 
				shops 
			WHERE 
				is_active = 1 
			ORDER BY 
				shop_id ASC
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$shops = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合は空配列を返却
		return $shops ?: [];
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
/*
 * [店舗一覧検索]
 *  引数
 *   $searchConditions：検索条件配列
 */
function searchShopList($searchConditions)
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "
			SELECT 
				shop_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
				postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
				lunch_open_time, lunch_close_time, lunch_note, 
				dinner_open_time, dinner_close_time, dinner_note, 
				business_hours_types, 
				regular_holiday_display, closed_weekdays, sort_order, created_at 
			FROM 
				shops WHERE is_active = 1
		";
		#WHERE句生成：ヘルパー関数呼び出し
		list($whereSql, $sqlParams) = searchShopHelper($searchConditions);
		$strSQL .= $whereSql;
		$strSQL .= " ORDER BY shop_id ASC";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		foreach ($sqlParams as $paramKey => $paramValue) {
			$newStmt->bindValue($paramKey, $paramValue, PDO::PARAM_STR);
		}
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$shops = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合は空配列を返却
		return $shops ?: [];
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
/*
 * [店舗一覧検索用ヘルパー関数]
 *  引数
 *   $searchConditions：検索条件配列
 *  戻り値
 *   array($whereSql, $sqlParams)
 */
function searchShopHelper($searchConditions)
{
	$whereSql = '';
	$sqlParams = array();
	foreach ($searchConditions as $key => $value) {
		#検索条件設定
		# NOTE: '0' は有効値（例：非公開）なので空判定は厳密比較で行う
		if ($key === '' || $value === '' || $value === null) {
			continue;
		}
		switch ($key) {
			#店舗ID
			case 'shopId':
				#shop_idで検索
				$whereSql .= " AND shop_id = :shop_id";
				$sqlParams[':shop_id'] = $value;
				break;
			#公開設定
			case 'isPublic':
				#is_publicで検索
				$whereSql .= " AND is_public = :is_public";
				$sqlParams[':is_public'] = $value;
				break;
				#その他の条件はここに追加
		}
	}
	#共通WHERE句を応答
	return array($whereSql, $sqlParams);
}
/*
 * [店舗情報取得（ID）]
 *  引数
 *   $shopId：店舗ID
 */
function getShops_FindById($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null) {
			#SQL定義
			$strSQL = "
				SELECT 
					shop_id, eccube_sale_type_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
					postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
					lunch_open_time, lunch_close_time, lunch_note, 
					dinner_open_time, dinner_close_time, dinner_note, 
					business_hours_types, 
					regular_holiday_display, closed_weekdays, sort_order, is_active, created_at 
				FROM 
					shops 
				WHERE 
					shop_id = :value LIMIT 1
			";
		} else {
			#店舗IDが指定されていない場合
			return null;
		}
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':value', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$shop = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合はnullを返却
		return $shop ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
/**
 * 初期JSON生成の候補となる公開中の飲食店IDを取得
 *  予約利用可否の最終判定は呼び出し側で行う。
 */
function getReservationJsonBackfillShopIds()
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare("SELECT shop_id FROM shops WHERE shop_type = :shop_type AND is_active = 1 AND is_public = 1 ORDER BY shop_id ASC");
		if ($stmt === false) {
			return false;
		}
		$stmt->bindValue(':shop_type', 'food', PDO::PARAM_STR);
		if ($stmt->execute() !== true) {
			return false;
		}
		$shopIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
		$stmt->closeCursor();
		return array_map('intval', $shopIds);
	} catch (PDOException $e) {
		return false;
	}
}
/**
 * 予約処理用店舗行ロック取得
 *  transaction開始後に対象店舗行をFOR UPDATEでロックする
 */
function getReservationShopForUpdate($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return null;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				shop_id
			FROM
				shops
			WHERE
				shop_id = :shop_id
			LIMIT 1
			FOR UPDATE
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$shop = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $shop ?: null;
	} catch (PDOException $e) {
		return false;
	}
}
/**
 * 予約共通判定用店舗情報取得
 *  DBエラー時はfalseを返し店舗なしと区別する
 */
function getReservationShopForOccupancy($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return null;
		}

		$strSQL = "
			SELECT
				shop_id,
				is_public,
				shop_type,
				closed_weekdays,
				is_active
			FROM
				shops
			WHERE
				shop_id = :shop_id
			LIMIT 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$shop = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $shop ?: null;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約メール用店舗情報取得
 *  予約成立後の通知に必要な店舗名と店舗メールアドレスだけを返す
 */
function getReservationMailShop($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return null;
		}
		$shopId = (int)$shopId;

		$strSQL = "
			SELECT
				shop_id,
				shop_name,
				email
			FROM
				shops
			WHERE
				shop_id = :shop_id
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$shop = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($shop === false) {
			return null;
		}
		if (
			(int)($shop['shop_id'] ?? 0) !== $shopId ||
			is_string($shop['shop_name'] ?? null) === false ||
			$shop['shop_name'] === '' ||
			(($shop['email'] ?? null) !== null && is_string($shop['email']) === false)
		) {
			return false;
		}

		$shop['shop_id'] = $shopId;
		return $shop;
	} catch (PDOException $e) {
		return false;
	}
}
