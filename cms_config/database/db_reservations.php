<?php
/*
 * [予約情報設定]
 */

/**
 * 予約占有判定用の対象日予約席一覧取得
 *  reservation_seatsを予約単位にまとめる前の行として返す
 */
function getReservationsForOccupancy($shopId = null, $reservationDate = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationDbDateStringForReservations($reservationDate) === false) {
			return [];
		}

		$strSQL = "
			SELECT
				r.id AS reservation_id,
				r.party_size,
				r.status,
				rs.id AS reservation_seat_id,
				rs.seat_id,
				s.id AS matched_seat_id,
				s.is_temp_move AS seat_is_temp_move
			FROM
				reservations r
				LEFT JOIN reservation_seats rs ON r.id = rs.reservation_id
				LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
			WHERE
				r.shop_id = :shop_id
				AND r.reservation_date = :reservation_date
			ORDER BY
				r.id ASC,
				rs.id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_date', $reservationDate, PDO::PARAM_STR);
		$newStmt->execute();
		$reservations = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $reservations ?: [];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 店舗内の仮移動ガード状態を取得
 *  temp行または参照先master不整合が1件でもあれば管理画面ガード対象とする
 */
function getReservationTempMoveGuardState($shopId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		if ($shopId === null) {
			return false;
		}
		$strSQL = "
			SELECT
				EXISTS(
					SELECT 1
					FROM reservations r
					INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
					INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
					WHERE r.shop_id = :temp_shop_id AND s.is_temp_move = 1
				) AS has_temp_move,
				EXISTS(
					SELECT 1
					FROM reservations r
					INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
					LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
					WHERE r.shop_id = :invalid_shop_id AND s.id IS NULL
				) AS has_invalid_reference,
				(SELECT COUNT(DISTINCT r.id) FROM reservations r INNER JOIN reservation_seats rs ON r.id = rs.reservation_id INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id WHERE r.shop_id = :count_shop_id AND s.is_temp_move = 1) AS temp_reservation_count,
				(SELECT COUNT(*) FROM reservations r INNER JOIN reservation_seats rs ON r.id = rs.reservation_id INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id WHERE r.shop_id = :row_count_shop_id AND s.is_temp_move = 1) AS temp_row_count,
				(SELECT MIN(r.id) FROM reservations r INNER JOIN reservation_seats rs ON r.id = rs.reservation_id INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id WHERE r.shop_id = :reservation_shop_id AND s.is_temp_move = 1) AS guard_reservation_id,
				COALESCE(
					(SELECT MIN(r.reservation_date) FROM reservations r INNER JOIN reservation_seats rs ON r.id = rs.reservation_id INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id WHERE r.shop_id = :date_temp_shop_id AND s.is_temp_move = 1),
					(SELECT MIN(r.reservation_date) FROM reservations r INNER JOIN reservation_seats rs ON r.id = rs.reservation_id LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id WHERE r.shop_id = :date_invalid_shop_id AND s.id IS NULL)
				) AS guard_reservation_date
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':temp_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':invalid_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':date_temp_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':date_invalid_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':count_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':row_count_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$row = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($row === false) {
			return false;
		}
		$hasTempMove = normalizeReservationDbIntegerForReservations($row['has_temp_move'] ?? null, 0, 1);
		$hasInvalidReference = normalizeReservationDbIntegerForReservations($row['has_invalid_reference'] ?? null, 0, 1);
		$tempReservationCount = normalizeReservationDbIntegerForReservations($row['temp_reservation_count'] ?? null, 0, null);
		$tempRowCount = normalizeReservationDbIntegerForReservations($row['temp_row_count'] ?? null, 0, null);
		$guardReservationId = normalizeReservationDbIntegerForReservations($row['guard_reservation_id'] ?? null, 1, null);
		if ($hasTempMove === null || $hasInvalidReference === null || $tempReservationCount === null || $tempRowCount === null || ($tempReservationCount > 0 && $guardReservationId === null)) {
			return false;
		}
		return [
			'active' => $hasTempMove === 1 || $hasInvalidReference === 1,
			'has_temp_move' => $hasTempMove === 1,
			'has_invalid_reference' => $hasInvalidReference === 1,
			'temp_reservation_count' => $tempReservationCount,
			'temp_row_count' => $tempRowCount,
			'guard_reservation_id' => $guardReservationId,
			'guard_reservation_date' => isReservationDbDateStringForReservations($row['guard_reservation_date'] ?? null) ? $row['guard_reservation_date'] : null,
		];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 仮移動更新用の対象予約席行をfresh取得
 *  transactionと店舗mutex取得後にmaster整合・temp件数を判定する元データを返す
 */
function getReservationSeatRowsForTempMoveWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$strSQL = "
			SELECT rs.id AS reservation_seat_id, rs.seat_id, s.id AS matched_seat_id, s.is_temp_move
			FROM reservations r
			INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
			LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
			WHERE r.id = :reservation_id AND r.shop_id = :shop_id
			ORDER BY rs.id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return $rows;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 対象予約のtemp席行だけを削除
 *  元実席行を維持し、呼出側が指定したfresh件数との一致も確認する
 */
function deleteReservationTempMoveRows($shopId = null, $reservationId = null, $expectedCount = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		$expectedCount = normalizeReservationDbIntegerForReservations($expectedCount, 1, null);
		if ($shopId === null || $reservationId === null || $expectedCount === null || is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$strSQL = "
			DELETE rs
			FROM reservation_seats rs
			INNER JOIN reservations r ON rs.reservation_id = r.id AND r.shop_id = :shop_id
			INNER JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id AND s.is_temp_move = 1
			WHERE rs.reservation_id = :reservation_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$deletedCount = $newStmt->rowCount();
		$newStmt->closeCursor();
		return $result === true && $deletedCount === $expectedCount;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約本体登録
 *  caller所有のtransaction内でreservationsへ1行登録し発行IDを返す
 */
function insertReservation($shopId = null, $reservationData = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($reservationData) === false) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$reservationDate = $reservationData['reservation_date'] ?? null;
		$partySize = $reservationData['party_size'] ?? null;
		$reservationRoute = $reservationData['reservation_route'] ?? null;
		$status = array_key_exists('status', $reservationData) === true ? $reservationData['status'] : 1;

		if (isReservationDbDateStringForReservations($reservationDate) === false) {
			return false;
		}
		if (is_numeric($partySize) === false) {
			return false;
		}
		if (is_numeric($reservationRoute) === false || is_numeric($status) === false) {
			return false;
		}
		$status = (int)$status;
		if (
			array_key_exists('customer_name', $reservationData) === false ||
			array_key_exists('customer_kana', $reservationData) === false ||
			array_key_exists('customer_tel', $reservationData) === false ||
			$reservationData['customer_name'] === null ||
			$reservationData['customer_kana'] === null ||
			$reservationData['customer_tel'] === null
		) {
			return false;
		}

		$customerName = (string)$reservationData['customer_name'];
		$customerKana = (string)$reservationData['customer_kana'];
		$customerTel = (string)$reservationData['customer_tel'];
		$customerEmail = array_key_exists('customer_email', $reservationData) ? $reservationData['customer_email'] : null;
		$accommodationName = array_key_exists('accommodation_name', $reservationData) ? $reservationData['accommodation_name'] : null;
		$customerNote = array_key_exists('customer_note', $reservationData) ? $reservationData['customer_note'] : null;
		$shopMemo = array_key_exists('shop_memo', $reservationData) ? $reservationData['shop_memo'] : null;
		$cancelledAt = array_key_exists('cancelled_at', $reservationData) ? $reservationData['cancelled_at'] : null;

		$customerEmail = $customerEmail === null ? null : (string)$customerEmail;
		$accommodationName = $accommodationName === null ? null : (string)$accommodationName;
		$customerNote = $customerNote === null ? null : (string)$customerNote;
		$shopMemo = $shopMemo === null ? null : (string)$shopMemo;
		$cancelledAt = $cancelledAt === null ? null : (string)$cancelledAt;

		$strSQL = "
			INSERT INTO
				reservations (
					shop_id,
					reservation_date,
					party_size,
					customer_name,
					customer_kana,
					customer_tel,
					customer_email,
					accommodation_name,
					customer_note,
					shop_memo,
					reservation_route,
					status,
					cancelled_at
				)
			VALUES (
				:shop_id,
				:reservation_date,
				:party_size,
				:customer_name,
				:customer_kana,
				:customer_tel,
				:customer_email,
				:accommodation_name,
				:customer_note,
				:shop_memo,
				:reservation_route,
				:status,
				:cancelled_at
			)
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_date', $reservationDate, PDO::PARAM_STR);
		$newStmt->bindValue(':party_size', (int)$partySize, PDO::PARAM_INT);
		$newStmt->bindValue(':customer_name', $customerName, PDO::PARAM_STR);
		$newStmt->bindValue(':customer_kana', $customerKana, PDO::PARAM_STR);
		$newStmt->bindValue(':customer_tel', $customerTel, PDO::PARAM_STR);
		$newStmt->bindValue(':customer_email', $customerEmail, $customerEmail === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':accommodation_name', $accommodationName, $accommodationName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':customer_note', $customerNote, $customerNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':shop_memo', $shopMemo, $shopMemo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':reservation_route', (int)$reservationRoute, PDO::PARAM_INT);
		$newStmt->bindValue(':status', $status, PDO::PARAM_INT);
		$newStmt->bindValue(':cancelled_at', $cancelledAt, $cancelledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		if ($result !== true) {
			return false;
		}
		$reservationId = (int)$DB_CONNECT->lastInsertId();
		return $reservationId > 0 ? $reservationId : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約席登録
 *  対象予約と席のshop ownershipを確認しseat_name_snapshot付きで登録する
 */
function insertReservationSeats($reservationId = null, $shopId = null, $seatIds = [])
{
	global $DB_CONNECT;
	try {
		if ($reservationId === null || is_numeric($reservationId) === false || (int)$reservationId < 1 || $shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($seatIds) === false || empty($seatIds) === true) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$reservation = getReservationForWrite($shopId, $reservationId);
		if (is_array($reservation) === false) {
			return false;
		}

		$normalizedSeatIds = [];
		foreach ($seatIds as $seatId) {
			if (is_numeric($seatId) === false || (int)$seatId < 1) {
				return false;
			}
			$seatId = (int)$seatId;
			if (isset($normalizedSeatIds[$seatId]) === true) {
				return false;
			}
			$normalizedSeatIds[$seatId] = $seatId;
		}
		$normalizedSeatIds = array_values($normalizedSeatIds);
		$seatRows = getReservationSeatRowsForWrite($shopId, $normalizedSeatIds);
		if ($seatRows === false || count($seatRows) !== count($normalizedSeatIds)) {
			return false;
		}

		$strSQL = "
			INSERT INTO
				reservation_seats (
					reservation_id,
					seat_id,
					seat_name_snapshot
				)
			VALUES (
				:reservation_id,
				:seat_id,
				:seat_name_snapshot
			)
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($normalizedSeatIds as $seatId) {
			if (isset($seatRows[$seatId]) === false) {
				return false;
			}
			$newStmt->bindValue(':reservation_id', (int)$reservationId, PDO::PARAM_INT);
			$newStmt->bindValue(':seat_id', $seatId, PDO::PARAM_INT);
			$newStmt->bindValue(':seat_name_snapshot', (string)$seatRows[$seatId]['name'], PDO::PARAM_STR);
			if ($newStmt->execute() !== true) {
				$newStmt->closeCursor();
				return false;
			}
		}
		$newStmt->closeCursor();

		return true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約席差し替え
 *  自動relocation等の確定済みplanに従い既存席を削除して新席を登録する
 */
function replaceReservationSeats($reservationId = null, $shopId = null, $seatIds = [])
{
	global $DB_CONNECT;
	try {
		if ($reservationId === null || is_numeric($reservationId) === false || (int)$reservationId < 1 || $shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$reservation = getReservationForWrite($shopId, $reservationId);
		if (is_array($reservation) === false) {
			return false;
		}

		$strSQL = "
			DELETE FROM
				reservation_seats
			WHERE
				reservation_id = :reservation_id
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', (int)$reservationId, PDO::PARAM_INT);
		if ($newStmt->execute() !== true) {
			$newStmt->closeCursor();
			return false;
		}
		$newStmt->closeCursor();

		return insertReservationSeats($reservationId, $shopId, $seatIds);
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約メニュー登録
 *  null slotは保存せず、選択済みguest_noのsnapshot行だけを登録する
 */
function insertReservationMenus($reservationId = null, $shopId = null, $menuRows = [])
{
	global $DB_CONNECT;
	try {
		if ($reservationId === null || is_numeric($reservationId) === false || (int)$reservationId < 1 || $shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($menuRows) === false) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$reservation = getReservationForWrite($shopId, $reservationId);
		if (is_array($reservation) === false) {
			return false;
		}

		$normalizedRows = [];
		$guestNos = [];
		$menuIds = [];
		foreach ($menuRows as $row) {
			if ($row === null) {
				continue;
			}
			if (is_array($row) === false) {
				return false;
			}
			$guestNo = $row['guest_no'] ?? null;
			$menuId = $row['menu_id'] ?? null;
			$menuName = array_key_exists('menu_name', $row) ? $row['menu_name'] : ($row['menu_name_snapshot'] ?? null);
			$menuPrice = array_key_exists('price', $row) ? $row['price'] : ($row['menu_price_snapshot'] ?? null);
			$taxIncluded = array_key_exists('tax_included', $row) ? $row['tax_included'] : ($row['tax_included_snapshot'] ?? 1);

			if (
				is_numeric($guestNo) === false ||
				(int)$guestNo < 1 ||
				isset($guestNos[(int)$guestNo]) === true ||
				is_numeric($menuId) === false ||
				(int)$menuId < 1 ||
				is_string($menuName) === false ||
				$menuName === '' ||
				is_numeric($menuPrice) === false ||
				(int)$menuPrice < 0 ||
				is_numeric($taxIncluded) === false ||
				in_array((int)$taxIncluded, [0, 1], true) === false
			) {
				return false;
			}

			$guestNo = (int)$guestNo;
			$menuId = (int)$menuId;
			$guestNos[$guestNo] = true;
			$menuIds[$menuId] = $menuId;
			$normalizedRows[] = [
				'guest_no' => $guestNo,
				'menu_id' => $menuId,
				'menu_name_snapshot' => $menuName,
				'menu_price_snapshot' => (int)$menuPrice,
				'tax_included_snapshot' => (int)$taxIncluded === 1 ? 1 : 0,
			];
		}

		if (empty($normalizedRows) === true) {
			return true;
		}

		$menuRowsById = getReservationMenuRowsForWrite($shopId, array_values($menuIds));
		if ($menuRowsById === false || count($menuRowsById) !== count($menuIds)) {
			return false;
		}

		$strSQL = "
			INSERT INTO
				reservation_menus (
					reservation_id,
					guest_no,
					menu_id,
					menu_name_snapshot,
					menu_price_snapshot,
					tax_included_snapshot
				)
			VALUES (
				:reservation_id,
				:guest_no,
				:menu_id,
				:menu_name_snapshot,
				:menu_price_snapshot,
				:tax_included_snapshot
			)
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($normalizedRows as $row) {
			$newStmt->bindValue(':reservation_id', (int)$reservationId, PDO::PARAM_INT);
			$newStmt->bindValue(':guest_no', $row['guest_no'], PDO::PARAM_INT);
			$newStmt->bindValue(':menu_id', $row['menu_id'], PDO::PARAM_INT);
			$newStmt->bindValue(':menu_name_snapshot', $row['menu_name_snapshot'], PDO::PARAM_STR);
			$newStmt->bindValue(':menu_price_snapshot', $row['menu_price_snapshot'], PDO::PARAM_INT);
			$newStmt->bindValue(':tax_included_snapshot', $row['tax_included_snapshot'], PDO::PARAM_INT);
			if ($newStmt->execute() !== true) {
				$newStmt->closeCursor();
				return false;
			}
		}
		$newStmt->closeCursor();

		return true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約書込用予約所有確認
 *  reservation_idが対象shopに属する場合のみ行を返す
 */
function getReservationForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || $reservationId === null || is_numeric($reservationId) === false || (int)$reservationId < 1) {
			return null;
		}

		$strSQL = "
			SELECT
				id,
				shop_id
			FROM
				reservations
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
			LIMIT 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', (int)$reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$reservation = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $reservation ?: null;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * ステータス変更用予約取得
 *  transaction内で対象shopの予約statusとcancelled_atを検証して返す
 */
function getReservationStatusForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return null;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				reservation_date,
				status,
				cancelled_at
			FROM
				reservations
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
			LIMIT 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$reservation = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($reservation === false) {
			return null;
		}

		$rowReservationId = normalizeReservationDbIntegerForReservations($reservation['id'] ?? null, 1, null);
		$rowShopId = normalizeReservationDbIntegerForReservations($reservation['shop_id'] ?? null, 1, null);
		$status = normalizeReservationDbIntegerForReservations($reservation['status'] ?? null, 1, 4);
		$cancelledAt = $reservation['cancelled_at'] ?? null;
		if (
			$rowReservationId !== $reservationId ||
			$rowShopId !== $shopId ||
			$status === null ||
			isReservationDbDateStringForReservations($reservation['reservation_date'] ?? null) === false ||
			($cancelledAt !== null && isReservationDbDateTimeStringForReservations($cancelledAt) === false)
		) {
			return false;
		}

		return [
			'id' => $rowReservationId,
			'shop_id' => $rowShopId,
			'reservation_date' => $reservation['reservation_date'],
			'status' => $status,
			'cancelled_at' => $cancelledAt,
		];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 席変更用fresh予約取得
 *  caller所有transactionとshops mutexの後で対象予約の判定値を返す
 */
function getReservationSeatChangeForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return null;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				reservation_date,
				party_size,
				status
			FROM
				reservations
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$reservation = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($reservation === false) {
			return null;
		}

		$rowReservationId = normalizeReservationDbIntegerForReservations($reservation['id'] ?? null, 1, null);
		$rowShopId = normalizeReservationDbIntegerForReservations($reservation['shop_id'] ?? null, 1, null);
		$partySize = normalizeReservationDbIntegerForReservations($reservation['party_size'] ?? null, 1, 4);
		$status = normalizeReservationDbIntegerForReservations($reservation['status'] ?? null, 1, 4);
		if (
			$rowReservationId !== $reservationId ||
			$rowShopId !== $shopId ||
			$partySize === null ||
			$status === null ||
			isReservationDbDateStringForReservations($reservation['reservation_date'] ?? null) === false
		) {
			return false;
		}

		return [
			'id' => $rowReservationId,
			'shop_id' => $rowShopId,
			'reservation_date' => $reservation['reservation_date'],
			'party_size' => $partySize,
			'status' => $status,
		];
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 実席変更前の現在席整合性確認
 *  DELETE前に重複・master不存在・他店舗席・temp混入・実席欠落を拒否する
 */
function validateReservationSeatChangeRowsForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				rs.id AS reservation_seat_id,
				rs.seat_id,
				s.id AS matched_seat_id,
				s.is_temp_move
			FROM
				reservations r
				INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
				LEFT JOIN seats s ON rs.seat_id = s.id AND s.shop_id = r.shop_id
			WHERE
				r.id = :reservation_id
				AND r.shop_id = :shop_id
			ORDER BY
				rs.id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if (empty($rows) === true) {
			return false;
		}

		$reservationSeatIds = [];
		$seatIds = [];
		$normalSeatCount = 0;
		foreach ($rows as $row) {
			$reservationSeatId = normalizeReservationDbIntegerForReservations($row['reservation_seat_id'] ?? null, 1, null);
			$seatId = normalizeReservationDbIntegerForReservations($row['seat_id'] ?? null, 1, null);
			$matchedSeatId = normalizeReservationDbIntegerForReservations($row['matched_seat_id'] ?? null, 1, null);
			$isTempMove = normalizeReservationDbIntegerForReservations($row['is_temp_move'] ?? null, 0, 1);
			if (
				$reservationSeatId === null ||
				$seatId === null ||
				$matchedSeatId !== $seatId ||
				$isTempMove === null ||
				isset($reservationSeatIds[$reservationSeatId]) === true ||
				isset($seatIds[$seatId]) === true ||
				$isTempMove !== 0
			) {
				return false;
			}
			$reservationSeatIds[$reservationSeatId] = true;
			$seatIds[$seatId] = true;
			$normalSeatCount++;
		}

		return $normalSeatCount > 0;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約ステータス更新
 *  caller所有のtransaction内でstatusとcancelled_atだけを更新する
 */
function updateReservationStatus($shopId = null, $reservationId = null, $status = null, $cancelledAt = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		$status = normalizeReservationDbIntegerForReservations($status, 1, 4);
		if ($shopId === null || $reservationId === null || $status === null) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		if (
			($cancelledAt !== null && isReservationDbDateTimeStringForReservations($cancelledAt) === false) ||
			(in_array($status, [1, 2], true) === true && $cancelledAt !== null) ||
			(in_array($status, [3, 4], true) === true && $cancelledAt === null)
		) {
			return false;
		}

		$strSQL = "
			UPDATE
				reservations
			SET
				status = :status,
				cancelled_at = :cancelled_at
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':status', $status, PDO::PARAM_INT);
		$newStmt->bindValue(':cancelled_at', $cancelledAt, $cancelledAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$updatedRows = $newStmt->rowCount();
		$newStmt->closeCursor();

		return $result === true && $updatedRows === 1;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約席書込用席一覧取得
 *  指定seat IDsが対象shopに属するか確認しsnapshot元の席名を返す
 */
function getReservationSeatRowsForWrite($shopId = null, $seatIds = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($seatIds) === false || empty($seatIds) === true) {
			return false;
		}

		$placeholders = [];
		foreach (array_values($seatIds) as $index => $seatId) {
			if (is_numeric($seatId) === false || (int)$seatId < 1) {
				return false;
			}
			$placeholders[] = ':seat_id_' . $index;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				name
			FROM
				seats
			WHERE
				shop_id = :shop_id
				AND id IN (" . implode(',', $placeholders) . ")
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		foreach (array_values($seatIds) as $index => $seatId) {
			$newStmt->bindValue(':seat_id_' . $index, (int)$seatId, PDO::PARAM_INT);
		}
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		$seatsById = [];
		foreach ($rows ?: [] as $row) {
			$seatsById[(int)$row['id']] = $row;
		}
		return $seatsById;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約メニュー書込用メニュー一覧取得
 *  指定menu IDsが対象shopに属するか確認する
 */
function getReservationMenuRowsForWrite($shopId = null, $menuIds = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($menuIds) === false || empty($menuIds) === true) {
			return false;
		}

		$placeholders = [];
		foreach (array_values($menuIds) as $index => $menuId) {
			if (is_numeric($menuId) === false || (int)$menuId < 1) {
				return false;
			}
			$placeholders[] = ':menu_id_' . $index;
		}

		$strSQL = "
			SELECT
				id,
				shop_id
			FROM
				food_menus
			WHERE
				shop_id = :shop_id
				AND id IN (" . implode(',', $placeholders) . ")
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		foreach (array_values($menuIds) as $index => $menuId) {
			$newStmt->bindValue(':menu_id_' . $index, (int)$menuId, PDO::PARAM_INT);
		}
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		$menusById = [];
		foreach ($rows ?: [] as $row) {
			$menusById[(int)$row['id']] = $row;
		}
		return $menusById;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集用予約取得
 *  transaction内で対象shop所有のfresh編集対象値を返す
 */
function getReservationDetailEditForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return null;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				reservation_route,
				reservation_date,
				party_size,
				customer_name,
				customer_kana,
				customer_tel,
				customer_email,
				accommodation_name,
				customer_note,
				shop_memo,
				status,
				cancelled_at
			FROM
				reservations
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
			LIMIT 1
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$reservation = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if ($reservation === false) {
			return null;
		}

		$reservation['id'] = normalizeReservationDbIntegerForReservations($reservation['id'] ?? null, 1, null);
		$reservation['shop_id'] = normalizeReservationDbIntegerForReservations($reservation['shop_id'] ?? null, 1, null);
		$reservation['reservation_route'] = normalizeReservationDbIntegerForReservations($reservation['reservation_route'] ?? null, 1, 3);
		$reservation['party_size'] = normalizeReservationDbIntegerForReservations($reservation['party_size'] ?? null, 1, 4);
		$reservation['status'] = normalizeReservationDbIntegerForReservations($reservation['status'] ?? null, 1, 4);
		$requiredStrings = ['customer_name', 'customer_kana', 'customer_tel'];
		$nullableStrings = ['customer_email', 'accommodation_name', 'customer_note', 'shop_memo'];
		if ($reservation['id'] !== $reservationId || $reservation['shop_id'] !== $shopId || $reservation['reservation_route'] === null || isReservationDbDateStringForReservations($reservation['reservation_date'] ?? null) === false || $reservation['party_size'] === null || $reservation['status'] === null) {
			return false;
		}
		foreach ($requiredStrings as $column) {
			if (array_key_exists($column, $reservation) === false || is_string($reservation[$column]) === false) {
				return false;
			}
		}
		foreach ($nullableStrings as $column) {
			if (array_key_exists($column, $reservation) === false || ($reservation[$column] !== null && is_string($reservation[$column]) === false)) {
				return false;
			}
		}
		if (($reservation['cancelled_at'] ?? null) !== null && isReservationDbDateTimeStringForReservations($reservation['cancelled_at']) === false) {
			return false;
		}
		return $reservation;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集用menu snapshot取得
 *  対象shop所有を確認してfresh rowをguest_no順で返す
 */
function getReservationDetailEditMenuRowsForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				rm.reservation_id,
				r.party_size,
				rm.guest_no,
				rm.menu_id,
				rm.menu_name_snapshot,
				rm.menu_price_snapshot,
				rm.tax_included_snapshot
			FROM
				reservations r
				INNER JOIN reservation_menus rm ON r.id = rm.reservation_id
			WHERE
				r.id = :reservation_id
				AND r.shop_id = :shop_id
			ORDER BY
				rm.guest_no ASC,
				rm.id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return is_array($rows) === true ? $rows : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集用fresh割当席取得
 *  LEFT JOINでcurrent seat master missingも識別可能にする
 */
function getReservationDetailEditAssignedSeatRowsForWrite($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			SELECT
				rs.seat_id,
				s.id AS current_seat_id,
				s.capacity,
				s.is_active,
				s.is_temp_move
			FROM
				reservations r
				INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
				LEFT JOIN seats s ON rs.seat_id = s.id AND r.shop_id = s.shop_id
			WHERE
				r.id = :reservation_id
				AND r.shop_id = :shop_id
			ORDER BY
				rs.id ASC
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		return is_array($rows) === true ? $rows : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集field更新
 *  server固定mappingに存在する変更columnだけをUPDATEする
 */
function updateReservationDetailFields($shopId = null, $reservationId = null, $changes = [])
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_array($changes) === false || empty($changes) === true) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		$columnTypes = [
			'reservation_route' => 'int',
			'party_size' => 'int',
			'customer_name' => 'string',
			'customer_kana' => 'string',
			'customer_tel' => 'string',
			'customer_email' => 'nullable_string',
			'accommodation_name' => 'nullable_string',
			'customer_note' => 'nullable_string',
			'shop_memo' => 'nullable_string',
		];
		$setClauses = [];
		foreach ($changes as $column => $value) {
			if (isset($columnTypes[$column]) === false) {
				return false;
			}
			if ($column === 'reservation_route' && normalizeReservationDbIntegerForReservations($value, 1, 3) === null) {
				return false;
			}
			if ($column === 'party_size' && normalizeReservationDbIntegerForReservations($value, 1, 4) === null) {
				return false;
			}
			if ($columnTypes[$column] === 'string' && is_string($value) === false) {
				return false;
			}
			if ($columnTypes[$column] === 'nullable_string' && $value !== null && is_string($value) === false) {
				return false;
			}
			$setClauses[] = $column . ' = :' . $column;
		}

		$strSQL = "
			UPDATE
				reservations
			SET
				" . implode(",\n\t\t\t\t", $setClauses) . "
			WHERE
				id = :reservation_id
				AND shop_id = :shop_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($changes as $column => $value) {
			$type = $value === null ? PDO::PARAM_NULL : ($columnTypes[$column] === 'int' ? PDO::PARAM_INT : PDO::PARAM_STR);
			$newStmt->bindValue(':' . $column, $value, $type);
		}
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$newStmt->closeCursor();
		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集menu更新
 *  guest_no単位でcurrent master由来snapshotだけを書き換える
 */
function updateReservationMenusForDetailEdit($shopId = null, $reservationId = null, $menuRows = [])
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_array($menuRows) === false) {
			return false;
		}
		if (empty($menuRows) === true) {
			return true;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$strSQL = "
			UPDATE
				reservation_menus rm
				INNER JOIN reservations r ON rm.reservation_id = r.id
			SET
				rm.menu_id = :menu_id,
				rm.menu_name_snapshot = :menu_name_snapshot,
				rm.menu_price_snapshot = :menu_price_snapshot,
				rm.tax_included_snapshot = :tax_included_snapshot
			WHERE
				rm.reservation_id = :reservation_id
				AND rm.guest_no = :guest_no
				AND r.shop_id = :shop_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$guestNos = [];
		foreach ($menuRows as $row) {
			if (is_array($row) === false) {
				$newStmt->closeCursor();
				return false;
			}
			$guestNo = normalizeReservationDbIntegerForReservations($row['guest_no'] ?? null, 1, 4);
			$menuId = normalizeReservationDbIntegerForReservations($row['menu_id'] ?? null, 1, null);
			$menuName = $row['menu_name'] ?? null;
			$menuPrice = normalizeReservationDbIntegerForReservations($row['price'] ?? null, 0, null);
			$taxIncluded = normalizeReservationDbIntegerForReservations($row['tax_included'] ?? null, 0, 1);
			if ($guestNo === null || $menuId === null || is_string($menuName) === false || $menuName === '' || $menuPrice === null || $taxIncluded === null || isset($guestNos[$guestNo]) === true) {
				$newStmt->closeCursor();
				return false;
			}
			$guestNos[$guestNo] = true;
			$newStmt->bindValue(':menu_id', $menuId, PDO::PARAM_INT);
			$newStmt->bindValue(':menu_name_snapshot', $menuName, PDO::PARAM_STR);
			$newStmt->bindValue(':menu_price_snapshot', $menuPrice, PDO::PARAM_INT);
			$newStmt->bindValue(':tax_included_snapshot', $taxIncluded, PDO::PARAM_INT);
			$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
			$newStmt->bindValue(':guest_no', $guestNo, PDO::PARAM_INT);
			$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
			if ($newStmt->execute() !== true) {
				$newStmt->closeCursor();
				return false;
			}
		}
		$newStmt->closeCursor();
		return true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細編集menu削除
 *  対象shop所有予約の指定guest_noだけをまとめて削除する
 */
function deleteReservationMenusForDetailEdit($shopId = null, $reservationId = null, $guestNos = [])
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDbIntegerForReservations($shopId, 1, null);
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		if ($shopId === null || $reservationId === null || is_array($guestNos) === false) {
			return false;
		}
		if (empty($guestNos) === true) {
			return true;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}

		$placeholders = [];
		$normalizedGuestNos = [];
		foreach (array_values($guestNos) as $index => $guestNo) {
			$guestNo = normalizeReservationDbIntegerForReservations($guestNo, 1, 4);
			if ($guestNo === null || isset($normalizedGuestNos[$guestNo]) === true) {
				return false;
			}
			$normalizedGuestNos[$guestNo] = $guestNo;
			$placeholders[] = ':guest_no_' . $index;
		}

		$strSQL = "
			DELETE rm
			FROM
				reservation_menus rm
				INNER JOIN reservations r ON rm.reservation_id = r.id
			WHERE
				rm.reservation_id = :reservation_id
				AND rm.guest_no IN (" . implode(',', $placeholders) . ")
				AND r.shop_id = :shop_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		foreach (array_values($normalizedGuestNos) as $index => $guestNo) {
			$newStmt->bindValue(':guest_no_' . $index, $guestNo, PDO::PARAM_INT);
		}
		$result = $newStmt->execute();
		$newStmt->closeCursor();
		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約メール送信結果登録
 *  COMMIT後に宛先単位の初回送信結果をreservation_mail_logsへ記録する
 */
function insertReservationMailLog($reservationId = null, $recipientType = null, $recipientAddress = null, $status = null, $lastError = null)
{
	global $DB_CONNECT;
	try {
		$reservationId = normalizeReservationDbIntegerForReservations($reservationId, 1, null);
		$recipientType = normalizeReservationDbIntegerForReservations($recipientType, 1, 4);
		$status = normalizeReservationDbIntegerForReservations($status, 1, 3);
		if (
			$reservationId === null ||
			$recipientType === null ||
			$status === null ||
			($recipientAddress !== null && (is_string($recipientAddress) === false || strlen($recipientAddress) > 255)) ||
			($lastError !== null && is_string($lastError) === false)
		) {
			return false;
		}

		$sentAt = null;
		if ($status === 1) {
			$sentAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
		}

		$strSQL = "
			INSERT INTO reservation_mail_logs (
				reservation_id,
				recipient_type,
				recipient_address,
				status,
				attempt_count,
				last_error,
				sent_at
			) VALUES (
				:reservation_id,
				:recipient_type,
				:recipient_address,
				:status,
				1,
				:last_error,
				:sent_at
			)
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':reservation_id', $reservationId, PDO::PARAM_INT);
		$newStmt->bindValue(':recipient_type', $recipientType, PDO::PARAM_INT);
		$newStmt->bindValue(':recipient_address', $recipientAddress, $recipientAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':status', $status, PDO::PARAM_INT);
		$newStmt->bindValue(':last_error', $lastError, $lastError === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$newStmt->bindValue(':sent_at', $sentAt, $sentAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
		$result = $newStmt->execute();
		$newStmt->closeCursor();
		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * DB helper用日付文字列確認
 *  Y-m-d形式のみ有効とする
 */
function isReservationDbDateStringForReservations($date)
{
	if (is_string($date) === false || $date === '') {
		return false;
	}
	$dateTime = DateTime::createFromFormat('Y-m-d', $date);
	return $dateTime instanceof DateTime && $dateTime->format('Y-m-d') === $date;
}

/**
 * 予約DB helper用整数値正規化
 *  intまたはASCII数字文字列をPHP整数範囲内で検証する
 */
function normalizeReservationDbIntegerForReservations($value, $min = null, $max = null)
{
	if (is_int($value) === true) {
		$normalizedValue = $value;
	} elseif (is_string($value) === true && preg_match('/\A[0-9]+\z/D', $value) === 1) {
		$digits = ltrim($value, '0');
		$digits = $digits === '' ? '0' : $digits;
		$phpIntMax = (string)PHP_INT_MAX;
		if (strlen($digits) > strlen($phpIntMax) || (strlen($digits) === strlen($phpIntMax) && strcmp($digits, $phpIntMax) > 0)) {
			return null;
		}
		$normalizedValue = (int)$digits;
	} else {
		return null;
	}

	if (($min !== null && $normalizedValue < $min) || ($max !== null && $normalizedValue > $max)) {
		return null;
	}
	return $normalizedValue;
}

/**
 * 予約DB helper用日時文字列確認
 *  Asia/TokyoのY-m-d H:i:s形式の実在日時だけを有効とする
 */
function isReservationDbDateTimeStringForReservations($value)
{
	if (is_string($value) === false || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
		return false;
	}
	$dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Tokyo'));
	return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d H:i:s') === $value;
}
