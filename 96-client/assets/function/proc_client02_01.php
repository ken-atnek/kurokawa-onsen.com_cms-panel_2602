<?php
/*
 * [96-client/assets/function/proc_client02_01.php]
 *  - 管理画面 -
 *  自由ページ記事一覧：検索／公開切替／削除
 *
 * [初版]
 *  2026.4.29
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
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_articles.php';
#フロントJSON生成
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeArticleJson.php';

$makeTag = array(
  'tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
);

/**
 * JSONレスポンスを返して終了
 *
 */
function client0201JsonExit($makeTag)
{
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($makeTag);
  exit;
}
/**
 * 検索条件をDB helper用のキーへ整形
 *
 */
function client0201BuildDbSearchConditions($searchConditions)
{
  $dbSearchConditions = $searchConditions;
  $displayFlg = isset($searchConditions['displayFlg']) ? (string)$searchConditions['displayFlg'] : '';
  $dbSearchConditions['searchStatus'] = in_array($displayFlg, array('0', '1'), true) ? $displayFlg : '';
  return $dbSearchConditions;
}
/**
 * 自由記事一覧HTMLを生成
 *
 */
function client0201BuildArticleListTag($articleList)
{
  $tag = <<<HTML
          <ul>
            <li>
              <div>タイトル</div>
              <div>表示位置・順</div>
              <div><span>公開</span><span>設定変更</span></div>
              <div>編集</div>
              <div>削除</div>
            </li>

HTML;
  if (!empty($articleList)) {
    $jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
    foreach ($articleList as $article) {
      $articleIdRaw = (int)$article['article_id'];
      $title = htmlspecialchars((string)$article['title'], ENT_QUOTES, 'UTF-8');
      $displayOrder = htmlspecialchars((string)$article['display_order'], ENT_QUOTES, 'UTF-8');
      $status = (int)$article['status'];
      $statusClass = ($status === 1) ? 'is-active' : 'is-inactive';
      $changeStatusLabel = ($status === 1) ? '非公開へ' : '公開へ';
      $nextStatus = ($status === 1) ? 0 : 1;
      $articleIdJs = json_encode($articleIdRaw, $jsonHex);
      $nextStatusJs = json_encode($nextStatus, $jsonHex);
      $editPageUrl = './products/' . $articleIdRaw;
      switch ((int)$article['article_type']) {
        case 1:
          $editPageUrl = './client02_02_01.php?method=edit&articleId=' . $articleIdRaw;
          break;
        case 2:
          $editPageUrl = './client02_02_02.php?method=edit&articleId=' . $articleIdRaw;
          break;
      }
      $editPageUrl = htmlspecialchars($editPageUrl, ENT_QUOTES, 'UTF-8');
      $tag .= <<<HTML
            <li>
              <div class="item-name"><span>{$title}</span></div>
              <div class="item-position">
                <span><i>{$displayOrder}</i></span>
              </div>
              <div class="item-status">
                <!-- NOTE ↑公開中→[is-active] / 非公開→[is-inactive] -->
                <div class="status {$statusClass}">
                  <span></span>
                </div>
                <div class="btn">
                  <button type="button" onclick="changeArticleStatus({$articleIdJs}, {$nextStatusJs})">{$changeStatusLabel}</button>
                </div>
              </div>
              <div class="item-edit">
                <a href="{$editPageUrl}"></a>
              </div>
              <div class="item-delate">
                <a href="javascript:void(0);" onclick="deleteArticle({$articleIdJs});"></a>
              </div>
            </li>

HTML;
    }
  } else {
    $tag .= <<<HTML
            <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">
              <div>該当するデータが存在しません。</div>
            </li>

HTML;
  }
  $tag .= <<<HTML
          </ul>

HTML;
  return $tag;
}
/**
 * 自由記事一覧レスポンスを組み立て
 *
 */
