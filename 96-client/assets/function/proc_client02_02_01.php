<?php
/*
 * [96-client/assets/function/proc_client02_02_01.php]
 *  - 【加盟店】管理画面 -
 *  自由ページ記事(定型タイプ)：登録／編集 処理
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
 * JSONレスポンスを返して処理を終了
 *
 */
function client020201JsonExit($makeTag)
{
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($makeTag);
  exit;
}
/**
 * 一時アップロード用のキー文字列を安全なディレクトリ名へ整形
 *
 */
function client020201SafeKeySegment($value)
{
  $value = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$value);
  return ($value !== '') ? $value : 'anonymous';
}
/**
 * ディレクトリがなければ作成
 *
 */
function client020201EnsureDir($dir)
{
  if (is_dir($dir)) {
    return true;
  }
  return mkdir($dir, 0777, true);
}
/**
 * 許可MIMEから保存拡張子を取得
 *
 */
function client020201GetImageExtFromMime($mime)
{
  $map = array(
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
  );
  return $map[$mime] ?? null;
}
/**
 * 段落サムネイル一時画像を安全に削除
 *
 */
function client020201DeleteArticleThumbnailTmpFile($tmpName)
{
  $tmpName = (string)$tmpName;
  if ($tmpName === '') {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  $rootDir = realpath(__DIR__ . '/../../../tmp_upload/article_thumbnail');
  if ($rootDir === false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  $realPath = realpath($tmpName);
  if ($realPath === false || is_file($realPath) === false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    return array('deleted' => false, 'exists' => true, 'invalid' => true);
  }
  return array('deleted' => @unlink($realPath), 'exists' => true, 'invalid' => false);
}
/**
 * セッションに保持した段落サムネイル一時画像を削除
 *
 */
function client020201DeleteArticleThumbnailSessionTmp($noUpDateKey, $paragraphNo = null)
{
  $result = array('deleted' => false, 'found' => false, 'invalid' => false);
  if (!isset($_SESSION[$noUpDateKey]['article_thumbnail']) || is_array($_SESSION[$noUpDateKey]['article_thumbnail']) === false) {
    return $result;
  }
  if ($paragraphNo !== null) {
    $targets = array((int)$paragraphNo);
  } else {
    $targets = array_keys($_SESSION[$noUpDateKey]['article_thumbnail']);
  }
  foreach ($targets as $targetNo) {
    if (!isset($_SESSION[$noUpDateKey]['article_thumbnail'][$targetNo]) || is_array($_SESSION[$noUpDateKey]['article_thumbnail'][$targetNo]) === false) {
      continue;
    }
    $thumbnail = $_SESSION[$noUpDateKey]['article_thumbnail'][$targetNo];
    if (($thumbnail['kind'] ?? '') === 'tmp') {
      $result['found'] = true;
      $deleteResult = client020201DeleteArticleThumbnailTmpFile($thumbnail['tmp_name'] ?? '');
      if ($deleteResult['invalid'] === true) {
        $result['invalid'] = true;
        continue;
      }
      if ($deleteResult['deleted'] === true) {
        $result['deleted'] = true;
      }
      if ($deleteResult['deleted'] === true || $deleteResult['exists'] === false) {
        unset($_SESSION[$noUpDateKey]['article_thumbnail'][$targetNo]);
      }
    }
  }
  if (empty($_SESSION[$noUpDateKey]['article_thumbnail'])) {
    unset($_SESSION[$noUpDateKey]['article_thumbnail']);
  }
  return $result;
}
/**
 * ファイル名から段落サムネイル一時画像を削除
 *
 */
function client020201DeleteArticleThumbnailTmpByFileName($noUpDateKey, $paragraphNo, $fileName)
{
  $fileName = (string)$fileName;
  if ($fileName === '') {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  if (strpos($fileName, '..') !== false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => true);
  }
  $safeFileName = basename($fileName);
  if ($safeFileName === '' || $safeFileName !== $fileName) {
    return array('deleted' => false, 'exists' => false, 'invalid' => true);
  }
  $safeKey = client020201SafeKeySegment($noUpDateKey);
  $tmpPath = __DIR__ . '/../../../tmp_upload/article_thumbnail/' . $safeKey . '/paragraph_' . (int)$paragraphNo . '/' . $safeFileName;
  return client020201DeleteArticleThumbnailTmpFile($tmpPath);
}
/**
 * TipTap本文内一時画像を安全に削除
 *
 */
function client020201DeleteArticleInlineTmpFile($tmpName)
{
  $tmpName = (string)$tmpName;
  if ($tmpName === '') {
    return false;
  }
  $rootDir = realpath(__DIR__ . '/../../../tmp_upload/article_inline');
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
 * セッションに保持したTipTap本文内一時画像を削除
 *
 */
function client020201DeleteArticleInlineSessionTmp($noUpDateKey)
{
  if (!isset($_SESSION[$noUpDateKey]['article_inline']) || is_array($_SESSION[$noUpDateKey]['article_inline']) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_inline'] as $paragraphNo => $inlineImages) {
    if (is_array($inlineImages) === false) {
      continue;
    }
    foreach ($inlineImages as $idx => $inlineImage) {
      if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
        continue;
      }
      client020201DeleteArticleInlineTmpFile($inlineImage['tmp_name'] ?? '');
      unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo][$idx]);
    }
    if (empty($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo])) {
      unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]);
    }
  }
  if (empty($_SESSION[$noUpDateKey]['article_inline'])) {
    unset($_SESSION[$noUpDateKey]['article_inline']);
  }
}
/**
 * ファイル名からTipTap本文内一時画像を削除
 *
 */
