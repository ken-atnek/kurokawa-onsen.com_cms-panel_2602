<?php
/*
 * [自由ページ記事詳細取得 (定型タイプ)]
 *  引数
 *   $shopId   ：店舗ID
 *   $articleId：記事ID
 */
function getShopArticleData_FindById($shopId = null, $articleId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				shop_id,
				article_type,
				status,
				title,
				display_order,
				body_html,
				is_active,
				created_at,
				updated_at,
				deleted_at
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND article_id = :article_id
				AND article_type = 1
				AND is_active = 1
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ?: false;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [自由ページ記事画像取得 (定型タイプ)]
 *  引数
 *   $shopId   ：店舗ID
 *   $articleId：記事ID
 */
function getShopArticleImages($shopId = null, $articleId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return [];
	}
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return [];
	}
	try {
		$strSQL = "
			SELECT
				p.paragraph_id,
				p.article_id,
				p.paragraph_no,
				p.title,
				p.body_text,
				p.image_storage_path,
				p.link_enabled,
				p.link_text,
				p.link_url,
				p.link_target,
				p.created_at,
				p.updated_at
			FROM
				shop_article_paragraphs AS p
				INNER JOIN shop_articles AS a
					ON a.article_id = p.article_id
			WHERE
				a.shop_id = :shop_id
				AND a.article_id = :article_id
				AND a.article_type = 1
				AND a.is_active = 1
			ORDER BY
				p.paragraph_no ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		$paragraphs = [];
		foreach ($rows ?: [] as $row) {
			$paragraphNo = isset($row['paragraph_no']) ? (int)$row['paragraph_no'] : 0;
			if ($paragraphNo < 1) {
				continue;
			}
			$paragraphs[$paragraphNo] = $row;
		}
		return $paragraphs;
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [自由記事操作対象データ取得]
 *  引数
 *   $shopId   ：店舗ID
 *   $articleId：記事ID
 */
function getShopArticleData_FindActiveById($shopId = null, $articleId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				shop_id,
				display_order,
				is_active
			FROM
				shop_articles
			WHERE
				article_id = :article_id
				AND shop_id = :shop_id
				AND is_active = 1
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ?: false;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [自由ページ記事詳細取得 (HTMLタイプ)]
 *  引数
 *   $shopId   ：店舗ID
 *   $articleId：記事ID
 */
function getShopArticleHtmlData_FindById($shopId = null, $articleId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				shop_id,
				article_type,
				status,
				title,
				display_order,
				body_html,
				is_active,
				created_at,
				updated_at,
				deleted_at
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND article_id = :article_id
				AND article_type = 2
				AND is_active = 1
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ?: false;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [再採番対象記事一覧取得]
 *  引数
 *   $shopId：店舗ID
 */
function getShopArticleDisplayOrderRows($shopId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return [];
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				display_order
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND is_active = 1
			ORDER BY
				display_order ASC,
				article_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [自由記事一覧件数取得]
 *  引数
 *   $searchConditions：検索条件
 *   $fixedShopId     ：店舗ID
 */
function countShopArticles($searchConditions = [], $fixedShopId = null)
{
	global $DB_CONNECT;
	if ($fixedShopId === null || is_numeric($fixedShopId) === false || (int)$fixedShopId < 1) {
		return 0;
	}
	try {
		$where = [
			'a.shop_id = :shop_id',
			'a.is_active = 1',
		];
		$params = [
			':shop_id' => [(int)$fixedShopId, PDO::PARAM_INT],
		];
		$searchStatus = isset($searchConditions['searchStatus']) ? trim((string)$searchConditions['searchStatus']) : '';
		if (in_array($searchStatus, ['0', '1'], true)) {
			$where[] = 'a.status = :status';
			$params[':status'] = [(int)$searchStatus, PDO::PARAM_INT];
		}
		$searchTitle = isset($searchConditions['searchTitle']) ? trim((string)$searchConditions['searchTitle']) : '';
		if ($searchTitle !== '') {
			$searchTitleLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTitle) . '%';
			$where[] = "
				(
					a.title LIKE :search_article_title ESCAPE '\\\\'
					OR a.body_html LIKE :search_body_html ESCAPE '\\\\'
					OR EXISTS (
						SELECT
							1
						FROM
							shop_article_paragraphs AS p
						WHERE
							p.article_id = a.article_id
							AND (
								p.title LIKE :search_paragraph_title ESCAPE '\\\\'
								OR p.body_text LIKE :search_paragraph_body ESCAPE '\\\\'
							)
					)
				)
			";
			$params[':search_article_title'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_body_html'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_paragraph_title'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_paragraph_body'] = [$searchTitleLike, PDO::PARAM_STR];
		}
		$strSQL = "
			SELECT
				COUNT(DISTINCT a.article_id) AS cnt
			FROM
				shop_articles AS a
			WHERE
				" . implode("\n				AND ", $where) . "
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($params as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return isset($row['cnt']) ? (int)$row['cnt'] : 0;
	} catch (PDOException $e) {
		return 0;
	}
}
/*
 * [自由記事一覧検索]
 *   $searchConditions：検索条件配列
 *   $pageNumber      ：ページ番号
 *   $displayNumber   ：表示件数
 *   $fixedShopId     ：店舗ID
 */
function searchShopArticles($searchConditions = [], $pageNumber = 1, $displayNumber = 10, $fixedShopId = null)
{
	global $DB_CONNECT, $displayNumberList, $initialDisplayNumber;
	if ($fixedShopId === null || is_numeric($fixedShopId) === false || (int)$fixedShopId < 1) {
		return [];
	}
	try {
		$pageNumber = is_numeric($pageNumber) ? (int)$pageNumber : 1;
		if ($pageNumber < 1) {
			$pageNumber = 1;
		}
		$allowedDisplayNumbers = (
			isset($displayNumberList)
			&& is_array($displayNumberList)
			&& !empty($displayNumberList)
		) ? array_map('intval', $displayNumberList) : [10, 20, 30, 50, 100];
		$defaultDisplayNumber = (
			isset($initialDisplayNumber)
			&& is_numeric($initialDisplayNumber)
			&& in_array((int)$initialDisplayNumber, $allowedDisplayNumbers, true)
		) ? (int)$initialDisplayNumber : $allowedDisplayNumbers[0];
		$displayNumber = is_numeric($displayNumber) ? (int)$displayNumber : $defaultDisplayNumber;
		if (in_array($displayNumber, $allowedDisplayNumbers, true) === false) {
			$displayNumber = $defaultDisplayNumber;
		}
		$offset = ($pageNumber - 1) * $displayNumber;
		$where = [
			'a.shop_id = :shop_id',
			'a.is_active = 1',
		];
		$params = [
			':shop_id' => [(int)$fixedShopId, PDO::PARAM_INT],
		];
		$searchStatus = isset($searchConditions['searchStatus']) ? trim((string)$searchConditions['searchStatus']) : '';
		if (in_array($searchStatus, ['0', '1'], true)) {
			$where[] = 'a.status = :status';
			$params[':status'] = [(int)$searchStatus, PDO::PARAM_INT];
		}
		$searchTitle = isset($searchConditions['searchTitle']) ? trim((string)$searchConditions['searchTitle']) : '';
		if ($searchTitle !== '') {
			$searchTitleLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTitle) . '%';
			$where[] = "
				(
					a.title LIKE :search_article_title ESCAPE '\\\\'
					OR a.body_html LIKE :search_body_html ESCAPE '\\\\'
					OR EXISTS (
						SELECT
							1
						FROM
							shop_article_paragraphs AS p
						WHERE
							p.article_id = a.article_id
							AND (
								p.title LIKE :search_paragraph_title ESCAPE '\\\\'
								OR p.body_text LIKE :search_paragraph_body ESCAPE '\\\\'
							)
					)
				)
			";
			$params[':search_article_title'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_body_html'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_paragraph_title'] = [$searchTitleLike, PDO::PARAM_STR];
			$params[':search_paragraph_body'] = [$searchTitleLike, PDO::PARAM_STR];
		}
		$strSQL = "
			SELECT
				a.article_id,
				a.shop_id,
				a.article_type,
				a.status,
				a.title,
				a.display_order,
				a.is_active,
				a.created_at,
				a.updated_at
			FROM
				shop_articles AS a
			WHERE
				" . implode("\n				AND ", $where) . "
			ORDER BY
				a.article_id ASC
			LIMIT :limit
			OFFSET :offset
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($params as $key => $param) {
			$newStmt->bindValue($key, $param[0], $param[1]);
		}
		$newStmt->bindValue(':limit', $displayNumber, PDO::PARAM_INT);
		$newStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [記事段落の存在確認]
 *  引数
 *   $articleId  ：記事ID
 *   $paragraphNo：段落番号
 */
function getShopArticleParagraphExists($articleId = null, $paragraphNo = null)
{
	global $DB_CONNECT;
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return false;
	}
	if ($paragraphNo === null || is_numeric($paragraphNo) === false || (int)$paragraphNo < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				paragraph_id
			FROM
				shop_article_paragraphs
			WHERE
				article_id = :article_id
				AND paragraph_no = :paragraph_no
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->bindValue(':paragraph_no', (int)$paragraphNo, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ? true : false;
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [表示順変更ページ用記事一覧取得]
 *  引数
 *   $shopId：店舗ID
 */
function getShopArticleRowsForDisplayOrder($shopId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return [];
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				article_type,
				status,
				title,
				display_order
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND is_active = 1
			ORDER BY
				display_order ASC,
				article_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [フロントJSON用 公開記事一覧取得]
 *  店舗ページ用JSON articles に出力する公開記事一覧を取得する
 *  引数
 *   $shopId：店舗ID
 */
function getPublicShopArticlesForJson($shopId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return [];
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				article_type,
				title,
				display_order
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND status = 1
				AND is_active = 1
				AND article_type IN (1, 2)
			ORDER BY
				display_order ASC,
				article_id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return [];
	}
}
/*
 * [フロントJSON用 公開記事詳細取得]
 *  記事詳細JSON active 出力用に公開中記事の本体データを取得する
 *  引数
 *   $shopId   ：店舗ID
 *   $articleId：記事ID
 */
function getPublicShopArticleDetailForJson($shopId = null, $articleId = null)
{
	global $DB_CONNECT;
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	if ($articleId === null || is_numeric($articleId) === false || (int)$articleId < 1) {
		return false;
	}
	try {
		$strSQL = "
			SELECT
				article_id,
				shop_id,
				article_type,
				status,
				title,
				body_html,
				display_order,
				is_active,
				created_at,
				updated_at,
				deleted_at
			FROM
				shop_articles
			WHERE
				shop_id = :shop_id
				AND article_id = :article_id
				AND status = 1
				AND is_active = 1
				AND article_type IN (1, 2)
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':article_id', (int)$articleId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $row ?: false;
	} catch (PDOException $e) {
		return false;
	}
}
