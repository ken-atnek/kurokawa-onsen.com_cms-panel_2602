<?php
/*
 * [店舗一覧取得]
 */
function getShopList()
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "SELECT 
								shop_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
								postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
								lunch_open_time, lunch_close_time, lunch_note, 
								dinner_open_time, dinner_close_time, dinner_note, 
								regular_holiday_display, closed_weekdays, sort_order, created_at 
						FROM shops WHERE is_active = 1 ORDER BY shop_id DESC";
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
		$strSQL = "SELECT 
								shop_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
								postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
								lunch_open_time, lunch_close_time, lunch_note, 
								dinner_open_time, dinner_close_time, dinner_note, 
								regular_holiday_display, closed_weekdays, sort_order, created_at 
						FROM shops WHERE is_active = 1";
		#WHERE句生成：ヘルパー関数呼び出し
		list($whereSql, $sqlParams) = searchShopHelper($searchConditions);
		$strSQL .= $whereSql;
		$strSQL .= " ORDER BY shop_id DESC";
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
			$strSQL = "SELECT 
								shop_id, is_public,shop_type, shop_name, shop_name_kana, shop_name_en, 
								postal_code, address1, address2, address3, tel, fax, email, is_email_public, website_url, 
								lunch_open_time, lunch_close_time, lunch_note, 
								dinner_open_time, dinner_close_time, dinner_note, 
								regular_holiday_display, closed_weekdays, sort_order, is_active, created_at 
							FROM shops WHERE shop_id = :value LIMIT 1";
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
