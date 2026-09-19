<?php
/*
 * [cms_config/common/set_reservation_detail_edit_function.php]
 *  飲食店予約詳細編集のpure共通処理
 */
require_once __DIR__ . '/set_reservation_function.php';

/**
 * 予約詳細編集用整数正規化
 *  integerまたはASCII decimal文字列だけを範囲内の整数へ変換する
 */
function normalizeReservationDetailEditInteger($value, $min = null, $max = null)
{
	if (is_int($value) === true) {
		$normalizedValue = $value;
	} elseif (is_string($value) === true && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
		$phpIntMax = (string)PHP_INT_MAX;
		if (strlen($value) > strlen($phpIntMax) || (strlen($value) === strlen($phpIntMax) && strcmp($value, $phpIntMax) > 0)) {
			return null;
		}
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
 * 予約詳細編集用正整数文字列正規化
 *  先頭ゼロ、全角数字、小数、PHP整数上限超過を拒否する
 */
function normalizeReservationDetailEditPositiveIntegerString($value)
{
	if (is_string($value) === false || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
		return null;
	}
	return normalizeReservationDetailEditInteger($value, 1, null);
}

/**
 * 予約詳細編集用list配列判定
 *  guest slot順を壊す連想配列を拒否する
 */
function isReservationDetailEditListArray($values)
{
	if (is_array($values) === false) {
		return false;
	}
	return empty($values) === true || array_keys($values) === range(0, count($values) - 1);
}

/**
 * 予約詳細編集用Unicode空白判定
 *  不正UTF-8やPCRE failureも安全側で不正として扱う
 */
function reservationDetailEditBlankResult($value)
{
	if (is_string($value) === false) {
		return null;
	}
	$result = preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $value);
	return $result === false ? null : $result === 1;
}

/**
 * 予約詳細編集用文字数取得
 *  UTF-8不正を拒否し文字数を返す
 */
function reservationDetailEditStringLength($value)
{
	if (is_string($value) === false || preg_match('//u', $value) !== 1) {
		return null;
	}
	return function_exists('mb_strlen') === true ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/**
 * 予約詳細編集用optional文字列正規化
 *  空文字とUnicode空白だけの値をNULLへ変換する
 */
function normalizeReservationDetailEditOptionalString($value)
{
	$blank = reservationDetailEditBlankResult($value);
	if ($blank === null) {
		return false;
	}
	return $blank === true ? null : $value;
}

/**
 * 予約詳細編集POST構文判定
 *  strict allow-list、型、文字数、semantic値を副作用なく検証する
 */
function isReservationDetailEditPostSyntaxValid($post)
{
	$allowedKeys = [
		'noUpDateKey', 'csrfToken', 'reservationId', 'detailEditVersion',
		'reservationRoute', 'reservationPerson', 'customerName', 'customerKana',
		'customerTel', 'customerEmail',
		'reservationMenu', 'accommodationName', 'reservationNote', 'shopMemo',
	];
	$requiredScalarKeys = array_values(array_diff($allowedKeys, ['reservationMenu']));
	if (is_array($post) === false) {
		return false;
	}
	foreach (array_keys($post) as $key) {
		if (is_string($key) === false || in_array($key, $allowedKeys, true) === false) {
			return false;
		}
	}
	foreach ($requiredScalarKeys as $key) {
		if (array_key_exists($key, $post) === false || is_string($post[$key]) === false) {
			return false;
		}
	}
	if (array_key_exists('reservationMenu', $post) === true) {
		if (isReservationDetailEditListArray($post['reservationMenu']) === false) {
			return false;
		}
		foreach ($post['reservationMenu'] as $menuValue) {
			if (
				is_string($menuValue) === false ||
				($menuValue !== '' && normalizeReservationDetailEditPositiveIntegerString($menuValue) === null)
			) {
				return false;
			}
		}
	}

	$reservationId = normalizeReservationDetailEditPositiveIntegerString($post['reservationId']);
	$partySize = normalizeReservationDetailEditPositiveIntegerString($post['reservationPerson']);
	if (
		$reservationId === null ||
		$partySize === null ||
		$partySize > 4 ||
		in_array($post['reservationRoute'], ['web', 'tel', 'other'], true) === false ||
		preg_match('/\A[0-9a-f]{64}\z/D', $post['csrfToken']) !== 1 ||
		preg_match('/\A[0-9a-f]{64}\z/D', $post['detailEditVersion']) !== 1 ||
		$post['noUpDateKey'] === ''
	) {
		return false;
	}

	if (
		normalizeReservationCustomerIdentityValue($post['customerName']) === null ||
		normalizeReservationCustomerIdentityValue($post['customerKana']) === null
	) {
		return false;
	}
	$customerTelBlank = reservationDetailEditBlankResult($post['customerTel']);
	$customerTelLength = reservationDetailEditStringLength($post['customerTel']);
	if ($customerTelBlank !== false || $customerTelLength === null || $customerTelLength > 20) {
		return false;
	}

	$optionalFields = ['customerEmail', 'accommodationName', 'reservationNote', 'shopMemo'];
	$optionalValues = [];
	foreach ($optionalFields as $key) {
		$optionalValues[$key] = normalizeReservationDetailEditOptionalString($post[$key]);
		if ($optionalValues[$key] === false) {
			return false;
		}
	}
	$email = $optionalValues['customerEmail'];
	$accommodationName = $optionalValues['accommodationName'];
	$customerNote = $optionalValues['reservationNote'];
	$shopMemo = $optionalValues['shopMemo'];
	if ($email !== null && (reservationDetailEditStringLength($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
		return false;
	}
	if ($accommodationName !== null && reservationDetailEditStringLength($accommodationName) > 100) {
		return false;
	}
	if (($customerNote !== null && strlen($customerNote) > 65535) || ($shopMemo !== null && strlen($shopMemo) > 65535)) {
		return false;
	}
	return true;
}

/**
 * 予約詳細編集POST正規化
 *  構文確認済みrequestをDB保存用の固定shapeへ変換する
 */
function normalizeReservationDetailEditPost($post)
{
	if (isReservationDetailEditPostSyntaxValid($post) === false) {
		return false;
	}
	$reservationId = normalizeReservationDetailEditPositiveIntegerString($post['reservationId']);
	$partySize = normalizeReservationDetailEditPositiveIntegerString($post['reservationPerson']);
	$routeMap = ['web' => 1, 'tel' => 2, 'other' => 3];
	$email = normalizeReservationDetailEditOptionalString($post['customerEmail']);
	$accommodationName = normalizeReservationDetailEditOptionalString($post['accommodationName']);
	$customerNote = normalizeReservationDetailEditOptionalString($post['reservationNote']);
	$shopMemo = normalizeReservationDetailEditOptionalString($post['shopMemo']);
	$customerName = normalizeReservationCustomerIdentityValue($post['customerName']);
	$customerKana = normalizeReservationCustomerIdentityValue($post['customerKana']);
	$menuSlots = [];
	foreach (($post['reservationMenu'] ?? []) as $menuValue) {
		if ($menuValue === '') {
			$menuSlots[] = null;
			continue;
		}
		$menuSlots[] = normalizeReservationDetailEditPositiveIntegerString($menuValue);
	}

	return [
		'no_update_key' => $post['noUpDateKey'],
		'csrf_token' => $post['csrfToken'],
		'reservation_id' => $reservationId,
		'detail_edit_version' => $post['detailEditVersion'],
		'party_size' => $partySize,
		'menu_key_present' => array_key_exists('reservationMenu', $post),
		'menu_slots' => $menuSlots,
		'reservation_data' => [
			'reservation_route' => $routeMap[$post['reservationRoute']],
			'party_size' => $partySize,
			'customer_name' => $customerName,
			'customer_kana' => $customerKana,
			'customer_tel' => $post['customerTel'],
			'customer_email' => $email,
			'accommodation_name' => $accommodationName,
			'customer_note' => $customerNote,
			'shop_memo' => $shopMemo,
		],
	];
}

/**
 * 予約詳細編集用日時形式判定
 *  DBのY-m-d H:i:s文字列だけをcanonical stateへ許可する
 */
function isReservationDetailEditDateTimeString($value)
{
	if (is_string($value) === false || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value) !== 1) {
		return false;
	}
	$dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
	$errors = DateTimeImmutable::getLastErrors();
	return $dateTime !== false &&
		($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) &&
		$dateTime->format('Y-m-d H:i:s') === $value;
}

/**
 * 予約詳細表示用menu row判定
 *  Step3-Gで表示可能なsnapshotかをDetail Edit固有条件と分離して検証する
 */
function isReservationDetailReadMenuRowsValid($menuRows, $reservationId, $partySize)
{
	$reservationId = normalizeReservationDetailEditInteger($reservationId, 1, null);
	$partySize = normalizeReservationDetailEditInteger($partySize, 1, 4);
	if ($reservationId === null || $partySize === null || isReservationDetailEditListArray($menuRows) === false) {
		return false;
	}

	$reservationMenuIds = [];
	$guestNos = [];
	foreach ($menuRows as $row) {
		if (is_array($row) === false) {
			return false;
		}
		$reservationMenuId = normalizeReservationDetailEditInteger($row['reservation_menu_id'] ?? null, 1, null);
		$rowReservationId = normalizeReservationDetailEditInteger($row['reservation_id'] ?? null, 1, null);
		$guestNo = normalizeReservationDetailEditInteger($row['guest_no'] ?? null, 1, $partySize);
		$menuId = $row['menu_id'] ?? null;
		if ($menuId !== null) {
			$menuId = normalizeReservationDetailEditInteger($menuId, 1, null);
		}
		$menuPrice = normalizeReservationDetailEditInteger($row['menu_price_snapshot'] ?? null, 0, null);
		$taxIncluded = normalizeReservationDetailEditInteger($row['tax_included_snapshot'] ?? null, 0, 1);
		if (
			$reservationMenuId === null ||
			$rowReservationId !== $reservationId ||
			$guestNo === null ||
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
	}
	return true;
}

/**
 * 予約詳細編集用menu row正規化
 *  ownership、guest slot、snapshot破損を検証しguest_no順へ揃える
 */
function normalizeReservationDetailEditMenuRows($menuRows, $reservationId, $partySize)
{
	if (isReservationDetailEditListArray($menuRows) === false) {
		return false;
	}
	$normalizedRows = [];
	$guestNos = [];
	foreach ($menuRows as $row) {
		if (is_array($row) === false) {
			return false;
		}
		$rowReservationId = normalizeReservationDetailEditInteger($row['reservation_id'] ?? null, 1, null);
		$guestNo = normalizeReservationDetailEditInteger($row['guest_no'] ?? null, 1, $partySize);
		$menuId = normalizeReservationDetailEditInteger($row['menu_id'] ?? null, 1, null);
		$menuPrice = normalizeReservationDetailEditInteger($row['menu_price_snapshot'] ?? null, 0, null);
		$taxIncluded = normalizeReservationDetailEditInteger($row['tax_included_snapshot'] ?? null, 0, 1);
		$menuName = $row['menu_name_snapshot'] ?? null;
		if (
			$rowReservationId !== $reservationId ||
			$guestNo === null ||
			$menuId === null ||
			$menuPrice === null ||
			$taxIncluded === null ||
			is_string($menuName) === false ||
			reservationDetailEditBlankResult($menuName) !== false ||
			isset($guestNos[$guestNo]) === true
		) {
			return false;
		}
		$guestNos[$guestNo] = true;
		$normalizedRows[] = [
			'reservation_id' => $rowReservationId,
			'guest_no' => $guestNo,
			'menu_id' => $menuId,
			'menu_name_snapshot' => $menuName,
			'menu_price_snapshot' => $menuPrice,
			'tax_included_snapshot' => $taxIncluded,
		];
	}
	usort($normalizedRows, function ($a, $b) {
		return $a['guest_no'] <=> $b['guest_no'];
	});
	return $normalizedRows;
}

/**
 * 予約詳細編集canonical state生成
 *  初期表示と保存時の同一固定順stateを構築する
 */
function buildReservationDetailEditCanonicalState($reservation, $menuRows, $menuSelectionType)
{
	if (is_array($reservation) === false) {
		return false;
	}
	$reservationId = normalizeReservationDetailEditInteger($reservation['id'] ?? null, 1, null);
	$shopId = normalizeReservationDetailEditInteger($reservation['shop_id'] ?? null, 1, null);
	$reservationRoute = normalizeReservationDetailEditInteger($reservation['reservation_route'] ?? null, 1, 3);
	$partySize = normalizeReservationDetailEditInteger($reservation['party_size'] ?? null, 1, 4);
	$status = normalizeReservationDetailEditInteger($reservation['status'] ?? null, 1, 4);
	$menuSelectionType = normalizeReservationDetailEditInteger($menuSelectionType, 0, 2);
	if ($reservationId === null || $shopId === null || $reservationRoute === null || $partySize === null || $status === null || $menuSelectionType === null) {
		return false;
	}

	$requiredStrings = ['customer_name', 'customer_kana', 'customer_tel'];
	$nullableStrings = ['customer_email', 'accommodation_name', 'customer_note', 'shop_memo'];
	foreach ($requiredStrings as $key) {
		if (array_key_exists($key, $reservation) === false || reservationDetailEditStringLength($reservation[$key]) === null) {
			return false;
		}
	}
	if (
		normalizeReservationCustomerIdentityValue($reservation['customer_name']) !== $reservation['customer_name'] ||
		normalizeReservationCustomerIdentityValue($reservation['customer_kana']) !== $reservation['customer_kana']
	) {
		return false;
	}
	foreach ($nullableStrings as $key) {
		if (array_key_exists($key, $reservation) === false || ($reservation[$key] !== null && reservationDetailEditStringLength($reservation[$key]) === null)) {
			return false;
		}
	}
	$cancelledAt = $reservation['cancelled_at'] ?? null;
	if (
		(in_array($status, [1, 2], true) === true && $cancelledAt !== null) ||
		(in_array($status, [3, 4], true) === true && ($cancelledAt === null || isReservationDetailEditDateTimeString($cancelledAt) === false))
	) {
		return false;
	}
	$normalizedMenuRows = normalizeReservationDetailEditMenuRows($menuRows, $reservationId, $partySize);
	if ($normalizedMenuRows === false) {
		return false;
	}

	$canonicalMenus = [];
	foreach ($normalizedMenuRows as $row) {
		$canonicalMenus[] = [
			'guest_no' => $row['guest_no'],
			'menu_id' => $row['menu_id'],
			'menu_name_snapshot' => $row['menu_name_snapshot'],
			'menu_price_snapshot' => $row['menu_price_snapshot'],
			'tax_included_snapshot' => $row['tax_included_snapshot'],
		];
	}

	return [
		'reservation' => [
			'id' => $reservationId,
			'shop_id' => $shopId,
			'reservation_route' => $reservationRoute,
			'party_size' => $partySize,
			'customer_name' => $reservation['customer_name'],
			'customer_kana' => $reservation['customer_kana'],
			'customer_tel' => $reservation['customer_tel'],
			'customer_email' => $reservation['customer_email'],
			'accommodation_name' => $reservation['accommodation_name'],
			'customer_note' => $reservation['customer_note'],
			'shop_memo' => $reservation['shop_memo'],
			'status' => $status,
			'cancelled_at' => $cancelledAt,
		],
		'menus' => $canonicalMenus,
		'settings' => [
			'menu_selection_type' => $menuSelectionType,
		],
	];
}

/**
 * 予約詳細編集version生成
 *  canonical JSONをSHA-256 lowercase hexへ変換する
 */
function buildReservationDetailEditVersion($reservation, $menuRows, $menuSelectionType)
{
	$canonicalState = buildReservationDetailEditCanonicalState($reservation, $menuRows, $menuSelectionType);
	if ($canonicalState === false) {
		return false;
	}
	$canonicalJson = json_encode($canonicalState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	return is_string($canonicalJson) === true ? hash('sha256', $canonicalJson) : false;
}

/**
 * 予約詳細編集対象reservation差分生成
 *  固定fieldだけをstrict比較し変更値だけ返す
 */
function buildReservationDetailEditReservationChanges($freshReservation, $requestedReservationData)
{
	$allowedColumns = [
		'reservation_route', 'party_size', 'customer_name', 'customer_kana',
		'customer_tel', 'customer_email',
		'accommodation_name', 'customer_note', 'shop_memo',
	];
	if (is_array($freshReservation) === false || is_array($requestedReservationData) === false || array_keys($requestedReservationData) !== $allowedColumns) {
		return false;
	}
	$changes = [];
	foreach ($allowedColumns as $column) {
		if (array_key_exists($column, $freshReservation) === false) {
			return false;
		}
		if ($freshReservation[$column] !== $requestedReservationData[$column]) {
			$changes[$column] = $requestedReservationData[$column];
		}
	}
	return $changes;
}

/**
 * 予約詳細編集menu差分計画
 *  historical snapshotを保持し変更guest slotだけをwrite計画へ分ける
 */
function buildReservationDetailEditMenuPlan($menuSelectionType, $currentPartySize, $newPartySize, $currentMenuRows, $requestedMenuSlots)
{
	$menuSelectionType = normalizeReservationDetailEditInteger($menuSelectionType, 0, 2);
	$currentPartySize = normalizeReservationDetailEditInteger($currentPartySize, 1, 4);
	$newPartySize = normalizeReservationDetailEditInteger($newPartySize, 1, 4);
	if ($menuSelectionType === null || $currentPartySize === null || $newPartySize === null || isReservationDetailEditListArray($currentMenuRows) === false || isReservationDetailEditListArray($requestedMenuSlots) === false) {
		return false;
	}
	if (($menuSelectionType === 0 && empty($requestedMenuSlots) === false) || ($menuSelectionType !== 0 && count($requestedMenuSlots) !== $newPartySize)) {
		return false;
	}

	$currentByGuestNo = [];
	foreach ($currentMenuRows as $row) {
		$guestNo = $row['guest_no'] ?? null;
		$menuId = $row['menu_id'] ?? null;
		if (is_int($guestNo) === false || $guestNo < 1 || $guestNo > $currentPartySize || is_int($menuId) === false || $menuId < 1 || isset($currentByGuestNo[$guestNo]) === true) {
			return false;
		}
		$currentByGuestNo[$guestNo] = $row;
	}

	$plan = ['unchanged' => [], 'updates' => [], 'inserts' => [], 'deletes' => [], 'snapshot_slots' => array_fill(0, $newPartySize, null), 'menu_ids' => []];
	foreach ($currentByGuestNo as $guestNo => $row) {
		if ($guestNo > $newPartySize) {
			$plan['deletes'][] = $guestNo;
		}
	}
	if ($menuSelectionType === 0) {
		return $plan;
	}

	foreach ($requestedMenuSlots as $index => $requestedMenuId) {
		$guestNo = $index + 1;
		$currentRow = $currentByGuestNo[$guestNo] ?? null;
		$currentMenuId = $currentRow['menu_id'] ?? null;
		if ($requestedMenuId !== null && (is_int($requestedMenuId) === false || $requestedMenuId < 1)) {
			return false;
		}
		if ($currentMenuId === $requestedMenuId) {
			if ($menuSelectionType === 2 && $requestedMenuId === null && $guestNo > $currentPartySize) {
				return false;
			}
			$plan['unchanged'][] = $guestNo;
			continue;
		}
		if ($requestedMenuId === null) {
			if ($menuSelectionType === 2) {
				return false;
			}
			if ($currentRow !== null) {
				$plan['deletes'][] = $guestNo;
			}
			continue;
		}
		$operation = $currentRow === null ? 'inserts' : 'updates';
		$plan[$operation][] = $guestNo;
		$plan['snapshot_slots'][$index] = $requestedMenuId;
		$plan['menu_ids'][$requestedMenuId] = $requestedMenuId;
	}
	$plan['menu_ids'] = array_values($plan['menu_ids']);
	return $plan;
}

/**
 * 予約詳細編集menu write行生成
 *  fresh masterから生成済みsnapshotをINSERTとUPDATEへ振り分ける
 */
function completeReservationDetailEditMenuPlan($plan, $snapshotRows)
{
	if (is_array($plan) === false || isReservationDetailEditListArray($snapshotRows) === false) {
		return false;
	}
	$snapshotByGuestNo = [];
	foreach ($snapshotRows as $row) {
		$guestNo = normalizeReservationDetailEditInteger($row['guest_no'] ?? null, 1, 4);
		if ($guestNo === null || isset($snapshotByGuestNo[$guestNo]) === true) {
			return false;
		}
		$snapshotByGuestNo[$guestNo] = $row;
	}
	foreach (['updates', 'inserts'] as $operation) {
		$rows = [];
		foreach (($plan[$operation] ?? []) as $guestNo) {
			if (isset($snapshotByGuestNo[$guestNo]) === false) {
				return false;
			}
			$rows[] = $snapshotByGuestNo[$guestNo];
		}
		$plan[$operation] = $rows;
	}
	if (count($snapshotRows) !== count($plan['updates']) + count($plan['inserts'])) {
		return false;
	}
	unset($plan['snapshot_slots'], $plan['menu_ids']);
	$plan['deletes'] = array_values(array_unique($plan['deletes']));
	sort($plan['deletes'], SORT_NUMERIC);
	return $plan;
}

/**
 * 予約詳細編集人数変更判定
 *  status 1/2の増員時だけfresh割当実席capacityを検証する
 */
function isReservationDetailEditPartySizeAllowed($currentPartySize, $newPartySize, $status, $seatRows = null)
{
	$currentPartySize = normalizeReservationDetailEditInteger($currentPartySize, 1, 4);
	$newPartySize = normalizeReservationDetailEditInteger($newPartySize, 1, 4);
	$status = normalizeReservationDetailEditInteger($status, 1, 4);
	if ($currentPartySize === null || $newPartySize === null || $status === null) {
		return false;
	}
	if ($newPartySize <= $currentPartySize || in_array($status, [3, 4], true) === true) {
		return true;
	}
	if (isReservationDetailEditListArray($seatRows) === false) {
		return false;
	}

	$totalCapacity = 0;
	$seatIds = [];
	foreach ($seatRows as $row) {
		if (is_array($row) === false) {
			return false;
		}
		$seatId = normalizeReservationDetailEditInteger($row['seat_id'] ?? null, 1, null);
		$currentSeatId = normalizeReservationDetailEditInteger($row['current_seat_id'] ?? null, 1, null);
		$isActive = normalizeReservationDetailEditInteger($row['is_active'] ?? null, 0, 1);
		$isTempMove = normalizeReservationDetailEditInteger($row['is_temp_move'] ?? null, 0, 1);
		$capacity = normalizeReservationDetailEditInteger($row['capacity'] ?? null, 0, null);
		if ($seatId === null || $currentSeatId !== $seatId || $isActive === null || $isTempMove === null || $capacity === null || isset($seatIds[$seatId]) === true) {
			return false;
		}
		$seatIds[$seatId] = true;
		if ($isTempMove === 1) {
			continue;
		}
		if ($isActive === 0) {
			continue;
		}
		if ($capacity < 1) {
			return false;
		}
		$totalCapacity += $capacity;
	}
	return $newPartySize <= $totalCapacity;
}
