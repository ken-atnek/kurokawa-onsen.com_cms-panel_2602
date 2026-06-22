<?php
/*
 * [96-client/assets/function/proc_client02_02_02.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事(HTMLタイプ)：登録／編集 処理
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
#自由記事情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_articles.php';
#HTMLサニタイズ
require_once DOCUMENT_ROOT_PATH . '/assets/lib/TipTap/html_sanitizer.php';
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
 * JSONレスポンスを返して終了
 *
 */
function client020202JsonExit($makeTag)
{
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($makeTag);
  exit;
}

/**
 * セッションキー等をディレクトリ名用に安全化
 *
 */
function client020202SafeKeySegment($value)
{
  return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value);
}

/**
 * ディレクトリを作成
 *
 */
function client020202EnsureDir($dir)
{
  if (is_dir($dir)) {
    return true;
  }
  return mkdir($dir, 0777, true);
}

/**
 * MIMEから画像拡張子を取得
 *
 */
function client020202GetImageExtFromMime($mime)
{
  $map = array(
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  );
  return $map[(string)$mime] ?? null;
}

/**
 * HTMLから本文テキストを抽出
 *
 */
function client020202PlainTextFromHtml($html)
{
  return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'));
}

/**
 * 管理画面表示用URLをDB保存用URLへ戻す
 *
 */
function client020202NormalizeImageSrcForStorage($html)
{
  if ($html === null || $html === '') {
    return $html;
  }
  $bases = array();
  if (defined('DOMAIN_NAME_PREVIEW') && DOMAIN_NAME_PREVIEW !== '') {
    $bases[] = rtrim(DOMAIN_NAME_PREVIEW, '/');
  }
  if (defined('DOMAIN_NAME') && DOMAIN_NAME !== '') {
    $bases[] = rtrim(DOMAIN_NAME, '/');
  }
  return preg_replace_callback('/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)(\2)/i', function ($matches) use ($bases) {
    $src = html_entity_decode((string)$matches[3], ENT_QUOTES, 'UTF-8');
    $normalizedSrc = $src;
    foreach ($bases as $base) {
      if ($base !== '' && strpos($normalizedSrc, $base . '/db/images/') === 0) {
        $normalizedSrc = substr($normalizedSrc, strlen($base));
        break;
      }
    }
    return $matches[1] . $matches[2] . htmlspecialchars($normalizedSrc, ENT_QUOTES, 'UTF-8') . $matches[4];
  }, (string)$html);
}

/**
 * HTMLフリー本文HTMLを保存用に整形
 *
 */
function client020202NormalizeBodyHtml($html)
{
  $html = trim((string)$html);
  $html = client020202NormalizeImageSrcForStorage($html);
  if (client020202PlainTextFromHtml($html) === '') {
    return '';
  }
  $html = sanitizeArticleHtml($html);
  if (client020202PlainTextFromHtml($html) === '') {
    return '';
  }
  return $html;
}

/**
 * 店舗IDを3桁文字列に整形
 *
 */
function client020202FormatShopId3($shopId)
{
  return str_pad((string)(int)$shopId, 3, '0', STR_PAD_LEFT);
}

/**
 * HTMLフリー本文画像storage_pathを組み立て
 *
 */
function client020202BuildHtmlBodyImageStoragePath($shopId, $articleId, $fileName)
{
  $fileName = (string)$fileName;
  if ($fileName === '' || strpos($fileName, '..') !== false || basename($fileName) !== $fileName) {
    throw new RuntimeException('画像ファイル名が不正です。');
  }
  return 'shops/' . client020202FormatShopId3($shopId) . '/articles/' . (int)$articleId . '/html_body/' . $fileName;
}

/**
 * 記事画像storage_pathから物理パスを取得
 *
 */
function client020202BuildArticleImagePhysicalPath($storagePath)
{
  $storagePath = ltrim(trim((string)$storagePath), '/');
  if ($storagePath === '' || strpos($storagePath, '..') !== false) {
    throw new RuntimeException('画像保存先が不正です。');
  }
  $baseDir = rtrim(DEFINE_FILE_DIR_PATH, '/\\');
  $imageRoot = (basename($baseDir) === 'shops') ? dirname($baseDir) : $baseDir;
  return rtrim($imageRoot, '/\\') . '/' . str_replace('\\', '/', $storagePath);
}

