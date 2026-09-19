<?php
/*
 * [予約カレンダー・選択日参照共通処理]
 */

/**
 * 予約参照対象月の正規化
 *  YYYY-MM形式の実在月だけを返す
 */
function normalizeReservationReadTargetMonth($targetMonth)
{
	if (is_string($targetMonth) === false || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/D', $targetMonth) !== 1) {
		return null;
	}
	[$year, $monthNumber] = array_map('intval', explode('-', $targetMonth));
	if (checkdate($monthNumber, 1, $year) === false) {
		return null;
	}
	$month = DateTimeImmutable::createFromFormat('!Y-m-d', $targetMonth . '-01', new DateTimeZone('Asia/Tokyo'));
	return $month instanceof DateTimeImmutable && $month->format('Y-m') === $targetMonth ? $targetMonth : null;
}

/**
 * 予約参照対象日の正規化
 *  YYYY-MM-DD形式の実在日だけを返す
 */
function normalizeReservationReadSelectedDate($selectedDate)
{
	if (is_string($selectedDate) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $selectedDate) !== 1) {
		return null;
	}
	[$year, $monthNumber, $day] = array_map('intval', explode('-', $selectedDate));
	if (checkdate($monthNumber, $day, $year) === false) {
		return null;
	}
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate, new DateTimeZone('Asia/Tokyo'));
	return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $selectedDate ? $selectedDate : null;
}

/**
 * 月間カレンダーgrid範囲生成
 *  日曜開始で5週月は35日、6週月は42日を返す
 */
function buildReservationReadCalendarRange($targetMonth)
{
	$targetMonth = normalizeReservationReadTargetMonth($targetMonth);
	if ($targetMonth === null) {
		return null;
	}

	$timezone = new DateTimeZone('Asia/Tokyo');
	$firstDate = DateTimeImmutable::createFromFormat('!Y-m-d', $targetMonth . '-01', $timezone);
	if ($firstDate instanceof DateTimeImmutable === false) {
		return null;
	}
	$leadingDays = (int)$firstDate->format('w');
	$cellCount = $leadingDays + (int)$firstDate->format('t') > 35 ? 42 : 35;
	$gridStartDate = $leadingDays > 0 ? $firstDate->modify('-' . $leadingDays . ' days') : $firstDate;
	$dates = [];
	for ($index = 0; $index < $cellCount; $index++) {
		$dates[] = $gridStartDate->modify('+' . $index . ' days')->format('Y-m-d');
	}

	return [
		'target_month' => $targetMonth,
		'grid_start_date' => $dates[0],
		'grid_end_date' => $dates[$cellCount - 1],
		'cell_count' => $cellCount,
		'dates' => $dates,
	];
}

/**
 * 店舗全体の予約受付可否生成
 *  日単位availabilityとは分離して不可理由と人数範囲を返す
 */
function buildReservationReadShopEligibility($shop, $settings)
{
	if (is_array($shop) === false) {
		return false;
	}
	if (($shop['shop_type'] ?? null) !== 'food') {
		return ['eligible' => false, 'reason' => 'shop_type', 'guest_min' => null, 'guest_max' => null];
	}
	if ((int)($shop['is_active'] ?? 0) !== 1) {
		return ['eligible' => false, 'reason' => 'shop_inactive', 'guest_min' => null, 'guest_max' => null];
	}
	if ($settings === null) {
		return ['eligible' => false, 'reason' => 'settings_missing', 'guest_min' => null, 'guest_max' => null];
	}
	if (is_array($settings) === false) {
		return false;
	}

	$reservationEnabled = normalizeReservationRegistrationInteger($settings['reservation_enabled'] ?? null, 0, 1);
	if ($reservationEnabled === null) {
		return ['eligible' => false, 'reason' => 'settings_invalid', 'guest_min' => null, 'guest_max' => null];
	}
	$guestMin = normalizeReservationRegistrationInteger($settings['guest_min'] ?? null, 1, 4);
	$guestMax = normalizeReservationRegistrationInteger($settings['guest_max'] ?? null, 1, 4);
	if ($guestMin === null || $guestMax === null || $guestMin > $guestMax) {
		return ['eligible' => false, 'reason' => 'settings_invalid', 'guest_min' => null, 'guest_max' => null];
	}
	if ($reservationEnabled !== 1) {
		return ['eligible' => false, 'reason' => 'reservation_disabled', 'guest_min' => $guestMin, 'guest_max' => $guestMax];
	}

	return ['eligible' => true, 'reason' => null, 'guest_min' => $guestMin, 'guest_max' => $guestMax];
}

