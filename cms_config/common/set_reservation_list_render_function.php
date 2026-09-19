<?php
/*
 * [予約一覧表示]
 */

/**
 * 予約一覧来店日表示名生成
 *  Y-m-dを曜日付きの管理画面表示へ変換する
 */
function clientReservationListDateLabel($date)
{
  if (is_string($date) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date) !== 1) {
    return null;
  }
  $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
  if ($dateTime instanceof DateTimeImmutable === false || $dateTime->format('Y-m-d') !== $date) {
    return null;
  }
  $weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  return $dateTime->format('Y/m/d') . '（' . $weekdayLabels[(int)$dateTime->format('w')] . '）';
}

/**
 * 予約一覧status class取得
 *  保存済みstatus 1～4を既存一覧の表示classへ変換する
 */
function clientReservationListStatusClass($status)
{
  $classes = [
    1 => 'status-confirmed',
    2 => 'status-visited',
    3 => 'status-canceled',
    4 => 'status-no-show',
  ];
  return $classes[(int)$status] ?? null;
}

/**
 * 予約一覧menu snapshot集約
 *  表示対象予約ごとにguest_no順のsnapshot行をまとめる
 */
function clientReservationListGroupMenuRows($reservationIds, $menuRows)
{
  if (is_array($reservationIds) === false || is_array($menuRows) === false) {
    return null;
  }
  $groupedRows = [];
  foreach ($reservationIds as $reservationId) {
    if (is_int($reservationId) === false || $reservationId < 1) {
      return null;
    }
    $groupedRows[$reservationId] = [];
  }
  foreach ($menuRows as $menuRow) {
    if (is_array($menuRow) === false) {
      return null;
    }
    $reservationId = (int)($menuRow['reservation_id'] ?? 0);
    $guestNo = (int)($menuRow['guest_no'] ?? 0);
    if ($reservationId < 1 || $guestNo < 1 || array_key_exists($reservationId, $groupedRows) === false) {
      return null;
    }
    $groupedRows[$reservationId][] = [
      'guest_no' => $guestNo,
      'menu_name_snapshot' => (string)($menuRow['menu_name_snapshot'] ?? ''),
    ];
  }
  return $groupedRows;
}

/**
 * 予約一覧HTML生成
 *  初期表示とAjaxで共通利用するsearch-list全体を安全に生成する
 */
