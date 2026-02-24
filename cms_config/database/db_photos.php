<?php
/*
 * [写真一覧取得]
 *  引数
 *   $shopId：店舗ID
 */
function getPhotoList($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null) {
			$strSQL = "
				SELECT 
					photo_id, shop_id, folder_id, file_path, title, 
					mime_type, file_size, width, height, is_active, created_at 
				FROM 
					shops_photos 
				WHERE 
					shop_id = :value AND is_active = 1 
				ORDER BY created_at DESC
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
		$photo = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合はnullを返却
		return $photo ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
/*
 * [削除対象写真取得]
 *  引数
 *   $shopId：店舗ID
 *   $photoId：写真ID
 */
function getDeletePhoto($shopId = null, $photoId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null && $photoId !== null) {
			$strSQL = "
				SELECT 
					photo_id, shop_id, folder_id, file_path, title, 
					mime_type, file_size, width, height, is_active, created_at 
				FROM 
					shops_photos 
				WHERE shop_id = :shop_id AND photo_id = :photo_id AND is_active = 1 LIMIT 1
			";
		} else {
			#店舗IDまたは写真IDが指定されていない場合
			return null;
		}
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':photo_id', $photoId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$deletePhoto = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合はnullを返却
		return $deletePhoto ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
