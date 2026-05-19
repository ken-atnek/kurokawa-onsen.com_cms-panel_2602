<?php
/*
 * [96-client/client02_02_01.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事作成：定型タイプ
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
 * 画面表示時に段落サムネイル一時画像を安全に削除
 *
 */
function client020201DeleteArticleThumbnailTmpOnDisplay($tmpName)
{
  $tmpName = (string)$tmpName;
  if ($tmpName === '') {
    return false;
  }
  $rootDir = realpath(__DIR__ . '/../tmp_upload/article_thumbnail');
  if ($rootDir === false) {
    return false;
  }
  $realPath = realpath($tmpName);
  if ($realPath === false || is_file($realPath) === false) {
    return false;
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    return false;
  }
  return @unlink($realPath);
}
/**
 * 古い段落サムネイル一時画像と空ディレクトリを軽量削除
 *
 */
function client020201CleanupOldArticleThumbnailTmpOnDisplay()
{
  $rootDir = __DIR__ . '/../tmp_upload/article_thumbnail';
  if (is_dir($rootDir) === false) {
    return;
  }
  $limitTime = time() - (24 * 60 * 60);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $fileInfo) {
    $path = $fileInfo->getPathname();
    if ($fileInfo->isFile() && $fileInfo->getMTime() < $limitTime) {
      client020201DeleteArticleThumbnailTmpOnDisplay($path);
    } elseif ($fileInfo->isDir()) {
      @rmdir($path);
    }
  }
}
/**
 * 旧画面セッションに残った段落サムネイル一時画像を削除
 *
 */
function client020201DeleteOldArticleThumbnailSessionTmpOnDisplay($pagePrefix, $currentKey)
{
  foreach ($_SESSION as $key => $val) {
    if ($key === $currentKey || strpos((string)$key, $pagePrefix) !== 0 || is_array($val) === false) {
      continue;
    }
    if (!isset($val['article_thumbnail']) || is_array($val['article_thumbnail']) === false) {
      continue;
    }
    foreach ($val['article_thumbnail'] as $thumbnail) {
      if (is_array($thumbnail) === false || ($thumbnail['kind'] ?? '') !== 'tmp') {
        continue;
      }
      client020201DeleteArticleThumbnailTmpOnDisplay($thumbnail['tmp_name'] ?? '');
    }
  }
}
/**
 * 画面表示時にTipTap本文内一時画像を安全に削除
 *
 */
function client020201DeleteArticleInlineTmpOnDisplay($tmpName)
{
  $tmpName = (string)$tmpName;
  if ($tmpName === '') {
    return false;
  }
  $rootDir = realpath(__DIR__ . '/../tmp_upload/article_inline');
  if ($rootDir === false) {
    return false;
  }
  $realPath = realpath($tmpName);
  if ($realPath === false || is_file($realPath) === false) {
    return false;
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    return false;
  }
  return @unlink($realPath);
}
/**
 * 古いTipTap本文内一時画像と空ディレクトリを軽量削除
 *
 */
function client020201CleanupOldArticleInlineTmpOnDisplay()
{
  $rootDir = __DIR__ . '/../tmp_upload/article_inline';
  if (is_dir($rootDir) === false) {
    return;
  }
  $limitTime = time() - (24 * 60 * 60);
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($iterator as $fileInfo) {
    $path = $fileInfo->getPathname();
    if ($fileInfo->isFile() && $fileInfo->getMTime() < $limitTime) {
      client020201DeleteArticleInlineTmpOnDisplay($path);
    } elseif ($fileInfo->isDir()) {
      @rmdir($path);
    }
  }
}
/**
 * 旧画面セッションに残ったTipTap本文内一時画像を削除
 *
 */
function client020201DeleteOldArticleInlineSessionTmpOnDisplay($pagePrefix, $currentKey)
{
  foreach ($_SESSION as $key => $val) {
    if ($key === $currentKey || strpos((string)$key, $pagePrefix) !== 0 || is_array($val) === false) {
      continue;
    }
    if (!isset($val['article_inline']) || is_array($val['article_inline']) === false) {
      continue;
    }
    foreach ($val['article_inline'] as $inlineImages) {
      if (is_array($inlineImages) === false) {
        continue;
      }
      foreach ($inlineImages as $inlineImage) {
        if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
          continue;
        }
        client020201DeleteArticleInlineTmpOnDisplay($inlineImage['tmp_name'] ?? '');
      }
    }
  }
}
/**
 * 記事本文HTMLからプレーンテキストを取得する
 *
 */