/**
 * 記事画像storage_pathから本文保存用URLを取得
 *
 */
function client020202BuildArticleImageUrl($storagePath)
{
  return '/db/images/' . ltrim((string)$storagePath, '/');
}

/**
 * tmp画像が指定tmpルート配下にあることを確認
 *
 */
function client020202AssertTmpFileInRoot($tmpName, $rootRelativeDir)
{
  $tmpName = (string)$tmpName;
  if ($tmpName === '') {
    throw new RuntimeException('一時画像が見つかりません。');
  }
  $rootDir = realpath(__DIR__ . '/../../../tmp_upload/' . trim((string)$rootRelativeDir, '/'));
  $realPath = realpath($tmpName);
  if ($rootDir === false || $realPath === false || is_file($realPath) === false) {
    throw new RuntimeException('一時画像が見つかりません。');
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    throw new RuntimeException('一時画像パスが不正です。');
  }
  return $realPath;
}

/**
 * tmp画像を記事画像本保存先へコピー
 *
 */
function client020202CopyTmpFileToArticleImagePath($tmpName, $storagePath, &$copiedFiles)
{
  $physicalPath = client020202BuildArticleImagePhysicalPath($storagePath);
  $saveDir = dirname($physicalPath);
  if (is_dir($saveDir) === false && mkdir($saveDir, 0777, true) === false) {
    throw new RuntimeException('画像保存先を作成できませんでした。');
  }
  if (file_exists($physicalPath)) {
    throw new RuntimeException('同名の画像ファイルが既に存在します。');
  }
  if (copy($tmpName, $physicalPath) === false) {
    throw new RuntimeException('画像の本保存に失敗しました。');
  }
  $copiedFiles[] = $physicalPath;
  return $physicalPath;
}

/**
 * HTML本文内のtmp画像srcを本保存URLへ置換
 *
 */
function client020202RewriteHtmlInlineImageSrc($html, $replacementMap)
{
  if ($html === null || empty($replacementMap)) {
    return $html;
  }
  return preg_replace_callback('/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)(\2)/i', function ($matches) use ($replacementMap) {
    $src = html_entity_decode((string)$matches[3], ENT_QUOTES, 'UTF-8');
    $fileName = basename(parse_url($src, PHP_URL_PATH) ?: $src);
    if ($fileName === '' || !isset($replacementMap[$fileName])) {
      return $matches[0];
    }
    if (strpos($src, 'tmp_upload/article_html_inline') === false) {
      return $matches[0];
    }
    return $matches[1] . $matches[2] . htmlspecialchars($replacementMap[$fileName], ENT_QUOTES, 'UTF-8') . $matches[4];
  }, (string)$html);
}

/**
 * HTMLフリー本文内画像を本保存へ確定して本文HTMLを置換
 *
 */
function client020202FinalizeHtmlInlineImages($noUpDateKey, $shopId, $articleId, $bodyHtml, &$copiedFiles, &$tmpFilesToDelete)
{
  if (!isset($_SESSION[$noUpDateKey]['article_html_inline']) || is_array($_SESSION[$noUpDateKey]['article_html_inline']) === false) {
    return $bodyHtml;
  }
  $replacementMap = array();
  foreach ($_SESSION[$noUpDateKey]['article_html_inline'] as $inlineImage) {
    if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
      continue;
    }
    $tmpName = (string)($inlineImage['tmp_name'] ?? '');
    $fileName = basename((string)($inlineImage['name'] ?? ''));
    if ($fileName === '') {
      continue;
    }
    if (strpos((string)$bodyHtml, $fileName) === false) {
      if ($tmpName !== '') {
        $tmpFilesToDelete[] = $tmpName;
      }
      continue;
    }
    $tmpPath = client020202AssertTmpFileInRoot($tmpName, 'article_html_inline');
    $storagePath = client020202BuildHtmlBodyImageStoragePath($shopId, $articleId, $fileName);
    client020202CopyTmpFileToArticleImagePath($tmpPath, $storagePath, $copiedFiles);
    $replacementMap[$fileName] = client020202BuildArticleImageUrl($storagePath);
    $tmpFilesToDelete[] = $tmpPath;
  }
  return client020202RewriteHtmlInlineImageSrc($bodyHtml, $replacementMap);
}

