<?php
/*
 * [飲食店予約共通処理]
 */
require_once __DIR__ . '/set_food_menu_function.php';

/**
 * 予約者の氏名・ふりがなを保存形式へ正規化
 *  Unicode空白を半角スペース1文字へ揃え、姓名区切りを必須とする
 */
function normalizeReservationCustomerIdentityValue($value)
{
	if (is_string($value) === false || preg_match('//u', $value) !== 1) {
		return null;
	}
	$controlCharacterResult = preg_match('/\p{Cc}/u', $value);
	if ($controlCharacterResult !== 0) {
		return null;
	}
	$normalizedValue = preg_replace('/[\s\p{Z}\x{FEFF}]+/u', ' ', $value);
	if (is_string($normalizedValue) === false) {
		return null;
	}
	$normalizedValue = trim($normalizedValue, ' ');
	$length = function_exists('mb_strlen') === true
		? mb_strlen($normalizedValue, 'UTF-8')
		: strlen($normalizedValue);
	if (
		$length < 3 ||
		$length > 101 ||
		preg_match('/\A[^ ]+ [^ ]+(?: [^ ]+)*\z/uD', $normalizedValue) !== 1
	) {
		return null;
	}
	return $normalizedValue;
}

/**
 * 定休日曜日配列を正規化
 *  異常値は予約受付安全性を優先して全曜日定休日扱いにする
 */
function normalizeRegularHolidays($closedWeekdays)
{
	if (is_string($closedWeekdays) === true) {
		$decoded = json_decode($closedWeekdays, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			return [0, 1, 2, 3, 4, 5, 6];
		}
		$closedWeekdays = $decoded;
	}

	if (is_array($closedWeekdays) === false) {
		return [0, 1, 2, 3, 4, 5, 6];
	}

	$holidays = [];
	foreach ($closedWeekdays as $weekday) {
		if (is_int($weekday) === false && (is_string($weekday) === false || ctype_digit($weekday) === false)) {
			continue;
		}
		$weekday = (int)$weekday;
		if ($weekday < 0 || $weekday > 6) {
			continue;
		}
		$holidays[$weekday] = $weekday;
	}

	if (count($closedWeekdays) > 0 && count($holidays) === 0) {
		return [0, 1, 2, 3, 4, 5, 6];
	}

	$holidays = array_values($holidays);
	sort($holidays, SORT_NUMERIC);
	return $holidays;
}

/**
 * 予約ステータスが席占有対象か判定
 *  status=1/2のみ席占有として扱う
 */
function isReservationOccupiedStatus($status)
{
	if (is_numeric($status) === false) {
		return false;
	}
	return in_array((int)$status, [1, 2], true);
}

/**
 * 予約ステータス遷移可否を判定
 *  キャンセル系から占有系への復帰は禁止する
 */
function isAllowedReservationStatusTransition($from, $to)
{
	if (is_numeric($from) === false || is_numeric($to) === false) {
		return false;
	}

	$from = (int)$from;
	$to = (int)$to;
	$allowedStatuses = [1, 2, 3, 4];
	if (in_array($from, $allowedStatuses, true) === false || in_array($to, $allowedStatuses, true) === false) {
		return false;
	}
	if ($from === $to) {
		return true;
	}

	$allowedTransitions = [
		1 => [2, 3, 4],
		2 => [1, 3, 4],
		3 => [4],
		4 => [3],
	];

	return isset($allowedTransitions[$from]) === true && in_array($to, $allowedTransitions[$from], true) === true;
}

/**
 * 店舗の予約受付利用可否を判定
 *  DB取得は各database helperへ委譲する
 */
function isReservationEnabledForShop($shopId)
{
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return false;
	}
	$shopId = (int)$shopId;

	if (
		function_exists('getReservationShopForOccupancy') === false ||
		function_exists('getShopReservationSettings') === false ||
		function_exists('countActiveFoodMenusForReservation') === false ||
		function_exists('countActiveNormalSeatsForReservation') === false
	) {
		return false;
	}

	$shop = getReservationShopForOccupancy($shopId);
	if (empty($shop) === true) {
		return false;
	}

	if (($shop['shop_type'] ?? '') !== 'food') {
		return false;
	}
	if ((int)($shop['is_active'] ?? 0) !== 1) {
		return false;
	}
	if ((int)($shop['is_public'] ?? 0) !== 1) {
		return false;
	}

	$settings = getShopReservationSettings($shopId);
	if (empty($settings) === true || (int)($settings['reservation_enabled'] ?? 0) !== 1) {
		return false;
	}

	if ((int)($settings['menu_selection_type'] ?? 0) === 2 && countActiveFoodMenusForReservation($shopId) < 1) {
		return false;
	}

	if (countActiveNormalSeatsForReservation($shopId) < 1) {
		return false;
	}

	return true;
}

/**
 * DBから予約占有stateを構築
 *  commitモード用に対象日の席と予約席を正規化する
 */
