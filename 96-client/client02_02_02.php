<?php
/*
 * [96-client/client02_02_02.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事登録：HTMLフリータイプ登録
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

/**
 * HTMLフリー本文の保存済み画像パスを管理画面プレビュー用URLへ変換
 *
 */
function client020202ConvertArticleBodyHtmlForAdminPreview($html)
{
  if ($html === null || $html === '') {
    return $html;
  }
  $previewBase = defined('DOMAIN_NAME_PREVIEW') ? rtrim(DOMAIN_NAME_PREVIEW, '/') : '';
  if ($previewBase === '') {
    return $html;
  }
  return preg_replace_callback('/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)(\2)/i', function ($matches) use ($previewBase) {
    $src = html_entity_decode((string)$matches[3], ENT_QUOTES, 'UTF-8');
    if (strpos($src, '/db/images/') !== 0) {
      return $matches[0];
    }
    $convertedSrc = $previewBase . $src;
    return $matches[1] . $matches[2] . htmlspecialchars($convertedSrc, ENT_QUOTES, 'UTF-8') . $matches[4];
  }, (string)$html);
}

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'cKey02-02_02_';
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
#新規／編集
$method = isset($_GET['method']) ? $_GET['method'] : null;
#モードチェック
if ($method === null || ($method !== 'new' && $method !== 'edit')) {
  #不正アクセス：一覧ページへリダイレクト
  header("Location: ./client02_01.php");
  exit;
}
#-------------#
#商品ID（編集／削除時のみ）
$articleId = null;
if ($method === 'edit') {
  $articleId = filter_input(INPUT_GET, 'articleId', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
  ]);
  if (!$articleId) {
    header("Location: ./client02_01.php");
    exit;
  }
}
$articleData = array();
$articleCount = 0;
#-------------#
#店舗ID（編集／削除時のみ）
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
  #定型タイプ記事情報
  if ($method === 'edit') {
    if ($articleId !== null) {
      $articleData = getShopArticleHtmlData_FindById($shopId, $articleId);
      if (!$articleData) {
        header("Location: ./client02_01.php");
        exit;
      }
    } else {
      #不正アクセス：ログインページへリダイレクト
      header("Location: ./logout.php");
      exit;
    }
  }
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

#================#
# メニュータイトル
#----------------#
#メニュータイトル
$menuTitle = "記事登録";
if ($method === 'new') {
  $menuTitle = "記事登録";
} elseif ($method === 'edit') {
  if (!isset($shopData) || empty($shopData)) {
    #店舗データが無い場合は不正アクセス：トップページへリダイレクト
    header("Location: ./client02_01.php");
    exit;
  } else {
    $menuTitle = "記事編集";
  }
}

#公開設定
$articleStatus = ($method === 'edit' && isset($articleData['status']) && (int)$articleData['status'] === 0) ? 0 : 1;
$articleStatusPrivateChecked = ($articleStatus === 0) ? ' checked' : '';
$articleStatusPublicChecked = ($articleStatus === 1) ? ' checked' : '';
#記事タイトル
$articleTitle = isset($articleData['title']) ? (string)$articleData['title'] : '';
$articleBodyHtml = isset($articleData['body_html']) ? (string)client020202ConvertArticleBodyHtmlForAdminPreview($articleData['body_html']) : '';
$articleDisplayOrder = isset($articleData['display_order']) ? (string)$articleData['display_order'] : '';
$articleTitleEsc = htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8');
#表示順
$articleDisplayOrderEsc = htmlspecialchars($articleDisplayOrder, ENT_QUOTES, 'UTF-8');
$articleCount = countShopArticles([], $shopId);
$articleDisplayOrderMax = ($method === 'new') ? ($articleCount + 1) : max($articleCount, 1);
$articleDisplayOrderSelected = $articleDisplayOrderMax;
if ($method === 'edit' && isset($articleData['display_order']) && is_numeric($articleData['display_order'])) {
  $articleDisplayOrderSelected = (int)$articleData['display_order'];
}
if ($articleDisplayOrderSelected < 1 || $articleDisplayOrderSelected > $articleDisplayOrderMax) {
  $articleDisplayOrderSelected = $articleDisplayOrderMax;
}
$articleDisplayOrderOptions = array();
$articleDisplayOrderOptionsHtml = '';
$articleDisplayOrderSelectedLabel = '';
for ($orderNo = 1; $orderNo <= $articleDisplayOrderMax; $orderNo++) {
  if ($orderNo === 1 && $orderNo === $articleDisplayOrderMax) {
    $label = '最初';
  } elseif ($orderNo === 1) {
    $label = '先頭';
  } elseif ($orderNo === $articleDisplayOrderMax) {
    $label = '最後';
  } else {
    $label = $orderNo . '番目';
  }
  $checked = ($orderNo === $articleDisplayOrderSelected) ? ' checked' : '';
  $id = 'articleDisplayOrder' . $orderNo;
  $valueEsc = htmlspecialchars((string)$orderNo, ENT_QUOTES, 'UTF-8');
  $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  $idEsc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
  $articleDisplayOrderOptions[] = array(
    'value' => $orderNo,
    'label' => $label,
    'checked' => $checked,
    'id' => $id,
  );
  $articleDisplayOrderOptionsHtml .= <<<HTML
                      <li>
                        <input type="radio" name="articleDisplayOrder" value="{$valueEsc}" id="{$idEsc}"{$checked}>
                        <label for="{$idEsc}">{$labelEsc}</label>
                      </li>

HTML;
  if ($orderNo === $articleDisplayOrderSelected) {
    $articleDisplayOrderSelectedLabel = $label;
  }
}
if ($articleDisplayOrderSelectedLabel === '') {
  $articleDisplayOrderSelectedLabel = ($articleDisplayOrderMax === 1) ? '最初' : '最後';
}
$articleDisplayOrderHiddenValueEsc = htmlspecialchars((string)$articleDisplayOrderSelected, ENT_QUOTES, 'UTF-8');
$articleDisplayOrderSelectedLabelEsc = htmlspecialchars($articleDisplayOrderSelectedLabel, ENT_QUOTES, 'UTF-8');