/**
 * 保存成功後に使用済みtmp画像を削除しセッションを整理
 *
 */
function client020202CleanupHtmlInlineTempsAfterCommit($noUpDateKey, $tmpFilesToDelete)
{
  foreach (array_unique($tmpFilesToDelete) as $tmpFile) {
    if (is_file($tmpFile)) {
      @unlink($tmpFile);
    }
  }
  if (isset($_SESSION[$noUpDateKey]['article_html_inline'])) {
    unset($_SESSION[$noUpDateKey]['article_html_inline']);
  }
}

/**
 * HTMLフリー本文内tmp画像を削除
 *
 */
function client020202DeleteHtmlInlineTmpByFileName($noUpDateKey, $fileName)
{
  $fileName = (string)$fileName;
  if ($fileName === '' || strpos($fileName, '..') !== false || basename($fileName) !== $fileName) {
    return array('deleted' => false, 'invalid' => true);
  }
  $safeKey = client020202SafeKeySegment($noUpDateKey);
  $tmpName = __DIR__ . '/../../../tmp_upload/article_html_inline/' . $safeKey . '/' . $fileName;
  $rootDir = realpath(__DIR__ . '/../../../tmp_upload/article_html_inline');
  $realPath = realpath($tmpName);
  if ($rootDir === false || $realPath === false || is_file($realPath) === false) {
    return array('deleted' => false, 'invalid' => false);
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    return array('deleted' => false, 'invalid' => true);
  }
  return array('deleted' => @unlink($realPath), 'invalid' => false);
}

/**
 * HTMLフリー本文内tmp画像セッションを削除
 *
 */
function client020202UnsetHtmlInlineSessionTmp($noUpDateKey, $fileName)
{
  if (!isset($_SESSION[$noUpDateKey]['article_html_inline']) || is_array($_SESSION[$noUpDateKey]['article_html_inline']) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_html_inline'] as $idx => $inlineImage) {
    if (is_array($inlineImage) && (string)($inlineImage['name'] ?? '') === (string)$fileName) {
      unset($_SESSION[$noUpDateKey]['article_html_inline'][$idx]);
    }
  }
  $_SESSION[$noUpDateKey]['article_html_inline'] = array_values($_SESSION[$noUpDateKey]['article_html_inline']);
  if (empty($_SESSION[$noUpDateKey]['article_html_inline'])) {
    unset($_SESSION[$noUpDateKey]['article_html_inline']);
  }
}

/**
 * HTMLフリー本文内tmp画像をまとめて破棄
 *
 */
function client020202DeleteHtmlInlineSessionTmp($noUpDateKey)
{
  if (!isset($_SESSION[$noUpDateKey]['article_html_inline']) || is_array($_SESSION[$noUpDateKey]['article_html_inline']) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_html_inline'] as $inlineImage) {
    if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
      continue;
    }
    $tmpName = (string)($inlineImage['tmp_name'] ?? '');
    if ($tmpName === '') {
      continue;
    }
    try {
      $tmpPath = client020202AssertTmpFileInRoot($tmpName, 'article_html_inline');
      if (is_file($tmpPath)) {
        @unlink($tmpPath);
      }
    } catch (Throwable $e) {
      continue;
    }
  }
  unset($_SESSION[$noUpDateKey]['article_html_inline']);
}

/**
 * 保存済みHTMLフリー本文内画像srcをDB保存形式へ正規化
 *
 */