function client020201DeleteArticleInlineTmpByFileName($noUpDateKey, $paragraphNo, $fileName)
{
  $fileName = (string)$fileName;
  if ($fileName === '' || strpos($fileName, '..') !== false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => true);
  }
  $safeFileName = basename($fileName);
  if ($safeFileName === '' || $safeFileName !== $fileName) {
    return array('deleted' => false, 'exists' => false, 'invalid' => true);
  }
  $rootDir = realpath(__DIR__ . '/../../../tmp_upload/article_inline');
  if ($rootDir === false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  $safeKey = client020201SafeKeySegment($noUpDateKey);
  $tmpPath = __DIR__ . '/../../../tmp_upload/article_inline/' . $safeKey . '/paragraph_' . (int)$paragraphNo . '/' . $safeFileName;
  $realPath = realpath($tmpPath);
  if ($realPath === false || is_file($realPath) === false) {
    return array('deleted' => false, 'exists' => false, 'invalid' => false);
  }
  $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/') . '/';
  $realPath = str_replace('\\', '/', $realPath);
  if (strpos($realPath, $rootDir) !== 0) {
    return array('deleted' => false, 'exists' => true, 'invalid' => true);
  }
  return array('deleted' => @unlink($realPath), 'exists' => true, 'invalid' => false);
}
/**
 * TipTap本文内一時画像セッションから指定ファイルを削除
 *
 */
function client020201UnsetArticleInlineSessionTmp($noUpDateKey, $paragraphNo, $fileName)
{
  if (!isset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]) || is_array($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo] as $idx => $inlineImage) {
    if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
      continue;
    }
    if ((string)($inlineImage['name'] ?? '') === (string)$fileName) {
      unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo][$idx]);
    }
  }
  if (empty($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo])) {
    unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]);
  }
  if (empty($_SESSION[$noUpDateKey]['article_inline'])) {
    unset($_SESSION[$noUpDateKey]['article_inline']);
  }
}
/**
 * HTMLから本文テキストを抽出
 *
 */
function client020201PlainTextFromHtml($html)
{
  return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8'));
}
/**
 * 管理画面表示用URLをDB保存用URLへ戻す
 *
 */
function client020201NormalizeArticleImageSrcForStorage($html)
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
 * TipTap本文HTMLを保存用に正規化
 *
 */
function client020201NormalizeArticleBodyHtml($html)
{
  $html = trim((string)$html);
  $html = client020201NormalizeArticleImageSrcForStorage($html);
  if (client020201PlainTextFromHtml($html) === '') {
    return null;
  }
  return $html;
}
/**
 * 段落POST値を保存用配列に整形
 *
 */
function client020201BuildParagraphPostData()
{
  $postedParagraphs = isset($_POST['paragraphs']) && is_array($_POST['paragraphs']) ? $_POST['paragraphs'] : array();
  $paragraphs = array();
  for ($paragraphNo = 1; $paragraphNo <= 3; $paragraphNo++) {
    $legacyPrefix = 'paragraphs' . $paragraphNo;
    $posted = isset($postedParagraphs[$paragraphNo]) && is_array($postedParagraphs[$paragraphNo]) ? $postedParagraphs[$paragraphNo] : array();
    $bodyHtml = isset($posted['body_html']) ? (string)$posted['body_html'] : '';
    $linkEnabled = isset($posted['link_enabled']) ? (int)$posted['link_enabled'] : (int)($_POST[$legacyPrefix . 'LinkMode'] ?? 0);
    $linkTarget = isset($posted['link_target']) ? (int)$posted['link_target'] : (int)($_POST[$legacyPrefix . 'LinWindow'] ?? 1);
    $paragraphs[$paragraphNo] = array(
      'title' => trim((string)($posted['title'] ?? ($_POST[$legacyPrefix . 'Title'] ?? ''))),
      'body_text' => client020201NormalizeArticleBodyHtml($bodyHtml),
      'link_enabled' => ($linkEnabled === 1) ? 1 : 0,
      'link_text' => trim((string)($posted['link_text'] ?? ($_POST[$legacyPrefix . 'LinkText'] ?? ''))),
      'link_url' => trim((string)($posted['link_url'] ?? ($_POST[$legacyPrefix . 'LinkUrl'] ?? ''))),
      'link_target' => ($linkTarget === 2) ? 2 : 1,
    );
  }
  return $paragraphs;
}
/**
 * 保存済みサムネイル画像の削除予定段落を取得
 *
 */
function client020201BuildDeleteSavedThumbnailMap()
{
  $deleteSavedThumbnail = isset($_POST['delete_saved_thumbnail']) && is_array($_POST['delete_saved_thumbnail'])
    ? $_POST['delete_saved_thumbnail']
    : array();
  $deleteMap = array();
  foreach ($deleteSavedThumbnail as $paragraphNo => $value) {
    if ((string)$value !== '1') {
      continue;
    }
    $paragraphNo = is_numeric($paragraphNo) ? (int)$paragraphNo : 0;
    if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
      throw new RuntimeException('削除対象の段落番号が不正です。');
    }
    $deleteMap[$paragraphNo] = true;
  }
  return $deleteMap;
}
/**
 * 指定段落に一時サムネイル画像があるか確認
 *
 */
function client020201HasArticleThumbnailTmp($noUpDateKey, $paragraphNo)
{
  return isset($_SESSION[$noUpDateKey]['article_thumbnail'][(int)$paragraphNo])
    && is_array($_SESSION[$noUpDateKey]['article_thumbnail'][(int)$paragraphNo])
    && ($_SESSION[$noUpDateKey]['article_thumbnail'][(int)$paragraphNo]['kind'] ?? '') === 'tmp';
}
/**
 * 新規記事挿入時の表示順を調整
 *
 */
function client020201ShiftArticleDisplayOrderForInsert($shopId, $displayOrder)
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
function client020201ShiftArticleDisplayOrderForMove($shopId, $articleId, $oldOrder, $newOrder)
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
 * 自由記事本体を登録
 *
 */