/**
 * 予約・席・menu行の予約単位集約
 *  結合済みbase行を予約IDへまとめ、base外の後続menu行は除外する
 */
function buildReservationReadReservationIndex($reservationSeatRows, $menuRows = [])
{
	if (is_array($reservationSeatRows) === false || is_array($menuRows) === false) {
		return false;
	}

	$reservationsByDate = [];
	$reservationDatesById = [];
	$reservationSeatIds = [];
	$reservationSeatIdsByReservation = [];
	$seatlessReservationIds = [];
	$tempSeatCounts = [];
	$tempSeatIdsByReservation = [];
	$normalSeatCounts = [];
	foreach ($reservationSeatRows as $row) {
		if (is_array($row) === false) {
			return false;
		}
		$reservationId = normalizeReservationRegistrationInteger($row['reservation_id'] ?? null, 1, null);
		$reservationDate = normalizeReservationReadSelectedDate($row['reservation_date'] ?? null);
		$partySize = normalizeReservationRegistrationInteger($row['party_size'] ?? null, 1, 4);
		$status = normalizeReservationRegistrationInteger($row['status'] ?? null, 1, 4);
		$reservationRoute = normalizeReservationRegistrationInteger($row['reservation_route'] ?? null, 1, 3);
		if ($reservationId === null || $reservationDate === null || $partySize === null || $status === null || $reservationRoute === null) {
			return false;
		}

		$customerName = (string)($row['customer_name'] ?? '');
		$customerTel = (string)($row['customer_tel'] ?? '');
		if (isset($reservationDatesById[$reservationId]) === false) {
			$reservationDatesById[$reservationId] = $reservationDate;
			$reservationsByDate[$reservationDate][$reservationId] = [
				'reservation_id' => $reservationId,
				'party_size' => $partySize,
				'customer_name' => $customerName,
				'customer_tel' => $customerTel,
				'reservation_route' => $reservationRoute,
				'status' => $status,
				'seat_ids' => [],
				'seats' => [],
				'has_temp_move' => false,
				'seat_data_error' => false,
				'seat_data_error_types' => [],
				'duplicate_temp_recoverable' => false,
				'menus' => [],
			];
			$reservationSeatIdsByReservation[$reservationId] = [];
			$tempSeatCounts[$reservationId] = 0;
			$tempSeatIdsByReservation[$reservationId] = [];
			$normalSeatCounts[$reservationId] = 0;
		} else {
			$baseReservation = $reservationsByDate[$reservationDatesById[$reservationId]][$reservationId] ?? null;
			if (
				is_array($baseReservation) === false ||
				$reservationDatesById[$reservationId] !== $reservationDate ||
				$baseReservation['party_size'] !== $partySize ||
				$baseReservation['customer_name'] !== $customerName ||
				$baseReservation['customer_tel'] !== $customerTel ||
				$baseReservation['reservation_route'] !== $reservationRoute ||
				$baseReservation['status'] !== $status
			) {
				return false;
			}
		}

		$reservationSeatIdValue = $row['reservation_seat_id'] ?? null;
		$seatIdValue = $row['seat_id'] ?? null;
		if ($reservationSeatIdValue === null && $seatIdValue === null) {
			if (
				($row['seat_name_snapshot'] ?? null) !== null ||
				($row['matched_seat_id'] ?? null) !== null ||
				($row['seat_is_temp_move'] ?? null) !== null ||
				($row['seat_type'] ?? null) !== null ||
				($row['seat_counter_area'] ?? null) !== null ||
				isset($seatlessReservationIds[$reservationId]) === true ||
				empty($reservationsByDate[$reservationDate][$reservationId]['seats']) === false
			) {
				$reservationsByDate[$reservationDate][$reservationId]['seat_data_error'] = true;
			}
			$seatlessReservationIds[$reservationId] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error'] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error_types']['missing_normal_seat'] = true;
			continue;
		}

		$reservationSeatId = normalizeReservationRegistrationInteger($reservationSeatIdValue, 1, null);
		$seatId = normalizeReservationRegistrationInteger($seatIdValue, 1, null);
		if ($reservationSeatId === null || isset($reservationSeatIds[$reservationSeatId]) === true) {
			return false;
		}
		$reservationSeatIds[$reservationSeatId] = true;
		if ($seatId === null) {
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error'] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error_types']['invalid_seat_reference'] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seats'][] = [
				'reservation_seat_id' => $reservationSeatId,
				'seat_id' => 0,
				'seat_name_snapshot' => (string)($row['seat_name_snapshot'] ?? ''),
				'seat_is_temp_move' => null,
				'seat_type' => null,
				'seat_counter_area' => null,
			];
			continue;
		}
		$matchedSeatId = normalizeReservationRegistrationInteger($row['matched_seat_id'] ?? null, 1, null);
		$seatIsTempMove = normalizeReservationRegistrationInteger($row['seat_is_temp_move'] ?? null, 0, 1);
		$seatType = normalizeReservationRegistrationInteger($row['seat_type'] ?? null, 1, 2);
		$seatCounterArea = $row['seat_counter_area'] ?? null;
		$seatMatchesShop = $matchedSeatId !== null && $matchedSeatId === $seatId && $seatIsTempMove !== null;
		if (isset($seatlessReservationIds[$reservationId]) === true || $seatMatchesShop === false) {
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error'] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seat_data_error_types']['invalid_seat_reference'] = true;
		}
		if (isset($reservationSeatIdsByReservation[$reservationId][$seatId]) === true) {
			if ($seatMatchesShop === false || $seatIsTempMove !== 1) {
				$reservationsByDate[$reservationDate][$reservationId]['seat_data_error'] = true;
				$reservationsByDate[$reservationDate][$reservationId]['seat_data_error_types']['duplicate_normal_seat'] = true;
			}
		} else {
			$reservationSeatIdsByReservation[$reservationId][$seatId] = true;
			$reservationsByDate[$reservationDate][$reservationId]['seat_ids'][] = $seatId;
		}
		$reservationsByDate[$reservationDate][$reservationId]['seats'][] = [
			'reservation_seat_id' => $reservationSeatId,
			'seat_id' => $seatId,
			'seat_name_snapshot' => (string)($row['seat_name_snapshot'] ?? ''),
			'seat_is_temp_move' => $seatMatchesShop ? $seatIsTempMove : null,
			'seat_type' => $seatMatchesShop ? $seatType : null,
			'seat_counter_area' => $seatMatchesShop ? $seatCounterArea : null,
		];
		if ($seatMatchesShop === true && $seatIsTempMove === 1) {
			$tempSeatCounts[$reservationId]++;
			$tempSeatIdsByReservation[$reservationId][$seatId] = true;
			$reservationsByDate[$reservationDate][$reservationId]['has_temp_move'] = true;
		} elseif ($seatMatchesShop === true && $seatIsTempMove === 0) {
			$normalSeatCounts[$reservationId]++;
		}
	}

	$reservationMenuIds = [];
	foreach ($menuRows as $row) {
		if (is_array($row) === false) {
			return false;
		}
		$reservationId = normalizeReservationRegistrationInteger($row['reservation_id'] ?? null, 1, null);
		$reservationDate = normalizeReservationReadSelectedDate($row['reservation_date'] ?? null);
		$reservationMenuId = normalizeReservationRegistrationInteger($row['reservation_menu_id'] ?? null, 1, null);
		$guestNo = normalizeReservationRegistrationInteger($row['guest_no'] ?? null, 1, 4);
		if ($reservationId === null || $reservationDate === null || $reservationMenuId === null || $guestNo === null || isset($reservationMenuIds[$reservationMenuId]) === true) {
			return false;
		}
		$reservationMenuIds[$reservationMenuId] = true;
		if (isset($reservationDatesById[$reservationId]) === false) {
			continue;
		}

		if ($reservationDate !== $reservationDatesById[$reservationId]) {
			return false;
		}
		$reservationsByDate[$reservationDate][$reservationId]['menus'][] = [
			'reservation_menu_id' => $reservationMenuId,
			'guest_no' => $guestNo,
			'menu_name_snapshot' => (string)($row['menu_name_snapshot'] ?? ''),
			'menu_price_snapshot' => (int)($row['menu_price_snapshot'] ?? 0),
			'tax_included_snapshot' => (int)($row['tax_included_snapshot'] ?? 0),
		];
	}

	foreach ($reservationsByDate as &$reservations) {
		foreach ($reservations as &$reservation) {
			$reservationId = (int)($reservation['reservation_id'] ?? 0);
			if (($normalSeatCounts[$reservationId] ?? 0) < 1) {
				$reservation['seat_data_error'] = true;
				$reservation['seat_data_error_types']['missing_normal_seat'] = true;
			}
			if (($tempSeatCounts[$reservationId] ?? 0) > 1) {
				$reservation['seat_data_error'] = true;
				$reservation['seat_data_error_types']['duplicate_temp'] = true;
				if (count($tempSeatIdsByReservation[$reservationId] ?? []) !== 1) {
					$reservation['seat_data_error_types']['multiple_temp_masters'] = true;
				}
			}
			$reservation['seat_data_error_types'] = array_keys($reservation['seat_data_error_types']);
			$reservation['duplicate_temp_recoverable'] = $reservation['seat_data_error_types'] === ['duplicate_temp']
				&& ($normalSeatCounts[$reservationId] ?? 0) > 0
				&& ($tempSeatCounts[$reservationId] ?? 0) > 1;
			usort($reservation['seats'], function ($left, $right) {
				return (int)($left['reservation_seat_id'] ?? 0) <=> (int)($right['reservation_seat_id'] ?? 0);
			});
			usort($reservation['menus'], function ($left, $right) {
				$guestNoDiff = (int)($left['guest_no'] ?? 0) <=> (int)($right['guest_no'] ?? 0);
				return $guestNoDiff !== 0
					? $guestNoDiff
					: (int)($left['reservation_menu_id'] ?? 0) <=> (int)($right['reservation_menu_id'] ?? 0);
			});
		}
		unset($reservation);
	}
	unset($reservations);

	return $reservationsByDate;
}