function client020202NormalizeSavedImageSrc($src)
{
  $src = html_entity_decode((string)$src, ENT_QUOTES, 'UTF-8');
  $bases = array();
  if (defined('DOMAIN_NAME_PREVIEW') && DOMAIN_NAME_PREVIEW !== '') {
    $bases[] = rtrim(DOMAIN_NAME_PREVIEW, '/');
  }
  if (defined('DOMAIN_NAME') && DOMAIN_NAME !== '') {
    $bases[] = rtrim(DOMAIN_NAME, '/');
  }
  foreach ($bases as $base) {
    if ($base !== '' && strpos($src, $base . '/db/images/') === 0) {
      return substr($src, strlen($base));
    }
  }
  return $src;
}

/**
 * HTML本文から保存済みHTMLフリー本文内画像src一覧を抽出
 *
 */
function client020202ExtractSavedInlineImageSrcList($html)
{
  if ($html === null || $html === '') {
    return array();
  }
  $srcMap = array();
  preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1/i', (string)$html, $matches);
  foreach ($matches[2] ?? array() as $src) {
    $src = client020202NormalizeSavedImageSrc($src);
    if (strpos($src, '/db/images/shops/') !== 0 || strpos($src, '/html_body/') === false) {
      continue;
    }
    $srcMap[$src] = true;
  }
  return array_keys($srcMap);
}

/**
 * 旧本文と新本文を比較し削除済みHTMLフリー本文内画像srcを取得
 *
 */
function client020202BuildDeletedInlineImageTargets($oldHtml, $newHtml)
{
  $oldSrcMap = array();
  $newSrcMap = array();
  foreach (client020202ExtractSavedInlineImageSrcList($oldHtml) as $src) {
    $oldSrcMap[$src] = true;
  }
  foreach (client020202ExtractSavedInlineImageSrcList($newHtml) as $src) {
    $newSrcMap[$src] = true;
  }
  $targets = array();
  foreach ($oldSrcMap as $src => $enabled) {
    if ($enabled === true && !isset($newSrcMap[$src])) {
      $targets[] = $src;
    }
  }
  return $targets;
}

/**
 * HTMLフリー本文内画像srcから物理パスを取得
 *
 */
function client020202ResolveHtmlInlineImageSrcPhysicalPath($src, $shopId, $articleId)
{
  $src = client020202NormalizeSavedImageSrc($src);
  if (strpos($src, '/db/images/') !== 0) {
    return false;
  }
  $storagePath = ltrim(substr($src, strlen('/db/images/')), '/');
  if ($storagePath === '' || strpos($storagePath, '..') !== false) {
    return false;
  }
  $expectedPrefix = 'shops/' . client020202FormatShopId3($shopId) . '/articles/' . (int)$articleId . '/html_body/';
  if (strpos($storagePath, $expectedPrefix) !== 0 || basename($storagePath) === '') {
    return false;
  }
  $physicalPath = client020202BuildArticleImagePhysicalPath($storagePath);
  $realPath = realpath($physicalPath);
  $imageRoot = realpath(client020202BuildArticleImagePhysicalPath('shops'));
  if ($realPath === false || $imageRoot === false || is_file($realPath) === false) {
    return false;
  }
  $realPath = str_replace('\\', '/', $realPath);
  $imageRoot = rtrim(str_replace('\\', '/', $imageRoot), '/') . '/';
  if (strpos($realPath, $imageRoot) !== 0) {
    return false;
  }
  return $realPath;
}

/**
 * DB確定後に保存済みHTMLフリー本文内画像を物理削除
 *
 */
function client020202DeleteSavedInlineImageFilesAfterCommit($targets, $shopId, $articleId)
{
  $deletedMap = array();
  foreach ($targets as $src) {
    $realPath = client020202ResolveHtmlInlineImageSrcPhysicalPath($src, $shopId, $articleId);
    if ($realPath === false) {
      continue;
    }
    $realPathKey = str_replace('\\', '/', $realPath);
    if (isset($deletedMap[$realPathKey])) {
      continue;
    }
    $deletedMap[$realPathKey] = true;
    if (@unlink($realPath) === false) {
      $data = [
        'pageName' => 'proc_client02_02_02',
        'reason' => '保存済みHTMLフリー本文内画像削除失敗',
        'errorMessage' => (string)$src,
      ];
      makeLog($data);
    }
  }
}