function buildReservationOccupancyStateFromDb($shopId, $date)
{
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationDateString($date) === false) {
		return null;
	}
	if (
		function_exists('getReservationShopForOccupancy') === false ||
		function_exists('getSeatsForReservationOccupancy') === false ||
		function_exists('getReservationsForOccupancy') === false ||
		function_exists('getReservationCalendarOverride') === false
	) {
		return null;
	}

	$shopId = (int)$shopId;
	$shop = getReservationShopForOccupancy($shopId);
	$override = getReservationCalendarOverride($shopId, $date);
	$seatRows = getSeatsForReservationOccupancy($shopId);
	$reservationRows = getReservationsForOccupancy($shopId, $date);

	if (empty($shop) === true || $override === false || $seatRows === false || $reservationRows === false) {
		return null;
	}

	$state = [
		'shop_id' => $shopId,
		'reservation_date' => $date,
		'regular_holidays' => normalizeRegularHolidays($shop['closed_weekdays'] ?? null),
		'calendar_override_status_type' => empty($override) === true ? null : (int)$override['status_type'],
		'seatsById' => [],
		'reservationsById' => [],
		'nextSyntheticReservationId' => -1,
	];

	foreach ($seatRows as $seatRow) {
		$seatId = (int)($seatRow['id'] ?? 0);
		if ($seatId < 1) {
			continue;
		}
		$state['seatsById'][$seatId] = [
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

	foreach ($reservationRows as $row) {
		$reservationId = (int)($row['reservation_id'] ?? 0);
		if ($reservationId < 1) {
			continue;
		}
		if (isset($state['reservationsById'][$reservationId]) === false) {
			$state['reservationsById'][$reservationId] = [
				'reservation_id' => $reservationId,
				'party_size' => (int)($row['party_size'] ?? 0),
				'status' => (int)($row['status'] ?? 0),
				'seat_ids' => [],
				'has_temp_move' => false,
				'is_synthetic' => false,
			];
		}

		$seatId = isset($row['seat_id']) ? (int)$row['seat_id'] : 0;
		if ($seatId < 1) {
			continue;
		}
		$state['reservationsById'][$reservationId]['seat_ids'][] = $seatId;
		$matchedSeatId = isset($row['matched_seat_id']) ? (int)$row['matched_seat_id'] : 0;
		if ($matchedSeatId === $seatId && (int)($row['seat_is_temp_move'] ?? 0) === 1) {
			$state['reservationsById'][$reservationId]['has_temp_move'] = true;
		}
	}

	return $state;
}

/**
 * 席変更競合検知version生成
 *  予約本体と現在割当席の変更を同一hashで検出する
 */
function makeReservationSeatChangeVersion($reservationId, $reservationDate, $partySize, $status, $seatIds, $hasTempMove = false)
{
	if (
		is_numeric($reservationId) === false ||
		(int)$reservationId < 1 ||
		isReservationDateString($reservationDate) === false ||
		is_numeric($partySize) === false ||
		(int)$partySize < 1 ||
		(int)$partySize > 4 ||
		is_numeric($status) === false ||
		in_array((int)$status, [1, 2, 3, 4], true) === false ||
		is_array($seatIds) === false
	) {
		return null;
	}

	$normalizedSeatIds = [];
	foreach ($seatIds as $seatId) {
		if (is_numeric($seatId) === false || (int)$seatId < 1 || isset($normalizedSeatIds[(int)$seatId]) === true) {
			return null;
		}
		$normalizedSeatIds[(int)$seatId] = (int)$seatId;
	}
	$normalizedSeatIds = array_values($normalizedSeatIds);
	sort($normalizedSeatIds, SORT_NUMERIC);

	$payload = json_encode([
		'reservation_id' => (int)$reservationId,
		'reservation_date' => $reservationDate,
		'party_size' => (int)$partySize,
		'status' => (int)$status,
		'seat_ids' => $normalizedSeatIds,
		'has_temp_move' => $hasTempMove === true,
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	return is_string($payload) === true ? hash('sha256', $payload) : null;
}

/**
 * 管理画面の実席変更候補を全件生成
 *  対象予約自身の現在席だけを占有計算から外し、table・counter順で返す
 */
function buildReservationSeatChangeCandidates($occupancyState, $reservationId)
{
	if (
		is_array($occupancyState) === false ||
		is_numeric($reservationId) === false ||
		(int)$reservationId < 1 ||
		isValidReservationOccupancyState(
			$occupancyState['shop_id'] ?? null,
			$occupancyState['reservation_date'] ?? null,
			$occupancyState
		) === false
	) {
		return null;
	}

	$reservationId = (int)$reservationId;
	$reservation = $occupancyState['reservationsById'][$reservationId] ?? null;
	if (
		is_array($reservation) === false ||
		isReservationOccupiedStatus($reservation['status'] ?? null) === false ||
		(bool)($reservation['has_temp_move'] ?? false) === true
	) {
		return [];
	}

	$partySize = (int)($reservation['party_size'] ?? 0);
	if ($partySize < 1 || $partySize > 4) {
		return null;
	}
	$currentSeatIds = getReservationNormalSeatIds($occupancyState, $reservation['seat_ids'] ?? []);
	if (empty($currentSeatIds) === true) {
		return [];
	}
	$currentSeatIdSet = $currentSeatIds;
	sort($currentSeatIdSet, SORT_NUMERIC);

	$candidateState = $occupancyState;
	unset($candidateState['reservationsById'][$reservationId]);
	$occupiedSeatIds = getReservationOccupiedSeatIds($candidateState);
	$tableCandidates = [];
	$counters = [];

	foreach (($candidateState['seatsById'] ?? []) as $seat) {
		$seatId = (int)($seat['id'] ?? 0);
		if ($seatId < 1 || (int)($seat['is_temp_move'] ?? 0) !== 0) {
			continue;
		}
		if ((int)($seat['type'] ?? 0) === 2) {
			if (
				(int)($seat['is_active'] ?? 0) === 1 &&
				(int)($seat['capacity'] ?? 0) >= $partySize &&
				isset($occupiedSeatIds[$seatId]) === false
			) {
				$tableCandidates[] = $seat;
			}
			continue;
		}
		if ((int)($seat['type'] ?? 0) === 1) {
			$counters[] = $seat;
		}
	}

	usort($tableCandidates, function ($left, $right) use ($partySize) {
		$leftExact = (int)($left['capacity'] ?? 0) === $partySize ? 0 : 1;
		$rightExact = (int)($right['capacity'] ?? 0) === $partySize ? 0 : 1;
		if ($leftExact !== $rightExact) {
			return $leftExact <=> $rightExact;
		}
		return compareReservationSeatsForAssignment($left, $right);
	});
	usort($counters, 'compareReservationSeatsByPhysicalOrder');

	$candidates = [];
	foreach ($tableCandidates as $seat) {
		$seatIds = [(int)$seat['id']];
		$comparisonIds = $seatIds;
		sort($comparisonIds, SORT_NUMERIC);
		if ($comparisonIds === $currentSeatIdSet) {
			continue;
		}
		$name = (string)($seat['name'] ?? '');
		if (trim($name) === '') {
			return null;
		}
		$candidates[] = [
			'seat_ids' => $seatIds,
			'label' => $name,
			'type' => 'table',
		];
	}

	$counterCandidateAreas = [];
	$currentCounterSeats = [];
	$currentSeatsAreCounter = true;
	foreach ($currentSeatIds as $currentSeatId) {
		$currentSeat = $occupancyState['seatsById'][$currentSeatId] ?? null;
		if (
			is_array($currentSeat) === false ||
			(int)($currentSeat['type'] ?? 0) !== 1 ||
			($currentSeat['counter_area'] ?? null) === null ||
			($currentSeat['counter_area'] ?? '') === ''
		) {
			$currentSeatsAreCounter = false;
			break;
		}
		$currentCounterSeats[] = $currentSeat;
	}
	if ($currentSeatsAreCounter === true && empty($currentCounterSeats) === false) {
		usort($currentCounterSeats, 'compareReservationSeatsByPhysicalOrder');
		$currentCounterAreas = [];
		foreach ($currentCounterSeats as $currentCounterSeat) {
			$currentCounterAreas[$currentCounterSeat['counter_area']] = true;
		}
		$counterCandidateAreas[implode('/', array_keys($currentCounterAreas))] = true;
	}
	if ($partySize <= count($counters)) {
		for ($index = 0; $index <= count($counters) - $partySize; $index++) {
			$block = array_slice($counters, $index, $partySize);
			$seatIds = [];
			$counterArea = null;
			$counterAreas = [];
			$assignable = true;
			foreach ($block as $counter) {
				$seatId = (int)($counter['id'] ?? 0);
				$currentArea = $counter['counter_area'] ?? null;
				if (
					$seatId < 1 ||
					$currentArea === null ||
					$currentArea === '' ||
					(int)($counter['is_active'] ?? 0) !== 1 ||
					isset($occupiedSeatIds[$seatId]) === true
				) {
					$assignable = false;
					break;
				}
				if ($partySize === 2 && $counterArea !== null && $counterArea !== $currentArea) {
					$assignable = false;
					break;
				}
				$counterArea = $currentArea;
				$counterAreas[$currentArea] = true;
				$seatIds[] = $seatId;
			}
			if ($assignable === false || count($seatIds) !== $partySize) {
				continue;
			}
			$counterAreaKeys = array_keys($counterAreas);
			$counterCandidateAreaKey = implode('/', $counterAreaKeys);
			if (isset($counterCandidateAreas[$counterCandidateAreaKey]) === true) {
				continue;
			}
			$comparisonIds = $seatIds;
			sort($comparisonIds, SORT_NUMERIC);
			if ($comparisonIds === $currentSeatIdSet) {
				continue;
			}
			$counterCandidateAreas[$counterCandidateAreaKey] = true;
			$counterLabel = count($counterAreaKeys) === 1
				? 'カウンター' . $counterAreaKeys[0]
				: 'カウンター' . $counterAreaKeys[0] . '〜' . $counterAreaKeys[count($counterAreaKeys) - 1];
			$candidates[] = [
				'seat_ids' => $seatIds,
				'label' => $counterLabel,
				'type' => 'counter',
			];
		}
	}

	return $candidates;
}

/**
 * 予約席割当を判定
 *  commitはDBからstateを構築し、simulateは渡されたstateを使用する
 */
function checkAndAssignSeat($shopId, $date, $partySize, $mode, $occupancyState = null)
{
	if ($mode === 'commit') {
		if ($occupancyState !== null) {
			return makeReservationAssignmentResult(false, [], [], 'invalid_occupancy_state');
		}
		$occupancyState = buildReservationOccupancyStateFromDb($shopId, $date);
		if ($occupancyState === null) {
			return makeReservationAssignmentResult(false, [], [], 'occupancy_state_unavailable');
		}
	} elseif ($mode !== 'simulate') {
		return makeReservationAssignmentResult(false, [], [], 'invalid_mode');
	}

	if ($mode === 'simulate' && isValidReservationOccupancyState($shopId, $date, $occupancyState) === false) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_occupancy_state');
	}

	return calculateSeatAssignment($shopId, $date, $partySize, $occupancyState);
}

/**
 * 共通席割当core
 *  1名はcounter専用、2〜4名はtable優先、relocation、counterの順で割当計画を返す
 */
function calculateSeatAssignment($shopId, $date, $partySize, $occupancyState)
{
	if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_shop_id');
	}
	if (isReservationDateString($date) === false) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_date');
	}
	if (is_numeric($partySize) === false || (int)$partySize < 1 || (int)$partySize > 4) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_party_size');
	}
	if (isValidReservationOccupancyState($shopId, $date, $occupancyState) === false) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_occupancy_state');
	}

	$partySize = (int)$partySize;
	$calendarStatus = $occupancyState['calendar_override_status_type'];
	if ($calendarStatus !== null && (is_int($calendarStatus) === false && (is_string($calendarStatus) === false || ctype_digit($calendarStatus) === false))) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_calendar_override');
	}
	$calendarStatus = $calendarStatus === null ? null : (int)$calendarStatus;
	if ($calendarStatus !== null && in_array($calendarStatus, [1, 2, 3], true) === false) {
		return makeReservationAssignmentResult(false, [], [], 'invalid_calendar_override');
	}
	if ($calendarStatus === 1) {
		return makeReservationAssignmentResult(false, [], [], 'acceptance_stopped');
	}
	if ($calendarStatus === 2) {
		return makeReservationAssignmentResult(false, [], [], 'shop_holiday');
	}
	if ($calendarStatus !== 3) {
		$regularHolidays = normalizeRegularHolidays($occupancyState['regular_holidays']);
		if (in_array((int)date('w', strtotime($date)), $regularHolidays, true) === true) {
			return makeReservationAssignmentResult(false, [], [], 'regular_holiday');
		}
	}

	if ($partySize === 1) {
		$counterAssignment = findCounterAssignment($occupancyState, $partySize);
		return empty($counterAssignment) === false
			? makeReservationAssignmentResult(true, $counterAssignment, [], null)
			: makeReservationAssignmentResult(false, [], [], 'full');
	}

	$tableAssignment = findTableAssignment($occupancyState, $partySize, true);
	if (empty($tableAssignment) === false) {
		return makeReservationAssignmentResult(true, $tableAssignment, [], null);
	}

	$tableAssignment = findTableAssignment($occupancyState, $partySize, false);
	if (empty($tableAssignment) === false) {
		return makeReservationAssignmentResult(true, $tableAssignment, [], null);
	}

	$relocationPlan = findRelocationPlan($occupancyState, $partySize);
	if (empty($relocationPlan) === false) {
		return makeReservationAssignmentResult(true, [(int)$relocationPlan['released_table_seat_id']], [$relocationPlan], null);
	}

	$counterAssignment = findCounterAssignment($occupancyState, $partySize);
	if (empty($counterAssignment) === false) {
		return makeReservationAssignmentResult(true, $counterAssignment, [], null);
	}

	return makeReservationAssignmentResult(false, [], [], 'full');
}