/**
 * calendar overrideの日付index生成
 *  status_type 1～3を日付ごとに一意に保持する
 */
function buildReservationReadOverrideIndex($overrideRows)
{
	if (is_array($overrideRows) === false) {
		return false;
	}
	$overridesByDate = [];
	foreach ($overrideRows as $row) {
		$targetDate = normalizeReservationReadSelectedDate($row['target_date'] ?? null);
		$statusType = normalizeReservationRegistrationInteger($row['status_type'] ?? null, 1, 3);
		if ($targetDate === null || $statusType === null || isset($overridesByDate[$targetDate]) === true) {
			return false;
		}
		$overridesByDate[$targetDate] = $statusType;
	}
	return $overridesByDate;
}

/**
 * 席masterのoccupancy index生成
 *  C1 simulateへ渡すseatsById形式へ正規化する
 */
function buildReservationReadSeatIndex($seatRows)
{
	if (is_array($seatRows) === false) {
		return false;
	}
	$seatsById = [];
	foreach ($seatRows as $seatRow) {
		$seatId = (int)($seatRow['id'] ?? 0);
		if ($seatId < 1 || isset($seatsById[$seatId]) === true) {
			return false;
		}
		$seatsById[$seatId] = [
			'id' => $seatId,
			'name' => (string)($seatRow['name'] ?? ''),
			'type' => (int)($seatRow['type'] ?? 0),
			'capacity' => (int)($seatRow['capacity'] ?? 0),
			'counter_area' => $seatRow['counter_area'] ?? null,
			'sort_order' => (int)($seatRow['sort_order'] ?? 0),
			'is_active' => (int)($seatRow['is_active'] ?? 0),
			'is_temp_move' => (int)($seatRow['is_temp_move'] ?? 0),
		];
	}
	return $seatsById;
}