/**
 * 新規記事挿入時の表示順を調整
 *
 */
function client020202ShiftArticleDisplayOrderForInsert($shopId, $displayOrder)
{
  global $DB_CONNECT;
  # display_order = display_order + 1 の式UPDATEはSQL_Processで表現しづらいため、表示順シフトのみ最小PDOで処理する。
  $strSQL = "
    UPDATE
      shop_articles
    SET
      display_order = display_order + 1,
      updated_at = NOW()
    WHERE
      shop_id = :shop_id
      AND is_active = 1
      AND display_order >= :display_order
  ";
  $stmt = $DB_CONNECT->prepare($strSQL);
  $stmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
  $stmt->bindValue(':display_order', (int)$displayOrder, PDO::PARAM_INT);
  $result = $stmt->execute();
  $stmt->closeCursor();
  return $result;
}

/**
 * 編集時の記事表示順を移動調整
 *
 */
function client020202ShiftArticleDisplayOrderForMove($shopId, $articleId, $oldOrder, $newOrder)
{
  global $DB_CONNECT;
  # display_order の加減算式UPDATEはSQL_Processで表現しづらいため、表示順シフトのみ最小PDOで処理する。
  $oldOrder = (int)$oldOrder;
  $newOrder = (int)$newOrder;
  if ($oldOrder === $newOrder) {
    return true;
  }
  if ($oldOrder > $newOrder) {
    $strSQL = "
      UPDATE
        shop_articles
      SET
        display_order = display_order + 1,
        updated_at = NOW()
      WHERE
        shop_id = :shop_id
        AND is_active = 1
        AND article_id <> :article_id
        AND display_order >= :new_order
        AND display_order < :old_order
    ";
  } else {
    $strSQL = "
      UPDATE
        shop_articles
      SET
        display_order = display_order - 1,
        updated_at = NOW()
      WHERE
        shop_id = :shop_id
        AND is_active = 1
        AND article_id <> :article_id
        AND display_order > :old_order
        AND display_order <= :new_order
    ";
  }
  $stmt = $DB_CONNECT->prepare($strSQL);
  $stmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
  $stmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
  $stmt->bindValue(':old_order', $oldOrder, PDO::PARAM_INT);
  $stmt->bindValue(':new_order', $newOrder, PDO::PARAM_INT);
  $result = $stmt->execute();
  $stmt->closeCursor();
  return $result;
}

/**
 * HTMLフリー記事本体を登録
 *
 */
function client020202InsertArticle($shopId, $data)
{
  global $DB_CONNECT;
  $now = date('Y-m-d H:i:s');
  $dbFiledData = array();
  $dbFiledData['shop_id'] = array(':shop_id', (int)$shopId, 1);
  $dbFiledData['article_type'] = array(':article_type', 2, 1);
  $dbFiledData['status'] = array(':status', (int)($data['status'] ?? 0), 1);
  $dbFiledData['title'] = array(':title', (string)($data['title'] ?? ''), 0);
  $dbFiledData['display_order'] = array(':display_order', (int)($data['display_order'] ?? 1), 1);
  $dbFiledData['body_html'] = array(':body_html', (string)($data['body_html'] ?? ''), 0);
  $dbFiledData['is_active'] = array(':is_active', 1, 1);
  $dbFiledData['created_at'] = array(':created_at', $now, 0);
  $dbFiledData['updated_at'] = array(':updated_at', $now, 0);
  $result = SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, array(), 1, 2);
  if ($result != 1) {
    return false;
  }
  return (int)$DB_CONNECT->lastInsertId();
}

/**
 * HTMLフリー記事本体を更新
 *
 */