/**
 * table席の直接割当候補を取得
 *  capacity、sort_order、idで決定性を担保する
 */
function findTableAssignment($occupancyState, $partySize, $exactOnly = false)
{
	$occupiedSeatIds = getReservationOccupiedSeatIds($occupancyState);
	$candidates = [];

	foreach (($occupancyState['seatsById'] ?? []) as $seat) {
		if ((int)($seat['type'] ?? 0) !== 2 || (int)($seat['is_active'] ?? 0) !== 1 || (int)($seat['is_temp_move'] ?? 0) !== 0) {
			continue;
		}
		if (isset($occupiedSeatIds[(int)$seat['id']]) === true) {
			continue;
		}
		if ($exactOnly === true && (int)$seat['capacity'] !== (int)$partySize) {
			continue;
		}
		if ((int)$seat['capacity'] < (int)$partySize) {
			continue;
		}
		$candidates[] = $seat;
	}

	usort($candidates, 'compareReservationSeatsForAssignment');
	if (empty($candidates) === true) {
		return [];
	}

	return [(int)$candidates[0]['id']];
}

/**
 * counter席の直接割当候補を取得
 *  無効counterは物理連続を切る壁として扱う
 */
function findCounterAssignment($occupancyState, $partySize)
{
	if (is_numeric($partySize) === false || (int)$partySize < 1 || (int)$partySize > 4) {
		return [];
	}
	$partySize = (int)$partySize;
	$occupiedSeatIds = getReservationOccupiedSeatIds($occupancyState);
	$counters = [];

	foreach (($occupancyState['seatsById'] ?? []) as $seat) {
		if ((int)($seat['type'] ?? 0) !== 1 || (int)($seat['is_temp_move'] ?? 0) !== 0) {
			continue;
		}
		$counters[] = $seat;
	}

	usort($counters, 'compareReservationSeatsByPhysicalOrder');
	if ($partySize === 1) {
		foreach ($counters as $counter) {
			if ((int)($counter['is_active'] ?? 0) === 1 && isset($occupiedSeatIds[(int)$counter['id']]) === false) {
				return [(int)$counter['id']];
			}
		}
		return [];
	}

	for ($i = 0; $i <= count($counters) - $partySize; $i++) {
		$block = array_slice($counters, $i, $partySize);
		$seatIds = [];
		$counterArea = null;
		$assignable = true;

		foreach ($block as $counter) {
			$seatId = (int)$counter['id'];
			if ((int)($counter['is_active'] ?? 0) !== 1 || isset($occupiedSeatIds[$seatId]) === true) {
				$assignable = false;
				break;
			}

			if ($partySize === 2) {
				$currentArea = $counter['counter_area'] ?? null;
				if ($currentArea === null || $currentArea === '') {
					$assignable = false;
					break;
				}
				if ($counterArea === null) {
					$counterArea = $currentArea;
				} elseif ($counterArea !== $currentArea) {
					$assignable = false;
					break;
				}
			}

			$seatIds[] = $seatId;
		}

		if ($assignable === true && count($seatIds) === $partySize) {
			return $seatIds;
		}
	}

	return [];
}

