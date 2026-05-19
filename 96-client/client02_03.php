<?php
/*
 * [96-client/client02_03.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事並べ替え
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
$pagePrefix = 'cKey02-03_';
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

#=========#
# 記事一覧
#---------#
$articleRows = getShopArticleRowsForDisplayOrder($shopId);
$articleCount = count($articleRows);

$articleListHtml = '';
if ($articleCount === 0) {
  $articleListHtml = <<<INNER
            <li class="no-data">
              <div class="box_ban-title">記事がありません</div>
            </li>

INNER;
} else {
  foreach ($articleRows as $idx => $row) {
    $articleIdVal = (int)$row['article_id'];
    $titleVal = htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8');
    $isFirst = ($idx === 0);
    $isLast = ($idx === $articleCount - 1);
    $btnDown = $isLast
      ? '              <div class="box_btn btn_down"></div>'
      : '              <div class="box_btn btn_down"><button type="button" onclick="goSortDown(this);"></button></div>';
    $btnUp = $isFirst
      ? '              <div class="box_btn btn_up"></div>'
      : '              <div class="box_btn btn_up"><button type="button" onclick="goSortUp(this);"></button></div>';
    $articleListHtml .= <<<INNER
            <li data-article-id="{$articleIdVal}" draggable="true">
              {$btnDown}
              {$btnUp}
              <div class="box_ban-title" draggable="false">{$titleVal}</div>
            </li>

INNER;
  }
}

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
  <link rel="stylesheet" href="../assets/css/client02-03.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-02-03-01 status-client">
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
        <h2>自由ページ 並び順変更</h2>
        <form name="sortForm">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <input type="hidden" name="shopId" value="{$shopId}">
          <ul>
            <li>
              <div>順序変更</div>
              <div>記事タイトル</div>
            </li>
            {$articleListHtml}
          </ul>
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        <a href="./client02_01.php" class="link_page-back_bottom">戻る</a>
      </div>
    </div>
  </main>
  <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>自由ページ並び順変更</p>
        <button type="button" onclick="closeModal()" class="btn-top-close"></button>
      </div>
      <div class="box-details">
        <p></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" onclick="closeModal()">閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="./assets/js/client02_03.js" defer></script>
</body>

</html>

HTML;