function client020202UpdateArticle($shopId, $articleId, $data)
{
  global $DB_CONNECT;
  $dbFiledData = array();
  $dbFiledData['status'] = array(':status', (int)($data['status'] ?? 0), 1);
  $dbFiledData['title'] = array(':title', (string)($data['title'] ?? ''), 0);
  $dbFiledData['display_order'] = array(':display_order', (int)($data['display_order'] ?? 1), 1);
  $dbFiledData['body_html'] = array(':body_html', (string)($data['body_html'] ?? ''), 0);
  $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
  $dbFiledValue = array();
  $dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
  $dbFiledValue['article_id'] = array(':where_article_id', (int)$articleId, 1);
  $dbFiledValue['article_type'] = array(':where_article_type', 2, 1);
  $dbFiledValue['is_active'] = array(':where_is_active', 1, 1);
  return SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) == 1;
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
    client020202JsonExit($makeTag);
  }
}
#-------------#
#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : null;
#確認／修正／登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#店舗ID
$shopId = isset($_POST['shopId']) ? $_POST['shopId'] : null;
#-------------#
#公開／非公開
$articleStatus = isset($_POST['articleStatus']) ? $_POST['articleStatus'] : 0;
#ページタイトル
$articleTitle = isset($_POST['articleTitle']) ? $_POST['articleTitle'] : null;
#表示順
$articleDisplayOrder = isset($_POST['articleDisplayOrder']) ? $_POST['articleDisplayOrder'] : null;
#本文HTML
$bodyHtml = isset($_POST['body_html']) ? $_POST['body_html'] : '';

$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($sessionShopId === null || is_numeric($sessionShopId) === false || (int)$sessionShopId <= 0) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。再ログインしてください。';
  client020202JsonExit($makeTag);
}
$sessionShopId = (int)$sessionShopId;
if ($shopId === null || $shopId === '') {
  $shopId = $sessionShopId;
}
if (is_numeric($shopId) === false || (int)$shopId !== $sessionShopId) {
  $makeTag['status'] = 'error';
  $makeTag['title'] = '権限エラー';
  $makeTag['msg'] = '不正な操作です。ページを再読み込みしてください。';
  client020202JsonExit($makeTag);
}
$shopId = $sessionShopId;