/**
 * tableからcounterへの自動relocation計画を取得
 *  counter既存予約の自動並べ替えは行わない
 */
function findRelocationPlan($occupancyState, $partySize)
{
	$candidates = [];

	foreach (($occupancyState['reservationsById'] ?? []) as $reservation) {
		if (isReservationOccupiedStatus($reservation['status'] ?? null) === false || (bool)($reservation['has_temp_move'] ?? false) === true) {
			continue;
		}

		$normalSeatIds = getReservationNormalSeatIds($occupancyState, $reservation['seat_ids'] ?? []);
		if (empty($normalSeatIds) === true || reservationUsesCounterSeat($occupancyState, $normalSeatIds) === true) {
			continue;
		}

		foreach ($normalSeatIds as $seatId) {
			$seat = $occupancyState['seatsById'][$seatId] ?? null;
			if (
				is_array($seat) === false ||
				(int)($seat['type'] ?? 0) !== 2 ||
				(int)($seat['is_active'] ?? 0) !== 1 ||
				(int)($seat['is_temp_move'] ?? 0) !== 0 ||
				(int)($seat['capacity'] ?? 0) < (int)$partySize
			) {
				continue;
			}

			$candidates[] = [
				'reservation_id' => (int)$reservation['reservation_id'],
				'reservation_party_size' => (int)$reservation['party_size'],
				'from_seat_ids' => $normalSeatIds,
				'table_seat' => $seat,
			];
		}
	}

	usort($candidates, 'compareReservationRelocationCandidates');
	foreach ($candidates as $candidate) {
		$counterSeatIds = findCounterAssignment($occupancyState, (int)$candidate['reservation_party_size']);
		if (empty($counterSeatIds) === true) {
			continue;
		}
		return [
			'reservation_id' => (int)$candidate['reservation_id'],
			'from_seat_ids' => array_values($candidate['from_seat_ids']),
			'to_seat_ids' => $counterSeatIds,
			'released_table_seat_id' => (int)$candidate['table_seat']['id'],
		];
	}

	return [];
}

