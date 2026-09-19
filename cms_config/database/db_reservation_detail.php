<?php
/*
 * [予約詳細参照]
 */

/**
 * 予約詳細参照用整数値正規化
 *  intまたはASCII数字文字列をPHP整数範囲内で検証する
 */
function normalizeReservationDetailReadInteger($value, $min = null, $max = null)
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
 * 予約詳細参照用日付確認
 *  Y-m-d形式の実在日だけを有効とする
 */
function isReservationDetailReadDateString($value)
{
	if (is_string($value) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
		return false;
	}
	$dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
	return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d') === $value;
}

/**
 * 予約詳細参照用日時確認
 *  Y-m-d H:i:s形式の実在日時だけを有効とする
 */
function isReservationDetailReadDateTimeString($value)
{
	if (is_string($value) === false || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
		return false;
	}
	$dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Tokyo'));
	return $dateTime instanceof DateTimeImmutable && $dateTime->format('Y-m-d H:i:s') === $value;
}

/**
 * 予約詳細本体取得
 *  reservation_idとshop_idのownershipが一致する予約だけを返す
 */
function getReservationDetail($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDetailReadInteger($shopId, 1, null);
		$reservationId = normalizeReservationDetailReadInteger($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return null;
		}

		$strSQL = "
			SELECT
				r.id,
				r.shop_id,
				r.reservation_date,
				r.party_size,
				r.customer_name,
				r.customer_kana,
				r.customer_tel,
				r.customer_email,
				r.accommodation_name,
				r.customer_note,
				r.shop_memo,
				r.reservation_route,
				r.status,
				r.cancelled_at,
				r.created_at,
				r.updated_at
			FROM
				reservations r
			WHERE
				r.id = :reservation_id
				AND r.shop_id = :shop_id
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

		$normalizedReservationId = normalizeReservationDetailReadInteger($reservation['id'] ?? null, 1, null);
		$normalizedShopId = normalizeReservationDetailReadInteger($reservation['shop_id'] ?? null, 1, null);
		$partySize = normalizeReservationDetailReadInteger($reservation['party_size'] ?? null, 1, 4);
		$reservationRoute = normalizeReservationDetailReadInteger($reservation['reservation_route'] ?? null, 1, 3);
		$status = normalizeReservationDetailReadInteger($reservation['status'] ?? null, 1, 4);
		$requiredStringColumns = ['reservation_date', 'customer_name', 'customer_kana', 'customer_tel', 'created_at', 'updated_at'];
		$nullableStringColumns = ['customer_email', 'accommodation_name', 'customer_note', 'shop_memo', 'cancelled_at'];
		if (
			$normalizedReservationId !== $reservationId ||
			$normalizedShopId !== $shopId ||
			$partySize === null ||
			$reservationRoute === null ||
			$status === null ||
			isReservationDetailReadDateString($reservation['reservation_date'] ?? null) === false ||
			isReservationDetailReadDateTimeString($reservation['created_at'] ?? null) === false ||
			isReservationDetailReadDateTimeString($reservation['updated_at'] ?? null) === false ||
			(($reservation['cancelled_at'] ?? null) !== null && isReservationDetailReadDateTimeString($reservation['cancelled_at']) === false)
		) {
			return false;
		}
		foreach ($requiredStringColumns as $column) {
			if (array_key_exists($column, $reservation) === false || is_string($reservation[$column]) === false) {
				return false;
			}
		}
		foreach ($nullableStringColumns as $column) {
			if (array_key_exists($column, $reservation) === false || ($reservation[$column] !== null && is_string($reservation[$column]) === false)) {
				return false;
			}
		}

		$reservation['id'] = $normalizedReservationId;
		$reservation['shop_id'] = $normalizedShopId;
		$reservation['party_size'] = $partySize;
		$reservation['reservation_route'] = $reservationRoute;
		$reservation['status'] = $status;
		return $reservation;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細席一覧取得
 *  reservationsでshop ownershipを再確認し席名snapshotを保存順で返す
 */
function getReservationDetailSeatRows($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDetailReadInteger($shopId, 1, null);
		$reservationId = normalizeReservationDetailReadInteger($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return false;
		}

		$strSQL = "
			SELECT
				rs.id AS reservation_seat_id,
				rs.reservation_id,
				rs.seat_id,
				rs.seat_name_snapshot
			FROM
				reservations r
				INNER JOIN reservation_seats rs ON r.id = rs.reservation_id
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
		if (is_array($rows) === false) {
			return false;
		}

		$normalizedRows = [];
		$reservationSeatIds = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				return false;
			}
			$reservationSeatId = normalizeReservationDetailReadInteger($row['reservation_seat_id'] ?? null, 1, null);
			$rowReservationId = normalizeReservationDetailReadInteger($row['reservation_id'] ?? null, 1, null);
			$seatId = normalizeReservationDetailReadInteger($row['seat_id'] ?? null, 1, null);
			if (
				$reservationSeatId === null ||
				$rowReservationId !== $reservationId ||
				$seatId === null ||
				array_key_exists('seat_name_snapshot', $row) === false ||
				is_string($row['seat_name_snapshot']) === false ||
				isset($reservationSeatIds[$reservationSeatId]) === true
			) {
				return false;
			}
			$reservationSeatIds[$reservationSeatId] = true;
			$normalizedRows[] = [
				'reservation_seat_id' => $reservationSeatId,
				'reservation_id' => $rowReservationId,
				'seat_id' => $seatId,
				'seat_name_snapshot' => $row['seat_name_snapshot'],
			];
		}

		return $normalizedRows;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約詳細メニュー一覧取得
 *  reservationsでshop ownershipを再確認し保存済みsnapshotだけをguest_no順で返す
 */