#***** タグ生成開始 *****#
switch ($action) {
  #***** HTMLフリー本文内画像 一時ファイル破棄 *****#
  case 'discardArticleHtmlInlineTemps': {
      client020202DeleteHtmlInlineSessionTmp($noUpDateKey);
      $makeTag['status'] = 'success';
      client020202JsonExit($makeTag);
    }
    break;
  #***** HTMLフリー本文内画像 一時ファイル削除 *****#
  case 'deleteArticleHtmlInlineTemp': {
      try {
        $fileName = isset($_POST['fileName']) ? (string)$_POST['fileName'] : '';
        if ($fileName === '' || strpos($fileName, '..') !== false || basename($fileName) !== $fileName) {
          throw new RuntimeException('削除対象の画像パスが不正です。');
        }
        $deleteResult = client020202DeleteHtmlInlineTmpByFileName($noUpDateKey, $fileName);
        if ($deleteResult['invalid'] === true) {
          throw new RuntimeException('削除対象の画像パスが不正です。');
        }
        client020202UnsetHtmlInlineSessionTmp($noUpDateKey, $fileName);
        $makeTag['status'] = 'success';
        $makeTag['deleted'] = $deleteResult['deleted'];
        if ($deleteResult['deleted'] === false) {
          $makeTag['msg'] = '削除対象の一時画像はありません。';
        }
        client020202JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像削除失敗';
        $makeTag['msg'] = $e->getMessage();
        client020202JsonExit($makeTag);
      }
    }
    break;
  #***** HTMLフリー本文内画像 一時アップロード *****#
  case 'uploadInlineImage': {
      try {
        if ($method !== 'new' && $method !== 'edit') {
          throw new RuntimeException('処理方法が不正です。');
        }
        if (!isset($_FILES['file'])) {
          throw new RuntimeException('画像ファイルが見つかりません。');
        }
        $file = $_FILES['file'];
        if (!is_array($file) || !isset($file['tmp_name']) || $file['tmp_name'] === '') {
          throw new RuntimeException('画像ファイルが不正です。');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
          throw new RuntimeException('アップロードに失敗しました。');
        }
        $maxBytes = 5 * 1024 * 1024;
        $fileSize = isset($file['size']) ? (int)$file['size'] : 0;
        if ($fileSize <= 0 || $fileSize > $maxBytes) {
          throw new RuntimeException('ファイルサイズは5MB以内にしてください。');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
          throw new RuntimeException('画像形式を確認できませんでした。');
        }
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $ext = client020202GetImageExtFromMime((string)$mime);
        if ($ext === null) {
          throw new RuntimeException('許可されていない画像形式です。');
        }
        $safeKey = client020202SafeKeySegment($noUpDateKey);
        $relativeDir = 'article_html_inline/' . $safeKey;
        $saveDir = __DIR__ . '/../../../tmp_upload/' . $relativeDir;
        if (client020202EnsureDir($saveDir) === false) {
          throw new RuntimeException('画像保存先を作成できませんでした。');
        }
        $fileName = 'inline_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $savePath = rtrim($saveDir, '/\\') . '/' . $fileName;
        if (move_uploaded_file($file['tmp_name'], $savePath) === false) {
          throw new RuntimeException('画像保存に失敗しました。');
        }
        if (!isset($_SESSION[$noUpDateKey]['article_html_inline']) || is_array($_SESSION[$noUpDateKey]['article_html_inline']) === false) {
          $_SESSION[$noUpDateKey]['article_html_inline'] = array();
        }
        $url = DEFINE_PREVIEW_IMAGE_DIR_PATH . '/' . $relativeDir . '/' . $fileName;
        $_SESSION[$noUpDateKey]['article_html_inline'][] = array(
          'kind' => 'tmp',
          'tmp_name' => $savePath,
          'preview' => $url,
          'name' => $fileName,
          'type' => (string)$mime,
          'size' => $fileSize,
          'uploaded_at' => time(),
        );
        $makeTag['status'] = 'success';
        $makeTag['url'] = $url;
        $makeTag['file_name'] = $fileName;
        client020202JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = $e->getMessage();
        client020202JsonExit($makeTag);
      }
    }
    break;
  #***** 登録 *****#
  case 'sendInput': {
      $copiedArticleImageFiles = array();
      $tmpArticleImageFilesToDelete = array();
      $savedInlineImageFilesToDelete = array();
      try {
        $validationErrors = array();
        if ($method !== 'new' && $method !== 'edit') {
          $validationErrors[] = '処理方法が不正です。';
        }
        $articleStatus = is_numeric($articleStatus) ? (int)$articleStatus : -1;
        if (in_array($articleStatus, array(0, 1), true) === false) {
          $validationErrors[] = '公開設定が不正です。';
        }
        $articleTitle = trim((string)$articleTitle);
        if ($articleTitle === '') {
          $validationErrors[] = 'ページタイトルは必須です。';
        }
        $articleDisplayOrder = is_numeric($articleDisplayOrder) ? (int)$articleDisplayOrder : 0;
        if ($articleDisplayOrder < 1) {
          $validationErrors[] = '表示順が不正です。';
        }
        $bodyHtml = trim((string)client020202NormalizeImageSrcForStorage($bodyHtml));
        if (client020202PlainTextFromHtml($bodyHtml) === '') {
          $validationErrors[] = '本文は必須です。';
        }
        $articleId = isset($_POST['articleId']) ? (int)$_POST['articleId'] : 0;
        if ($method === 'edit' && $articleId < 1) {
          $validationErrors[] = '記事IDが不正です。';
        }
        if (!empty($validationErrors)) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = '入力エラー';
          $makeTag['msg'] = implode("\n", $validationErrors);
          client020202JsonExit($makeTag);
        }
        $existingArticle = false;
        if ($method === 'edit') {
          $existingArticle = getShopArticleHtmlData_FindById($shopId, $articleId);
          if ($existingArticle === false) {
            $makeTag['status'] = 'error';
            $makeTag['title'] = '入力エラー';
            $makeTag['msg'] = '対象の記事が見つかりません。';
            client020202JsonExit($makeTag);
          }
        }
        $articleCount = countShopArticles(array(), $shopId);
        if ($method === 'new') {
          $displayOrderMax = $articleCount + 1;
          if ($articleDisplayOrder < 1 || $articleDisplayOrder > $displayOrderMax) {
            $articleDisplayOrder = $displayOrderMax;
          }
        } else {
          $displayOrderMax = max($articleCount, 1);
          if ($articleDisplayOrder < 1 || $articleDisplayOrder > $displayOrderMax) {
            $articleDisplayOrder = isset($existingArticle['display_order']) ? (int)$existingArticle['display_order'] : $displayOrderMax;
          }
          if ($articleDisplayOrder < 1 || $articleDisplayOrder > $displayOrderMax) {
            $articleDisplayOrder = $displayOrderMax;
          }
        }
        if (DB_Transaction(1) === false) {
          throw new RuntimeException('トランザクション開始に失敗しました。');
        }
        if ($method === 'new') {
          if (client020202ShiftArticleDisplayOrderForInsert($shopId, $articleDisplayOrder) === false) {
            throw new RuntimeException('表示順の更新に失敗しました。');
          }
          $articleId = client020202InsertArticle($shopId, array(
            'status' => $articleStatus,
            'title' => $articleTitle,
            'display_order' => $articleDisplayOrder,
            'body_html' => '',
          ));
          if ($articleId === false || (int)$articleId < 1) {
            throw new RuntimeException('記事の登録に失敗しました。');
          }
        } else {
          $oldOrder = isset($existingArticle['display_order']) ? (int)$existingArticle['display_order'] : $articleDisplayOrder;
          if ($oldOrder !== $articleDisplayOrder && client020202ShiftArticleDisplayOrderForMove($shopId, $articleId, $oldOrder, $articleDisplayOrder) === false) {
            throw new RuntimeException('表示順の更新に失敗しました。');
          }
        }
        $bodyHtml = client020202FinalizeHtmlInlineImages($noUpDateKey, $shopId, $articleId, $bodyHtml, $copiedArticleImageFiles, $tmpArticleImageFilesToDelete);
        $bodyHtml = client020202NormalizeBodyHtml($bodyHtml);
        if ($bodyHtml === '') {
          throw new RuntimeException('本文は必須です。');
        }
        if ($method === 'edit') {
          $savedInlineImageFilesToDelete = client020202BuildDeletedInlineImageTargets($existingArticle['body_html'] ?? '', $bodyHtml);
        }
        if (client020202UpdateArticle($shopId, $articleId, array(
          'status' => $articleStatus,
          'title' => $articleTitle,
          'display_order' => $articleDisplayOrder,
          'body_html' => $bodyHtml,
        )) === false) {
          throw new RuntimeException(($method === 'edit') ? '記事の更新に失敗しました。' : '記事の登録に失敗しました。');
        }
        DB_Transaction(2);
        client020202CleanupHtmlInlineTempsAfterCommit($noUpDateKey, $tmpArticleImageFilesToDelete);
        client020202DeleteSavedInlineImageFilesAfterCommit($savedInlineImageFilesToDelete, $shopId, $articleId);
        $makeTag['status'] = 'success';
        $makeTag['title'] = ($method === 'edit') ? '自由記事編集' : '自由記事登録';
        $makeTag['msg'] = ($method === 'edit') ? '保存が完了しました。' : '登録が完了しました。';
        $makeTag['redirect'] = './client02_01.php';
        syncFrontendArticleJson($makeTag, $shopId, $articleId);
      } catch (Throwable $e) {
        DB_Transaction(3);
        foreach ($copiedArticleImageFiles as $copiedFile) {
          if (is_file($copiedFile)) {
            @unlink($copiedFile);
          }
        }
        $data = [
          'pageName' => 'proc_client02_02_02',
          'reason' => 'HTMLフリー記事保存失敗',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = $e->getMessage();
      }
    }
    break;
  default:
    $makeTag['status'] = 'error';
    $makeTag['title'] = 'エラー';
    $makeTag['msg'] = '不正な操作です。';
    break;
}
#-------------------------------------------#
#json 応答
client020202JsonExit($makeTag);
#-------------------------------------------#
#===========================================#