/**
 * 割当計画をsimulate用occupancyStateへ適用
 *  新規予約は負数IDのsynthetic予約として追加する
 */
function applyAssignmentPlanToOccupancyState($occupancyState, $assignmentPlan, $partySize)
{
	if (is_array($occupancyState) === false || is_array($assignmentPlan) === false || ($assignmentPlan['assignable'] ?? false) !== true) {
		return $occupancyState;
	}

	foreach (($assignmentPlan['relocations'] ?? []) as $relocation) {
		$reservationId = (int)($relocation['reservation_id'] ?? 0);
		if (isset($occupancyState['reservationsById'][$reservationId]) === false) {
			continue;
		}
		$occupancyState['reservationsById'][$reservationId]['seat_ids'] = array_values(array_map('intval', $relocation['to_seat_ids'] ?? []));
	}

	$syntheticId = isset($occupancyState['nextSyntheticReservationId']) && is_numeric($occupancyState['nextSyntheticReservationId']) === true
		? (int)$occupancyState['nextSyntheticReservationId']
		: -1;
	if ($syntheticId >= 0) {
		$syntheticId = -1;
	}

	$occupancyState['reservationsById'][$syntheticId] = [
		'reservation_id' => $syntheticId,
		'party_size' => (int)$partySize,
		'status' => 1,
		'seat_ids' => array_values(array_map('intval', $assignmentPlan['assigned_seat_ids'] ?? [])),
		'has_temp_move' => false,
		'is_synthetic' => true,
	];
	$occupancyState['nextSyntheticReservationId'] = $syntheticId - 1;

	return $occupancyState;
}

/**
 * 予約席割当結果を生成
 *  Ajax文言を含まない内部コードだけを返す
 */
function makeReservationAssignmentResult($assignable, $assignedSeatIds = [], $relocations = [], $reason = null)
{
	return [
		'assignable' => (bool)$assignable,
		'assigned_seat_ids' => array_values(array_map('intval', $assignedSeatIds)),
		'relocations' => $relocations,
		'reason' => $reason,
	];
}

/**
 * 日付文字列の形式を確認
 *  Y-m-d形式のみ有効とする
 */
function isReservationDateString($date)
{
	if (is_string($date) === false || $date === '') {
		return false;
	}
	$dateTime = DateTime::createFromFormat('Y-m-d', $date);
	return $dateTime instanceof DateTime && $dateTime->format('Y-m-d') === $date;
}

/**
 * 予約占有stateの最小整合性確認
 *  simulateの不正stateを空席や営業日として扱わない
 */
function isValidReservationOccupancyState($shopId, $date, $occupancyState)
{
	if (is_array($occupancyState) === false) {
		return false;
	}
	if (
		array_key_exists('shop_id', $occupancyState) === false ||
		array_key_exists('reservation_date', $occupancyState) === false ||
		array_key_exists('seatsById', $occupancyState) === false ||
		array_key_exists('reservationsById', $occupancyState) === false ||
		array_key_exists('regular_holidays', $occupancyState) === false ||
		array_key_exists('calendar_override_status_type', $occupancyState) === false ||
		array_key_exists('nextSyntheticReservationId', $occupancyState) === false
	) {
		return false;
	}
	if (is_numeric($occupancyState['shop_id']) === false || (int)$occupancyState['shop_id'] !== (int)$shopId) {
		return false;
	}
	if ((string)$occupancyState['reservation_date'] !== (string)$date) {
		return false;
	}
	if (is_array($occupancyState['seatsById']) === false || is_array($occupancyState['reservationsById']) === false || is_array($occupancyState['regular_holidays']) === false) {
		return false;
	}
	foreach ($occupancyState['seatsById'] as $seatKey => $seat) {
		if (
			is_numeric($seatKey) === false ||
			is_array($seat) === false ||
			array_key_exists('id', $seat) === false ||
			is_numeric($seat['id']) === false
		) {
			return false;
		}
		if ((int)$seatKey !== (int)$seat['id']) {
			return false;
		}
	}
	foreach ($occupancyState['reservationsById'] as $reservationKey => $reservation) {
		if (
			is_numeric($reservationKey) === false ||
			is_array($reservation) === false ||
			array_key_exists('reservation_id', $reservation) === false ||
			is_numeric($reservation['reservation_id']) === false
		) {
			return false;
		}
		if ((int)$reservationKey !== (int)$reservation['reservation_id']) {
			return false;
		}
	}
	if (is_numeric($occupancyState['nextSyntheticReservationId']) === false || (int)$occupancyState['nextSyntheticReservationId'] >= 0) {
		return false;
	}
	return true;
}

