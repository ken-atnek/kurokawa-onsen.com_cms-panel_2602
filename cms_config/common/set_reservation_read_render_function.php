<?php
/*
 * [予約カレンダー・選択日表示処理]
 */

/**
 * 予約参照HTML escape
 *  DB値・ユーザー由来値をUTF-8のHTML本文・属性向けに無害化する
 */
function clientReservationReadEscape($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * availability表示名取得
 *  日単位statusと休日理由を管理画面表示へ変換する
 */
function clientReservationReadAvailabilityLabel($status, $reason)
{
  if ($status === 'normal') {
    return '受付中';
  }
  if ($status === 'full') {
    return '満席';
  }
  if ($status === 'stopped') {
    return '受付停止';
  }
  if ($status === 'holiday' && $reason === 'shop_holiday') {
    return '店休日';
  }
  if ($status === 'holiday' && $reason === 'regular_holiday') {
    return '定休日';
  }
  return '';
}

/**
 * availability class取得
 *  既存calendar・status表示classへ変換する
 */
function clientReservationReadAvailabilityClass($status, $statusSummary = false)
{
  if ($status === 'normal') {
    return $statusSummary === true ? 'is-available' : '';
  }
  $classes = [
    'full' => 'is-full',
    'holiday' => 'is-holiday',
    'stopped' => 'is-stopped',
  ];
  return $classes[$status] ?? '';
}

/**
 * 月間カレンダーHTML生成
 *  既存contents-calender構造で35・42日gridを生成し、店舗受付可否と選択状態を表示へ反映する
 */
function clientReservationReadRenderCalendarTag($range, $days, $selectedDate = null, $shopEligible = true)
{
  if (is_array($range) === false || is_array($days) === false || is_bool($shopEligible) === false) {
    return null;
  }
  if ($selectedDate !== null) {
    $selectedDate = normalizeReservationReadSelectedDate($selectedDate);
    if ($selectedDate === null) {
      return null;
    }
  }
  $targetMonth = $range['target_month'] ?? '';
  $targetMonthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $targetMonth . '-01', new DateTimeZone('Asia/Tokyo'));
  if ($targetMonthDate instanceof DateTimeImmutable === false) {
    return null;
  }
  $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

  $lines = [];
  $lines[] = '<div class="contents-calender">';
  $lines[] = '  <div class="box-month">';
  $lines[] = '    <button type="button" class="btn-prev">';
  $lines[] = '      <span>前月</span>';
  $lines[] = '    </button>';
  $lines[] = '    <span>' . clientReservationReadEscape($targetMonthDate->format('Y年n月')) . '</span>';
  $lines[] = '    <button type="button" class="btn-next">';
  $lines[] = '      <span>翌月</span>';
  $lines[] = '    </button>';
  $lines[] = '  </div>';
  $lines[] = '';
  $lines[] = '  <ul class="list-week">';
  $lines[] = '    <li class="sun">日</li>';
  $lines[] = '    <li>月</li>';
  $lines[] = '    <li>火</li>';
  $lines[] = '    <li>水</li>';
  $lines[] = '    <li>木</li>';
  $lines[] = '    <li>金</li>';
  $lines[] = '    <li class="sat">土</li>';
  $lines[] = '  </ul>';
  $lines[] = '  <ul class="list-days">';
  $selectedDateCount = 0;
  foreach ($range['dates'] ?? [] as $date) {
    if (isset($days[$date]) === false) {
      return null;
    }
    $day = $days[$date];
    $dayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
    if ($dayDate instanceof DateTimeImmutable === false) {
      return null;
    }
    $classes = [];
    $isOutside = $dayDate->format('Y-m') !== $targetMonth;
    if ($isOutside) {
      $classes[] = 'is-outside';
    }
    $availabilityClass = clientReservationReadAvailabilityClass($day['availability_status'] ?? '');
    if ($shopEligible && $availabilityClass !== '') {
      $classes[] = $availabilityClass;
    }
    if ($selectedDate === $date) {
      $classes[] = 'is-selected';
      $selectedDateCount++;
    }
    $classAttribute = empty($classes) === true ? '' : ' class="' . implode(' ', $classes) . '"';
    $availabilityLabel = clientReservationReadAvailabilityLabel($day['availability_status'] ?? '', $day['availability_reason'] ?? null);
    if ($availabilityLabel === '') {
      return null;
    }
    $statusLabel = $shopEligible ? $availabilityLabel : '予約不可';
    $statusStyleAttribute = $shopEligible ? '' : ' style="background-color:#555; color:#fff;"';
    if ($isOutside === false && $shopEligible && ($day['availability_status'] ?? '') === 'normal' && $date < $today) {
      $statusLabel = '営業終了';
      $statusStyleAttribute = ' style="background-color:#f2f3f5; color:#6f7478;"';
    }
    $reservationCount = (int)($day['reservation_count'] ?? 0);
    $numberLabel = ($day['availability_status'] ?? '') === 'holiday' ? '-' : $reservationCount . '件';
    $lines[] = '    <li data-day="' . clientReservationReadEscape($dayDate->format('j')) . '" data-date="' . clientReservationReadEscape($date) . '"' . $classAttribute . '>';
    if ($isOutside === false) {
      $lines[] = '      <span class="status"' . $statusStyleAttribute . '>' . clientReservationReadEscape($statusLabel) . '</span>';
      $lines[] = '      <span class="number">' . clientReservationReadEscape($numberLabel) . '</span>';
    }
    $lines[] = '    </li>';
  }
  if ($selectedDate !== null && $selectedDateCount !== 1) {
    return null;
  }
  $lines[] = '  </ul>';
  $lines[] = '</div>';
  return implode("\n", $lines);
}