#inline JS（onclick等）用
$jsonHex = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$tipsConfigJs = json_encode([
  'noUpDateKey' => (string)$noUpDateKey,
  'method' => (string)$method,
  'articleId' => $articleId,
  'initialBody' => $articleBodyHtml,
], $jsonHex | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$submitButtonText = ($method === 'edit') ? '保存' : '登録';
#CSP nonce（inline script 用）
$cspNonce = base64_encode(random_bytes(16));
$cspNonceEsc = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');
$cspMeta = "default-src 'self'; img-src 'self' data: https://kurokawa-onsen.com/; style-src 'self' 'unsafe-inline'; script-src 'self' https://esm.sh 'nonce-{$cspNonce}'; script-src-elem 'self' https://esm.sh 'nonce-{$cspNonce}'; script-src-attr 'unsafe-inline'; connect-src 'self' https://esm.sh;";

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(クライアント)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="{$cspMeta}">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/tiptap_app.css">
  <link rel="stylesheet" href="../assets/css/client02-02.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-02-02-02 status-client">
    <section class="container-left-menu menu-color02">
      <div class="title">サイト管理</div>
      <nav>
        <a href="./client02_01.php" {$client02_01_active}><span>自由記事一覧</span></a>
        <a href="./client02_02.php" {$client02_02_02_active}><span>自由記事登録</span></a>
        <a href="./client02_03.php" {$client02_03_active}><span>自由記事並び順変更</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color02">
      <div class="block_inner">
        <h2>自由ページ {$menuTitle}</h2>
        <form name="validationForm">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <input type="hidden" name="method" value="{$method}">
          <input type="hidden" name="articleId" value="{$articleId}">
          <input type="hidden" name="shopId" value="{$shopId}">
          <h3>入力項目</h3>
          <dl>
            <div class="box_setting free-page">
              <dt>公開設定</dt>
              <dd>
                <div>
                  <input type="radio" name="articleStatus" value="0" id="articleStatus01" {$articleStatusPrivateChecked}>
                  <label for="articleStatus01">非公開</label>
                </div>
                <div>
                  <input type="radio" name="articleStatus" value="1" id="articleStatus02" {$articleStatusPublicChecked}>
                  <label for="articleStatus02">公開</label>
                </div>
              </dd>
            </div>
            <div class="box_contents">
              <dt>ページタイトル</dt>
              <dd>
                <input type="text" name="articleTitle" value="{$articleTitleEsc}">
              </dd>
            </div>
            <div class="box_view-order">
              <dt>表示順</dt>
              <dd>
                <div class="select-page-number" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="articleDisplayOrder" value="{$articleDisplayOrderHiddenValueEsc}" data-selectbox-hidden>
                    <span class="selectbox__value" data-selectbox-value>{$articleDisplayOrderSelectedLabelEsc}</span>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">
                      {$articleDisplayOrderOptionsHtml}
                    </ul>
                  </div>
                </div>
              </dd>
            </div>
            <!-- <div class="box_url">
              <dt>ＵＲＬ文字指定</dt>
              <dd>
                <p>
                  ※ＵＲＬを/free/〇〇〇の形にする場合はここに○○○の部分を登録して下さい。
                </p>
                <div>
                  <input type="text" name="td31FolderName" value="">
                  <span>（上限半角英数字15文字以内）</span>
                </div>
              </dd>
            </div> -->
            <div class="box_detail">
              <dt class="position-top">メニュー</dt>
              <dd>
                <div class="toolbar" id="toolbar">
                  <button type="button" data-cmd="bold">B</button>
                  <button type="button" data-cmd="italic">I</button>
                  <button type="button" data-cmd="strike">S</button>
                  <button type="button" data-cmd="h4">H1</button>
                  <button type="button" data-cmd="h5">H2</button>
                  <button type="button" data-cmd="bullet">箇条書き</button>
                  <button type="button" data-cmd="ordered">番号</button>
                  <span class="sep" aria-hidden="true"></span>
                  <input type="color" id="textColor" value="#000000" title="文字色">
                  <button type="button" data-cmd="unsetColor">色解除</button>
                  <span class="sep" aria-hidden="true"></span>
                  <button type="button" data-cmd="imageUpload">画像アップロード</button>
                  <input id="imageInput" type="file" accept="image/*" multiple style="display:none">
                  <div class="hint">ヒント：画像は「ボタン」または「ドラッグ＆ドロップ」「貼り付け（Ctrl+V）」でも挿入できます。</div>
                </div>
              </dd>
              <dt>内容</dt>
              <dd class="dd-editor">
                <div class="editor" id="TipTapEditor"></div>
              </dd>
            </div>
          </dl>
          <!-- <p class="notice">（上限32,767文字以内）<br>(全角シングルコーテーション「’」は半角シングルコーテーション「'」へ置換されます)</p> -->
          <p class="notice"></p>
          <button type="button" class="btn_submit" onclick="sendInput();">{$submitButtonText}</button>
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
        <p>自由ページ登録</p>
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
  <script nonce="{$cspNonceEsc}">
    window.CLIENT02_02_02 = {$tipsConfigJs};
  </script>
  <script src="./assets/js/client02_02_02.js" defer></script>
</body>

</html>

HTML;