/**
 * 占有中の通常席IDを取得
 *  temp seatは占有計算から除外する
 */
function getReservationOccupiedSeatIds($occupancyState)
{
	$occupiedSeatIds = [];
	foreach (($occupancyState['reservationsById'] ?? []) as $reservation) {
		if (isReservationOccupiedStatus($reservation['status'] ?? null) === false) {
			continue;
		}
		foreach (($reservation['seat_ids'] ?? []) as $seatId) {
			$seatId = (int)$seatId;
			$seat = $occupancyState['seatsById'][$seatId] ?? null;
			if (is_array($seat) === true && (int)($seat['is_temp_move'] ?? 0) === 0) {
				$occupiedSeatIds[$seatId] = true;
			}
		}
	}
	return $occupiedSeatIds;
}

/**
 * 予約に紐づく通常席IDを取得
 *  temp seatは除外し元席を保持する
 */
function getReservationNormalSeatIds($occupancyState, $seatIds)
{
	$normalSeatIds = [];
	foreach ($seatIds as $seatId) {
		$seatId = (int)$seatId;
		$seat = $occupancyState['seatsById'][$seatId] ?? null;
		if (is_array($seat) === true && (int)($seat['is_temp_move'] ?? 0) === 0) {
			$normalSeatIds[] = $seatId;
		}
	}
	return array_values(array_unique($normalSeatIds));
}

/**
 * 予約席にcounterが含まれるか判定
 *  counter既存予約の自動並べ替えを防止する
 */
function reservationUsesCounterSeat($occupancyState, $seatIds)
{
	foreach ($seatIds as $seatId) {
		$seat = $occupancyState['seatsById'][(int)$seatId] ?? null;
		if (is_array($seat) === true && (int)($seat['type'] ?? 0) === 1) {
			return true;
		}
	}
	return false;
}

/**
 * 席候補を割当優先順で比較
 *  capacity、sort_order、idの順で安定化する
 */
function compareReservationSeatsForAssignment($a, $b)
{
	foreach (['capacity', 'sort_order', 'id'] as $key) {
		$diff = (int)($a[$key] ?? 0) <=> (int)($b[$key] ?? 0);
		if ($diff !== 0) {
			return $diff;
		}
	}
	return 0;
}

/**
 * counter物理配列用に席を比較
 *  sort_order、idの順で物理順を固定する
 */
function compareReservationSeatsByPhysicalOrder($a, $b)
{
	foreach (['sort_order', 'id'] as $key) {
		$diff = (int)($a[$key] ?? 0) <=> (int)($b[$key] ?? 0);
		if ($diff !== 0) {
			return $diff;
		}
	}
	return 0;
}

/**
 * relocation候補を比較
 *  table条件とreservation_idで決定性を担保する
 */
function compareReservationRelocationCandidates($a, $b)
{
	foreach (['capacity', 'sort_order', 'id'] as $key) {
		$diff = (int)($a['table_seat'][$key] ?? 0) <=> (int)($b['table_seat'][$key] ?? 0);
		if ($diff !== 0) {
			return $diff;
		}
	}
	return (int)($a['reservation_id'] ?? 0) <=> (int)($b['reservation_id'] ?? 0);
}

/**
 * 予約登録共通確定処理
 *  caller側でtransaction開始、shops mutex取得、route別eligibility確認済みの状態で呼び出す
 */
