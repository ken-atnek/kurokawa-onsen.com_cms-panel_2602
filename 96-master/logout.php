<?php
/*
 * [rw-master/index.php]
 *  - 管理画面 -
 *  ログアウト
 *
 * [初版]
 *  2025.12.13
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** セッション初期化ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';

/***** セッション破棄 *****/
#セッション情報を完全に初期化してから破棄
$_SESSION = [];
unset($_SESSION['master_login']);
session_destroy();

/***** 表示：ログインページへリダイレクト *****/
header("Location: ./");
exit;