/**
 * 選択日status HTML生成
 *  店舗受付可否・受付状態・status 1/2の予約件数・人数を既存list-status構造で返す
 */
function clientReservationReadRenderStatusTag($day, $shopEligible = true)
{
  if (is_array($day) === false || is_bool($shopEligible) === false) {
    return null;
  }
  $date = normalizeReservationReadSelectedDate($day['date'] ?? null);
  $dateTime = $date === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
  $weekdays = ['日', '月', '火', '水', '木', '金', '土'];
  $status = $day['availability_status'] ?? '';
  $availabilityLabel = clientReservationReadAvailabilityLabel($status, $day['availability_reason'] ?? null);
  $statusLabel = $shopEligible ? $availabilityLabel : '予約不可';
  $statusClass = $shopEligible ? clientReservationReadAvailabilityClass($status, true) : '';
  if (
    $dateTime instanceof DateTimeImmutable === false ||
    $availabilityLabel === '' ||
    ($shopEligible && $statusClass === '')
  ) {
    return null;
  }
  $dateLabel = $dateTime->format('n/j') . '(' . $weekdays[(int)$dateTime->format('w')] . ')';
  $statusClassAttribute = $statusClass === '' ? '' : ' class="' . clientReservationReadEscape($statusClass) . '"';
  $isPastNormal = $shopEligible && $status === 'normal' && $date < (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  if ($isPastNormal) {
    $statusLabel = '営業終了';
  }
  $statusStyleAttribute = $isPastNormal ? ' style="background-color:#f2f3f5; color:#6f7478; border-color:#dce0e3;"' : '';

  $lines = [];
  $lines[] = '<ul class="list-status">';
  $lines[] = '  <li>';
  $lines[] = '    <h4>選択日</h4>';
  $lines[] = '    <span id="selectedReservationDateShortLabel">' . clientReservationReadEscape($dateLabel) . '</span>';
  $lines[] = '  </li>';
  $lines[] = '  <li' . $statusClassAttribute . $statusStyleAttribute . '>';
  $lines[] = '    <h4>受付状態</h4>';
  $lines[] = '    <span>' . clientReservationReadEscape($statusLabel) . '</span>';
  $lines[] = '  </li>';
  $lines[] = '  <li>';
  $lines[] = '    <h4>予約件数</h4>';
  $lines[] = '    <span>' . (int)($day['reservation_count'] ?? 0) . '<i>件</i></span>';
  $lines[] = '  </li>';
  $lines[] = '  <li>';
  $lines[] = '    <h4>予約人数</h4>';
  $lines[] = '    <span>' . (int)($day['guest_count'] ?? 0) . '<i>名</i></span>';
  $lines[] = '  </li>';
  $lines[] = '</ul>';
  return implode("\n", $lines);
}

/**
 * 予約経路表示名取得
 *  保存済みroute 1～3を管理画面表示へ変換する
 */
function clientReservationReadRouteLabel($route)
{
  $labels = [1 => 'Web', 2 => '電話', 3 => 'その他'];
  return $labels[(int)$route] ?? '';
}

/**
 * 予約status表示名取得
 *  保存済みstatus 1～4を管理画面表示へ変換する
 */
function clientReservationReadStatusLabel($status)
{
  $labels = [1 => '確定', 2 => '来店済み', 3 => 'キャンセル', 4 => '無断キャンセル'];
  return $labels[(int)$status] ?? '';
}

/**
 * 予約menu snapshot表示生成
 *  guest_no順のsnapshotを予約単位の表示文字列へまとめる
 */
function clientReservationReadMenuLabel($menus)
{
  if (is_array($menus) === false || empty($menus) === true) {
    return '---';
  }
  $labels = [];
  foreach ($menus as $menu) {
    if (is_array($menu) === false) {
      return null;
    }
    $guestNo = (int)($menu['guest_no'] ?? 0);
    if ($guestNo < 1) {
      return null;
    }
    $labels[] = $guestNo . '人目：' . (string)($menu['menu_name_snapshot'] ?? '');
  }
  return implode("\n", $labels);
}

/**
 * 予約一覧の現在席表示生成
 *  通常カウンター席だけの割当は、個別席名ではなくカウンターエリア名へまとめる
 */
function clientReservationReadSeatLabel($seats)
{
  if (is_array($seats) === false) {
    return null;
  }
  $seatLabels = [];
  $normalSeatIds = [];
  $counterAreas = [];
  $allNormalSeatsAreCounter = true;
  foreach ($seats as $seat) {
    if (is_array($seat) === false) {
      return null;
    }
    if (($seat['seat_is_temp_move'] ?? null) !== 0) {
      continue;
    }
    $seatId = (int)($seat['seat_id'] ?? 0);
    $seatName = (string)($seat['seat_name_snapshot'] ?? '');
    if ($seatId < 1 || trim($seatName) === '') {
      return null;
    }
    $normalSeatIds[] = $seatId;
    $seatLabels[] = $seatName;
    $seatType = $seat['seat_type'] ?? null;
    $counterArea = $seat['seat_counter_area'] ?? null;
    if ($seatType !== 1 || is_string($counterArea) === false || $counterArea === '') {
      $allNormalSeatsAreCounter = false;
      continue;
    }
    $counterAreas[$counterArea] = true;
  }
  if (empty($normalSeatIds) === true) {
    return '---';
  }
  if ($allNormalSeatsAreCounter === true && empty($counterAreas) === false) {
    $counterAreaKeys = array_keys($counterAreas);
    return count($counterAreaKeys) === 1
      ? 'カウンター' . $counterAreaKeys[0]
      : 'カウンター' . $counterAreaKeys[0] . '〜' . $counterAreaKeys[count($counterAreaKeys) - 1];
  }
  return implode(' / ', $seatLabels);
}

/**
 * 選択日予約一覧HTML生成
 *  seat・menuの1:Nを集約済み予約単位で表示専用行へ変換する
 */
function clientReservationReadRenderListTag($reservations)
{
  if (is_array($reservations) === false) {
    return null;
  }
  $lines = [];
  $lines[] = '<ul class="list-customer">';
  $lines[] = '  <li>';
  $lines[] = '    <div style="align-items: flex-start">お客様名</div>';
  $lines[] = '    <div></div>';
  $lines[] = '    <div>人数</div>';
  $lines[] = '    <div>メニュー</div>';
  $lines[] = '    <div><span>予約</span><span>経路</span></div>';
  $lines[] = '    <div>電話番号</div>';
  $lines[] = '    <div>ステータス</div>';
  $lines[] = '    <div></div>';
  $lines[] = '  </li>';

  if (empty($reservations) === true) {
    $lines[] = '  <li>';
    $lines[] = '    <div class="item-name"><span>予約はありません。</span></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '    <div></div>';
    $lines[] = '  </li>';
  }

  foreach ($reservations as $reservation) {
    if (is_array($reservation) === false) {
      return null;
    }
    $routeLabel = clientReservationReadRouteLabel($reservation['reservation_route'] ?? null);
    $statusLabel = clientReservationReadStatusLabel($reservation['status'] ?? null);
    $menuLabel = clientReservationReadMenuLabel($reservation['menus'] ?? []);
    if ($routeLabel === '' || $statusLabel === '' || $menuLabel === null) {
      return null;
    }
    $menuLabelHtml = str_replace("\n", '<br>', clientReservationReadEscape($menuLabel));
	$normalSeatIds = [];
    foreach ($reservation['seats'] ?? [] as $seat) {
      if (is_array($seat) === false) {
        return null;
      }
		if (($seat['seat_is_temp_move'] ?? null) === 0) {
			$normalSeatIds[] = (int)($seat['seat_id'] ?? 0);
		}
    }
    $seatLabel = clientReservationReadSeatLabel($reservation['seats'] ?? []);
	if ($seatLabel === null) {
		return null;
	}
    $customerName = (string)($reservation['customer_name'] ?? '');
	$reservationId = (int)($reservation['reservation_id'] ?? 0);
	$status = (int)($reservation['status'] ?? 0);
	$seatDataError = ($reservation['seat_data_error'] ?? false) === true;
	$duplicateTempRecoverable = ($reservation['duplicate_temp_recoverable'] ?? false) === true;
	$hasTempMove = ($reservation['has_temp_move'] ?? false) === true;
	$seatChangeDateAllowed = ($reservation['seat_change_date_allowed'] ?? false) === true;
	$seatChangeVersion = (string)($reservation['seat_change_version'] ?? '');
	$seatChangeCandidates = $reservation['seat_change_candidates'] ?? null;
	$currentSeatIds = array_values(array_unique(array_map('intval', $reservation['seat_ids'] ?? [])));
	if (
		$reservationId < 1 ||
		($seatDataError === false && preg_match('/\A[0-9a-f]{64}\z/D', $seatChangeVersion) !== 1) ||
		is_array($seatChangeCandidates) === false
	) {
		return null;
	}
	if ($seatDataError === true) {
		$seatChangeVersion = '';
		$seatChangeCandidates = [];
	}
	$normalSeatIds = array_values(array_unique(array_filter($normalSeatIds, function ($seatId) { return $seatId > 0; })));
	sort($normalSeatIds, SORT_NUMERIC);
	$currentSeatValue = implode(',', $normalSeatIds);
	$seatTargetEnabled = in_array($status, [1, 2], true) === true
		&& $seatDataError === false
		&& $seatChangeDateAllowed
		&& empty($normalSeatIds) === false;
	$seatRestoreEnabled = $hasTempMove === true
		&& empty($normalSeatIds) === false
		&& (
			($duplicateTempRecoverable === true && in_array($status, [1, 2, 3, 4], true)) ||
			($seatDataError === false && in_array($status, [1, 2], true))
		);
	$seatChangeEnabled = $seatTargetEnabled || $seatRestoreEnabled;
	$seatControlDisabled = $seatChangeEnabled ? '' : ' disabled aria-disabled="true"';
	$seatFormAttributes = ' data-reservation-seat-change'
		. ' data-reservation-id="' . $reservationId . '"'
		. ($seatDataError ? ' data-reservation-seat-data-error="1"' : '')
		. ($duplicateTempRecoverable ? ' data-reservation-duplicate-temp-recoverable="1"' : '')
		. ($hasTempMove ? ' data-reservation-temp-move-active="1"' : '')
		. ' data-seat-change-version="' . clientReservationReadEscape($seatChangeVersion) . '"'
		. ' data-current-seat-value="' . clientReservationReadEscape($currentSeatValue) . '"'
		. ' data-current-seat-label="' . clientReservationReadEscape($seatLabel) . '"';
	$seatOptions = [];
	$currentOptionId = 'reservationSeat_' . $reservationId . '_current';
	$seatOptions[] = '          <li><input type="radio" name="reservationSeat_' . $reservationId . '" value="' . clientReservationReadEscape($currentSeatValue) . '" id="' . $currentOptionId . '" data-reservation-seat-option checked' . ($seatChangeEnabled ? '' : ' disabled') . '><label for="' . $currentOptionId . '">' . clientReservationReadEscape($seatLabel) . '</label></li>';
	$tempMoveOption = '';
	if ($seatTargetEnabled && $hasTempMove === false) {
		$tempOptionId = 'reservationSeat_' . $reservationId . '_temp';
		$tempMoveOption = '          <li><input type="radio" name="reservationSeat_' . $reservationId . '" value="__temp_start__" id="' . $tempOptionId . '" data-reservation-seat-option><label for="' . $tempOptionId . '">仮の席へ移動</label></li>';
	}
	foreach ($seatChangeCandidates as $candidateIndex => $candidate) {
		if (is_array($candidate) === false || is_array($candidate['seat_ids'] ?? null) === false || is_string($candidate['label'] ?? null) === false) {
			return null;
		}
		$candidateSeatIds = array_values(array_unique(array_map('intval', $candidate['seat_ids'])));
		if (empty($candidateSeatIds) === true || min($candidateSeatIds) < 1) {
			return null;
		}
		$candidateValue = implode(',', $candidateSeatIds);
		$candidateOptionId = 'reservationSeat_' . $reservationId . '_' . ($candidateIndex + 1);
		$seatOptions[] = '          <li><input type="radio" name="reservationSeat_' . $reservationId . '" value="' . clientReservationReadEscape($candidateValue) . '" id="' . $candidateOptionId . '" data-reservation-seat-option' . ($seatTargetEnabled ? '' : ' disabled') . '><label for="' . $candidateOptionId . '">' . clientReservationReadEscape($candidate['label']) . '</label></li>';
	}
	if ($hasTempMove === true) {
		$restoreOptionId = 'reservationSeat_' . $reservationId . '_restore';
		$restoreValue = $duplicateTempRecoverable ? '__temp_restore_duplicate__' : '__temp_restore__';
		$seatOptions[] = '          <li><input type="radio" name="reservationSeat_' . $reservationId . '" value="' . $restoreValue . '" id="' . $restoreOptionId . '" data-reservation-seat-option' . ($seatRestoreEnabled ? '' : ' disabled') . '><label for="' . $restoreOptionId . '">元の席に戻す</label></li>';
	}
	if ($tempMoveOption !== '') {
		$seatOptions[] = $tempMoveOption;
	}

    $lines[] = '  <li>';
    $lines[] = '    <div class="item-name"><span>' . clientReservationReadEscape($customerName) . '</span></div>';
	$lines[] = '    <form class="form-seat"' . $seatFormAttributes . '>';
	$lines[] = '      <div class="select-seat-type is-selected" data-selectbox>';
	$lines[] = '        <button type="button" class="selectbox__head" aria-expanded="false"' . $seatControlDisabled . '>';
	$lines[] = '          <input type="hidden" name="reservationSeat" value="' . clientReservationReadEscape($currentSeatValue) . '" data-selectbox-hidden data-reservation-seat-value' . ($seatChangeEnabled ? '' : ' disabled') . '>';
	$lines[] = '          <span class="selectbox__value" data-selectbox-value>' . clientReservationReadEscape($seatLabel) . '</span>';
	$lines[] = '        </button>';
	$lines[] = '        <div class="list-wrapper"><ul class="selectbox__panel">';
	$lines = array_merge($lines, $seatOptions);
	$lines[] = '        </ul></div>';
	$lines[] = '      </div>';
	$lines[] = '    </form>';
    $lines[] = '    <div>' . (int)($reservation['party_size'] ?? 0) . '名</div>';
    $lines[] = '    <div>' . $menuLabelHtml . '</div>';
    $lines[] = '    <div>' . clientReservationReadEscape($routeLabel) . '</div>';
    $lines[] = '    <div>' . clientReservationReadEscape($reservation['customer_tel'] ?? '') . '</div>';
    $lines[] = '    <div>' . clientReservationReadEscape($statusLabel) . '</div>';
	$lines[] = '    <nav><button type="button" class="btn-edit" data-tooltip="予約詳細" aria-label="予約詳細" data-reservation-detail-url="./client04_05_01.php?reservationId=' . $reservationId . '"></button></nav>';
    $lines[] = '  </li>';
  }
  $lines[] = '</ul>';
  return implode("\n", $lines);
}
