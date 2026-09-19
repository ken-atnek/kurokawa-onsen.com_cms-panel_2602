<?php
/*
 * [座席情報設定]
 */
/**
 * 予約占有判定用の席一覧取得
 *  無効counterも物理連続判定に必要なため取得対象に含める
 */
function getSeatsForReservationOccupancy($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return [];
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				name,
				type,
				capacity,
				counter_area,
				sort_order,
				is_temp_move,
				is_active
			FROM
				seats
			WHERE
				shop_id = :shop_id
			ORDER BY
				sort_order ASC,
				id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$seats = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $seats ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約受付判定用の有効通常席件数取得
 *  temp move用ダミー席は通常席から除外する
 */
function countActiveNormalSeatsForReservation($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return 0;
		}

		$strSQL = "
			SELECT
				COUNT(*) AS cnt
			FROM
				seats
			WHERE
				shop_id = :shop_id
				AND is_temp_move = 0
				AND is_active = 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return (int)($row['cnt'] ?? 0);
	} catch (PDOException $e) {
		return 0;
	}
}

/**
 * 予約基本設定警告用の上限定員超過席取得
 *  temp move席を除く通常席から指定定員を超える席だけを返す
 */
function getNormalSeatsExceedingCapacity($shopId = null, $capacity = null)
{
	global $DB_CONNECT;
	try {
		if (
			$shopId === null ||
			is_numeric($shopId) === false ||
			(int)$shopId < 1 ||
			is_int($capacity) === false ||
			$capacity < 1 ||
			$capacity > 4
		) {
			return false;
		}

		$strSQL = "
			SELECT
				id,
				name,
				capacity
			FROM
				seats
			WHERE
				shop_id = :shop_id
				AND is_temp_move = 0
				AND capacity > :capacity
			ORDER BY
				sort_order ASC,
				id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':capacity', $capacity, PDO::PARAM_INT);
		$newStmt->execute();
		$seats = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $seats ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約席移動用temp seatを取得または生成
 *  shops mutex取得済みtransaction内で店舗ごと1件だけを保証する
 */
function ensureReservationTempMoveSeat($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				id,
				name,
				type,
				capacity,
				counter_area,
				sort_order,
				is_temp_move,
				is_active
			FROM
				seats
			WHERE
				shop_id = :shop_id
				AND is_temp_move = 1
			ORDER BY
				id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$seats = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		if (count($seats) > 1) {
			return false;
		}
		if (count($seats) === 1) {
			if (
				(string)$seats[0]['name'] !== '席移動（仮）' ||
				(int)$seats[0]['type'] !== 2 ||
				(int)$seats[0]['capacity'] !== 0 ||
				$seats[0]['counter_area'] !== null ||
				(int)$seats[0]['sort_order'] !== 999999 ||
				(int)$seats[0]['is_temp_move'] !== 1 ||
				(int)$seats[0]['is_active'] !== 1
			) {
				return false;
			}
			return (int)$seats[0]['id'];
		}

		$strSQL = "
			INSERT INTO
				seats (
					shop_id,
					name,
					type,
					capacity,
					counter_area,
					sort_order,
					is_temp_move,
					is_active
				)
			VALUES (
				:shop_id,
				:name,
				:type,
				:capacity,
				:counter_area,
				:sort_order,
				:is_temp_move,
				:is_active
			)
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':name', '席移動（仮）', PDO::PARAM_STR);
		$newStmt->bindValue(':type', 2, PDO::PARAM_INT);
		$newStmt->bindValue(':capacity', 0, PDO::PARAM_INT);
		$newStmt->bindValue(':counter_area', null, PDO::PARAM_NULL);
		$newStmt->bindValue(':sort_order', 999999, PDO::PARAM_INT);
		$newStmt->bindValue(':is_temp_move', 1, PDO::PARAM_INT);
		$newStmt->bindValue(':is_active', 1, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		if ($result !== true) {
			return false;
		}
		$seatId = (int)$DB_CONNECT->lastInsertId();
		return $seatId > 0 ? $seatId : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 席管理用の保存済み整数を検証
 *  PDOの整数文字列をPHP intへ安全に変換する
 */
function normalizeSeatManagementStoredInteger($value)
{
	if (is_int($value)) {
		return $value;
	}
	if (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
		return null;
	}
	$result = filter_var($value, FILTER_VALIDATE_INT);
	return $result === false ? null : $result;
}

/**
 * 席管理対象の通常席を検証
 *  壊れたmasterは補正せず読取失敗として扱う
 */
function normalizeSeatManagementRow($row)
{
	if (!is_array($row) || !is_string($row['name'] ?? null) || !function_exists('mb_strlen')) {
		return false;
	}
	$id = normalizeSeatManagementStoredInteger($row['id'] ?? null);
	$shopId = normalizeSeatManagementStoredInteger($row['shop_id'] ?? null);
	$type = normalizeSeatManagementStoredInteger($row['type'] ?? null);
	$capacity = normalizeSeatManagementStoredInteger($row['capacity'] ?? null);
	$sortOrder = normalizeSeatManagementStoredInteger($row['sort_order'] ?? null);
	$isTempMove = normalizeSeatManagementStoredInteger($row['is_temp_move'] ?? null);
	$isActive = normalizeSeatManagementStoredInteger($row['is_active'] ?? null);
	$name = $row['name'];
	$area = $row['counter_area'] ?? null;
	if (
		$id === null || $id < 1 || $shopId === null || $shopId < 1 ||
		!in_array($type, [1, 2], true) || $capacity === null || $capacity < 1 ||
		$sortOrder === null || $isTempMove !== 0 || !in_array($isActive, [0, 1], true) ||
		preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $name) !== 0 ||
		mb_strlen($name, 'UTF-8') > 50 ||
		($type === 1 && ($capacity !== 1 || !is_string($area) || preg_match('/\A[A-H]\z/D', $area) !== 1)) ||
		($type === 2 && $area !== null)
	) {
		return false;
	}
	return [
		'id' => $id, 'shop_id' => $shopId, 'name' => $name, 'type' => $type,
		'capacity' => $capacity, 'counter_area' => $area, 'sort_order' => $sortOrder,
		'is_temp_move' => $isTempMove, 'is_active' => $isActive,
	];
}

/**
 * 席管理用の通常席一覧を取得
 *  0件は空配列、DB不良や不正masterはfalseを返す
 */
function getNormalSeatsForManagement($shopId)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT id, shop_id, name, type, capacity, counter_area, sort_order, is_temp_move, is_active FROM seats WHERE shop_id = :shop_id AND is_temp_move = 0 ORDER BY sort_order ASC, id ASC');
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		if (!is_array($rows)) {
			return false;
		}
		$result = [];
		foreach ($rows as $row) {
			$seat = normalizeSeatManagementRow($row);
			if ($seat === false || $seat['shop_id'] !== $shopId) {
				return false;
			}
			$result[] = $seat;
		}
		return $result;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 席管理用の通常席1件を所有店舗付きで取得
 *  見つからない場合はnull、取得失敗はfalseを返す
 */
function getNormalSeatForManagement($shopId, $seatId)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1 || !is_int($seatId) || $seatId < 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT id, shop_id, name, type, capacity, counter_area, sort_order, is_temp_move, is_active FROM seats WHERE id = :id AND shop_id = :shop_id AND is_temp_move = 0 LIMIT 1');
		$stmt->bindValue(':id', $seatId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		if ($row === false) {
			return null;
		}
		$seat = normalizeSeatManagementRow($row);
		return $seat !== false && $seat['shop_id'] === $shopId ? $seat : false;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 通常席のcanonical state versionを生成
 *  更新時刻の秒精度には依存しない
 */
function buildSeatManagementSeatVersion($seat)
{
	$seat = normalizeSeatManagementRow($seat);
	if ($seat === false) {
		return false;
	}
	$json = json_encode($seat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	return is_string($json) ? hash('sha256', $json) : false;
}

/**
 * 通常席全体の並び順versionを生成
 *  現在のsort_order順にIDとorderだけを固定する
 */
function buildSeatManagementOrderVersion($seats)
{
	if (!is_array($seats) || !array_is_list($seats)) {
		return false;
	}
	$state = [];
	$seen = [];
	foreach ($seats as $row) {
		$seat = normalizeSeatManagementRow($row);
		if ($seat === false || isset($seen[$seat['id']])) {
			return false;
		}
		$seen[$seat['id']] = true;
		$state[] = [$seat['id'], $seat['sort_order']];
	}
	$json = json_encode($state);
	return is_string($json) ? hash('sha256', $json) : false;
}

/**
 * 席に紐づくJST当日以降の有効予約を調べる
 *  1=存在、0=なし、false=取得失敗を返す
 */
function hasSeatManagementActiveReservation($shopId, $seatId, $today)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1 || !is_int($seatId) || $seatId < 1 || !is_string($today) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $today) !== 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT 1 FROM reservation_seats rs INNER JOIN reservations r ON r.id = rs.reservation_id WHERE rs.seat_id = :seat_id AND r.shop_id = :shop_id AND r.reservation_date >= :today AND r.status IN (1, 2) LIMIT 1');
		$stmt->bindValue(':seat_id', $seatId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$stmt->bindValue(':today', $today, PDO::PARAM_STR);
		if ($stmt->execute() !== true) {
			return false;
		}
		$found = $stmt->fetchColumn() !== false;
		$stmt->closeCursor();
		return $found ? 1 : 0;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 通常席を登録
 *  shopとtemp値は呼出側で確定した値だけを使用する
 */
function insertNormalSeatForManagement($shopId, $seat)
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('INSERT INTO seats (shop_id, name, type, capacity, counter_area, sort_order, is_temp_move, is_active) VALUES (:shop_id, :name, :type, :capacity, :counter_area, :sort_order, 0, 1)');
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$stmt->bindValue(':name', $seat['name'], PDO::PARAM_STR);
		$stmt->bindValue(':type', $seat['type'], PDO::PARAM_INT);
		$stmt->bindValue(':capacity', $seat['capacity'], PDO::PARAM_INT);
		$stmt->bindValue(':counter_area', $seat['counter_area'], $seat['counter_area'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$stmt->bindValue(':sort_order', $seat['sort_order'], PDO::PARAM_INT);
		$result = $stmt->execute();
		$stmt->closeCursor();
		return $result === true && (int)$DB_CONNECT->lastInsertId() > 0;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 通常席の変更列だけを更新
 *  対象店舗とtemp除外をwrite条件にも適用する
 */
function updateNormalSeatForManagement($shopId, $seatId, $changes)
{
	global $DB_CONNECT;
	$allowed = ['name' => PDO::PARAM_STR, 'type' => PDO::PARAM_INT, 'capacity' => PDO::PARAM_INT, 'counter_area' => PDO::PARAM_STR, 'is_active' => PDO::PARAM_INT];
	if (!is_array($changes) || $changes === [] || array_diff(array_keys($changes), array_keys($allowed)) !== []) {
		return false;
	}
	try {
		$sets = [];
		foreach ($changes as $column => $value) {
			$sets[] = $column . ' = :' . $column;
		}
		$stmt = $DB_CONNECT->prepare('UPDATE seats SET ' . implode(', ', $sets) . ' WHERE id = :id AND shop_id = :shop_id AND is_temp_move = 0');
		foreach ($changes as $column => $value) {
			$stmt->bindValue(':' . $column, $value, $value === null ? PDO::PARAM_NULL : $allowed[$column]);
		}
		$stmt->bindValue(':id', $seatId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true && $count === 1;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 通常席のsort_orderだけを更新
 *  変更対象外の席は一切書き換えない
 */
function updateNormalSeatSortOrderForManagement($shopId, $seatId, $sortOrder)
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('UPDATE seats SET sort_order = :sort_order WHERE id = :id AND shop_id = :shop_id AND is_temp_move = 0');
		$stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
		$stmt->bindValue(':id', $seatId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true && $count === 1;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 通常席を物理削除
 *  予約存在確認はtransaction ownerが直前に行う
 */
function deleteNormalSeatForManagement($shopId, $seatId)
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('DELETE FROM seats WHERE id = :id AND shop_id = :shop_id AND is_temp_move = 0');
		$stmt->bindValue(':id', $seatId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true && $count === 1;
	} catch (PDOException $e) {
		return false;
	}
}