function client0201BuildListResponse($makeTag, $searchConditions, $shopId, $pagerDisplayMax)
{
  $displayNumber = isset($searchConditions['displayNumber']) ? (int)$searchConditions['displayNumber'] : 10;
  if ($displayNumber < 1) {
    $displayNumber = 10;
  }
  $pageNumber = isset($searchConditions['pageNumber']) ? (int)$searchConditions['pageNumber'] : 1;
  $dbSearchConditions = client0201BuildDbSearchConditions($searchConditions);
  $totalItems = countShopArticles($dbSearchConditions, $shopId);
  $totalPages = (int)ceil($totalItems / $displayNumber);
  if ($totalPages < 1) {
    $totalPages = 1;
  }
  if ($pageNumber < 1) {
    $pageNumber = 1;
  } elseif ($pageNumber > $totalPages) {
    $pageNumber = $totalPages;
  }
  $searchConditions['pageNumber'] = $pageNumber;
  $_SESSION['searchConditions_client02_01'] = $searchConditions;
  $articleList = searchShopArticles($dbSearchConditions, $pageNumber, $displayNumber, $shopId);
  $makeTag['tag'] = client0201BuildArticleListTag($articleList);
  $makeTag['status'] = 'success';
  $makeTag['total_items'] = $totalItems;
  $makeTag['total_count'] = $totalItems;
  $makeTag['total_pages'] = $totalPages;
  $makeTag['page_number'] = $pageNumber;
  $makeTag['pager'] = makePagerBoxTag((int)$pageNumber, (int)$totalPages, $pagerDisplayMax, 'movePage');
  return $makeTag;
}
/**
 * 記事の公開状態を更新
 *
 */
function client0201UpdateArticleStatus($shopId, $articleId, $status)
{
  global $DB_CONNECT;
  $dbFiledData = array();
  $dbFiledData['status'] = array(':status', (int)$status, 1);
  $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
  $dbFiledValue = array();
  $dbFiledValue['article_id'] = array(':where_article_id', (int)$articleId, 1);
  $dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
  $dbFiledValue['is_active'] = array(':where_is_active', 1, 1);
  return SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) == 1;
}
/**
 * 記事を論理削除
 *
 */
function client0201DeleteArticle($shopId, $articleId)
{
  global $DB_CONNECT;
  $now = date('Y-m-d H:i:s');
  $dbFiledData = array();
  $dbFiledData['is_active'] = array(':is_active', 0, 1);
  $dbFiledData['deleted_at'] = array(':deleted_at', $now, 0);
  $dbFiledData['updated_at'] = array(':updated_at', $now, 0);
  $dbFiledValue = array();
  $dbFiledValue['article_id'] = array(':where_article_id', (int)$articleId, 1);
  $dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
  $dbFiledValue['is_active'] = array(':where_is_active', 1, 1);
  return SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) == 1;
}
/**
 * 削除後の表示順を再採番
 *
 */
function client0201RenumberArticleDisplayOrder($shopId)
{
  global $DB_CONNECT;
  $rows = getShopArticleDisplayOrderRows($shopId);
  $displayOrder = 1;
  foreach ($rows as $row) {
    if ((int)$row['display_order'] !== $displayOrder) {
      $dbFiledData = array();
      $dbFiledData['display_order'] = array(':display_order', $displayOrder, 1);
      $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
      $dbFiledValue = array();
      $dbFiledValue['article_id'] = array(':where_article_id', (int)$row['article_id'], 1);
      $dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
      if (SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) != 1) {
        return false;
      }
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
    $makeTag['msg'] = 'セッションが切れました。ページを再読み込みしてください。';
    client0201JsonExit($makeTag);
  }
}
$makeTag['noUpDateKey'] = ($currentNoUpDateKey !== '' ? $currentNoUpDateKey : $noUpDateKey);
#==============================#
# 加盟店権限チェック（shopId固定）
#------------------------------#
$shopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId <= 0) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。再ログインしてください。';
  client0201JsonExit($makeTag);
}
$shopId = (int)$shopId;
#-------------#
#確認
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
#検索タイトル
$searchTitle = isset($_POST['searchTitle']) ? (string)$_POST['searchTitle'] : '';
#公開設定
$displayFlg = isset($_POST['displayFlg']) ? (string)$_POST['displayFlg'] : '';
#h表示件数
$displayNumber = isset($_POST['displayNumber']) ? (int)$_POST['displayNumber'] : $initialDisplayNumber;
if ($displayNumber < 1) {
  $displayNumber = $initialDisplayNumber;
}
#ページ番号
$pageNumber = isset($_POST['pageNumber']) ? (int)$_POST['pageNumber'] : 1;

