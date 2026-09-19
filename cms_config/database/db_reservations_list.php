<?php
/*
 * [予約一覧・カレンダー参照]
 */

/**
 * 予約参照用日付文字列確認
 *  Y-m-d形式の実在日だけを有効とする
 */
function isReservationReadDbDateString($date)
{
	if (is_string($date) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1) {
		return false;
	}
	[$year, $monthNumber, $day] = array_map('intval', explode('-', $date));
	if (checkdate($monthNumber, $day, $year) === false) {
		return false;
	}
	$dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
	return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d') === $date;
}

/**
 * 予約参照用日付範囲確認
 *  開始日から終了日までの昇順範囲だけを有効とする
 */
function isReservationReadDbDateRange($startDate, $endDate)
{
	return isReservationReadDbDateString($startDate) === true
		&& isReservationReadDbDateString($endDate) === true
		&& $startDate <= $endDate;
}

/**
 * 予約本体・予約席snapshotの範囲一覧取得
 *  LEFT JOINで席未割当の予約も含め、status 1～4を単一SELECTで返す
 */
function getReservationWithSeatsReadRowsByDateRange($shopId = null, $startDate = null, $endDate = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationReadDbDateRange($startDate, $endDate) === false) {
			return false;
		}

		$strSQL = "
			SELECT
				r.id AS reservation_id,
				r.reservation_date,
				r.party_size,
				r.customer_last_name,
				r.customer_first_name,
				r.customer_tel,
				r.reservation_route,
				r.status,
				rs.id AS reservation_seat_id,
				rs.seat_id,
				rs.seat_name_snapshot,
				CASE
					WHEN rs.id IS NULL THEN NULL
					ELSE COALESCE(s.is_temp_move, 0)
				END AS seat_is_temp_move
			FROM
				reservations r
				LEFT JOIN reservation_seats rs ON r.id = rs.reservation_id
				LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
			WHERE
				r.shop_id = :shop_id
				AND r.reservation_date BETWEEN :start_date AND :end_date
				AND r.status IN (1, 2, 3, 4)
			ORDER BY
				r.reservation_date ASC,
				r.id ASC,
				rs.id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
		$newStmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $rows ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約menu snapshotの範囲一覧取得
 *  対象予約のメニューをguest_no順で返す
 */
function getReservationMenuReadRowsByDateRange($shopId = null, $startDate = null, $endDate = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationReadDbDateRange($startDate, $endDate) === false) {
			return false;
		}

		$strSQL = "
			SELECT
				r.id AS reservation_id,
				r.reservation_date,
				rm.id AS reservation_menu_id,
				rm.guest_no,
				rm.menu_name_snapshot,
				rm.menu_price_snapshot,
				rm.tax_included_snapshot
			FROM
				reservations r
				INNER JOIN reservation_menus rm ON r.id = rm.reservation_id
			WHERE
				r.shop_id = :shop_id
				AND r.reservation_date BETWEEN :start_date AND :end_date
				AND r.status IN (1, 2, 3, 4)
			ORDER BY
				r.reservation_date ASC,
				r.id ASC,
				rm.guest_no ASC,
				rm.id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
		$newStmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $rows ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約カレンダー上書きの範囲一覧取得
 *  対象店舗・日付範囲のoverrideを日付順で返す
 */
function getReservationCalendarReadRowsByDateRange($shopId = null, $startDate = null, $endDate = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationReadDbDateRange($startDate, $endDate) === false) {
			return false;
		}

		$strSQL = "
			SELECT
				target_date,
				status_type
			FROM
				reservation_calendar_overrides
			WHERE
				shop_id = :shop_id
				AND target_date BETWEEN :start_date AND :end_date
			ORDER BY
				target_date ASC,
				id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
		$newStmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $rows ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約一覧literal LIKE文字列生成
 *  LIKEの特殊文字をescapeし、入力値を部分一致用patternへ変換する
 */
function makeReservationListLiteralLikePattern($value)
{
	if (is_string($value) === false) {
		return false;
	}
	return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}

/**
 * 予約一覧検索WHERE生成
 *  COUNTとLISTで共有する店舗・検索条件とbind値を生成する
 */
