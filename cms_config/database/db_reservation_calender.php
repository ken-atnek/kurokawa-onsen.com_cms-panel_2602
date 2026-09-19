<?php
/*
 * [予約カレンダー]
 */
/**
 * 予約カレンダー日次上書き取得
 *  1=受付停止、2=店休日、3=定休日解除
 */
function getReservationCalendarOverride($shopId = null, $targetDate = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || isReservationDbDateString($targetDate) === false) {
			return null;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				target_date,
				status_type,
				created_at,
				updated_at
			FROM
				reservation_calendar_overrides
			WHERE
				shop_id = :shop_id
				AND target_date = :target_date
			LIMIT 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
		$newStmt->execute();
		$override = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $override ?: null;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * DB helper用日付文字列確認
 *  Y-m-d形式のみ有効とする
 */
function isReservationDbDateString($date)
{
	if (is_string($date) === false || $date === '') {
		return false;
	}
	$dateTime = DateTime::createFromFormat('Y-m-d', $date);
	return $dateTime instanceof DateTime && $dateTime->format('Y-m-d') === $date;
}

/**
 * 予約カレンダー日次上書き登録・更新
 *  transaction開始後にstatus_type 1～3をUPSERTする
 */
function upsertReservationCalendarOverride($shopId = null, $targetDate = null, $statusType = null)
{
	global $DB_CONNECT;
	try {
		$shopIdValid = is_int($shopId) || (is_string($shopId) && ctype_digit($shopId));
		$statusTypeValid = (is_int($statusType) && in_array($statusType, [1, 2, 3], true)) ||
			(is_string($statusType) && preg_match('/\A[1-3]\z/D', $statusType) === 1);
		if (
			$shopIdValid === false ||
			(int)$shopId < 1 ||
			isReservationDbDateString($targetDate) === false ||
			$statusTypeValid === false ||
			is_object($DB_CONNECT) === false ||
			method_exists($DB_CONNECT, 'inTransaction') === false ||
			$DB_CONNECT->inTransaction() !== true
		) {
			return false;
		}

		$strSQL = "
			INSERT INTO reservation_calendar_overrides (
				shop_id,
				target_date,
				status_type
			) VALUES (
				:shop_id,
				:target_date,
				:status_type
			)
			ON DUPLICATE KEY UPDATE
				status_type = VALUES(status_type),
				updated_at = CURRENT_TIMESTAMP
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
		$newStmt->bindValue(':status_type', (int)$statusType, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約カレンダー日次上書き削除
 *  transaction開始後に店舗・対象日のoverrideを削除する
 */
function deleteReservationCalendarOverride($shopId = null, $targetDate = null)
{
	global $DB_CONNECT;
	try {
		$shopIdValid = is_int($shopId) || (is_string($shopId) && ctype_digit($shopId));
		if (
			$shopIdValid === false ||
			(int)$shopId < 1 ||
			isReservationDbDateString($targetDate) === false ||
			is_object($DB_CONNECT) === false ||
			method_exists($DB_CONNECT, 'inTransaction') === false ||
			$DB_CONNECT->inTransaction() !== true
		) {
			return false;
		}

		$strSQL = "
			DELETE FROM
				reservation_calendar_overrides
			WHERE
				shop_id = :shop_id
				AND target_date = :target_date
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 定休日変更後に不要となった定休日解除overrideを削除
 *  transaction開始後にJST当日以降のstatus_type=3だけを対象とする
 */
function deleteObsoleteReservationCalendarReleaseOverrides($shopId = null, $regularHolidayWeekdays = [], $today = null)
{
	global $DB_CONNECT;
	try {
		$shopIdValid = is_int($shopId) || (is_string($shopId) && ctype_digit($shopId));
		if (
			$shopIdValid === false ||
			(int)$shopId < 1 ||
			is_array($regularHolidayWeekdays) === false ||
			isReservationDbDateString($today) === false ||
			is_object($DB_CONNECT) === false ||
			method_exists($DB_CONNECT, 'inTransaction') === false ||
			$DB_CONNECT->inTransaction() !== true
		) {
			return false;
		}

		$normalizedWeekdays = [];
		foreach ($regularHolidayWeekdays as $index => $weekday) {
			$weekdayValid = is_int($weekday) || (is_string($weekday) && ctype_digit($weekday));
			if (
				$index !== count($normalizedWeekdays) ||
				$weekdayValid === false ||
				(int)$weekday < 0 ||
				(int)$weekday > 6 ||
				in_array((int)$weekday, $normalizedWeekdays, true) === true
			) {
				return false;
			}
			$normalizedWeekdays[] = (int)$weekday;
		}

		$weekdayPlaceholders = [];
		foreach ($normalizedWeekdays as $index => $weekday) {
			$weekdayPlaceholders[] = ':weekday_' . $index;
		}
		$weekdayCondition = '';
		if (empty($weekdayPlaceholders) === false) {
			$weekdayCondition = "\n\t\t\t\tAND (DAYOFWEEK(target_date) - 1) NOT IN (" . implode(', ', $weekdayPlaceholders) . ')';
		}

		$strSQL = "
			DELETE FROM
				reservation_calendar_overrides
			WHERE
				shop_id = :shop_id
				AND status_type = 3
				AND target_date >= :today{$weekdayCondition}
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':today', $today, PDO::PARAM_STR);
		foreach ($normalizedWeekdays as $index => $weekday) {
			$newStmt->bindValue(':weekday_' . $index, $weekday, PDO::PARAM_INT);
		}
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}
