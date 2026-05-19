<?php
/*
 * [96-client/assets/function/proc_client02_03.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事 表示順変更 処理
 *
 * [初版]
 *  2026.5.14
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#自由ページ記事情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_articles.php';
#フロントJSON生成
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeArticleJson.php';

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
	'tag' => '',
	'status' => '',
	'title' => '',
	'msg' => '',
);

/**
 * JSONレスポンスを返して処理を終了
 */
function client0203JsonExit($makeTag)
{
	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($makeTag);
	exit;
}

/**
 * POST の articleIds JSON を検証して int[] に正規化
 */
function client0203NormalizeArticleIdList($raw)
{
	if ($raw === '' || $raw === null) {
		return null;
	}
	$decoded = json_decode($raw, true);
	if (!is_array($decoded) || count($decoded) === 0) {
		return null;
	}
	$ids = [];
	foreach ($decoded as $v) {
		if (!is_numeric($v) || (int)$v < 1) {
			return null;
		}
		$ids[] = (int)$v;
	}
	return $ids;
}

/**
 * 表示順を更新（display_order が変わる記事だけ UPDATE）
 */
function client0203UpdateArticleDisplayOrder($shopId, $articleIds, $currentOrderMap)
{
	global $DB_CONNECT;
	$displayOrder = 1;
	foreach ($articleIds as $articleId) {
		$articleId = (int)$articleId;
		$currentOrder = isset($currentOrderMap[$articleId]) ? (int)$currentOrderMap[$articleId] : 0;
		if ($currentOrder === $displayOrder) {
			$displayOrder++;
			continue;
		}
		$dbFiledData = array();
		$dbFiledData['display_order'] = array(':display_order', $displayOrder, 1);
		$dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
		$dbFiledValue = array();
		$dbFiledValue['article_id'] = array(':where_article_id', $articleId, 1);
		$dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
		$dbFiledValue['is_active'] = array(':where_is_active', 1, 1);
		if (SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) != 1) {
			return false;
		}
		$displayOrder++;
	}
	return true;
}

#=============#
# POSTチェック
#-------------#
#セッションキー（画面インスタンス識別）
$noUpDateKey = isset($_POST['noUpDateKey']) ? (string)$_POST['noUpDateKey'] : '';
$currentNoUpDateKey = isset($_SESSION['sKey']) ? (string)$_SESSION['sKey'] : '';
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
	if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
		$noUpDateKey = $currentNoUpDateKey;
	} else {
		$makeTag['status'] = 'error';
		$makeTag['title'] = 'セッションエラー';
		$makeTag['msg'] = 'セッションが切れました。<br>ページを再読み込みしてください。';
		$makeTag['noUpDateKey'] = $currentNoUpDateKey;
		client0203JsonExit($makeTag);
	}
}
#応答には常に現行のキーを含め、フロント側のhiddenを更新できるようにする
$makeTag['noUpDateKey'] = ($currentNoUpDateKey !== '' ? $currentNoUpDateKey : $noUpDateKey);
#-------------#
#shopId取得
$shopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId <= 0) {
	$makeTag['status'] = 'error';
	$makeTag['title'] = 'セッションエラー';
	$makeTag['msg'] = '店舗情報が取得できませんでした。<br>再ログインしてください。';
	client0203JsonExit($makeTag);
}
$shopId = (int)$shopId;
#-------------#
#アクション
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
#-------------#

try {
	if ($action === 'updateDisplayOrder') {
		#POSTされた記事ID順序
		$rawArticleIds = isset($_POST['articleIds']) ? (string)$_POST['articleIds'] : '';
		$articleIds = client0203NormalizeArticleIdList($rawArticleIds);
		if ($articleIds === null) {
			throw new RuntimeException('並び順データが不正です。');
		}
		#DBの現在の記事ID一覧と照合（集合一致チェック）
		$dbRows = getShopArticleRowsForDisplayOrder($shopId);
		$dbArticleIds = array_map(function($r) { return (int)$r['article_id']; }, $dbRows);
		$submittedSorted = $articleIds;
		$dbSorted = $dbArticleIds;
		sort($submittedSorted);
		sort($dbSorted);
		if ($submittedSorted !== $dbSorted) {
			throw new RuntimeException('記事一覧が更新されています。<br>ページを再読み込みしてください。');
		}
		#現在の display_order マップ（article_id => display_order）
		$currentOrderMap = array();
		foreach ($dbRows as $row) {
			$currentOrderMap[(int)$row['article_id']] = (int)$row['display_order'];
		}
		#トランザクション開始
		if (DB_Transaction(1) === false) {
			throw new RuntimeException('トランザクション開始に失敗しました。');
		}
		#表示順更新（変更がある行のみ）
		if (client0203UpdateArticleDisplayOrder($shopId, $articleIds, $currentOrderMap) === false) {
			throw new RuntimeException('表示順の更新に失敗しました。');
		}
		DB_Transaction(2);
		$makeTag['status'] = 'success';
		$makeTag['title'] = '並び順変更';
		$makeTag['msg'] = '並び順を更新しました。';
		syncFrontendShopArticleJsons($makeTag, $shopId);
	} else {
		throw new RuntimeException('不正なアクションです。');
	}
	client0203JsonExit($makeTag);
} catch (Throwable $e) {
	DB_Transaction(3);
	$makeTag['status'] = 'error';
	$makeTag['title'] = 'エラー';
	$makeTag['msg'] = $e->getMessage();
	client0203JsonExit($makeTag);
}
