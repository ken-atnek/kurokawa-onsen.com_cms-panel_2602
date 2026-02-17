<?php
/*
 * [文字コードセット]
 *
 */
header("Content-Type: text/html; charset=UTF-8");
ini_set('session.gc_maxlifetime', 5 * 60 * 60);
ini_set("error_reporting", 2039);
ini_set("display_errors", 1);

#***** 定数定義ファイル：インクルード *****#
require_once __DIR__ . '/../common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once __DIR__ . '/../common/set_function.php';
#***** DB設定ファイル：インクルード *****#
require_once __DIR__ . '/../database/set_db.php';
#ログイン関数
require_once __DIR__ . '/../database/set_login.php';

/*
 * [セッション宣言]
 *
 */
session_cache_limiter('private,must-revalidate');
#セッション名設定
session_name('KKY_SHOP_SESSID');
#セッションCookie設定
session_set_cookie_params([
	'lifetime' => 0,
	'path' => '/',
	'domain' => '',
	'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
	'httponly' => true,
	'samesite' => 'Lax'
]);
session_start();

/*
 * [セッション情報取得]
 *
 */
$loginCheck = 0;
if (isset($_SESSION['client_login']['status'])) {
	$loginCheck = $_SESSION['client_login']['status'];
}

/*
 * [ログインチェック]
 *
 */
if ($loginCheck == 1) {
	#保存済みセッションIDから改めてアカウントが有効かチェック
	$accountId = $_SESSION['client_login']['account_id'] ?? null;
	$currentAccount = null;
	if ($accountId !== null) {
		$currentAccount = accounts_FindActiveById($accountId);
	}
	if ($currentAccount) {
		#事業所アカウント以外であればセッションを破棄してログイン画面へ
		if ($currentAccount['account_type'] !== 'facility' || $currentAccount['facility_id'] === null) {
			$_SESSION = [];
			session_destroy();
			header("Location: ./");
			exit;
		}
		#セッション継続が許可されたタイミングをログに追記
		$data = [
			'login_id' => $currentAccount['account_id'],
			'account_type' => $currentAccount['account_type'],
			'login_day' => date("Y-m-d H:i:s"),
		];
		makeLog($data);
	} else {
		#DB上で無効化された場合などは即座にログイン画面へ戻す
		$_SESSION["login_err"] = "ログインセッションが無効です。再度ログインしてください";
		header("Location: ./?loginERR=1");
		exit;
	}
}

/*
 * [ログ情報作成]
 *  引数
 *   $data：ログ情報データ
 */
function makeLog($data)
{
	$time = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
	ob_start();
	echo '------------------------------', PHP_EOL;
	echo $time->format('Y.m.d日 H:i:s'), PHP_EOL;
	echo '------------------------------', PHP_EOL;
	print_r($data);
	$output = ob_get_contents();
	ob_end_clean();
	#print_rの出力からArrayと括弧を除去
	$output = preg_replace('/^Array\s*\(\s*\n/m', '', $output);
	$output = preg_replace('/^\)\s*$/m', '', $output);
	$output = preg_replace('/\n\s*\n/', "\n", $output);
	$output = preg_replace('/^\s{4}/', '', $output);
	$output = preg_replace('/\n\s{4}/', "\n", $output);
	#print_rの出力からArrayと括弧を除去
	$dump = $output;
	#本日日付取得
	$today = date("Y/m/d");
	#ベースログファイルディレクトリ
	#$baseDir = '/home/xbaf8039/kurokawa-onsen.com/public_html/_maintenance_log/';
	$baseDir = '../../../_maintenance_log/';
	#今日の日付のディレクトリパス
	$todayDir = $baseDir . $today . '/';
	#account_idが$data内に存在するかチェック
	$accountId = null;
	if (is_array($data) && isset($data['account_id'])) {
		$accountId = $data['account_id'];
	} elseif (is_object($data) && isset($data->account_id)) {
		$accountId = $data->account_id;
	}
	#account_idが存在する場合は宿番号ディレクトリを追加
	if ($accountId !== null) {
		$todayDir = $todayDir . $accountId . '/';
	}
	#ディレクトリが存在しない場合は作成
	if (!is_dir($todayDir)) {
		@mkdir($todayDir, 0755, true);
	}
	#ログファイル名生成
	$logFile = 'shop_operation.log';
	@file_put_contents($todayDir . $logFile, $dump, FILE_APPEND);
}
