<?php
/*
 * [店舗紹介情報取得]
 *  引数
 *   $shopId：店舗ID
 */
function getShopDetailsData($shopId = null)
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "
			SELECT 
				shop_id, intro_body,intro_body_en, 
				main_image_path, image_path_1, image_title_1, image_path_2, image_title_2, image_path_3, image_title_3, 
				map_url, map_link_url 
			FROM 
				shops_details 
			WHERE shop_id = :value
		";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':value', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$shops = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合は空配列を返却
		return $shops ?: [];
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