#***** タグ生成開始 *****#
switch ($action) {
  case 'reset':
    $searchConditions = array(
      'searchTitle' => '',
      'displayFlg' => '1',
      'displayNumber' => $initialDisplayNumber,
      'pageNumber' => 1,
    );
    break;
  case 'search':
  case 'searchConditions':
  case 'changeStatus':
  case 'deleteArticle':
    $searchConditions = array(
      'searchTitle' => $searchTitle,
      'displayFlg' => $displayFlg,
      'displayNumber' => $displayNumber,
      'pageNumber' => $pageNumber,
    );
    break;
  default:
    $searchConditions = array(
      'searchTitle' => '',
      'displayFlg' => '1',
      'displayNumber' => $initialDisplayNumber,
      'pageNumber' => 1,
    );
    break;
}
#***** アクション毎に処理 *****#
$jsonSyncArticleId = 0;
try {
  if ($action === 'changeStatus') {
    $articleId = isset($_POST['articleId']) ? (int)$_POST['articleId'] : 0;
    $status = isset($_POST['status']) ? (int)$_POST['status'] : -1;
    if ($articleId < 1 || in_array($status, array(0, 1), true) === false) {
      throw new RuntimeException('指定内容が不正です。');
    }
    if (getShopArticleData_FindActiveById($shopId, $articleId) === false) {
      throw new RuntimeException('対象の記事が見つかりません。');
    }
    if (DB_Transaction(1) === false) {
      throw new RuntimeException('トランザクション開始に失敗しました。');
    }
    if (client0201UpdateArticleStatus($shopId, $articleId, $status) === false) {
      throw new RuntimeException('公開状態の更新に失敗しました。');
    }
    DB_Transaction(2);
    $makeTag['title'] = '公開設定変更';
    $makeTag['msg'] = '公開設定を変更しました。';
    $jsonSyncArticleId = $articleId;
  } elseif ($action === 'deleteArticle') {
    $articleId = isset($_POST['articleId']) ? (int)$_POST['articleId'] : 0;
    if ($articleId < 1) {
      throw new RuntimeException('記事IDが不正です。');
    }
    if (getShopArticleData_FindActiveById($shopId, $articleId) === false) {
      throw new RuntimeException('対象の記事が見つかりません。');
    }
    if (DB_Transaction(1) === false) {
      throw new RuntimeException('トランザクション開始に失敗しました。');
    }
    if (client0201DeleteArticle($shopId, $articleId) === false) {
      throw new RuntimeException('記事の削除に失敗しました。');
    }
    if (client0201RenumberArticleDisplayOrder($shopId) === false) {
      throw new RuntimeException('表示順の再採番に失敗しました。');
    }
    DB_Transaction(2);
    $makeTag['title'] = '自由記事削除';
    $makeTag['msg'] = '削除が完了しました。';
    $jsonSyncArticleId = $articleId;
  }
  $makeTag = client0201BuildListResponse($makeTag, $searchConditions, $shopId, $pagerDisplayMax);
  if ($jsonSyncArticleId > 0) {
    syncFrontendArticleJson($makeTag, $shopId, $jsonSyncArticleId);
  }
  client0201JsonExit($makeTag);
} catch (Throwable $e) {
  DB_Transaction(3);
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'エラー';
  $makeTag['msg'] = $e->getMessage();
  client0201JsonExit($makeTag);
}
#-------------------------------------------#
#===========================================#