function client020201InsertArticle($shopId, $data)
{
  global $DB_CONNECT;
  $now = date('Y-m-d H:i:s');
  $dbFiledData = array();
  $dbFiledData['shop_id'] = array(':shop_id', (int)$shopId, 1);
  $dbFiledData['article_type'] = array(':article_type', 1, 1);
  $dbFiledData['status'] = array(':status', (int)($data['status'] ?? 0), 1);
  $dbFiledData['title'] = array(':title', (string)($data['title'] ?? ''), 0);
  $dbFiledData['display_order'] = array(':display_order', (int)($data['display_order'] ?? 1), 1);
  $dbFiledData['body_html'] = array(':body_html', null, 2);
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
 * 自由記事本体を更新
 *
 */
function client020201UpdateArticle($shopId, $articleId, $data)
{
  global $DB_CONNECT;
  $dbFiledData = array();
  $dbFiledData['status'] = array(':status', (int)($data['status'] ?? 0), 1);
  $dbFiledData['title'] = array(':title', (string)($data['title'] ?? ''), 0);
  $dbFiledData['display_order'] = array(':display_order', (int)($data['display_order'] ?? 1), 1);
  $dbFiledData['body_html'] = array(':body_html', null, 2);
  $dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
  $dbFiledValue = array();
  $dbFiledValue['shop_id'] = array(':where_shop_id', (int)$shopId, 1);
  $dbFiledValue['article_id'] = array(':where_article_id', (int)$articleId, 1);
  $dbFiledValue['article_type'] = array(':where_article_type', 1, 1);
  $dbFiledValue['is_active'] = array(':where_is_active', 1, 1);
  return SQL_Process($DB_CONNECT, 'shop_articles', $dbFiledData, $dbFiledValue, 2, 2) == 1;
}
/**
 * 自由記事段落を登録・更新
 *
 */
function client020201UpsertArticleParagraph($articleId, $paragraphNo, $data)
{
  global $DB_CONNECT;
  $now = date('Y-m-d H:i:s');
  $exists = getShopArticleParagraphExists($articleId, $paragraphNo);
  $bodyTextValue = $data['body_text'] ?? null;
  if ($bodyTextValue !== null) {
    $bodyTextValue = sanitizeArticleParagraphHtml((string)$bodyTextValue);
  }
  $dbFiledData = array();
  $dbFiledData['title'] = array(':title', (string)($data['title'] ?? ''), 0);
  $dbFiledData['body_text'] = ($bodyTextValue === null)
    ? array(':body_text', null, 2)
    : array(':body_text', (string)$bodyTextValue, 0);
  $dbFiledData['link_enabled'] = array(':link_enabled', (int)($data['link_enabled'] ?? 0), 1);
  $dbFiledData['link_text'] = array(':link_text', (string)($data['link_text'] ?? ''), 0);
  $dbFiledData['link_url'] = array(':link_url', (string)($data['link_url'] ?? ''), 0);
  $dbFiledData['link_target'] = array(':link_target', (int)($data['link_target'] ?? 1), 1);
  if (!empty($data['update_image_storage_path'])) {
    $imageStoragePath = isset($data['image_storage_path']) ? trim((string)$data['image_storage_path']) : '';
    $dbFiledData['image_storage_path'] = ($imageStoragePath === '')
      ? array(':image_storage_path', null, 2)
      : array(':image_storage_path', $imageStoragePath, 0);
  }
  $dbFiledData['updated_at'] = array(':updated_at', $now, 0);
  if ($exists) {
    $dbFiledValue = array();
    $dbFiledValue['article_id'] = array(':where_article_id', (int)$articleId, 1);
    $dbFiledValue['paragraph_no'] = array(':where_paragraph_no', (int)$paragraphNo, 1);
    return SQL_Process($DB_CONNECT, 'shop_article_paragraphs', $dbFiledData, $dbFiledValue, 2, 2) == 1;
  }
  $dbFiledData['article_id'] = array(':article_id', (int)$articleId, 1);
  $dbFiledData['paragraph_no'] = array(':paragraph_no', (int)$paragraphNo, 1);
  if (array_key_exists('image_storage_path', $dbFiledData) === false) {
    $dbFiledData['image_storage_path'] = array(':image_storage_path', null, 2);
  }
  $dbFiledData['created_at'] = array(':created_at', $now, 0);
  return SQL_Process($DB_CONNECT, 'shop_article_paragraphs', $dbFiledData, array(), 1, 2) == 1;
}
/**
 * 店舗IDを3桁文字列に整形
 *
 */
function client020201FormatShopId3($shopId)
{
  return str_pad((string)(int)$shopId, 3, '0', STR_PAD_LEFT);
}
/**
 * 記事画像storage_pathを組み立て
 *
 */
function client020201BuildArticleImageStoragePath($shopId, $articleId, $paragraphNo, $fileName)
{
  $fileName = (string)$fileName;
  if ($fileName === '' || strpos($fileName, '..') !== false || basename($fileName) !== $fileName) {
    throw new RuntimeException('画像ファイル名が不正です。');
  }
  if (in_array((int)$paragraphNo, array(1, 2, 3), true) === false) {
    throw new RuntimeException('段落番号が不正です。');
  }
  return 'shops/' . client020201FormatShopId3($shopId) . '/articles/' . (int)$articleId . '/paragraph_' . (int)$paragraphNo . '/' . $fileName;
}
/**
 * 記事画像storage_pathから物理パスを取得
 *
 */
function client020201BuildArticleImagePhysicalPath($storagePath)
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
 * 記事画像storage_pathから管理画面表示URLを取得
 *
 */
function client020201BuildArticleImageAdminUrl($storagePath)
{
  return '/db/images/' . ltrim((string)$storagePath, '/');
}
/**
 * 保存済み記事画像storage_pathの削除対象情報を検証して物理パスを取得
 *
 */
function client020201ResolveSavedArticleImagePhysicalPath($storagePath, $shopId, $articleId, $paragraphNo)
{
  $storagePath = ltrim(trim((string)$storagePath), '/');
  $paragraphNo = (int)$paragraphNo;
  if ($storagePath === '' || strpos($storagePath, '..') !== false) {
    return false;
  }
  if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
    return false;
  }
  $expectedPrefix = 'shops/' . client020201FormatShopId3($shopId) . '/articles/' . (int)$articleId . '/paragraph_' . $paragraphNo . '/';
  if (strpos($storagePath, $expectedPrefix) !== 0) {
    return false;
  }
  $fileName = basename($storagePath);
  if ($fileName === '' || strpos($fileName, '..') !== false) {
    return false;
  }
  $physicalPath = client020201BuildArticleImagePhysicalPath($storagePath);
  $realPath = realpath($physicalPath);
  $imageRoot = realpath(rtrim(DEFINE_JSON_DIR_PATH, '/\\') . '/images');
  if ($realPath === false || $imageRoot === false || is_file($realPath) === false) {
    return false;
  }
  $realPathNormalized = str_replace('\\', '/', $realPath);
  $imageRootNormalized = rtrim(str_replace('\\', '/', $imageRoot), '/') . '/';
  if (strpos($realPathNormalized, $imageRootNormalized) !== 0) {
    return false;
  }
  return $realPath;
}
/**
 * 保存済みサムネイル削除予定を段落保存データへ反映
 *
 */
