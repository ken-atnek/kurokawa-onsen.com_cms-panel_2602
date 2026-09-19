<?php
/*
 * [予約基本設定]
 */
/**
 * 月初JSON整理対象の設定済み店舗IDを取得
 *  受付停止・非公開の店舗も古い月の削除対象に含める。
 */
function getReservationJsonMaintenanceShopIds()
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('SELECT shop_id FROM shop_reservation_settings ORDER BY shop_id ASC');
		if ($stmt === false || $stmt->execute() !== true) {
			return false;
		}
		$shopIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
		$stmt->closeCursor();
		return is_array($shopIds) ? array_map('intval', $shopIds) : false;
	} catch (PDOException $e) {
		return false;
	}
}
/**
 * 店舗予約基本設定取得
 *  設定行なしはnull、DBエラーはfalseを返す
 */
function getShopReservationSettings($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return null;
		}

		$strSQL = "
			SELECT
				shop_id,
				reservation_enabled,
				menu_selection_type,
				accept_start_days_before,
				accept_end_days_before,
				guest_min,
				guest_max,
				created_at,
				updated_at
			FROM
				shop_reservation_settings
			WHERE
				shop_id = :shop_id
			LIMIT 1
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->execute();
		$settings = $newStmt->fetch(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		return $settings ?: null;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約基本設定保存値を厳格に正規化
 *  DB rowまたは保存直前dataが正式contract外の場合はfalseを返す
 */
function normalizeShopReservationSettingsData($settingsData)
{
	if (is_array($settingsData) === false) {
		return false;
	}

	$normalizeInteger = static function ($value) {
		if (is_int($value) === true) {
			return $value;
		}
		if (is_string($value) === true && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
			return (int)$value;
		}
		return null;
	};

	$reservationEnabled = $normalizeInteger($settingsData['reservation_enabled'] ?? null);
	$menuSelectionType = $normalizeInteger($settingsData['menu_selection_type'] ?? null);
	$acceptStartRaw = $settingsData['accept_start_days_before'] ?? null;
	$acceptStartDaysBefore = $acceptStartRaw === null ? null : $normalizeInteger($acceptStartRaw);
	$acceptEndDaysBefore = $normalizeInteger($settingsData['accept_end_days_before'] ?? null);
	$guestMin = $normalizeInteger($settingsData['guest_min'] ?? null);
	$guestMax = $normalizeInteger($settingsData['guest_max'] ?? null);

	if (
		in_array($reservationEnabled, [0, 1], true) === false ||
		in_array($menuSelectionType, [0, 1, 2], true) === false ||
		($acceptStartRaw !== null && in_array($acceptStartDaysBefore, [90, 60, 30], true) === false) ||
		in_array($acceptEndDaysBefore, [0, 1, 3, 7], true) === false ||
		in_array($guestMin, [1, 2, 3, 4], true) === false ||
		in_array($guestMax, [1, 2, 3, 4], true) === false ||
		$guestMin > $guestMax ||
		($acceptStartDaysBefore !== null && $acceptStartDaysBefore < $acceptEndDaysBefore)
	) {
		return false;
	}

	return [
		'reservation_enabled' => $reservationEnabled,
		'menu_selection_type' => $menuSelectionType,
		'accept_start_days_before' => $acceptStartDaysBefore,
		'accept_end_days_before' => $acceptEndDaysBefore,
		'guest_min' => $guestMin,
		'guest_max' => $guestMax,
	];
}

/**
 * 店舗予約基本設定初回登録
 *  transaction内で呼ばれる前提で、schema default相当の初期値を登録する
 */
function insertShopReservationSettings($shopId = null, $settingsData = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($settingsData) === false) {
			return false;
		}
		if (is_object($DB_CONNECT) === false || method_exists($DB_CONNECT, 'inTransaction') === false || $DB_CONNECT->inTransaction() !== true) {
			return false;
		}
		if (function_exists('ensureReservationTempMoveSeat') === false) {
			return false;
		}

		$reservationEnabled = isset($settingsData['reservation_enabled']) ? (int)$settingsData['reservation_enabled'] : 0;
		$menuSelectionType = isset($settingsData['menu_selection_type']) ? (int)$settingsData['menu_selection_type'] : 0;
		$acceptStartDaysBefore = array_key_exists('accept_start_days_before', $settingsData) ? $settingsData['accept_start_days_before'] : null;
		$acceptEndDaysBefore = isset($settingsData['accept_end_days_before']) ? (int)$settingsData['accept_end_days_before'] : 0;
		$guestMin = isset($settingsData['guest_min']) ? (int)$settingsData['guest_min'] : 1;
		$guestMax = isset($settingsData['guest_max']) ? (int)$settingsData['guest_max'] : 4;

		if (
			(isset($settingsData['reservation_enabled']) === true && is_numeric($settingsData['reservation_enabled']) === false) ||
			(isset($settingsData['menu_selection_type']) === true && is_numeric($settingsData['menu_selection_type']) === false) ||
			(isset($settingsData['accept_end_days_before']) === true && is_numeric($settingsData['accept_end_days_before']) === false) ||
			(isset($settingsData['guest_min']) === true && is_numeric($settingsData['guest_min']) === false) ||
			(isset($settingsData['guest_max']) === true && is_numeric($settingsData['guest_max']) === false)
		) {
			return false;
		}
		if ($acceptStartDaysBefore !== null && ($acceptStartDaysBefore === '' || is_numeric($acceptStartDaysBefore) === false)) {
			return false;
		}
		$acceptStartDaysBefore = $acceptStartDaysBefore === null ? null : (int)$acceptStartDaysBefore;

		$strSQL = "
			INSERT INTO
				shop_reservation_settings (
					shop_id,
					reservation_enabled,
					menu_selection_type,
					accept_start_days_before,
					accept_end_days_before,
					guest_min,
					guest_max
				)
			VALUES (
				:shop_id,
				:reservation_enabled,
				:menu_selection_type,
				:accept_start_days_before,
				:accept_end_days_before,
				:guest_min,
				:guest_max
			)
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$newStmt->bindValue(':reservation_enabled', $reservationEnabled, PDO::PARAM_INT);
		$newStmt->bindValue(':menu_selection_type', $menuSelectionType, PDO::PARAM_INT);
		if ($acceptStartDaysBefore === null) {
			$newStmt->bindValue(':accept_start_days_before', null, PDO::PARAM_NULL);
		} else {
			$newStmt->bindValue(':accept_start_days_before', $acceptStartDaysBefore, PDO::PARAM_INT);
		}
		$newStmt->bindValue(':accept_end_days_before', $acceptEndDaysBefore, PDO::PARAM_INT);
		$newStmt->bindValue(':guest_min', $guestMin, PDO::PARAM_INT);
		$newStmt->bindValue(':guest_max', $guestMax, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$newStmt->closeCursor();

		if ($result !== true) {
			return false;
		}
		return ensureReservationTempMoveSeat($shopId) !== false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 店舗予約基本設定差分更新
 *  transaction内で、固定許可columnの変更分だけを更新する
 */
function updateShopReservationSettings($shopId = null, $settingsData = [], $changedFields = [])
{
	global $DB_CONNECT;
	try {
		if (
			$shopId === null ||
			is_numeric($shopId) === false ||
			(int)$shopId < 1 ||
			is_array($settingsData) === false ||
			is_array($changedFields) === false ||
			is_object($DB_CONNECT) === false ||
			method_exists($DB_CONNECT, 'inTransaction') === false ||
			$DB_CONNECT->inTransaction() !== true
		) {
			return false;
		}

		$normalizedData = normalizeShopReservationSettingsData($settingsData);
		if ($normalizedData === false) {
			return false;
		}

		$columnMap = [
			'reservation_enabled' => 'reservation_enabled',
			'menu_selection_type' => 'menu_selection_type',
			'accept_start_days_before' => 'accept_start_days_before',
			'accept_end_days_before' => 'accept_end_days_before',
			'guest_min' => 'guest_min',
			'guest_max' => 'guest_max',
		];
		$setClauses = [];
		$validatedFields = [];
		foreach ($changedFields as $index => $field) {
			if (
				$index !== count($validatedFields) ||
				is_string($field) === false ||
				isset($columnMap[$field]) === false ||
				in_array($field, $validatedFields, true) === true
			) {
				return false;
			}
			$validatedFields[] = $field;
			$setClauses[] = $columnMap[$field] . ' = :' . $field;
		}

		if (empty($setClauses) === true) {
			return true;
		}

		$strSQL = "
			UPDATE
				shop_reservation_settings
			SET
				" . implode(",\n\t\t\t\t", $setClauses) . "
			WHERE
				shop_id = :shop_id
		";
		$newStmt = $DB_CONNECT->prepare($strSQL);
		foreach ($validatedFields as $field) {
			$value = $normalizedData[$field];
			$newStmt->bindValue(':' . $field, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
		}
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$result = $newStmt->execute();
		$newStmt->closeCursor();
		return $result === true;
	} catch (PDOException $e) {
		return false;
	}
}
