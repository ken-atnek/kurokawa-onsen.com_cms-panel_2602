<?php
/*
 * [予約カレンダー日次状態変更共通処理]
 */

/**
 * 店休日判定用予約件数を取得
 *  status=1/2の予約だけを数える
 */
function countReservationCalendarOverrideActiveReservations($reservations)
{
	if (is_array($reservations) === false) {
		return null;
	}

	$count = 0;
	foreach ($reservations as $reservation) {
		if (is_array($reservation) === false) {
			return null;
		}
		$status = normalizeReservationRegistrationInteger($reservation['status'] ?? null, 1, 4);
		if ($status === null) {
			return null;
		}
		if (isReservationOccupiedStatus($status) === true) {
			$count++;
		}
	}

	return $count;
}

/**
 * 日次状態変更action stateを生成
 *  DB・SESSIONへ依存せず表示とwrite再検証に共用する
 */
function buildReservationCalendarOverrideActionState(
	$selectedDate,
	$today,
	$shopEligible,
	$closedWeekdays,
	$overrideStatusType,
	$reservations
) {
	$selectedDate = normalizeReservationReadSelectedDate($selectedDate);
	$today = normalizeReservationReadSelectedDate($today);
	if ($selectedDate === null || $today === null || is_bool($shopEligible) === false) {
		return false;
	}

	$statusType = null;
	if ($overrideStatusType !== null) {
		$statusType = normalizeReservationRegistrationInteger($overrideStatusType, 1, 3);
		if ($statusType === null) {
			return false;
		}
	}

	$regularHolidays = normalizeRegularHolidays($closedWeekdays);
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate, new DateTimeZone('Asia/Tokyo'));
	if ($date instanceof DateTimeImmutable === false) {
		return false;
	}
	$isRegularHoliday = in_array((int)$date->format('w'), $regularHolidays, true);
	if ($statusType === 3 && $isRegularHoliday === false) {
		return false;
	}

	$activeReservationCount = countReservationCalendarOverrideActiveReservations($reservations);
	if ($activeReservationCount === null) {
		return false;
	}

	$effectiveState = 'normal';
	if ($statusType === 1) {
		$effectiveState = 'stopped';
	} elseif ($statusType === 2) {
		$effectiveState = 'shop_holiday';
	} elseif ($isRegularHoliday && $statusType !== 3) {
		$effectiveState = 'regular_holiday';
	}

	$actions = [];
	$isPast = $selectedDate < $today;
	if ($shopEligible && $isPast === false) {
		if ($statusType === 1 || $statusType === 2 || ($isRegularHoliday && $statusType === null)) {
			$actions[] = 'setAvailable';
		} elseif ($statusType === 3) {
			$actions[] = 'restoreRegularHoliday';
			if ($activeReservationCount === 0) {
				$actions[] = 'setShopHoliday';
			}
			$actions[] = 'setStopped';
		} else {
			if ($activeReservationCount === 0) {
				$actions[] = 'setShopHoliday';
			}
			$actions[] = 'setStopped';
		}
	}

	return [
		'selected_date' => $selectedDate,
		'is_past' => $isPast,
		'shop_eligible' => $shopEligible,
		'is_regular_holiday' => $isRegularHoliday,
		'override_status_type' => $statusType,
		'active_reservation_count' => $activeReservationCount,
		'effective_state' => $effectiveState,
		'actions' => $actions,
	];
}

/**
 * 日次状態変更actionの実行可否を判定
 *  表示状態をauthorityにせずidempotentなwriteも許容する
 */
function isReservationCalendarOverrideActionAllowed($state, $action)
{
	if (
		is_array($state) === false ||
		is_string($action) === false ||
		($state['shop_eligible'] ?? null) !== true ||
		($state['is_past'] ?? null) !== false
	) {
		return false;
	}

	if ($action === 'setStopped' || $action === 'setAvailable') {
		return true;
	}
	if ($action === 'setShopHoliday') {
		return ($state['active_reservation_count'] ?? null) === 0;
	}
	if ($action === 'restoreRegularHoliday') {
		return ($state['is_regular_holiday'] ?? null) === true &&
			($state['override_status_type'] ?? null) === 3;
	}

	return false;
}

/**
 * 日次状態変更DB操作を生成
 *  actionとfresh stateからUPSERTまたはDELETEを確定する
 */
function buildReservationCalendarOverrideWriteOperation($state, $action)
{
	if (isReservationCalendarOverrideActionAllowed($state, $action) === false) {
		return null;
	}
	if ($action === 'setStopped') {
		return ['operation' => 'upsert', 'status_type' => 1];
	}
	if ($action === 'setShopHoliday') {
		return ['operation' => 'upsert', 'status_type' => 2];
	}
	if ($action === 'setAvailable' && ($state['is_regular_holiday'] ?? false) === true) {
		return ['operation' => 'upsert', 'status_type' => 3];
	}
	if ($action === 'setAvailable' || $action === 'restoreRegularHoliday') {
		return ['operation' => 'delete', 'status_type' => null];
	}

	return null;
}

/**
 * 日次状態変更actionの空fragmentを生成
 *  操作不可時もAjax差し替えrootを維持する
 */
function clientReservationCalendarOverrideRenderEmptyActionsTag()
{
	return '<div class="reservation-calendar-actions" style="display: contents;"></div>';
}

/**
 * 日次状態変更action fragmentを生成
 *  固定文言と許可済みactionだけをbuttonとして出力する
 */
function clientReservationCalendarOverrideRenderActionsTag($state)
{
	if (is_array($state) === false || is_array($state['actions'] ?? null) === false) {
		return null;
	}

	$buttonDefinitions = [
		'setAvailable' => ['class' => 'btn-is-active', 'label' => 'この日を受付可能にする'],
		'restoreRegularHoliday' => ['class' => 'btn-is-close', 'label' => 'この日を定休日に戻す'],
		'setShopHoliday' => ['class' => 'btn-is-close', 'label' => 'この日を店休日にする'],
		'setStopped' => ['class' => 'btn-is-close', 'label' => 'この日を受付停止にする'],
	];
	$buttons = [];
	foreach ($state['actions'] as $action) {
		if (is_string($action) === false || isset($buttonDefinitions[$action]) === false) {
			return null;
		}
		$definition = $buttonDefinitions[$action];
		$actionHtml = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
		$classHtml = htmlspecialchars($definition['class'], ENT_QUOTES, 'UTF-8');
		$labelHtml = htmlspecialchars($definition['label'], ENT_QUOTES, 'UTF-8');
		$buttons[] = '<button type="button" class="' . $classHtml . '" data-reservation-calendar-action="' . $actionHtml . '"><span>' . $labelHtml . '</span></button>';
	}

	return '<div class="reservation-calendar-actions" style="display: contents;">' . implode('', $buttons) . '</div>';
}
