<?php
/*
 * 型宣言を厳密にする
 */

declare(strict_types=1);

/*
 * [定数定義]
 */
#デバッグモード設定
define('DEFINE_DEBUGFLG', 0);

#ドメイン定義
#define('DOMAIN_NAME', 'https://kurokawa-onsen.com');
define('DOMAIN_NAME', '../../2603/public');

#ドキュメントルート定義
define('DOCUMENT_ROOT_PATH', dirname(__DIR__) . '/../');

/*
 * PUBLIC_HTML直下のパス定義
 * ここを起点に public_html を確定し、以後は絶対パスで扱う
 */
$publicHtml = realpath(__DIR__ . '/../../../');
if ($publicHtml === false) {
	throw new RuntimeException('PUBLIC_HTML の特定に失敗しました: ' . __DIR__);
}
#Windows対策(\ を / に統一しておくと扱いが安定)
$publicHtml = str_replace('\\', '/', $publicHtml);
#PUBLIC_HTMLパス
define('PUBLIC_HTML_DIR', $publicHtml);

#フロントエンド側ディレクトリパス
#define('DEFINE_FRONTEND_DIR_PATH', PUBLIC_HTML_DIR . '/2603');
define('DEFINE_FRONTEND_DIR_PATH', PUBLIC_HTML_DIR . '/2603/public');

#===================================#
#json生成ファンクションファイルパス
define('DEFINE_JSON_FUNCTION_MASTER', PUBLIC_HTML_DIR . '/cms-panel_2602/cms_config/common');

#===================================#
#jsonデータ保存パス
define('DEFINE_JSON_DIR_PATH', DEFINE_FRONTEND_DIR_PATH . '/db');

#フロント側マスタ定義jsonファイル
define('DEFINE_MASTER_JSON_DIR_PATH', DEFINE_FRONTEND_DIR_PATH . '/db/master');

#プレビュー画像保存パス
define('DEFINE_PREVIEW_IMAGE_DIR_PATH', '../../../tmp_upload');

#事業所登録画像ファイル保存パス
define('DEFINE_FILE_DIR_PATH', DEFINE_JSON_DIR_PATH . '/images');

#===================================#
#管理画面URL
$CMS_PANEL_URL = 'https://cms-panel.kurokawa-onsen.com';

#メールアドレス送信リスト
$sendAddressList = array(
	'shigetaka@a-fact.co.jp',
);

#マスター通知先（固定）
#※未設定の場合は $sendAddressList の先頭をフォールバック
$DEFINE_MASTER_NOTIFY_EMAIL = $sendAddressList[0] ?? '';
$DEFINE_MASTER_NOTIFY_NAME = '黒川温泉観光協会';

#NoReplyメールアドレス
$DEFINE_NO_REPLY = 'noreply@kurokawa-onsen.com';

#メール送信元名称
$DEFINE_MAIL_SENDER_NAME = '黒川温泉観光協会事務局';