function buildReservationListSearchWhere($shopId = null, $searchConditions = null)
{
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($searchConditions) === false) {
		return false;
	}

	$defaultConditions = [
		'visit_start_day' => '',
		'visit_end_day' => '',
		'reception_start_day' => '',
		'reception_end_day' => '',
		'customer_name' => '',
		'customer_tel' => '',
		'reservation_route' => null,
		'reservation_status' => null,
	];
	$conditions = array_merge($defaultConditions, $searchConditions);

	foreach (['visit_start_day', 'visit_end_day', 'reception_start_day', 'reception_end_day', 'customer_name', 'customer_tel'] as $key) {
		if (is_string($conditions[$key]) === false) {
			return false;
		}
	}
	if (($conditions['visit_start_day'] !== '' && isReservationReadDbDateString($conditions['visit_start_day']) === false)
		|| ($conditions['visit_end_day'] !== '' && isReservationReadDbDateString($conditions['visit_end_day']) === false)
		|| ($conditions['reception_start_day'] !== '' && isReservationReadDbDateString($conditions['reception_start_day']) === false)
		|| ($conditions['reception_end_day'] !== '' && isReservationReadDbDateString($conditions['reception_end_day']) === false)) {
		return false;
	}
	if (($conditions['visit_start_day'] !== '' && $conditions['visit_end_day'] !== '' && $conditions['visit_start_day'] > $conditions['visit_end_day'])
		|| ($conditions['reception_start_day'] !== '' && $conditions['reception_end_day'] !== '' && $conditions['reception_start_day'] > $conditions['reception_end_day'])) {
		return false;
	}
	if (mb_strlen($conditions['customer_name'], 'UTF-8') > 100 || mb_strlen($conditions['customer_tel'], 'UTF-8') > 20) {
		return false;
	}
	if (($conditions['reservation_route'] !== null && in_array($conditions['reservation_route'], [1, 2, 3], true) === false)
		|| ($conditions['reservation_status'] !== null && in_array($conditions['reservation_status'], [1, 2, 3, 4], true) === false)) {
		return false;
	}

	$where = [
		'r.shop_id = :shop_id',
		'r.status IN (1, 2, 3, 4)',
	];
	$params = [
		':shop_id' => [(int)$shopId, PDO::PARAM_INT],
	];

	if ($conditions['visit_start_day'] !== '') {
		$where[] = 'r.reservation_date >= :visit_start_day';
		$params[':visit_start_day'] = [$conditions['visit_start_day'], PDO::PARAM_STR];
	}
	if ($conditions['visit_end_day'] !== '') {
		$where[] = 'r.reservation_date <= :visit_end_day';
		$params[':visit_end_day'] = [$conditions['visit_end_day'], PDO::PARAM_STR];
	}
	if ($conditions['reception_start_day'] !== '') {
		$where[] = 'r.created_at >= :reception_start_at';
		$params[':reception_start_at'] = [$conditions['reception_start_day'] . ' 00:00:00', PDO::PARAM_STR];
	}
	if ($conditions['reception_end_day'] !== '') {
		$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $conditions['reception_end_day'], new DateTimeZone('Asia/Tokyo'));
		if ($endDate instanceof DateTimeImmutable === false) {
			return false;
		}
		$where[] = 'r.created_at < :reception_end_before';
		$params[':reception_end_before'] = [$endDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00', PDO::PARAM_STR];
	}
	if ($conditions['customer_name'] !== '') {
		$customerNameLike = makeReservationListLiteralLikePattern($conditions['customer_name']);
		if ($customerNameLike === false) {
			return false;
		}
		$where[] = "
			(
				r.customer_last_name LIKE :customer_last_name ESCAPE '\\\\'
				OR r.customer_first_name LIKE :customer_first_name ESCAPE '\\\\'
				OR r.customer_last_kana LIKE :customer_last_kana ESCAPE '\\\\'
				OR r.customer_first_kana LIKE :customer_first_kana ESCAPE '\\\\'
				OR CONCAT(r.customer_last_name, r.customer_first_name) LIKE :customer_full_name ESCAPE '\\\\'
				OR CONCAT(r.customer_last_kana, r.customer_first_kana) LIKE :customer_full_kana ESCAPE '\\\\'
			)
		";
		foreach ([':customer_last_name', ':customer_first_name', ':customer_last_kana', ':customer_first_kana', ':customer_full_name', ':customer_full_kana'] as $parameterName) {
			$params[$parameterName] = [$customerNameLike, PDO::PARAM_STR];
		}
	}
	if ($conditions['customer_tel'] !== '') {
		$customerTelLike = makeReservationListLiteralLikePattern($conditions['customer_tel']);
		if ($customerTelLike === false) {
			return false;
		}
		$where[] = "r.customer_tel LIKE :customer_tel ESCAPE '\\\\'";
		$params[':customer_tel'] = [$customerTelLike, PDO::PARAM_STR];
	}
	if ($conditions['reservation_route'] !== null) {
		$where[] = 'r.reservation_route = :reservation_route';
		$params[':reservation_route'] = [$conditions['reservation_route'], PDO::PARAM_INT];
	}
	if ($conditions['reservation_status'] !== null) {
		$where[] = 'r.status = :reservation_status';
		$params[':reservation_status'] = [$conditions['reservation_status'], PDO::PARAM_INT];
	}

	return [
		'where_sql' => implode("\n\t\t\t\tAND ", $where),
		'params' => $params,
	];
}