function client020201ApplySavedThumbnailDeleteRequests($deleteMap, $oldParagraphs, $noUpDateKey, $shopId, $articleId, &$paragraphs, &$savedFilesToDelete)
{
  if (empty($deleteMap)) {
    return;
  }
  foreach ($deleteMap as $paragraphNo => $enabled) {
    if ($enabled !== true) {
      continue;
    }
    $paragraphNo = (int)$paragraphNo;
    $oldStoragePath = isset($oldParagraphs[$paragraphNo]['image_storage_path']) ? trim((string)$oldParagraphs[$paragraphNo]['image_storage_path']) : '';
    if ($oldStoragePath !== '') {
      $savedFilesToDelete[] = array(
        'storage_path' => $oldStoragePath,
        'paragraph_no' => $paragraphNo,
      );
    }
    if (client020201HasArticleThumbnailTmp($noUpDateKey, $paragraphNo)) {
      continue;
    }
    $paragraphs[$paragraphNo]['image_storage_path'] = '';
    $paragraphs[$paragraphNo]['update_image_storage_path'] = true;
  }
}
/**
 * DB確定後に保存済み記事画像を物理削除
 *
 */
function client020201DeleteSavedArticleImageFilesAfterCommit($targets, $shopId, $articleId)
{
  $deletedMap = array();
  foreach ($targets as $target) {
    if (is_array($target) === false) {
      continue;
    }
    $storagePath = isset($target['storage_path']) ? (string)$target['storage_path'] : '';
    $paragraphNo = isset($target['paragraph_no']) ? (int)$target['paragraph_no'] : 0;
    $realPath = client020201ResolveSavedArticleImagePhysicalPath($storagePath, $shopId, $articleId, $paragraphNo);
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
        'pageName' => 'proc_client02_02_01',
        'reason' => '保存済みサムネイル画像削除失敗',
        'errorMessage' => $storagePath,
      ];
      makeLog($data);
    }
  }
}
/**
 * 本文内画像srcを保存形式へ正規化
 *
 */
function client020201NormalizeInlineImageSrcForStorage($src)
{
  $src = html_entity_decode(trim((string)$src), ENT_QUOTES, 'UTF-8');
  if ($src === '') {
    return '';
  }
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
 * 本文HTMLから保存済み本文内画像src一覧を抽出
 *
 */
function client020201ExtractSavedInlineImageSrcList($html)
{
  if ($html === null || $html === '') {
    return array();
  }
  $srcMap = array();
  preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1/i', (string)$html, $matches);
  foreach ($matches[2] ?? array() as $src) {
    $src = client020201NormalizeInlineImageSrcForStorage($src);
    if (strpos($src, '/db/images/') !== 0) {
      continue;
    }
    $srcMap[$src] = true;
  }
  return array_keys($srcMap);
}
/**
 * 旧本文と新本文を比較し削除済み本文内画像srcを取得
 *
 */
function client020201BuildDeletedInlineImageTargets($oldParagraphs, $newParagraphs)
{
  $oldSrcMap = array();
  $newSrcMap = array();
  for ($paragraphNo = 1; $paragraphNo <= 3; $paragraphNo++) {
    $oldBody = $oldParagraphs[$paragraphNo]['body_text'] ?? null;
    $newBody = $newParagraphs[$paragraphNo]['body_text'] ?? null;
    foreach (client020201ExtractSavedInlineImageSrcList($oldBody) as $src) {
      $oldSrcMap[$src] = true;
    }
    foreach (client020201ExtractSavedInlineImageSrcList($newBody) as $src) {
      $newSrcMap[$src] = true;
    }
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
 * 本文内画像srcから保存済み記事画像物理パスを取得
 *
 */
function client020201ResolveInlineImageSrcPhysicalPath($src, $shopId, $articleId)
{
  $src = client020201NormalizeInlineImageSrcForStorage($src);
  if (strpos($src, '/db/images/') !== 0) {
    return false;
  }
  $storagePath = ltrim(substr($src, strlen('/db/images/')), '/');
  if ($storagePath === '' || strpos($storagePath, '..') !== false) {
    return false;
  }
  if (preg_match('#/paragraph_([1-3])/#', $storagePath, $matches) !== 1) {
    return false;
  }
  return client020201ResolveSavedArticleImagePhysicalPath($storagePath, $shopId, $articleId, (int)$matches[1]);
}
/**
 * DB確定後に保存済み本文内画像を物理削除
 *
 */
function client020201DeleteSavedInlineImageFilesAfterCommit($targets, $shopId, $articleId)
{
  $deletedMap = array();
  foreach ($targets as $src) {
    $realPath = client020201ResolveInlineImageSrcPhysicalPath($src, $shopId, $articleId);
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
        'pageName' => 'proc_client02_02_01',
        'reason' => '保存済み本文内画像削除失敗',
        'errorMessage' => (string)$src,
      ];
      makeLog($data);
    }
  }
}
/**
 * tmp画像が指定tmpルート配下にあることを確認
 *
 */
function client020201AssertTmpFileInRoot($tmpName, $rootRelativeDir)
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
function client020201CopyTmpFileToArticleImagePath($tmpName, $storagePath, &$copiedFiles)
{
  $physicalPath = client020201BuildArticleImagePhysicalPath($storagePath);
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
 * 段落サムネイル画像を本保存へ確定
 *
 */
function client020201FinalizeArticleThumbnailImages($noUpDateKey, $shopId, $articleId, &$paragraphs, &$copiedFiles, &$tmpFilesToDelete)
{
  if (!isset($_SESSION[$noUpDateKey]['article_thumbnail']) || is_array($_SESSION[$noUpDateKey]['article_thumbnail']) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_thumbnail'] as $paragraphNo => $thumbnail) {
    $paragraphNo = (int)$paragraphNo;
    if (in_array($paragraphNo, array(1, 2, 3), true) === false || is_array($thumbnail) === false || ($thumbnail['kind'] ?? '') !== 'tmp') {
      continue;
    }
    $tmpPath = client020201AssertTmpFileInRoot($thumbnail['tmp_name'] ?? '', 'article_thumbnail');
    $fileName = basename((string)($thumbnail['name'] ?? ''));
    $storagePath = client020201BuildArticleImageStoragePath($shopId, $articleId, $paragraphNo, $fileName);
    client020201CopyTmpFileToArticleImagePath($tmpPath, $storagePath, $copiedFiles);
    $paragraphs[$paragraphNo]['image_storage_path'] = $storagePath;
    $paragraphs[$paragraphNo]['update_image_storage_path'] = true;
    $tmpFilesToDelete[] = $tmpPath;
    # TODO: 既存画像差し替え時の旧画像物理削除は、削除仕様確定後に対応する。
  }
}
/**
 * 本文HTML内のtmp画像srcを本保存URLへ置換
 *
 */
function client020201RewriteInlineImageSrc($html, $replacementMap)
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
    if (strpos($src, 'tmp_upload/article_inline') === false) {
      return $matches[0];
    }
    return $matches[1] . $matches[2] . htmlspecialchars($replacementMap[$fileName], ENT_QUOTES, 'UTF-8') . $matches[4];
  }, (string)$html);
}
/**
 * TipTap本文内画像を本保存へ確定して本文HTMLを置換
 *
 */