function executeReservationRegistration($shopId = null, $reservationData = [], $menuSelections = [])
{
	global $DB_CONNECT;
	if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
		return makeReservationRegistrationResult(false, null, 'invalid_input');
	}
	if (is_array($reservationData) === false || is_array($menuSelections) === false) {
		return makeReservationRegistrationResult(false, null, 'invalid_input');
	}

	$shopId = normalizeReservationRegistrationInteger($shopId, 1, null);
	$reservationDate = $reservationData['reservation_date'] ?? null;
	$partySize = normalizeReservationRegistrationInteger($reservationData['party_size'] ?? null, 1, 4);
	$reservationRoute = normalizeReservationRegistrationInteger($reservationData['reservation_route'] ?? null, 1, 3);
	if ($shopId === null || isReservationDateString($reservationDate) === false || $partySize === null || $reservationRoute === null) {
		return makeReservationRegistrationResult(false, null, 'invalid_input');
	}
	foreach (['customer_name', 'customer_kana', 'customer_tel'] as $requiredKey) {
		if (array_key_exists($requiredKey, $reservationData) === false || $reservationData[$requiredKey] === null) {
			return makeReservationRegistrationResult(false, null, 'invalid_input');
		}
	}
	$customerName = normalizeReservationCustomerIdentityValue($reservationData['customer_name']);
	$customerKana = normalizeReservationCustomerIdentityValue($reservationData['customer_kana']);
	if ($customerName === null || $customerKana === null) {
		return makeReservationRegistrationResult(false, null, 'invalid_input');
	}
	$reservationData['customer_name'] = $customerName;
	$reservationData['customer_kana'] = $customerKana;
	foreach (['getShopReservationSettings', 'checkAndAssignSeat', 'replaceReservationSeats', 'insertReservation', 'insertReservationSeats', 'insertReservationMenus'] as $requiredFunction) {
		if (function_exists($requiredFunction) === false) {
			return makeReservationRegistrationResult(false, null, 'invalid_input');
		}
	}

	$settings = getShopReservationSettings($shopId);
	if (is_array($settings) === false) {
		return makeReservationRegistrationResult(false, null, 'settings_read_failed');
	}
	$menuSelectionType = normalizeReservationRegistrationInteger($settings['menu_selection_type'] ?? null, 0, 2);
	if ($menuSelectionType === null) {
		return makeReservationRegistrationResult(false, null, 'settings_read_failed');
	}

	$menuSelectionResult = normalizeReservationMenuSelectionsForRegistration($partySize, $menuSelectionType, $menuSelections);
	if (($menuSelectionResult['success'] ?? false) !== true) {
		return makeReservationRegistrationResult(false, null, 'invalid_menu');
	}

	$menuRowsById = [];
	if (empty($menuSelectionResult['menu_ids']) === false) {
		if (function_exists('getFoodMenuRowsForReservation') === false) {
			return makeReservationRegistrationResult(false, null, 'menu_read_failed');
		}
		$menuRowsById = getFoodMenuRowsForReservation($shopId, $menuSelectionResult['menu_ids']);
		if ($menuRowsById === false) {
			return makeReservationRegistrationResult(false, null, 'menu_read_failed');
		}
	}
	$menuSnapshotResult = buildReservationMenuSnapshotRowsForRegistration($shopId, $menuSelectionResult['menu_slots'], $menuRowsById, foodMenuTodayJst());
	if (($menuSnapshotResult['success'] ?? false) !== true) {
		return makeReservationRegistrationResult(false, null, 'invalid_menu');
	}
	$menuRows = $menuSnapshotResult['menu_rows'];

	$assignmentResult = checkAndAssignSeat($shopId, $reservationDate, $partySize, 'commit', null);
	if (is_array($assignmentResult) === false || ($assignmentResult['assignable'] ?? false) !== true || empty($assignmentResult['assigned_seat_ids']) === true) {
		return makeReservationRegistrationResult(false, null, 'allocation_failed', $assignmentResult['reason'] ?? null);
	}

	foreach (($assignmentResult['relocations'] ?? []) as $relocation) {
		$relocationReservationId = normalizeReservationRegistrationInteger($relocation['reservation_id'] ?? null, 1, null);
		$toSeatIds = normalizeReservationRegistrationIntegerList($relocation['to_seat_ids'] ?? []);
		if ($relocationReservationId === null || empty($toSeatIds) === true) {
			return makeReservationRegistrationResult(false, null, 'relocation_write_failed');
		}
		if (replaceReservationSeats($relocationReservationId, $shopId, $toSeatIds) !== true) {
			return makeReservationRegistrationResult(false, null, 'relocation_write_failed');
		}
	}

	$insertReservationData = buildReservationInsertDataForRegistration($reservationData, $reservationDate, $partySize, $reservationRoute);

	$reservationId = insertReservation($shopId, $insertReservationData);
	if ($reservationId === false || is_numeric($reservationId) === false || (int)$reservationId < 1) {
		return makeReservationRegistrationResult(false, null, 'reservation_insert_failed');
	}
	$reservationId = (int)$reservationId;

	if (insertReservationSeats($reservationId, $shopId, $assignmentResult['assigned_seat_ids']) !== true) {
		return makeReservationRegistrationResult(false, null, 'seat_insert_failed');
	}
	if (empty($menuRows) === false && insertReservationMenus($reservationId, $shopId, $menuRows) !== true) {
		return makeReservationRegistrationResult(false, null, 'menu_insert_failed');
	}

	return makeReservationRegistrationResult(true, $reservationId, null);
}

/**
 * 新規予約INSERT用データを生成
 *  caller入力に関わらず新規予約はstatus=1、cancelled_at=NULLへ固定する
 */
function buildReservationInsertDataForRegistration($reservationData, $reservationDate, $partySize, $reservationRoute)
{
	$insertReservationData = is_array($reservationData) === true ? $reservationData : [];
	$insertReservationData['reservation_date'] = $reservationDate;
	$insertReservationData['party_size'] = $partySize;
	$insertReservationData['reservation_route'] = $reservationRoute;
	$insertReservationData['status'] = 1;
	$insertReservationData['cancelled_at'] = null;
	return $insertReservationData;
}

/**
 * 予約登録用メニュー選択を内部slotへ正規化
 *  type=0は入力を無視し、type=1/2はguest slotの順序を保持する
 */
function normalizeReservationMenuSelectionsForRegistration($partySize, $menuSelectionType, $menuSelections)
{
	$partySize = normalizeReservationRegistrationInteger($partySize, 1, 4);
	$menuSelectionType = normalizeReservationRegistrationInteger($menuSelectionType, 0, 2);
	if ($partySize === null || $menuSelectionType === null || isReservationRegistrationListArray($menuSelections) === false) {
		return makeReservationMenuSelectionResult(false, [], [], 'invalid_menu');
	}
	if ($menuSelectionType === 0) {
		return makeReservationMenuSelectionResult(true, [], [], null);
	}

	$menuSelections = array_values($menuSelections);
	if ($menuSelectionType === 1 && count($menuSelections) > $partySize) {
		return makeReservationMenuSelectionResult(false, [], [], 'invalid_menu');
	}
	if ($menuSelectionType === 2 && count($menuSelections) !== $partySize) {
		return makeReservationMenuSelectionResult(false, [], [], 'invalid_menu');
	}

	$menuSlots = [];
	$menuIds = [];
	foreach ($menuSelections as $menuId) {
		if ($menuId === null) {
			if ($menuSelectionType === 2) {
				return makeReservationMenuSelectionResult(false, [], [], 'invalid_menu');
			}
			$menuSlots[] = null;
			continue;
		}

		$menuId = normalizeReservationRegistrationInteger($menuId, 1, null);
		if ($menuId === null) {
			return makeReservationMenuSelectionResult(false, [], [], 'invalid_menu');
		}
		$menuSlots[] = $menuId;
		$menuIds[$menuId] = $menuId;
	}

	return makeReservationMenuSelectionResult(true, $menuSlots, array_values($menuIds), null);
}