/**
 * Step3-C用occupancy baseline生成
 *  bulk取得済みデータをFROZEN C1のsimulate state形式へ変換する
 */
function buildReservationReadOccupancyState($shopId, $date, $regularHolidays, $seatsById, $reservations, $overrideStatus)
{
	if (is_numeric($shopId) === false || (int)$shopId < 1 || normalizeReservationReadSelectedDate($date) === null || is_array($regularHolidays) === false || is_array($seatsById) === false || is_array($reservations) === false) {
		return null;
	}
	if ($overrideStatus !== null && normalizeReservationRegistrationInteger($overrideStatus, 1, 3) === null) {
		return null;
	}

	$reservationsById = [];
	foreach ($reservations as $reservationId => $reservation) {
		if (is_array($reservation) === false || is_numeric($reservationId) === false || (int)$reservationId < 1) {
			return null;
		}
		$reservationsById[(int)$reservationId] = [
			'reservation_id' => (int)$reservationId,
			'party_size' => (int)($reservation['party_size'] ?? 0),
			'status' => (int)($reservation['status'] ?? 0),
			'seat_ids' => array_values(array_map('intval', $reservation['seat_ids'] ?? [])),
			'has_temp_move' => (bool)($reservation['has_temp_move'] ?? false),
			'is_synthetic' => false,
		];
	}

	return [
		'shop_id' => (int)$shopId,
		'reservation_date' => $date,
		'regular_holidays' => array_values($regularHolidays),
		'calendar_override_status_type' => $overrideStatus === null ? null : (int)$overrideStatus,
		'seatsById' => $seatsById,
		'reservationsById' => $reservationsById,
		'nextSyntheticReservationId' => -1,
	];
}