function client020201FinalizeArticleInlineImages($noUpDateKey, $shopId, $articleId, &$paragraphs, &$copiedFiles, &$tmpFilesToDelete)
{
  if (!isset($_SESSION[$noUpDateKey]['article_inline']) || is_array($_SESSION[$noUpDateKey]['article_inline']) === false) {
    return;
  }
  foreach ($_SESSION[$noUpDateKey]['article_inline'] as $paragraphNo => $inlineImages) {
    $paragraphNo = (int)$paragraphNo;
    if (in_array($paragraphNo, array(1, 2, 3), true) === false || is_array($inlineImages) === false) {
      continue;
    }
    $bodyHtml = $paragraphs[$paragraphNo]['body_text'] ?? null;
    $replacementMap = array();
    foreach ($inlineImages as $inlineImage) {
      if (is_array($inlineImage) === false || ($inlineImage['kind'] ?? '') !== 'tmp') {
        continue;
      }
      $tmpName = (string)($inlineImage['tmp_name'] ?? '');
      $fileName = basename((string)($inlineImage['name'] ?? ''));
      if ($bodyHtml === null || $fileName === '') {
        if ($tmpName !== '') {
          $tmpFilesToDelete[] = $tmpName;
        }
        continue;
      }
      $hasReference = (strpos((string)$bodyHtml, $fileName) !== false);
      if ($hasReference === false) {
        if ($tmpName !== '') {
          $tmpFilesToDelete[] = $tmpName;
        }
        continue;
      }
      $tmpPath = client020201AssertTmpFileInRoot($tmpName, 'article_inline');
      $storagePath = client020201BuildArticleImageStoragePath($shopId, $articleId, $paragraphNo, $fileName);
      client020201CopyTmpFileToArticleImagePath($tmpPath, $storagePath, $copiedFiles);
      $replacementMap[$fileName] = client020201BuildArticleImageAdminUrl($storagePath);
      $tmpFilesToDelete[] = $tmpPath;
    }
    if (!empty($replacementMap)) {
      $paragraphs[$paragraphNo]['body_text'] = client020201RewriteInlineImageSrc($bodyHtml, $replacementMap);
    }
  }
}
/**
 * 保存成功後に使用済みtmp画像を削除しセッションを整理
 *
 */
