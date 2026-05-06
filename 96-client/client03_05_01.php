<?php
/*
 * [96-client/client03_05_01.php]
 *  - 【加盟店】管理画面 -
 *  規格／分類管理
 *
 * [初版]
 *  2026.4.23
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
#店舗情報（EC関連）
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'cKey03-05-01_';
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

#規格ID
$specificationId = $_POST['specification_id'] ?? ($_GET['specification_id'] ?? null);
#規格IDがあれば規格情報取得
$itemSpecificationName = "";
$itemSpecificationAdminName = "";
if ($specificationId !== null && is_numeric($specificationId) === true && (int)$specificationId > 0) {
  $specificationId = (int)$specificationId;
  #規格詳細
  $itemSpecificationDetails = getShopItemSpecificationDetails($shopId, $specificationId);
  if ($itemSpecificationDetails === null) {
    header("Location: ./logout.php");
    exit;
  }
  $itemSpecificationName = htmlspecialchars($itemSpecificationDetails['name'], ENT_QUOTES, 'UTF-8');
  $itemSpecificationAdminName = htmlspecialchars($itemSpecificationDetails['backend_name'], ENT_QUOTES, 'UTF-8');
  #分類一覧
  $itemClassifyList = getShopItemClassify($shopId, $specificationId);
} else {
  #不正アクセス：ログインページへリダイレクト
  header("Location: ./logout.php");
  exit;
}

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

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
    <link rel="stylesheet" href="../assets/css/master03-05.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-03-05 status-client">
      <section class="container-left-menu menu-color03">
        <div class="title">EC販売管理</div>
        <nav>
          <a href="./client03_01.php" {$client03_01_active}><span>受注一覧</span></a>
          <a href="./client03_02.php" {$client03_02_active}><span>商品一覧</span></a>
          <a href="./client03_04.php" {$client03_04_active}><span>カテゴリ管理</span></a>
          <a href="./client03_05.php" {$client03_05_01_active}><span>規格管理</span></a>
          <a href="./client03_03.php?method=new" {$client03_03_active}><span>商品登録</span></a>
          <a href="#"><span>集計</span></a>
          <!-- <a href="#"><span>店舗登録</span ></a> -->
        </nav>
      </section>
      <div class="main-contents menu-color03">
        <div class="block_inner">
          <h2>規格詳細</h2>
          <article class="inner-head">
            <h3>規格情報</h3>
            <dl>
              <div>
                <dt>規格名</dt>
                <dd>{$itemSpecificationName}</dd>
              </div>
              <div>
                <dt>管理名</dt>
                <dd>{$itemSpecificationAdminName}</dd>
              </div>
            </dl>
          </article>
          <article class="inner_search-list">
            <h3>分類一覧</h3>
            <form name="inputForm" class="inputForm box-new-entry">
              <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
              <input type="hidden" name="method" value="new">
              <input type="hidden" name="specificationId" value="{$specificationId}">
              <div>
                <span>分類名</span>
                <input type="text" name="newClassifyName" class="required-item" placeholder="分類名を入力" required maxlength="255">
              </div>
              <div>
                <span>管理名</span>
                <input type="text" name="newClassifyAdminName" placeholder="省略可" maxlength="255">
              </div>
              <button type="button" class="btn-submit" onclick="sendInput()">新規作成</button>
            </form>
            <ul class="drag-area">
              <li>
                <div></div>
                <div>ID</div>
                <div style="align-items: flex-start">分類管理</div>
                <div></div>
              </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($itemClassifyList)) {
  $totalClassifyCount = count($itemClassifyList);
  $classifyIndex = 0;
  foreach ($itemClassifyList as $itemClassify) {
    $itemClassifyID = htmlspecialchars($itemClassify['classify_id'], ENT_QUOTES, 'UTF-8');
    $itemClassifyName = htmlspecialchars($itemClassify['name'], ENT_QUOTES, 'UTF-8');
    $itemClassifyAdminName = htmlspecialchars($itemClassify['backend_name'], ENT_QUOTES, 'UTF-8');
    $itemClassifyCount = htmlspecialchars((string)($itemClassify['class_category_count'] ?? 0), ENT_QUOTES, 'UTF-8');
    #checked判定
    $itemClassifyIsActive = ($itemClassify['is_active'] == 1) ? 'checked' : '';
    $isFirst = ($classifyIndex === 0);
    $isLast = ($classifyIndex === ($totalClassifyCount - 1));
    $upStyle = $isFirst ? ' style="visibility:hidden;"' : '';
    $downStyle = $isLast ? ' style="visibility:hidden;"' : '';
    print <<<HTML
              <li>
                <div class="item-control">
                  <button>
                    <span></span>
                    <span></span>
                    <span></span>
                  </button>
                </div>
                <div class="item-id">
                  <span>{$itemClassifyID}</span>
                </div>
                <div class="item-name">
                  <span class="name">{$itemClassifyName}</span>
                  <span class="admin">{$itemClassifyAdminName}</span>
                  <!-- <span class="number">{$itemClassifyCount}</span> -->
                </div>
                <nav>
                  <button class="btn-up" type="button" data-tooltip="上へ" aria-label="上へ"{$upStyle}></button>
                  <button class="btn-down" type="button" data-tooltip="下へ" aria-label="下へ"{$downStyle}></button>
                  <button class="btn-edit" type="button" data-tooltip="編集" aria-label="編集" onclick="editClassify(this)"></button>
                  <div class="wrap-toggle-button" data-tooltip-on="表示中 | 非表示にする" data-tooltip-off="非表示中 | 表示する">
                    <label class="toggle-button">
                      <input type="checkbox" name="classifyPublic" {$itemClassifyIsActive} onchange="checkClassifyPublic(this)">
                    </label>
                  </div>
                  <button class="btn-delate" type="button" data-tooltip="削除" aria-label="削除" onclick="deleteClassify(this)"></button>
                </nav>
              </li>

HTML;
    $classifyIndex++;
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
            <p>項目の順番はドラッグ＆ドロップでも変更可能です。</p>
          </article>
          <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>分類情報</p>
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
    <script src="../assets/js/form.js" defer></script>
    <script src="./assets/js/client03_05_01.js" defer></script>
  </body>
</html>

HTML;
