<?php
/*
 * [アカウント情報取得（IDまたは店舗ID指定）]
 *  引数
 *   $intId：アカウントID
 *   $shopId：店舗ID
 */
function accounts_FindById($intId = null, $shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($intId !== null) {
			#ID指定でアカウント情報を取得
			$strSQL = "SELECT account_id, account_type, login_id, password, password_hash FROM accounts WHERE account_id = :account_id AND (locked_until IS NULL OR locked_until < NOW()) LIMIT 1";
			$newStmt = $DB_CONNECT->prepare($strSQL);
			$newStmt->bindValue(':account_id', $intId, PDO::PARAM_INT);
		} elseif ($shopId !== null) {
			#店舗ID指定でアカウント情報を取得
			$strSQL = "SELECT account_id, account_type, login_id, password, password_hash FROM accounts WHERE shop_id = :shop_id AND (locked_until IS NULL OR locked_until < NOW()) LIMIT 1";
			$newStmt = $DB_CONNECT->prepare($strSQL);
			$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		} else {
			#IDも店舗IDも指定されていない場合はnullを返却
			return null;
		}
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$account = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない場合はnullを返却して呼び出し側で判定
		return $account ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