/**
 * 予約一覧検索bind実行
 *  WHERE生成済みの値を型付きでPDO statementへbindする
 */
function bindReservationListSearchParams($statement, $params)
{
	if ($statement instanceof PDOStatement === false || is_array($params) === false) {
		return false;
	}
	foreach ($params as $parameterName => $parameterData) {
		if (is_array($parameterData) === false || count($parameterData) !== 2) {
			return false;
		}
		$statement->bindValue($parameterName, $parameterData[0], $parameterData[1]);
	}
	return true;
}

/**
 * 予約一覧件数取得
 *  対象店舗と検索条件に一致する予約件数を0件とDB失敗を分けて返す
 */
function countReservationListRows($shopId = null, $searchConditions = null)
{
	global $DB_CONNECT;
	try {
		$sqlParts = buildReservationListSearchWhere($shopId, $searchConditions);
		if ($sqlParts === false) {
			return false;
		}
		$strSQL = "
			SELECT
				COUNT(*)
			FROM
				reservations r
			WHERE
				{$sqlParts['where_sql']}
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		if (bindReservationListSearchParams($newStmt, $sqlParts['params']) === false) {
			return false;
		}
		$newStmt->execute();
		$count = $newStmt->fetchColumn();
		$newStmt->closeCursor();
		return is_numeric($count) === true ? (int)$count : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約一覧取得
 *  1ページ10件固定で予約本体だけを来店日・IDの降順に返す
 */
function searchReservationListRows($shopId = null, $searchConditions = null, $pageNumber = 1)
{
	global $DB_CONNECT;
	try {
		if (is_int($pageNumber) === false || $pageNumber < 1) {
			return false;
		}
		$sqlParts = buildReservationListSearchWhere($shopId, $searchConditions);
		if ($sqlParts === false) {
			return false;
		}
		$pageSize = 10;
		$offset = ($pageNumber - 1) * $pageSize;
		$strSQL = "
			SELECT
				r.id AS reservation_id,
				r.reservation_date,
				r.party_size,
				r.customer_last_name,
				r.customer_first_name,
				r.customer_tel,
				r.reservation_route,
				r.status,
				r.created_at
			FROM
				reservations r
			WHERE
				{$sqlParts['where_sql']}
			ORDER BY
				r.reservation_date DESC,
				r.id DESC
			LIMIT :reservation_list_limit
			OFFSET :reservation_list_offset
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		if (bindReservationListSearchParams($newStmt, $sqlParts['params']) === false) {
			return false;
		}
		$newStmt->bindValue(':reservation_list_limit', $pageSize, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_list_offset', $offset, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約一覧menu snapshot取得
 *  表示中の最大10予約だけを対象に店舗ownership付きでguest順へ返す
 */
function getReservationListMenuRowsByReservationIds($shopId = null, $reservationIds = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($reservationIds) === false || array_is_list($reservationIds) === false || count($reservationIds) > 10) {
			return false;
		}
		if (empty($reservationIds) === true) {
			return [];
		}

		$normalizedIds = [];
		foreach ($reservationIds as $reservationId) {
			if ((is_int($reservationId) === false && (is_string($reservationId) === false || ctype_digit($reservationId) === false)) || (int)$reservationId < 1) {
				return false;
			}
			$normalizedId = (int)$reservationId;
			if (isset($normalizedIds[$normalizedId])) {
				return false;
			}
			$normalizedIds[$normalizedId] = $normalizedId;
		}

		$placeholders = [];
		$params = [':shop_id' => [(int)$shopId, PDO::PARAM_INT]];
		foreach (array_values($normalizedIds) as $index => $reservationId) {
			$parameterName = ':reservation_id_' . $index;
			$placeholders[] = $parameterName;
			$params[$parameterName] = [$reservationId, PDO::PARAM_INT];
		}
		$strSQL = "
			SELECT
				rm.reservation_id,
				rm.id AS reservation_menu_id,
				rm.guest_no,
				rm.menu_name_snapshot,
				rm.menu_price_snapshot,
				rm.tax_included_snapshot
			FROM
				reservation_menus rm
				INNER JOIN reservations r ON rm.reservation_id = r.id
			WHERE
				r.shop_id = :shop_id
				AND rm.reservation_id IN (" . implode(', ', $placeholders) . ")
			ORDER BY
				rm.reservation_id ASC,
				rm.guest_no ASC,
				rm.id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		if (bindReservationListSearchParams($newStmt, $params) === false) {
			return false;
		}
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows ?: [];
	} catch (PDOException $e) {
		return false;
	}
}
