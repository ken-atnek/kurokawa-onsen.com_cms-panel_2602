<?php
/*
 * [96-client/client02_01.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事一覧
 *
 * [初版]
 *  2026.5.14
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
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

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'cKey02-01_';
$searchConditionsSessionKey = 'searchConditions_client02_01';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
#セッション本体の初期化
$_SESSION[$noUpDateKey] = array();
#アカウントキー
$_SESSION[$noUpDateKey]['clientKey'] = $_SESSION['client_login']['account_id'];
#データ取得エラー
if ($_SESSION[$noUpDateKey]['clientKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}

#=============#
# POSTチェック
#-------------#
#店舗ID（編集／削除時のみ）
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
} else {
  #不正アクセス：ログインページへリダイレクト
  header("Location: ./logout.php");
  exit;
}

#=======#
# 店舗名
#-------#
$headerShopName = "";
if (!isset($shopData) || empty($shopData)) {
  #店舗データが無い場合は不正アクセス：ログインページへリダイレクト
  header("Location: ./logout.php");
  exit;
} else {
  $headerShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
}

#-------------#
#検索・絞り込み条件保持用セッションチェック
$searchConditions = array();
if (isset($_SESSION[$searchConditionsSessionKey]) === false || !is_array($_SESSION[$searchConditionsSessionKey])) {
  #セッション無し：初期化
  $_SESSION[$searchConditionsSessionKey] = array(
    'displayFlg' => '1',
    'searchTitle' => '',
    'displayNumber' => $initialDisplayNumber,
    'pageNumber' => 1
  );
  #初期値セット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
} else {
  #既存セッションがあれば変数にセット
  $searchConditions = $_SESSION[$searchConditionsSessionKey];
}
#必須キーが欠けている場合は初期化（運用上は常に揃う前提）
$requiredKeys = ['displayFlg', 'searchTitle', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $searchConditions)) {
    $searchConditions = array(
      'displayFlg' => isset($searchConditions['displayFlg']) ? (string)$searchConditions['displayFlg'] : '',
      'searchTitle' => isset($searchConditions['searchTitle']) ? (string)$searchConditions['searchTitle'] : '',
      'displayNumber' => isset($searchConditions['displayNumber']) ? (int)$searchConditions['displayNumber'] : $initialDisplayNumber,
      'pageNumber' => isset($searchConditions['pageNumber']) ? (int)$searchConditions['pageNumber'] : 1
    );
    break;
  }
}
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
#-------------#
#表示件数ページ・表示件数設定
$displayNumber = isset($searchConditions['displayNumber']) ? intval($searchConditions['displayNumber']) : $initialDisplayNumber;
$pageNumber = isset($searchConditions['pageNumber']) ? intval($searchConditions['pageNumber']) : 1;
if ($displayNumber < 1) {
  $displayNumber = $initialDisplayNumber;
}
#受注数取得：検索結果
$dbSearchConditions = $searchConditions;
$dbSearchConditions['searchStatus'] = (isset($searchConditions['displayFlg']) && in_array((string)$searchConditions['displayFlg'], ['0', '1'], true)) ? (string)$searchConditions['displayFlg'] : '';
$totalItems = countShopArticles($dbSearchConditions, $shopId);
$totalItemsHtml = htmlspecialchars((string)$totalItems, ENT_QUOTES, 'UTF-8');
#総件数（ページャー用）
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
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
$searchTitleHtml = htmlspecialchars($searchConditions['searchTitle'] ?? '', ENT_QUOTES, 'UTF-8');

#=========#
# 記事一覧
#---------#
$articleList = searchShopArticles($dbSearchConditions, $pageNumber, $displayNumber, $shopId);

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(加盟店)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/client02-01.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-02-01 status-client">
    <section class="container-left-menu menu-color02">
      <div class="title">サイト管理</div>
      <nav>
        <a href="./client02_01.php" {$client02_01_active}><span>自由記事一覧</span></a>
        <a href="./client02_02.php" {$client02_02_active}><span>自由記事登録</span></a>
        <a href="./client02_03.php" {$client02_03_active}><span>自由記事並び順変更</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color02">
      <div class="block_inner">
        <h2>自由ページ一覧</h2>
        <form name="searchForm" class="head-search-setting">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <h3>検索条件設定</h3>
          <dl>
            <div class="box_setting">
              <dt>公開設定</dt>
              <dd>

HTML;
#checked判定
$checkedPrivate = ($searchConditions['displayFlg'] == 0) ? 'checked' : '';
$checkedPublic = ($searchConditions['displayFlg'] == 1) ? 'checked' : '';
print <<<HTML
                <div>
                  <input type="radio" name="displayFlg" value="0" {$checkedPrivate} id="displayFlg01">
                  <label for="displayFlg01">非公開</label>
                </div>
                <div>
                  <input type="radio" name="displayFlg" value="1" {$checkedPublic} id="displayFlg02">
                  <label for="displayFlg02">公開</label>
                </div>
              </dd>
            </div>
            <div class="box_contents">
              <dt>タイトル/文章</dt>
              <dd>
                <input type="text" name="searchTitle" value="{$searchTitleHtml}">
              </dd>
            </div>
          </dl>
          <div class="box-btn">
            <button type="button" class="item-reset" onclick="searchConditions('reset')">リセット</button>
            <button type="button" class="item-search" onclick="searchConditions('search')">条件で検索</button>
          </div>
        </form>
        <article class="inner_search-list">
          <div class="wrap_pager-block">
            <h3>検索結果<span>-<i id="search-result-count">{$totalItemsHtml}件</i>が該当</span></h3>
            <div class="select-display-number" data-selectbox>

HTML;
#表示件数格納用変数を初期化
$currentDisplayNumber = isset($displayNumber) ? $displayNumber : $initialDisplayNumber;
#表示数が選択されている場合
foreach ($displayNumberList as $displayNumber) {
  if ($displayNumber === (int)$searchConditions['displayNumber']) {
    $currentDisplayNumber = $displayNumber;
    break;
  }
}
print <<<HTML
              <button type="button" class="selectbox__head" aria-expanded="false">
                <input type="hidden" name="displayNumber" value="{$currentDisplayNumber}" data-selectbox-hidden>
                <span class="selectbox__value" data-selectbox-value>{$currentDisplayNumber}</span>
              </button>
              <div class="list-wrapper">
                <ul class="selectbox__panel">

HTML;
#表示件数選択リストループで差し込む
foreach ($displayNumberList as $number) {
  $checked = ($number === (int)$searchConditions['displayNumber']) ? ' checked' : '';
  print <<<HTML
                  <li>
                    <input type="radio" name="displayNumber" id="display{$number}" value="{$number}" {$checked} onchange="searchConditions('search')">
                    <label for="display{$number}">{$number}</label>
                  </li>

HTML;
}
print <<<HTML
                </ul>
              </div>
            </div>
          </div>
          <ul>
            <li>
              <div>タイトル</div>
              <div>表示位置・順</div>
              <div><span>公開</span><span>設定変更</span></div>
              <div>編集</div>
              <div>削除</div>
            </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($articleList)) {
  #inline JS用エスケープ宣言
  $jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
  foreach ($articleList as $article) {
    #記事ID
    $articleIdRaw = (int)$article['article_id'];
    $articleId = htmlspecialchars((string)$articleIdRaw, ENT_QUOTES, 'UTF-8');
    #記事情報
    $title = htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8');
    #表示位置
    $displayOrder = htmlspecialchars($article['display_order'], ENT_QUOTES, 'UTF-8');
    #公開状態
    $status = (int)$article['status'];
    $isPublic = ($status === 1) ? 'is-active' : 'is-inactive';
    $changeStatusLabel = ($status === 1) ? '非公開へ' : '公開へ';
    $nextStatus = ($status === 1) ? 0 : 1;
    #inline JS用エスケープ（属性崩壊・注入対策）
    $articleIdJs = json_encode($articleIdRaw, $jsonHex);
    $nextStatusJs = json_encode($nextStatus, $jsonHex);
    #編集ページURL
    $editPageUrl = './products/' . $articleIdRaw;
    switch ((int)$article['article_type']) {
      #定型ページ
      case 1: {
          $editPageUrl = './client02_02_01.php?method=edit&articleId=' . $articleIdRaw;
        }
        break;
      #HTMLフリーページ
      case 2: {
          $editPageUrl = './client02_02_02.php?method=edit&articleId=' . $articleIdRaw;
        }
        break;
    }
    $editPageUrl = htmlspecialchars($editPageUrl, ENT_QUOTES, 'UTF-8');
    print <<<HTML
            <li>
              <div class="item-name"><span>{$title}</span></div>
              <div class="item-position">
                <span><i>{$displayOrder}</i></span>
              </div>
              <div class="item-status">
                <!-- NOTE ↑公開中→[is-active] / 非公開→[is-inactive] -->
                <div class="status {$isPublic}">
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
  print <<<HTML
            <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">
              <div>該当するデータが存在しません。</div>
            </li>

HTML;
}
print <<<HTML
            </ul>

HTML;
#ページャー表示
print makePagerBoxTag((int)$pageNumber, (int)$totalPages, $pagerDisplayMax, 'movePage');
print <<<HTML
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>公開設定</p>
        <button type="button" onclick="closeModal()" class="btn-top-close"></button>
      </div>
      <div class="box-details">
        <p></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel">キャンセル</button>
          <button type="button" class="btn-confirm">はい</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="./assets/js/client02_01.js" defer></script>
</body>

</html>

HTML;