/**
 * 日単位availability判定
 *  営業日ではguest_min～guest_maxを同一baselineから独立評価する
 */
function evaluateReservationReadAvailability($occupancyState, $shopEligibility)
{
	if (is_array($occupancyState) === false || is_array($shopEligibility) === false) {
		return false;
	}
	$shopId = $occupancyState['shop_id'] ?? null;
	$date = $occupancyState['reservation_date'] ?? null;
	if (isValidReservationOccupancyState($shopId, $date, $occupancyState) === false) {
		return false;
	}

	$guestMin = ($shopEligibility['eligible'] ?? false) === true ? ($shopEligibility['guest_min'] ?? null) : 1;
	$guestMax = ($shopEligibility['eligible'] ?? false) === true ? ($shopEligibility['guest_max'] ?? null) : 1;
	if (normalizeReservationRegistrationInteger($guestMin, 1, 4) === null || normalizeReservationRegistrationInteger($guestMax, 1, 4) === null || (int)$guestMin > (int)$guestMax) {
		return false;
	}

	for ($partySize = (int)$guestMin; $partySize <= (int)$guestMax; $partySize++) {
		$assignment = checkAndAssignSeat($shopId, $date, $partySize, 'simulate', $occupancyState);
		$reason = $assignment['reason'] ?? null;
		if ($reason === 'acceptance_stopped') {
			return ['status' => 'stopped', 'reason' => 'acceptance_stopped'];
		}
		if ($reason === 'shop_holiday') {
			return ['status' => 'holiday', 'reason' => 'shop_holiday'];
		}
		if ($reason === 'regular_holiday') {
			return ['status' => 'holiday', 'reason' => 'regular_holiday'];
		}
		if (($shopEligibility['eligible'] ?? false) !== true) {
			return $reason === 'full' || ($assignment['assignable'] ?? false) === true
				? ['status' => 'normal', 'reason' => null]
				: false;
		}
		if (($assignment['assignable'] ?? false) === true) {
			return ['status' => 'normal', 'reason' => null];
		}
		if ($reason !== 'full') {
			return false;
		}
	}

	return ['status' => 'full', 'reason' => 'full'];
}

