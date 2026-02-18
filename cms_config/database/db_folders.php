<?php
/*
 * [フォルダ一覧取得]
 *  引数
 *   $shopId：店舗ID
 */
function getFolderList($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId !== null) {
			$strSQL = "SELECT folder_id, shop_id, folder_name, is_active, created_at FROM shops_folders WHERE shop_id = :value AND is_active = 1 ORDER BY created_at DESC";
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
		$folder = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合はnullを返却
		return $folder ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
