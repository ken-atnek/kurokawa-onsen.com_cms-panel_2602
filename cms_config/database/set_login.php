<?php
/*
 * [ログイン認証用アカウント取得]
 *  引数
 *   $strLoginId：ログインID
 */
function accounts_Login($strLoginId)
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "SELECT account_id, account_type, shop_id, login_id, password_hash FROM accounts WHERE is_active = 1 AND login_id = :login_id AND (locked_until IS NULL OR locked_until < NOW()) LIMIT 1";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':login_id', $strLoginId);
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
/*
 * [アクティブアカウント取得]
 *  引数
 *   $accountId：アカウント内部ID
 */
function accounts_FindActiveById($accountId)
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "SELECT account_id, account_type, shop_id, login_id FROM accounts WHERE account_id = :account_id AND is_active = 1 AND (locked_until IS NULL OR locked_until < NOW()) LIMIT 1";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$account = $newStmt->fetch(PDO::FETCH_ASSOC);
		#ステートメントクローズ
		$newStmt->closeCursor();
		#存在しない／無効状態ならnullを返却
		return $account ?: null;
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
/*
 * [最終ログイン日時更新]
 *  引数
 *   $accountId：アカウント内部ID
 */
function accounts_UpdateLastLoginAt($accountId)
{
	global $DB_CONNECT;
	try {
		#SQL定義
		$strSQL = "UPDATE accounts SET last_login_at = NOW() WHERE account_id = :account_id";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
	} catch (PDOException $e) {
		echo $e->getMessage();
		exit;
	}
}