function client020201PlainTextFromArticleHtml($html)
{
  return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'));
}
/**
 * 保存済み本文内画像パスを管理画面プレビュー用に変換する
 *
 */
function client020201ConvertArticleBodyHtmlForAdminPreview($html)
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
/**
 * 段落データにユーザー入力があるか判定する
 *
 */
function client020201HasParagraphContent($paragraphData)
{
  if (is_array($paragraphData) === false) {
    return false;
  }
  if (trim((string)($paragraphData['title'] ?? '')) !== '') {
    return true;
  }
  if (client020201PlainTextFromArticleHtml($paragraphData['body_text'] ?? '') !== '') {
    return true;
  }
  if (trim((string)($paragraphData['image_storage_path'] ?? '')) !== '') {
    return true;
  }
  if ((int)($paragraphData['link_enabled'] ?? 0) === 1) {
    return true;
  }
  if (trim((string)($paragraphData['link_text'] ?? '')) !== '') {
    return true;
  }
  if (trim((string)($paragraphData['link_url'] ?? '')) !== '') {
    return true;
  }
  return false;
}

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'cKey02-02-01_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
client020201CleanupOldArticleThumbnailTmpOnDisplay();
client020201DeleteOldArticleThumbnailSessionTmpOnDisplay($pagePrefix, $noUpDateKey);
client020201CleanupOldArticleInlineTmpOnDisplay();
client020201DeleteOldArticleInlineSessionTmpOnDisplay($pagePrefix, $noUpDateKey);
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
$articleImages = array();
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
      $articleData = getShopArticleData_FindById($shopId, $articleId);
      if (!$articleData) {
        header("Location: ./client02_01.php");
        exit;
      }
      $articleImages = getShopArticleImages($shopId, $articleId);
      if (!is_array($articleImages)) {
        $articleImages = array();
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
$articleIdHidden = '';
if ($method === 'edit' && $articleId !== null) {
  $articleIdEsc = htmlspecialchars((string)$articleId, ENT_QUOTES, 'UTF-8');
  $articleIdHidden = '<input type="hidden" name="articleId" value="' . $articleIdEsc . '">';
}
$submitButtonText = ($method === 'edit') ? '保存' : '登録';
$paragraphDefaults = array(
  1 => array(
    'title' => '',
    'body_text' => '',
    'image_storage_path' => '',
    'link_enabled' => 0,
    'link_text' => '',
    'link_url' => '',
    'link_target' => 1,
  ),
  2 => array(
    'title' => '',
    'body_text' => '',
    'image_storage_path' => '',
    'link_enabled' => 0,
    'link_text' => '',
    'link_url' => '',
    'link_target' => 1,
  ),
  3 => array(
    'title' => '',
    'body_text' => '',
    'image_storage_path' => '',
    'link_enabled' => 0,
    'link_text' => '',
    'link_url' => '',
    'link_target' => 1,
  ),
);
foreach ($paragraphDefaults as $paragraphNo => $defaultValues) {
  if (isset($articleImages[$paragraphNo]) && is_array($articleImages[$paragraphNo])) {
    foreach ($defaultValues as $key => $value) {
      if (array_key_exists($key, $articleImages[$paragraphNo])) {
        $paragraphDefaults[$paragraphNo][$key] = $articleImages[$paragraphNo][$key];
      }
    }
  }
}
$paragraphViewData = array();
$initialBodies = array();
foreach ($paragraphDefaults as $paragraphNo => $paragraphData) {
  $storagePath = isset($paragraphData['image_storage_path']) ? trim((string)$paragraphData['image_storage_path']) : '';
  $imageSrc = '';
  if ($storagePath !== '') {
    $imageSrc = rtrim(DOMAIN_NAME_PREVIEW, '/') . '/db/images/' . ltrim($storagePath, '/');
  }
  $isParagraphOpen = ($paragraphNo === 1) ? true : client020201HasParagraphContent($paragraphData);
  $linkEnabled = isset($paragraphData['link_enabled']) ? (int)$paragraphData['link_enabled'] : 0;
  $linkTarget = isset($paragraphData['link_target']) ? (int)$paragraphData['link_target'] : 1;
  if ($linkTarget !== 2) {
    $linkTarget = 1;
  }
  $paragraphViewData[$paragraphNo] = array(
    'titleEsc' => htmlspecialchars((string)($paragraphData['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'imageSrcEsc' => htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8'),
    'emptyUiStyle' => ($imageSrc !== '') ? 'display: none' : '',
    'previewStyle' => ($imageSrc !== '') ? 'display: block' : 'display: none',
    'imageDeleteStyle' => ($imageSrc !== '') ? 'display: block' : 'display: none',
    'switchClass' => $isParagraphOpen ? 'box-switch is-active' : 'box-switch',
    'boxDisplayStyle' => $isParagraphOpen ? 'display: grid' : 'display: none',
    'linkDisabledChecked' => ($linkEnabled === 1) ? '' : ' checked',
    'linkEnabledChecked' => ($linkEnabled === 1) ? ' checked' : '',
    'linkTextEsc' => htmlspecialchars((string)($paragraphData['link_text'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'linkUrlEsc' => htmlspecialchars((string)($paragraphData['link_url'] ?? ''), ENT_QUOTES, 'UTF-8'),
    'linkTargetSameChecked' => ($linkTarget === 1) ? ' checked' : '',
    'linkTargetBlankChecked' => ($linkTarget === 2) ? ' checked' : '',
  );
  $initialBodies[$paragraphNo] = (string)client020201ConvertArticleBodyHtmlForAdminPreview($paragraphData['body_text'] ?? '');
}
#inline JS（onclick等）用
$jsonHex = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$tipsConfigJs = json_encode([
  'noUpDateKey' => (string)$noUpDateKey,
  'method' => (string)$method,
  'articleId' => $articleId,
  'initialBodies' => $initialBodies,
], $jsonHex | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
#CSP nonce（inline script 用）
$cspNonce = base64_encode(random_bytes(16));
$cspNonceEsc = htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8');
$cspMeta = "default-src 'self'; img-src 'self' data: https://kurokawa-onsen.com/; style-src 'self' 'unsafe-inline'; script-src 'self' https://esm.sh 'nonce-{$cspNonce}'; script-src-elem 'self' https://esm.sh 'nonce-{$cspNonce}'; script-src-attr 'unsafe-inline'; connect-src 'self' https://esm.sh;";

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(加盟店)</title>
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
  <main class="inner-02-02-01 status-client">
    <section class="container-left-menu menu-color02">
      <div class="title">サイト管理</div>
      <nav>
        <a href="./client02_01.php" {$client02_01_active}><span>自由記事一覧</span></a>
        <a href="./client02_02.php" {$client02_02_01_active}><span>自由記事登録</span></a>
        <a href="./client02_03.php" {$client02_03_active}><span>自由記事並び順変更</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color02">
      <div class="block_inner">
        <h2>自由ページ {$menuTitle}</h2>
        <form name="validationForm">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
          <input type="hidden" name="method" value="{$method}">
          {$articleIdHidden}
          <input type="hidden" name="action" value="checkInput">
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
          </dl>
          <article>
            <div class="head">
              <h4>段落-1</h4>
            </div>
            <dl id="box-dl01">
              <div>
                <dt>タイトル</dt>
                <dd>
                  <input type="text" name="paragraphs1Title" value="{$paragraphViewData[1]['titleEsc']}">
                </dd>
              </div>
              <div class="box_up-image">
                <dt>画像</dt>
                <dd>
                  <div class="select-image" id="js-dragDrop-articleThumbnail01">
                    <h4 data-article-thumbnail-empty-ui="01" style="{$paragraphViewData[1]['emptyUiStyle']}">画像をここにドラッグ＆ドロップ</h4>
                    <span data-article-thumbnail-empty-ui="01" style="{$paragraphViewData[1]['emptyUiStyle']}">または</span>
                    <input type="file" name="articleThumbnail01" id="js-fileElem-articleThumbnail01" accept="image/*" style="display: none">
                    <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-articleThumbnail01">
                    <input type="hidden" name="upload_image_area" value="article_thumbnail_1" id="js-uploadImageArea-articleThumbnail01">
                    <input type="hidden" name="up_image_area[]" value="article_thumbnail_1">
                    <input type="hidden" name="send_php" value="proc_client02_02_01.php">
                    <button type="button" id="js-fileSelect-articleThumbnail01" data-article-thumbnail-empty-ui="01" style="{$paragraphViewData[1]['emptyUiStyle']}">ファイルを選択</button>
                    <span data-article-thumbnail-empty-ui="01" style="{$paragraphViewData[1]['emptyUiStyle']}">※縦横サイズがオーバーしている場合は自動でリサイズされます</span>
                    <div class="wrap-caution" id="js-fileError-articleThumbnail01" style="display: none;">
                      <h5>アップロード失敗</h5>
                      <p></p>
                    </div>
                    <div class="preview-container" id="js-previewBlock-articleThumbnail01" style="{$paragraphViewData[1]['previewStyle']}">
                      <img src="{$paragraphViewData[1]['imageSrcEsc']}" alt="" class="preview-image" id="preview-image01">
                    </div>
                  </div>
                  <div class="item_image-delate" id="articleThumbnailDeleteUi01" style="{$paragraphViewData[1]['imageDeleteStyle']}">
                    <button type="button" class="custom-button" id="reset01-button">画像を削除する</button>
                  </div>
                </dd>
              </div>
              <div class="box_detail">
                <dt class="position-top">メニュー</dt>
                <dd>
                  <div class="toolbar" id="toolbar01">
                    <button type="button" data-cmd="bold">B</button>
                    <button type="button" data-cmd="italic">I</button>
                    <button type="button" data-cmd="strike">S</button>
                    <button type="button" data-cmd="h4">H1</button>
                    <button type="button" data-cmd="h5">H2</button>
                    <button type="button" data-cmd="bullet">箇条書き</button>
                    <button type="button" data-cmd="ordered">番号</button>
                    <span class="sep" aria-hidden="true"></span>
                    <input type="color" id="textColor01" value="#000000" title="文字色">
                    <button type="button" data-cmd="unsetColor">色解除</button>
                    <span class="sep" aria-hidden="true"></span>
                    <button type="button" data-cmd="imageUpload">画像アップロード</button>
                    <input id="imageInput01" type="file" accept="image/*" multiple style="display:none">
                    <div class="hint">ヒント：画像は「ボタン」または「ドラッグ＆ドロップ」「貼り付け（Ctrl+V）」でも挿入できます。</div>
                  </div>
                </dd>
                <dt>内容</dt>
                <dd class="dd-editor">
                  <div class="editor" id="TipTapEditor01"></div>
                </dd>
              </div>
              <div class="box_set-link">
                <dt>リンク設定</dt>
                <dd>
                  <div class="box_inactive">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs1LinkMode" value="0" id="paragraphs1LinkMode01" {$paragraphViewData[1]['linkDisabledChecked']}>
                      <label for="paragraphs1LinkMode01">リンクしない</label>
                    </div>
                  </div>
                  <div class="box_active">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs1LinkMode" value="1" id="paragraphs1LinkMode02" {$paragraphViewData[1]['linkEnabledChecked']}>
                      <label for="paragraphs1LinkMode02">リンクする</label>
                    </div>
                    <dl>
                      <div>
                        <dt>リンク対象テキスト</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs1LinkText" value="{$paragraphViewData[1]['linkTextEsc']}">
                          <p>このテキストへリンクを張ります。（上限50文字以内）</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先URL</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs1LinkUrl" value="{$paragraphViewData[1]['linkUrlEsc']}">
                          <p>「http:://～」「https://～」入力してください。</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先ウィンドウ</dt>
                        <dd>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs1LinWindow" value="1" id="paragraphs1LinWindow01" {$paragraphViewData[1]['linkTargetSameChecked']}>
                            <label for="paragraphs1LinWindow01">同じウィンドウ</label>
                          </div>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs1LinWindow" value="2" id="paragraphs1LinWindow02" {$paragraphViewData[1]['linkTargetBlankChecked']}>
                            <label for="paragraphs1LinWindow02">新しいウィンドウ</label>
                          </div>
                        </dd>
                      </div>
                    </dl>
                  </div>
                </dd>
              </div>
            </dl>
          </article>
          <article>
            <div class="head">
              <h4>段落-2</h4>
              <button type="button" class="{$paragraphViewData[2]['switchClass']}" id="box02_switch" onclick="toggleDisplay(this, 'box-dl02')"></button>
            </div>
            <dl id="box-dl02" style="{$paragraphViewData[2]['boxDisplayStyle']}">
              <div>
                <dt>タイトル</dt>
                <dd>
                  <input type="text" name="paragraphs2Title" value="{$paragraphViewData[2]['titleEsc']}">
                </dd>
              </div>
              <div class="box_up-image">
                <dt>画像</dt>
                <dd>
                  <div class="select-image" id="js-dragDrop-articleThumbnail02">
                    <h4 data-article-thumbnail-empty-ui="02" style="{$paragraphViewData[2]['emptyUiStyle']}">画像をここにドラッグ＆ドロップ</h4>
                    <span data-article-thumbnail-empty-ui="02" style="{$paragraphViewData[2]['emptyUiStyle']}">または</span>
                    <input type="file" name="articleThumbnail02" id="js-fileElem-articleThumbnail02" accept="image/*" style="display: none">
                    <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-articleThumbnail02">
                    <input type="hidden" name="upload_image_area" value="article_thumbnail_2" id="js-uploadImageArea-articleThumbnail02">
                    <input type="hidden" name="up_image_area[]" value="article_thumbnail_2">
                    <input type="hidden" name="send_php" value="proc_client02_02_01.php">
                    <button type="button" id="js-fileSelect-articleThumbnail02" data-article-thumbnail-empty-ui="02" style="{$paragraphViewData[2]['emptyUiStyle']}">ファイルを選択</button>
                    <span data-article-thumbnail-empty-ui="02" style="{$paragraphViewData[2]['emptyUiStyle']}">※縦横サイズがオーバーしている場合は自動でリサイズされます</span>
                    <div class="wrap-caution" id="js-fileError-articleThumbnail02" style="display: none;">
                      <h5>アップロード失敗</h5>
                      <p></p>
                    </div>
                    <div class="preview-container" id="js-previewBlock-articleThumbnail02" style="{$paragraphViewData[2]['previewStyle']}">
                      <img src="{$paragraphViewData[2]['imageSrcEsc']}" alt="" class="preview-image" id="preview-image02">
                    </div>
                  </div>
                  <div class="item_image-delate" id="articleThumbnailDeleteUi02" style="{$paragraphViewData[2]['imageDeleteStyle']}">
                    <button type="button" class="custom-button" id="reset02-button">画像を削除する</button>
                  </div>
                </dd>
              </div>
              <div class="box_detail">
                <dt class="position-top">メニュー</dt>
                <dd>
                  <div class="toolbar" id="toolbar02">
                    <button type="button" data-cmd="bold">B</button>
                    <button type="button" data-cmd="italic">I</button>
                    <button type="button" data-cmd="strike">S</button>
                    <button type="button" data-cmd="h4">H1</button>
                    <button type="button" data-cmd="h5">H2</button>
                    <button type="button" data-cmd="bullet">箇条書き</button>
                    <button type="button" data-cmd="ordered">番号</button>
                    <span class="sep" aria-hidden="true"></span>
                    <input type="color" id="textColor02" value="#000000" title="文字色">
                    <button type="button" data-cmd="unsetColor">色解除</button>
                    <span class="sep" aria-hidden="true"></span>
                    <button type="button" data-cmd="imageUpload">画像アップロード</button>
                    <input id="imageInput02" type="file" accept="image/*" multiple style="display:none">
                    <div class="hint">ヒント：画像は「ボタン」または「ドラッグ＆ドロップ」「貼り付け（Ctrl+V）」でも挿入できます。</div>
                  </div>
                </dd>
                <dt>内容</dt>
                <dd class="dd-editor"><div class="editor" id="TipTapEditor02"></div></dd>
              </div>
              <div class="box_set-link">
                <dt>リンク設定</dt>
                <dd>
                  <div class="box_inactive">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs2LinkMode" value="0" id="paragraphs2LinkMode01" {$paragraphViewData[2]['linkDisabledChecked']}>
                      <label for="paragraphs2LinkMode01">リンクしない</label>
                    </div>
                  </div>
                  <div class="box_active">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs2LinkMode" value="1" id="paragraphs2LinkMode02" {$paragraphViewData[2]['linkEnabledChecked']}>
                      <label for="paragraphs2LinkMode02">リンクする</label>
                    </div>
                    <dl>
                      <div>
                        <dt>リンク対象テキスト</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs2LinkText" value="{$paragraphViewData[2]['linkTextEsc']}">
                          <p>このテキストへリンクを張ります。（上限50文字以内）</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先URL</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs2LinkUrl" value="{$paragraphViewData[2]['linkUrlEsc']}">
                          <p>「http:://～」「https://～」入力してください。</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先ウィンドウ</dt>
                        <dd>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs2LinWindow" value="1" id="paragraphs2LinWindow01" {$paragraphViewData[2]['linkTargetSameChecked']}>
                            <label for="paragraphs2LinWindow01">同じウィンドウ</label>
                          </div>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs2LinWindow" value="2" id="paragraphs2LinWindow02" {$paragraphViewData[2]['linkTargetBlankChecked']}>
                            <label for="paragraphs2LinWindow02">新しいウィンドウ</label>
                          </div>
                        </dd>
                      </div>
                    </dl>
                  </div>
                </dd>
              </div>
            </dl>
          </article>
          <article>
            <div class="head">
              <h4>段落-3</h4>
              <button type="button" class="{$paragraphViewData[3]['switchClass']}" id="box03_switch" onclick="toggleDisplay(this, 'box-dl03')"></button>
            </div>
            <dl id="box-dl03" style="{$paragraphViewData[3]['boxDisplayStyle']}">
              <div>
                <dt>タイトル</dt>
                <dd>
                  <input type="text" name="paragraphs3Title" value="{$paragraphViewData[3]['titleEsc']}">
                </dd>
              </div>
              <div class="box_up-image">
                <dt>画像</dt>
                <dd>
                  <div class="select-image" id="js-dragDrop-articleThumbnail03">
                    <h4 data-article-thumbnail-empty-ui="03" style="{$paragraphViewData[3]['emptyUiStyle']}">画像をここにドラッグ＆ドロップ</h4>
                    <span data-article-thumbnail-empty-ui="03" style="{$paragraphViewData[3]['emptyUiStyle']}">または</span>
                    <input type="file" name="articleThumbnail03" id="js-fileElem-articleThumbnail03" accept="image/*" style="display: none">
                    <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-articleThumbnail03">
                    <input type="hidden" name="upload_image_area" value="article_thumbnail_3" id="js-uploadImageArea-articleThumbnail03">
                    <input type="hidden" name="up_image_area[]" value="article_thumbnail_3">
                    <input type="hidden" name="send_php" value="proc_client02_02_01.php">
                    <button type="button" id="js-fileSelect-articleThumbnail03" data-article-thumbnail-empty-ui="03" style="{$paragraphViewData[3]['emptyUiStyle']}">ファイルを選択</button>
                    <span data-article-thumbnail-empty-ui="03" style="{$paragraphViewData[3]['emptyUiStyle']}">※縦横サイズがオーバーしている場合は自動でリサイズされます</span>
                    <div class="wrap-caution" id="js-fileError-articleThumbnail03" style="display: none;">
                      <h5>アップロード失敗</h5>
                      <p></p>
                    </div>
                    <div class="preview-container" id="js-previewBlock-articleThumbnail03" style="{$paragraphViewData[3]['previewStyle']}">
                      <img src="{$paragraphViewData[3]['imageSrcEsc']}" alt="" class="preview-image" id="preview-image03">
                    </div>
                  </div>
                  <div class="item_image-delate" id="articleThumbnailDeleteUi03" style="{$paragraphViewData[3]['imageDeleteStyle']}">
                    <button type="button" class="custom-button" id="reset03-button">画像を削除する</button>
                  </div>
                </dd>
              </div>
              <div class="box_detail">
                <dt class="position-top">メニュー</dt>
                <dd>
                  <div class="toolbar" id="toolbar03">
                    <button type="button" data-cmd="bold">B</button>
                    <button type="button" data-cmd="italic">I</button>
                    <button type="button" data-cmd="strike">S</button>
                    <button type="button" data-cmd="h4">H1</button>
                    <button type="button" data-cmd="h5">H2</button>
                    <button type="button" data-cmd="bullet">箇条書き</button>
                    <button type="button" data-cmd="ordered">番号</button>
                    <span class="sep" aria-hidden="true"></span>
                    <input type="color" id="textColor03" value="#000000" title="文字色">
                    <button type="button" data-cmd="unsetColor">色解除</button>
                    <span class="sep" aria-hidden="true"></span>
                    <button type="button" data-cmd="imageUpload">画像アップロード</button>
                    <input id="imageInput03" type="file" accept="image/*" multiple style="display:none">
                    <div class="hint">ヒント：画像は「ボタン」または「ドラッグ＆ドロップ」「貼り付け（Ctrl+V）」でも挿入できます。</div>
                  </div>
                </dd>
                <dt>内容</dt>
                <dd class="dd-editor"><div class="editor" id="TipTapEditor03"></div></dd>
              </div>
              <div class="box_set-link">
                <dt>リンク設定</dt>
                <dd>
                  <div class="box_inactive">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs3LinkMode" value="0" id="paragraphs3LinkMode01" {$paragraphViewData[3]['linkDisabledChecked']}>
                      <label for="paragraphs3LinkMode01">リンクしない</label>
                    </div>
                  </div>
                  <div class="box_active">
                    <div class="item_radio">
                      <input type="radio" name="paragraphs3LinkMode" value="1" id="paragraphs3LinkMode02" {$paragraphViewData[3]['linkEnabledChecked']}>
                      <label for="paragraphs3LinkMode02">リンクする</label>
                    </div>
                    <dl>
                      <div>
                        <dt>リンク対象テキスト</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs3LinkText" value="{$paragraphViewData[3]['linkTextEsc']}">
                          <p>このテキストへリンクを張ります。（上限50文字以内）</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先URL</dt>
                        <dd class="item_url">
                          <input type="text" name="paragraphs3LinkUrl" value="{$paragraphViewData[3]['linkUrlEsc']}">
                          <p>「http:://～」「https://～」入力してください。</p>
                        </dd>
                      </div>
                      <div>
                        <dt>リンク先ウィンドウ</dt>
                        <dd>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs3LinWindow" value="1" id="paragraphs3LinWindow01" {$paragraphViewData[3]['linkTargetSameChecked']}>
                            <label for="paragraphs3LinWindow01">同じウィンドウ</label>
                          </div>
                          <div class="item_radio">
                            <input type="radio" name="paragraphs3LinWindow" value="2" id="paragraphs3LinWindow02" {$paragraphViewData[3]['linkTargetBlankChecked']}>
                            <label for="paragraphs3LinWindow02">新しいウィンドウ</label>
                          </div>
                        </dd>
                      </div>
                    </dl>
                  </div>
                </dd>
              </div>
            </dl>
          </article>
          <button type="button" class="btn_submit" onclick="sendInput();">{$submitButtonText}</button>
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        <a href="#" class="link_page-back_bottom">戻る</a>
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
  <script src="../assets/js/dropZone.js" defer></script>
  <script nonce="{$cspNonceEsc}">
    window.CLIENT02_02_01 = {$tipsConfigJs};
  </script>
  <script src="./assets/js/client02_02_01.js" defer></script>
</body>

</html>

HTML;