/**
 * 予約参照用日別データ集約
 *  bulk取得済み行からavailability・件数・人数・予約一覧を生成する
 */
function buildReservationReadDays($shopId, $dates, $shop, $settings, $seatRows, $reservationWithSeatRows, $overrideRows, $menuRows = [])
{
	if (is_numeric($shopId) === false || (int)$shopId < 1 || is_array($dates) === false || empty($dates) === true || is_array($shop) === false) {
		return false;
	}
	$shopEligibility = buildReservationReadShopEligibility($shop, $settings);
	$seatsById = buildReservationReadSeatIndex($seatRows);
	$reservationsByDate = buildReservationReadReservationIndex($reservationWithSeatRows, $menuRows);
	$overridesByDate = buildReservationReadOverrideIndex($overrideRows);
	if ($shopEligibility === false || $seatsById === false || $reservationsByDate === false || $overridesByDate === false) {
		return false;
	}

	$regularHolidays = normalizeRegularHolidays($shop['closed_weekdays'] ?? null);
	$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
	$days = [];
	foreach ($dates as $date) {
		$date = normalizeReservationReadSelectedDate($date);
		if ($date === null || isset($days[$date]) === true) {
			return false;
		}
		$reservations = $reservationsByDate[$date] ?? [];
		$occupancyState = buildReservationReadOccupancyState(
			(int)$shopId,
			$date,
			$regularHolidays,
			$seatsById,
			$reservations,
			$overridesByDate[$date] ?? null
		);
		$availability = evaluateReservationReadAvailability($occupancyState, $shopEligibility);
		if ($availability === false) {
			return false;
		}

		foreach ($reservations as $reservationId => &$reservation) {
			$reservation['seat_change_date_allowed'] = $date >= $today;
			if (($reservation['seat_data_error'] ?? false) === true) {
				$reservation['seat_change_candidates'] = [];
				$reservation['seat_change_version'] = '';
				continue;
			}
			$candidateState = $occupancyState;
			if (($reservation['has_temp_move'] ?? false) === true && isset($candidateState['reservationsById'][$reservationId])) {
				$candidateState['reservationsById'][$reservationId]['has_temp_move'] = false;
			}
			$candidates = buildReservationSeatChangeCandidates($candidateState, $reservationId);
			$version = makeReservationSeatChangeVersion(
				$reservationId,
				$date,
				$reservation['party_size'] ?? null,
				$reservation['status'] ?? null,
				$reservation['seat_ids'] ?? [],
				(bool)($reservation['has_temp_move'] ?? false)
			);
			if ($candidates === null || $version === null) {
				unset($reservation);
				return false;
			}
			$reservation['seat_change_candidates'] = $date < $today ? [] : $candidates;
			$reservation['seat_change_version'] = $version;
		}
		unset($reservation);

		$reservationCount = 0;
		$guestCount = 0;
		foreach ($reservations as $reservation) {
			if (isReservationOccupiedStatus($reservation['status'] ?? null) === true) {
				$reservationCount++;
				$guestCount += (int)($reservation['party_size'] ?? 0);
			}
		}
		$days[$date] = [
			'date' => $date,
			'availability_status' => $availability['status'],
			'availability_reason' => $availability['reason'],
			'reservation_count' => $reservationCount,
			'guest_count' => $guestCount,
			'reservations' => array_values($reservations),
		];
	}

	return [
		'shop_eligibility' => $shopEligibility,
		'days' => $days,
	];
}