/**
 * 予約登録用メニューsnapshot行を生成
 *  最新master行から保存用の名前、価格、税込フラグを作る
 */
function buildReservationMenuSnapshotRowsForRegistration($shopId, $menuSlots, $menuRowsById, $periodBasisDate = null)
{
	$shopId = normalizeReservationRegistrationInteger($shopId, 1, null);
	if ($shopId === null || isReservationRegistrationListArray($menuSlots) === false || is_array($menuRowsById) === false) {
		return makeReservationMenuSnapshotResult(false, [], 'invalid_menu');
	}
	$periodBasisDate = $periodBasisDate === null ? foodMenuTodayJst() : $periodBasisDate;
	if (isReservationDateString($periodBasisDate) === false) {
		return makeReservationMenuSnapshotResult(false, [], 'invalid_menu');
	}

	$menuRows = [];
	foreach (array_values($menuSlots) as $index => $menuId) {
		if ($menuId === null) {
			continue;
		}
		$menuId = normalizeReservationRegistrationInteger($menuId, 1, null);
		if ($menuId === null || isset($menuRowsById[$menuId]) === false || is_array($menuRowsById[$menuId]) === false) {
			return makeReservationMenuSnapshotResult(false, [], 'invalid_menu');
		}

		$menuRow = $menuRowsById[$menuId];
		$menuShopId = normalizeReservationRegistrationInteger($menuRow['shop_id'] ?? null, 1, null);
		$menuName = $menuRow['menu_name'] ?? null;
		$price = normalizeReservationRegistrationInteger($menuRow['price'] ?? null, 0, null);
		$taxIncluded = normalizeReservationRegistrationInteger($menuRow['tax_included'] ?? null, 0, 1);
		if ($menuShopId !== $shopId || is_string($menuName) === false || $price === null || $taxIncluded === null || isFoodMenuAvailableForReservationRegistration($menuRow, $periodBasisDate) === false) {
			return makeReservationMenuSnapshotResult(false, [], 'invalid_menu');
		}

		$menuRows[] = [
			'guest_no' => $index + 1,
			'menu_id' => $menuId,
			'menu_name' => $menuName,
			'price' => $price,
			'tax_included' => $taxIncluded,
		];
	}

	return makeReservationMenuSnapshotResult(true, $menuRows, null);
}

/**
 * 予約登録で利用可能なメニューmasterか判定
 *  menus.json生成と同じく今日基準の掲載期間で確認する
 */
function isFoodMenuAvailableForReservationRegistration($menuRow, $periodBasisDate)
{
	return is_array($menuRow) && isFoodMenuUsable($menuRow, $menuRow['shop_id'] ?? null, $periodBasisDate);
}

/**
 * 予約登録用整数を厳密に正規化
 *  小数や空文字を整数へ丸めて受理しない
 */
function normalizeReservationRegistrationInteger($value, $min = null, $max = null)
{
	if (is_int($value) === true) {
		$normalizedValue = $value;
	} elseif (is_string($value) === true && ctype_digit($value) === true) {
		$normalizedValue = (int)$value;
	} else {
		return null;
	}
	if ($min !== null && $normalizedValue < (int)$min) {
		return null;
	}
	if ($max !== null && $normalizedValue > (int)$max) {
		return null;
	}
	return $normalizedValue;
}

/**
 * 整数ID配列を重複拒否して正規化
 *  DB helperへ小数や不正値を渡さない
 */
function normalizeReservationRegistrationIntegerList($values)
{
	if (isReservationRegistrationListArray($values) === false) {
		return null;
	}

	$normalizedValues = [];
	foreach ($values as $value) {
		$normalizedValue = normalizeReservationRegistrationInteger($value, 1, null);
		if ($normalizedValue === null) {
			return null;
		}
		if (isset($normalizedValues[$normalizedValue]) === true) {
			return null;
		}
		$normalizedValues[$normalizedValue] = $normalizedValue;
	}
	return array_values($normalizedValues);
}

/**
 * 0始まりの配列か確認
 *  guest_no算出のため連想配列を受け付けない
 */
function isReservationRegistrationListArray($values)
{
	if (is_array($values) === false) {
		return false;
	}
	if (empty($values) === true) {
		return true;
	}
	return array_keys($values) === range(0, count($values) - 1);
}

/**
 * 予約登録結果を生成
 *  callerが終了処理を判断できる内部コードだけを返す
 */
function makeReservationRegistrationResult($success, $reservationId = null, $reason = null, $allocationReason = null)
{
	$result = [
		'success' => (bool)$success,
		'reservation_id' => $reservationId === null ? null : (int)$reservationId,
		'reason' => $reason,
	];
	if ($allocationReason !== null) {
		$result['allocation_reason'] = $allocationReason;
	}
	return $result;
}

/**
 * メニュー選択正規化結果を生成
 *  slotとDB取得用の一意menu IDを分けて保持する
 */
function makeReservationMenuSelectionResult($success, $menuSlots = [], $menuIds = [], $reason = null)
{
	return [
		'success' => (bool)$success,
		'menu_slots' => $menuSlots,
		'menu_ids' => $menuIds,
		'reason' => $reason,
	];
}

/**
 * メニューsnapshot生成結果を生成
 *  reservation_menusへ渡す行だけを保持する
 */
function makeReservationMenuSnapshotResult($success, $menuRows = [], $reason = null)
{
	return [
		'success' => (bool)$success,
		'menu_rows' => $menuRows,
		'reason' => $reason,
	];
}