function client020201CleanupArticleImageTempsAfterCommit($noUpDateKey, $tmpFilesToDelete)
{
  $deleteMap = array();
  $tmpRoots = array_filter(array(
    realpath(__DIR__ . '/../../../tmp_upload/article_thumbnail'),
    realpath(__DIR__ . '/../../../tmp_upload/article_inline'),
  ));
  foreach ($tmpFilesToDelete as $tmpPath) {
    $tmpPathNormalized = str_replace('\\', '/', (string)$tmpPath);
    if ($tmpPathNormalized !== '') {
      $deleteMap[$tmpPathNormalized] = true;
    }
    $realPath = realpath((string)$tmpPath);
    if ($realPath !== false) {
      $realPathNormalized = str_replace('\\', '/', $realPath);
      $deleteMap[$realPathNormalized] = true;
      $canDelete = false;
      foreach ($tmpRoots as $tmpRoot) {
        $tmpRootNormalized = rtrim(str_replace('\\', '/', $tmpRoot), '/') . '/';
        if (strpos($realPathNormalized, $tmpRootNormalized) === 0) {
          $canDelete = true;
          break;
        }
      }
      if ($canDelete) {
        @unlink($realPath);
      }
    }
  }
  if (isset($_SESSION[$noUpDateKey]['article_thumbnail']) && is_array($_SESSION[$noUpDateKey]['article_thumbnail'])) {
    foreach ($_SESSION[$noUpDateKey]['article_thumbnail'] as $paragraphNo => $thumbnail) {
      $sessionTmpPath = isset($thumbnail['tmp_name']) ? str_replace('\\', '/', (string)$thumbnail['tmp_name']) : '';
      $realPath = isset($thumbnail['tmp_name']) ? realpath((string)$thumbnail['tmp_name']) : false;
      $realPath = ($realPath !== false) ? str_replace('\\', '/', $realPath) : '';
      if (($sessionTmpPath !== '' && isset($deleteMap[$sessionTmpPath])) || ($realPath !== '' && isset($deleteMap[$realPath]))) {
        unset($_SESSION[$noUpDateKey]['article_thumbnail'][$paragraphNo]);
      }
    }
    if (empty($_SESSION[$noUpDateKey]['article_thumbnail'])) {
      unset($_SESSION[$noUpDateKey]['article_thumbnail']);
    }
  }
  if (isset($_SESSION[$noUpDateKey]['article_inline']) && is_array($_SESSION[$noUpDateKey]['article_inline'])) {
    foreach ($_SESSION[$noUpDateKey]['article_inline'] as $paragraphNo => $inlineImages) {
      if (is_array($inlineImages) === false) {
        continue;
      }
      foreach ($inlineImages as $idx => $inlineImage) {
        $sessionTmpPath = isset($inlineImage['tmp_name']) ? str_replace('\\', '/', (string)$inlineImage['tmp_name']) : '';
        $realPath = isset($inlineImage['tmp_name']) ? realpath((string)$inlineImage['tmp_name']) : false;
        $realPath = ($realPath !== false) ? str_replace('\\', '/', $realPath) : '';
        if (($sessionTmpPath !== '' && isset($deleteMap[$sessionTmpPath])) || ($realPath !== '' && isset($deleteMap[$realPath]))) {
          unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo][$idx]);
        }
      }
      if (empty($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo])) {
        unset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]);
      }
    }
    if (empty($_SESSION[$noUpDateKey]['article_inline'])) {
      unset($_SESSION[$noUpDateKey]['article_inline']);
    }
  }
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
    header('Content-Type: application/json; charset=UTF-8');
    $makeTag['status'] = 'error';
    $makeTag['title'] = 'セッションエラー';
    $makeTag['msg'] = 'セッションが切れました。ページを再読み込みしてください。';
    echo json_encode($makeTag);
    exit;
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
#-------------#
#【段落1】タイトル
$paragraphs1Title = isset($_POST['paragraphs1Title']) ? $_POST['paragraphs1Title'] : null;
#【段落1】画像
$articleThumbnail01 = isset($_POST['articleThumbnail01']) ? $_POST['articleThumbnail01'] : null;
#【段落1】リンク設定
$paragraphs1LinkMode = isset($_POST['paragraphs1LinkMode']) ? $_POST['paragraphs1LinkMode'] : 0;
#【段落1】リンク元テキスト
$paragraphs1LinkText = isset($_POST['paragraphs1LinkText']) ? $_POST['paragraphs1LinkText'] : null;
#【段落1】リンク先URL
$paragraphs1LinkUrl = isset($_POST['paragraphs1LinkUrl']) ? $_POST['paragraphs1LinkUrl'] : null;
#【段落1】リンク先ウィンドウ
$paragraphs1LinWindow = isset($_POST['paragraphs1LinWindow']) ? $_POST['paragraphs1LinWindow'] : null;
#-------------#
#【段落2】タイトル
$paragraphs2Title = isset($_POST['paragraphs2Title']) ? $_POST['paragraphs2Title'] : null;
#【段落2】画像
$articleThumbnail02 = isset($_POST['articleThumbnail02']) ? $_POST['articleThumbnail02'] : null;
#【段落2】リンク設定
$paragraphs2LinkMode = isset($_POST['paragraphs2LinkMode']) ? $_POST['paragraphs2LinkMode'] : 0;
#【段落2】リンク元テキスト
$paragraphs2LinkText = isset($_POST['paragraphs2LinkText']) ? $_POST['paragraphs2LinkText'] : null;
#【段落2】リンク先URL
$paragraphs2LinkUrl = isset($_POST['paragraphs2LinkUrl']) ? $_POST['paragraphs2LinkUrl'] : null;
#【段落2】リンク先ウィンドウ
$paragraphs2LinWindow = isset($_POST['paragraphs2LinWindow']) ? $_POST['paragraphs2LinWindow'] : null;
#-------------#
#【段落3】タイトル
$paragraphs3Title = isset($_POST['paragraphs3Title']) ? $_POST['paragraphs3Title'] : null;
#【段落3】画像
$articleThumbnail03 = isset($_POST['articleThumbnail03']) ? $_POST['articleThumbnail03'] : null;
#【段落3】リンク設定
$paragraphs3LinkMode = isset($_POST['paragraphs3LinkMode']) ? $_POST['paragraphs3LinkMode'] : 0;
#【段落3】リンク元テキスト
$paragraphs3LinkText = isset($_POST['paragraphs3LinkText']) ? $_POST['paragraphs3LinkText'] : null;
#【段落3】リンク先URL
$paragraphs3LinkUrl = isset($_POST['paragraphs3LinkUrl']) ? $_POST['paragraphs3LinkUrl'] : null;
#【段落3】リンク先ウィンドウ
$paragraphs3LinWindow = isset($_POST['paragraphs3LinWindow']) ? $_POST['paragraphs3LinWindow'] : null;