function clientReservationListRenderTag($reservations, $menuRows)
{
  if (is_array($reservations) === false || is_array($menuRows) === false) {
    return null;
  }

  $reservationIds = [];
  foreach ($reservations as $reservation) {
    if (is_array($reservation) === false) {
      return null;
    }
    $reservationIdRaw = $reservation['reservation_id'] ?? null;
    if (is_int($reservationIdRaw)) {
      $reservationId = $reservationIdRaw;
    } elseif (is_string($reservationIdRaw) && preg_match('/\A[0-9]+\z/D', $reservationIdRaw) === 1) {
      $reservationIdString = ltrim($reservationIdRaw, '0');
      $phpIntMaxString = (string)PHP_INT_MAX;
      if (
        $reservationIdString === ''
        || strlen($reservationIdString) > strlen($phpIntMaxString)
        || (
          strlen($reservationIdString) === strlen($phpIntMaxString)
          && strcmp($reservationIdString, $phpIntMaxString) > 0
        )
      ) {
        return null;
      }
      $reservationId = (int)$reservationIdString;
    } else {
      return null;
    }
    if ($reservationId < 1 || isset($reservationIds[$reservationId])) {
      return null;
    }
    $reservationIds[$reservationId] = $reservationId;
  }
  $menusByReservation = clientReservationListGroupMenuRows(array_values($reservationIds), $menuRows);
  if ($menusByReservation === null) {
    return null;
  }

  $lines = [
    '          <ul class="search-list" id="reservationList">',
    '            <li>',
    '              <div>来店日</div>',
    '              <div>お客様名</div>',
    '              <div>電話番号</div>',
    '              <div>人数</div>',
    '              <div>メニュー</div>',
    '              <div>経路</div>',
    '              <div>予約ステータス</div>',
    '              <div></div>',
    '            </li>',
  ];

  if (empty($reservations) === true) {
    $lines[] = '            <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">';
    $lines[] = '              <div>該当する予約はありません。</div>';
    $lines[] = '            </li>';
    $lines[] = '          </ul>';
    return implode("\n", $lines);
  }

  foreach ($reservations as $reservation) {
    $reservationId = (int)$reservation['reservation_id'];
    $reservationDateLabel = clientReservationListDateLabel($reservation['reservation_date'] ?? null);
    $partySize = (int)($reservation['party_size'] ?? 0);
    $routeLabel = clientReservationReadRouteLabel($reservation['reservation_route'] ?? null);
    $statusLabel = clientReservationReadStatusLabel($reservation['status'] ?? null);
    $statusClass = clientReservationListStatusClass($reservation['status'] ?? null);
    if ($reservationDateLabel === null || $partySize < 1 || $routeLabel === '' || $statusLabel === '' || $statusClass === null) {
      return null;
    }

    $customerName = trim((string)($reservation['customer_last_name'] ?? '') . ' ' . (string)($reservation['customer_first_name'] ?? ''));
    if ($customerName === '') {
      $customerName = '---';
    }
    $customerTel = (string)($reservation['customer_tel'] ?? '');
    if ($customerTel === '') {
      $customerTel = '---';
    }
    $menuLabel = clientReservationReadMenuLabel($menusByReservation[$reservationId] ?? []);
    if ($menuLabel === null) {
      return null;
    }

    $reservationDateHtml = clientReservationReadEscape($reservationDateLabel);
    $customerNameHtml = clientReservationReadEscape($customerName);
    $customerTelHtml = clientReservationReadEscape($customerTel);
    $menuLabelHtml = clientReservationReadEscape($menuLabel);
    $routeLabelHtml = clientReservationReadEscape($routeLabel);
    $statusLabelHtml = clientReservationReadEscape($statusLabel);
    $statusClassHtml = clientReservationReadEscape($statusClass);

    $lines[] = '            <li>';
    $lines[] = '              <div><span>' . $reservationDateHtml . '</span></div>';
    $lines[] = '              <div><span>' . $customerNameHtml . '</span></div>';
    $lines[] = '              <div>' . $customerTelHtml . '</div>';
    $lines[] = '              <div class="item-person">' . $partySize . '名</div>';
    $lines[] = '              <div><span>' . $menuLabelHtml . '</span></div>';
    $lines[] = '              <div class="item-channel"><span>' . $routeLabelHtml . '</span></div>';
    $lines[] = '              <div class="item-status"><span class="' . $statusClassHtml . '">' . $statusLabelHtml . '</span></div>';
    $lines[] = '              <nav><button type="button" class="btn-edit" data-tooltip="予約詳細" aria-label="予約詳細" onclick="location.href=\'./client04_05_01.php?reservationId=' . $reservationId . '\';"></button></nav>';
    $lines[] = '            </li>';
  }

  $lines[] = '          </ul>';
  return implode("\n", $lines);
}

/**
 * 予約一覧初期取得失敗HTML生成
 *  DB失敗時に0件表示と区別した安全な一覧messageを返す
 */
function clientReservationListRenderErrorTag()
{
  $lines = [
    '          <ul class="search-list" id="reservationList">',
    '            <li>',
    '              <div>来店日</div>',
    '              <div>お客様名</div>',
    '              <div>電話番号</div>',
    '              <div>人数</div>',
    '              <div>メニュー</div>',
    '              <div>経路</div>',
    '              <div>予約ステータス</div>',
    '              <div></div>',
    '            </li>',
    '            <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">',
    '              <div>予約一覧を取得できませんでした。ページを再読み込みしてください。</div>',
    '            </li>',
    '          </ul>',
  ];
  return implode("\n", $lines);
}
