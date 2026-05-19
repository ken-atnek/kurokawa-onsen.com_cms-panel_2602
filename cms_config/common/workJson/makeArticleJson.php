<?php
require_once __DIR__ . '/jsonExportCommon.php';
require_once __DIR__ . '/makeShopJson.php';
require_once __DIR__ . '/../../common/define.php';
require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../common/set_contents.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shop_articles.php';

/*
 * [記事JSON] 記事詳細JSONディレクトリパス生成
 */
function buildArticleDetailJsonDir($shopId, $articleId)
{
	return rtrim(DEFINE_JSON_DIR_PATH, '/\\') . '/shops/articles/' . sprintf('%03d', (int)$shopId) . '/' . (int)$articleId;
}

/*
 * [記事JSON] 記事詳細JSONファイル書き出し（active / inactive 共通）
 */
function writeArticleJsonFile($shopId, $articleId, array $writeData): bool
{
	$dir = buildArticleDetailJsonDir($shopId, $articleId);
	if (!is_dir($dir) && mkdir($dir, 0777, true) === false) {
		return false;
	}
	$json = json_encode($writeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		return false;
	}
	$filePath = $dir . '/article.json';
	if (file_put_contents($filePath, $json, LOCK_EX) === false) {
		return false;
	}
	@chmod($filePath, octdec('0666'));
	return true;
}

/*
 * [記事JSON] サムネイル画像パス変換
 *  image_storage_path を /db/images/... 形式に変換する。空なら null。
 */
function buildArticleJsonThumbnailPath($storagePath)
{
	$storagePath = trim((string)$storagePath);
	if ($storagePath === '') {
		return null;
	}
	return '/db/images/' . ltrim($storagePath, '/');
}

/*
 * [記事JSON] link_target DB値 → JSON値変換
 *  DB値 2 → '_blank'、それ以外 → '_self'
 */
function buildArticleJsonLinkTarget($linkTarget)
{
	return ((int)$linkTarget === 2) ? '_blank' : '_self';
}

/*
 * [記事JSON] 定型タイプ段落配列生成
 *  paragraphs を 1〜3 固定で出力する。DBに存在しない段落は null 含む空構造で出力する。
 */
function buildArticleJsonParagraphs($shopId, $articleId)
{
	$paragraphMap = getShopArticleImages($shopId, $articleId);
	$paragraphs = [];
	for ($i = 1; $i <= 3; $i++) {
		$row = isset($paragraphMap[$i]) ? $paragraphMap[$i] : null;
		if ($row === null) {
			$paragraphs[] = [
				'paragraphNo' => $i,
				'title'       => null,
				'thumbnail'   => null,
				'bodyHtml'    => null,
				'link'        => [
					'enabled' => false,
					'text'    => null,
					'url'     => null,
					'target'  => null,
				],
			];
			continue;
		}
		$title      = trim((string)($row['title'] ?? ''));
		$bodyText   = trim((string)($row['body_text'] ?? ''));
		$linkEnabled = (int)($row['link_enabled'] ?? 0) === 1;
		$linkText   = trim((string)($row['link_text'] ?? ''));
		$linkUrl    = trim((string)($row['link_url'] ?? ''));
		$paragraphs[] = [
			'paragraphNo' => $i,
			'title'       => ($title !== '') ? $title : null,
			'thumbnail'   => buildArticleJsonThumbnailPath($row['image_storage_path'] ?? ''),
			'bodyHtml'    => ($bodyText !== '') ? $bodyText : null,
			'link'        => [
				'enabled' => $linkEnabled,
				'text'    => ($linkEnabled && $linkText !== '') ? $linkText : null,
				'url'     => ($linkEnabled && $linkUrl !== '') ? $linkUrl : null,
				'target'  => $linkEnabled ? buildArticleJsonLinkTarget($row['link_target'] ?? 1) : null,
			],
		];
	}
	return $paragraphs;
}

/*
 * [記事JSON] 記事詳細JSON active 生成
 *  公開中記事の詳細JSONをファイルへ書き出す。
 *  article_type=1: paragraphs 1〜3固定出力
 *  article_type=2: bodyHtml 出力
 */
function generateArticleDetailJson($shopId, $articleId): bool
{
	$shopId    = (int)$shopId;
	$articleId = (int)$articleId;
	if ($shopId < 1 || $articleId < 1) {
		return false;
	}
	$row = getPublicShopArticleDetailForJson($shopId, $articleId);
	if ($row === false) {
		return false;
	}
	$articleType = (int)$row['article_type'];
	$writeData = [
		'status'      => 'active',
		'articleId'   => (int)$row['article_id'],
		'shopId'      => (int)$row['shop_id'],
		'articleType' => $articleType,
		'title'       => (string)$row['title'],
	];
	if ($articleType === 1) {
		$writeData['paragraphs'] = buildArticleJsonParagraphs($shopId, $articleId);
	} elseif ($articleType === 2) {
		$bodyHtml = trim((string)($row['body_html'] ?? ''));
		$writeData['bodyHtml'] = ($bodyHtml !== '') ? $bodyHtml : null;
	} else {
		return false;
	}
	return writeArticleJsonFile($shopId, $articleId, $writeData);
}

/*
 * [記事JSON] 記事詳細JSON inactive 書き出し
 *  非公開・削除時に inactive JSON でファイルを上書きする。
 *  DB参照不要。引数のみで書き出す。
 */
function writeInactiveArticleJson($shopId, $articleId): bool
{
	$shopId    = (int)$shopId;
	$articleId = (int)$articleId;
	if ($shopId < 1 || $articleId < 1) {
		return false;
	}
	$writeData = [
		'status'    => 'inactive',
		'articleId' => $articleId,
		'shopId'    => $shopId,
	];
	return writeArticleJsonFile($shopId, $articleId, $writeData);
}

/*
 * [記事JSON] 記事詳細JSON・店舗詳細JSON同期書き出し
 *  記事登録・編集・公開切替・削除の各procから呼ぶ共通sync関数。
 *  公開中記事なら active 詳細JSON、非公開・削除済みなら inactive JSON を書き出す。
 *  どちらの場合も店舗詳細JSONを更新する。失敗時は warning + makeLog。
 */
function syncFrontendArticleJson(&$makeTag, $shopId, $articleId): bool
{
	$publicArticle = getPublicShopArticleDetailForJson($shopId, $articleId);
	if ($publicArticle !== false) {
		$okArticle = generateArticleDetailJson($shopId, $articleId);
	} else {
		$okArticle = writeInactiveArticleJson($shopId, $articleId);
	}
	$okShop = generateShopJson($shopId);
	if ($okArticle !== true || $okShop !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		logFrontendJsonError('article_json_export_failed', $shopId, $articleId, [
			'article_json'   => $okArticle,
			'shop_json'      => $okShop,
			'public_article' => ($publicArticle !== false),
		]);
		return false;
	}
	return true;
}

/*
 * [記事JSON] 店舗詳細JSON articles 更新
 *  表示順変更時など、店舗詳細JSONの articles 順序だけ更新する。
 *  記事詳細JSONは更新しない。失敗時は warning + makeLog。
 */
function syncFrontendShopArticleJsons(&$makeTag, $shopId): bool
{
	$okShop = generateShopJson($shopId);
	if ($okShop !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		logFrontendJsonError('shop_article_json_export_failed', $shopId, null, [
			'shop_json' => $okShop,
		]);
		return false;
	}
	return true;
}