#==============================#
# 加盟店権限チェック（shopId固定）
#------------------------------#
$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($sessionShopId === null || is_numeric($sessionShopId) === false || (int)$sessionShopId <= 0) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。再ログインしてください。';
  echo json_encode($makeTag);
  exit;
}
$sessionShopId = (int)$sessionShopId;
if ($shopId === null || $shopId === '') {
  $shopId = $sessionShopId;
}
if (is_numeric($shopId) === false || (int)$shopId !== $sessionShopId) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = '権限エラー';
  $makeTag['msg'] = '不正な操作です。ページを再読み込みしてください。';
  echo json_encode($makeTag);
  exit;
}
$shopId = $sessionShopId;
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#***** タグ生成開始 *****#
switch ($action) {
  #***** 段落サムネイル画像 一時ファイル削除 *****#
  case 'deleteArticleThumbnailTemp': {
      try {
        $paragraphNo = isset($_POST['paragraphNo']) ? (int)$_POST['paragraphNo'] : 0;
        if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
          throw new RuntimeException('段落番号が不正です。');
        }
        $fileName = isset($_POST['fileName']) ? (string)$_POST['fileName'] : '';
        $deleteResult = client020201DeleteArticleThumbnailSessionTmp($noUpDateKey, $paragraphNo);
        if ($deleteResult['invalid'] === true) {
          throw new RuntimeException('削除対象の画像パスが不正です。');
        }
        if ($deleteResult['deleted'] === false && $fileName !== '') {
          $deleteByFileNameResult = client020201DeleteArticleThumbnailTmpByFileName($noUpDateKey, $paragraphNo, $fileName);
          if ($deleteByFileNameResult['invalid'] === true) {
            throw new RuntimeException('削除対象の画像パスが不正です。');
          }
          if ($deleteByFileNameResult['deleted'] === true) {
            $deleteResult['deleted'] = true;
          }
        }
        $makeTag['status'] = 'success';
        $makeTag['paragraphNo'] = $paragraphNo;
        $makeTag['deleted'] = $deleteResult['deleted'];
        if ($deleteResult['found'] === false || $deleteResult['deleted'] === false) {
          $makeTag['msg'] = '削除対象の一時画像はありません。';
        }
        client020201JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像削除失敗';
        $makeTag['msg'] = $e->getMessage();
        client020201JsonExit($makeTag);
      }
    }
    break;
  #***** 段落サムネイル画像 一時ファイル破棄 *****#
  case 'discardArticleThumbnailTemps': {
      client020201DeleteArticleThumbnailSessionTmp($noUpDateKey);
      $makeTag['status'] = 'success';
      client020201JsonExit($makeTag);
    }
    break;
  #***** TipTap本文内画像 一時ファイル破棄 *****#
  case 'discardArticleInlineTemps': {
      client020201DeleteArticleInlineSessionTmp($noUpDateKey);
      $makeTag['status'] = 'success';
      client020201JsonExit($makeTag);
    }
    break;
  #***** TipTap本文内画像 一時ファイル削除 *****#
  case 'deleteArticleInlineTemp': {
      try {
        $paragraphNo = isset($_POST['paragraphNo']) ? (int)$_POST['paragraphNo'] : 0;
        if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
          throw new RuntimeException('段落番号が不正です。');
        }
        $fileName = isset($_POST['fileName']) ? (string)$_POST['fileName'] : '';
        if ($fileName === '' || strpos($fileName, '..') !== false || basename($fileName) !== $fileName) {
          throw new RuntimeException('削除対象の画像パスが不正です。');
        }
        $deleteResult = client020201DeleteArticleInlineTmpByFileName($noUpDateKey, $paragraphNo, $fileName);
        if ($deleteResult['invalid'] === true) {
          throw new RuntimeException('削除対象の画像パスが不正です。');
        }
        client020201UnsetArticleInlineSessionTmp($noUpDateKey, $paragraphNo, $fileName);
        $makeTag['status'] = 'success';
        $makeTag['paragraphNo'] = $paragraphNo;
        $makeTag['deleted'] = $deleteResult['deleted'];
        if ($deleteResult['deleted'] === false) {
          $makeTag['msg'] = '削除対象の一時画像はありません。';
        }
        client020201JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '画像削除失敗';
        $makeTag['msg'] = $e->getMessage();
        client020201JsonExit($makeTag);
      }
    }
    break;
  #***** 段落サムネイル画像 一時アップロード *****#
  case 'preUploadArticleThumbnail': {
      try {
        if ($method !== 'new' && $method !== 'edit') {
          throw new RuntimeException('処理方法が不正です。');
        }
        $paragraphNo = isset($_POST['paragraphNo']) ? (int)$_POST['paragraphNo'] : 0;
        if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
          throw new RuntimeException('段落番号が不正です。');
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
        $ext = client020201GetImageExtFromMime((string)$mime);
        if ($ext === null) {
          throw new RuntimeException('許可されていない画像形式です。');
        }
        $safeKey = client020201SafeKeySegment($noUpDateKey);
        $relativeDir = 'article_thumbnail/' . $safeKey . '/paragraph_' . $paragraphNo;
        $saveDir = __DIR__ . '/../../../tmp_upload/' . $relativeDir;
        if (client020201EnsureDir($saveDir) === false) {
          throw new RuntimeException('画像保存先を作成できませんでした。');
        }
        if (!isset($_SESSION[$noUpDateKey]['article_thumbnail']) || !is_array($_SESSION[$noUpDateKey]['article_thumbnail'])) {
          $_SESSION[$noUpDateKey]['article_thumbnail'] = array();
        }
        if (isset($_SESSION[$noUpDateKey]['article_thumbnail'][$paragraphNo]['tmp_name'])) {
          $oldTmp = (string)$_SESSION[$noUpDateKey]['article_thumbnail'][$paragraphNo]['tmp_name'];
          client020201DeleteArticleThumbnailTmpFile($oldTmp);
        }
        $fileName = 'thumbnail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $savePath = rtrim($saveDir, '/\\') . '/' . $fileName;
        if (move_uploaded_file($file['tmp_name'], $savePath) === false) {
          throw new RuntimeException('画像保存に失敗しました。');
        }
        $fileUrl = DEFINE_PREVIEW_IMAGE_DIR_PATH . '/' . $relativeDir . '/' . $fileName;
        $_SESSION[$noUpDateKey]['article_thumbnail'][$paragraphNo] = array(
          'kind' => 'tmp',
          'paragraph_no' => $paragraphNo,
          'tmp_name' => $savePath,
          'preview' => $fileUrl,
          'name' => $fileName,
          'original' => isset($file['name']) ? (string)$file['name'] : '',
          'type' => (string)$mime,
          'size' => $fileSize,
          'uploaded_at' => time(),
        );
        $makeTag['status'] = 'success';
        $makeTag['file_url'] = $fileUrl;
        $makeTag['file_name'] = $fileName;
        $makeTag['paragraphNo'] = $paragraphNo;
        client020201JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = $e->getMessage();
        client020201JsonExit($makeTag);
      }
    }
    break;
  #***** TipTap本文内画像 一時アップロード *****#
  case 'uploadInlineImage': {
      try {
        if ($method !== 'new' && $method !== 'edit') {
          throw new RuntimeException('処理方法が不正です。');
        }
        $paragraphNo = isset($_POST['paragraphNo']) ? (int)$_POST['paragraphNo'] : 0;
        if (in_array($paragraphNo, array(1, 2, 3), true) === false) {
          throw new RuntimeException('段落番号が不正です。');
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
        $ext = client020201GetImageExtFromMime((string)$mime);
        if ($ext === null) {
          throw new RuntimeException('許可されていない画像形式です。');
        }
        $safeKey = client020201SafeKeySegment($noUpDateKey);
        $relativeDir = 'article_inline/' . $safeKey . '/paragraph_' . $paragraphNo;
        $saveDir = __DIR__ . '/../../../tmp_upload/' . $relativeDir;
        if (client020201EnsureDir($saveDir) === false) {
          throw new RuntimeException('画像保存先を作成できませんでした。');
        }
        $fileName = 'inline_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $savePath = rtrim($saveDir, '/\\') . '/' . $fileName;
        if (move_uploaded_file($file['tmp_name'], $savePath) === false) {
          throw new RuntimeException('画像保存に失敗しました。');
        }
        if (!isset($_SESSION[$noUpDateKey]['article_inline']) || is_array($_SESSION[$noUpDateKey]['article_inline']) === false) {
          $_SESSION[$noUpDateKey]['article_inline'] = array();
        }
        if (!isset($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]) || is_array($_SESSION[$noUpDateKey]['article_inline'][$paragraphNo]) === false) {
          $_SESSION[$noUpDateKey]['article_inline'][$paragraphNo] = array();
        }
        $makeTag['status'] = 'success';
        $makeTag['url'] = DEFINE_PREVIEW_IMAGE_DIR_PATH . '/' . $relativeDir . '/' . $fileName;
        $_SESSION[$noUpDateKey]['article_inline'][$paragraphNo][] = array(
          'kind' => 'tmp',
          'paragraph_no' => $paragraphNo,
          'tmp_name' => $savePath,
          'preview' => $makeTag['url'],
          'name' => $fileName,
          'type' => (string)$mime,
          'size' => $fileSize,
          'uploaded_at' => time(),
        );
        client020201JsonExit($makeTag);
      } catch (Throwable $e) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'アップロード失敗';
        $makeTag['msg'] = $e->getMessage();
        client020201JsonExit($makeTag);
      }
    }
    break;
  #***** 登録 *****#
  case 'sendInput': {
      $copiedArticleImageFiles = array();
      $tmpArticleImageFilesToDelete = array();
      $savedArticleImageFilesToDelete = array();
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
        $paragraphs = client020201BuildParagraphPostData();
        if ($paragraphs[1]['title'] === '') {
          $validationErrors[] = '段落1タイトルは必須です。';
        }
        if (client020201PlainTextFromHtml($paragraphs[1]['body_text']) === '') {
          $validationErrors[] = '段落1本文は必須です。';
        }
        $deleteSavedThumbnailMap = client020201BuildDeleteSavedThumbnailMap();
        $articleId = isset($_POST['articleId']) ? (int)$_POST['articleId'] : 0;
        if ($method === 'edit' && $articleId < 1) {
          $validationErrors[] = '記事IDが不正です。';
        }
        if (!empty($validationErrors)) {
          $makeTag['status'] = 'error';
          $makeTag['title'] = '入力エラー';
          $makeTag['msg'] = implode("\n", $validationErrors);
          client020201JsonExit($makeTag);
        }
        $existingArticle = false;
        if ($method === 'edit') {
          $existingArticle = getShopArticleData_FindById($shopId, $articleId);
          if ($existingArticle === false) {
            $makeTag['status'] = 'error';
            $makeTag['title'] = '入力エラー';
            $makeTag['msg'] = '対象の記事が見つかりません。';
            client020201JsonExit($makeTag);
          }
        }
        $oldArticleParagraphs = array();
        if ($method === 'edit') {
          $oldArticleParagraphs = getShopArticleImages($shopId, $articleId);
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
          if (client020201ShiftArticleDisplayOrderForInsert($shopId, $articleDisplayOrder) === false) {
            throw new RuntimeException('表示順の更新に失敗しました。');
          }
          $articleId = client020201InsertArticle($shopId, array(
            'status' => $articleStatus,
            'title' => $articleTitle,
            'display_order' => $articleDisplayOrder,
          ));
          if ($articleId === false || (int)$articleId < 1) {
            throw new RuntimeException('記事の登録に失敗しました。');
          }
        } else {
          $oldOrder = isset($existingArticle['display_order']) ? (int)$existingArticle['display_order'] : $articleDisplayOrder;
          if ($oldOrder !== $articleDisplayOrder && client020201ShiftArticleDisplayOrderForMove($shopId, $articleId, $oldOrder, $articleDisplayOrder) === false) {
            throw new RuntimeException('表示順の更新に失敗しました。');
          }
          if (client020201UpdateArticle($shopId, $articleId, array(
            'status' => $articleStatus,
            'title' => $articleTitle,
            'display_order' => $articleDisplayOrder,
          )) === false) {
            throw new RuntimeException('記事の更新に失敗しました。');
          }
        }
        if ($method === 'edit') {
          client020201ApplySavedThumbnailDeleteRequests($deleteSavedThumbnailMap, $oldArticleParagraphs, $noUpDateKey, $shopId, $articleId, $paragraphs, $savedArticleImageFilesToDelete);
        }
        client020201FinalizeArticleThumbnailImages($noUpDateKey, $shopId, $articleId, $paragraphs, $copiedArticleImageFiles, $tmpArticleImageFilesToDelete);
        client020201FinalizeArticleInlineImages($noUpDateKey, $shopId, $articleId, $paragraphs, $copiedArticleImageFiles, $tmpArticleImageFilesToDelete);
        if ($method === 'edit') {
          $savedInlineImageFilesToDelete = client020201BuildDeletedInlineImageTargets($oldArticleParagraphs, $paragraphs);
        }
        for ($paragraphNo = 1; $paragraphNo <= 3; $paragraphNo++) {
          if (client020201UpsertArticleParagraph($articleId, $paragraphNo, $paragraphs[$paragraphNo]) === false) {
            throw new RuntimeException('段落情報の保存に失敗しました。');
          }
        }
        DB_Transaction(2);
        client020201CleanupArticleImageTempsAfterCommit($noUpDateKey, $tmpArticleImageFilesToDelete);
        client020201DeleteSavedArticleImageFilesAfterCommit($savedArticleImageFilesToDelete, $shopId, $articleId);
        client020201DeleteSavedInlineImageFilesAfterCommit($savedInlineImageFilesToDelete, $shopId, $articleId);
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
          'pageName' => 'proc_client02_02_01',
          'reason' => '自由記事保存失敗',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = $e->getMessage();
      }
    }
    break;
}
#-------------------------------------------#
#json 応答
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