function getReservationDetailMenuRows($shopId = null, $reservationId = null)
{
	global $DB_CONNECT;
	try {
		$shopId = normalizeReservationDetailReadInteger($shopId, 1, null);
		$reservationId = normalizeReservationDetailReadInteger($reservationId, 1, null);
		if ($shopId === null || $reservationId === null) {
			return false;
		}

		$strSQL = "
			SELECT
				rm.id AS reservation_menu_id,
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
		if (is_array($rows) === false) {
			return false;
		}

		$normalizedRows = [];
		$reservationMenuIds = [];
		$guestNos = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				return false;
			}
			$reservationMenuId = normalizeReservationDetailReadInteger($row['reservation_menu_id'] ?? null, 1, null);
			$rowReservationId = normalizeReservationDetailReadInteger($row['reservation_id'] ?? null, 1, null);
			$partySize = normalizeReservationDetailReadInteger($row['party_size'] ?? null, 1, 4);
			$guestNo = normalizeReservationDetailReadInteger($row['guest_no'] ?? null, 1, null);
			$menuId = $row['menu_id'] ?? null;
			if ($menuId !== null) {
				$menuId = normalizeReservationDetailReadInteger($menuId, 1, null);
			}
			$menuPrice = normalizeReservationDetailReadInteger($row['menu_price_snapshot'] ?? null, 0, null);
			$taxIncluded = normalizeReservationDetailReadInteger($row['tax_included_snapshot'] ?? null, 0, 1);
			if (
				$reservationMenuId === null ||
				$rowReservationId !== $reservationId ||
				$partySize === null ||
				$guestNo === null ||
				$guestNo > $partySize ||
				(($row['menu_id'] ?? null) !== null && $menuId === null) ||
				array_key_exists('menu_name_snapshot', $row) === false ||
				is_string($row['menu_name_snapshot']) === false ||
				$menuPrice === null ||
				$taxIncluded === null ||
				isset($reservationMenuIds[$reservationMenuId]) === true ||
				isset($guestNos[$guestNo]) === true
			) {
				return false;
			}
			$reservationMenuIds[$reservationMenuId] = true;
			$guestNos[$guestNo] = true;
			$normalizedRows[] = [
				'reservation_menu_id' => $reservationMenuId,
				'reservation_id' => $rowReservationId,
				'guest_no' => $guestNo,
				'menu_id' => $menuId,
				'menu_name_snapshot' => $row['menu_name_snapshot'],
				'menu_price_snapshot' => $menuPrice,
				'tax_included_snapshot' => $taxIncluded,
			];
		}

		return $normalizedRows;
	} catch (PDOException $e) {
		return false;
	}
}
