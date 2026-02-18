<?php
/*
 * [データベース接続定義]
 *
 * [初版]
 *  2026.2.14
 */

define('DB_DSN', 'localhost');
define('DB_N', 'xbaf8039_km2603kko');
define('DB_USER', 'xbaf8039_kmkko');
define('DB_PASS', 'r|yX-5oW3dWJ');
#-------------------------------------------#
# MySQL DB 接続
function db_connect()
{
	$dsn = DB_DSN;
	$usr = DB_USER;
	$passwd = DB_PASS;
	try {
		#接続文字列に charset=utf8mb4 を含め、多言語文字を安全に扱う
		$connect = new PDO('mysql:host=' . $dsn . '; dbname=' . DB_N . '; charset=utf8mb4', $usr, $passwd);
		$connect->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$connect->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
	} catch (PDOException $e) {
		echo $e->getMessage();
		echo "現在、ページを表示できません。<br>\n";
		exit;
	}
	#応答
	return $connect;
}
#アプリ起動時に共有するDB接続を確立
$DB_CONNECT = db_connect();

/*
 * [DB登録・更新・削除 SQL分生成]
 *  引数
 *   $connect   ：DB接続
 *   $table     ：テーブル
 *   $inSqlParam：入力フィールド(フィールド名をキーとした配列)
 *   $whereParam：条件用フィールド(フィールド名をキーとした配列)
 *   $processFlg：[1].新規登録｜[2].修正｜[3].削除
 *   $exeFlg    ：[1].SQL文生成のみ｜[2].SQL文生成後実行
 */
function SQL_Process($connect, $table, $inSqlParams, $whereParams, $processFlg, $exeFlg)
{
	global $DB_CONNECT;
	$GLOBALS['DB_LAST_ERROR'] = null;
	$SQLDefine = '';
	if ($processFlg == 1) {
		#登録
		$fieldParam = implode(',', array_keys($inSqlParams));
		$dataParam = array();
		foreach ($inSqlParams as $inParam) {
			$dataParam[] = $inParam[0];
		}
		$valuesParam = implode(',', $dataParam);
		$SQLDefine = 'INSERT INTO ' . $table . ' (' . $fieldParam . ')';
		$SQLDefine .= ' VALUES (' . $valuesParam . ')';
	} else if ($processFlg == 2) {
		#更新
		$SQLDefine = 'UPDATE ' . $table . ' SET ';
		$i = 0;
		foreach ($inSqlParams as $key => $inParam) {
			$i++;
			if ($i < count($inSqlParams)) {
				$SQLDefine .= $key . ' = ' . $inParam[0] . ',';
			} else {
				$SQLDefine .= $key . ' = ' . $inParam[0];
			}
		}
	} else if ($processFlg == 3) {
		#削除
		$SQLDefine = 'DELETE FROM ' . $table;
	}
	#条件
	if ($SQLDefine != '' && count($whereParams) > 0) {
		$i = 0;
		foreach ($whereParams as $key => $wParam) {
			$i++;
			if ($i == 1) {
				$SQLDefine .= ' WHERE ' . $key . ' = ' . $wParam[0];
			} else {
				$SQLDefine .= ' AND ' . $key . ' = ' . $wParam[0];
			}
		}
	}
	#echo $SQLDefine;
	try {
		if ($exeFlg == 1) {
			#echo $SQLDefine."<br>";
			$newConnect = $connect->prepare($SQLDefine);
			#応答
			return $newConnect;
		} else if ($exeFlg == 2 && $SQLDefine != '') {
			#実行
			$newConnect = $connect->prepare($SQLDefine);
			#echo $SQLDefine."<br>";
			$exeReturn = pdoQueryExecute($newConnect, $inSqlParams, $whereParams);
			#応答
			return $exeReturn;
		} else {
			#応答
			return 0;
		}
	} catch (PDOException $e) {
		#接続断が発生した場合は再接続してリトライ
		if (strpos($e->getMessage(), 'MySQL server has gone away') !== false) {
			#再接続して再試行
			$DB_CONNECT = db_connect();
			return SQL_Process($DB_CONNECT, $table, $inSqlParams, $whereParams, $processFlg, $exeFlg);
		} else {
			#呼び出し元で処理できるよう失敗として返す（Fatal error を防止）
			$GLOBALS['DB_LAST_ERROR'] = [
				'table' => $table,
				'processFlg' => $processFlg,
				'exeFlg' => $exeFlg,
				'sqlstate' => (string)$e->getCode(),
				'message' => $e->getMessage(),
			];
			return 0;
		}
	}
}
/*
 * [SQL実行]
 *  引数
 *   $connect    ：DB接続
 *   $inSqlParams：テーブル
 *   $whereParams：入力フィールド(フィールド名をキーとした配列)
 */
function pdoQueryExecute($connect, $inSqlParams, $whereParams)
{
	foreach ($inSqlParams as $inParam) {
		#入力フィールドを型に応じて束縛
		switch ($inParam[2]) {
			case 1;
				#数値型
				$connect->bindValue($inParam[0], $inParam[1], PDO::PARAM_INT);
				break;
			case 2:
				#NULL
				$connect->bindValue($inParam[0], NULL, PDO::PARAM_NULL);
				break;
			case 3:
				#BOOLEAN型
				$connect->bindValue($inParam[0], $inParam[1], PDO::PARAM_BOOL);
				break;
			default:
				$connect->bindValue($inParam[0], $inParam[1]);
		}
	}
	if (count($whereParams) > 0) {
		foreach ($whereParams as $wParam) {
			#$connect->bindValue($wParam[0], $wParam[1]);
			#WHEREパラメータも同様に型別で束縛
			switch ($wParam[2]) {
				case 1;
					#数値型
					$connect->bindValue($wParam[0], $wParam[1], PDO::PARAM_INT);
					break;
				case 2:
					#NULL
					$connect->bindValue($wParam[0], NULL, PDO::PARAM_NULL);
					break;
				case 3:
					#BOOLEAN型
					$connect->bindValue($wParam[0], $wParam[1], PDO::PARAM_BOOL);
					break;
				default:
					$connect->bindValue($wParam[0], $wParam[1]);
			}
		}
	}
	#var_dump($inSqlParams, $whereParams);
	$result = $connect->execute();
	if (!$result) {
		$info = $connect->errorInfo();
		$GLOBALS['DB_LAST_ERROR'] = [
			'sqlstate' => (string)($info[0] ?? ''),
			'driver_code' => (string)($info[1] ?? ''),
			'message' => (string)($info[2] ?? ''),
		];
		#失敗応答
		return 0;
	} else {
		#成功応答
		return 1;
	}
}
/*
 * [トランザクション]
 *  引数
 *   $setType
 *    1 = BEGIN
 *    2 = COMMIT
 *    3 = ROLLBACK
 */
function DB_Transaction($setType)
{
	global $DB_CONNECT;
	$TransactionFlg = 1;
	if ($TransactionFlg != 1) {
		#トランザクション利用なし
		return TRUE;
	} else {
		#トランザクション利用
		if ($setType == 1) {
			return $DB_CONNECT->beginTransaction();
		} else if ($setType == 2) {
			if (inTransaction($DB_CONNECT)) {
				return $DB_CONNECT->commit();
			} else {
				return false;
			}
		} else if ($setType == 3) {
			if (inTransaction($DB_CONNECT)) {
				return $DB_CONNECT->rollBack();
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
}
/*
 * [トランザクションが開始されているかチェック]
 *  戻り値
 *   @return トランザクションが開始されていればtrue
 */
function inTransaction($DB_CONNECT)
{
	return $DB_CONNECT->inTransaction();
}
