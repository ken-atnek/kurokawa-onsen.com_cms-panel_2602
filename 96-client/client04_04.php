<?php
/*
 * [96-client/client04_04.php]
 * 【加盟店】予約カレンダー管理登録／編集
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations_list.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_calendar_override_function.php';


$pagePrefix = 'cKey04-04_';
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = array();
$_SESSION[$noUpDateKey]['clientKey'] = $_SESSION['client_login']['account_id'];
if ($_SESSION[$noUpDateKey]['clientKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}
$csrfToken = getClientCsrfToken();
$reservationTempMoveGuardActiveValue = (($GLOBALS['reservationTempMoveGuardActive'] ?? false) === true) ? '1' : '0';
$reservationTempMoveInvalidValue = (($GLOBALS['reservationTempMoveGuardState']['has_invalid_reference'] ?? false) === true) ? '1' : '0';

#店舗ID（編集／削除時のみ）
$shopId = isset($_SESSION['client_login']['shop_id']) ? $_SESSION['client_login']['shop_id'] : null;
$method = ($shopId !== null) ? 'edit' : 'new';
if ($shopId !== null) {
  $shopData = getShops_FindById($shopId);
  $accountData = accounts_FindById(null, $shopId);
} else {
  header("Location: ./logout.php");
  exit;
}

$headerShopName = "";
$headerShopType = "";
if (!isset($shopData) || empty($shopData)) {
  header("Location: ./logout.php");
  exit;
} else {
  $headerShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
  $headerShopType = htmlspecialchars($shopData['shop_type'], ENT_QUOTES, 'UTF-8');
}

$guardReservationDate = $GLOBALS['reservationTempMoveGuardState']['guard_reservation_date'] ?? null;
$initialReservationDate = is_string($guardReservationDate) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $guardReservationDate) === 1
  ? new DateTimeImmutable($guardReservationDate, new DateTimeZone('Asia/Tokyo'))
  : new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
$initialReservationDateValue = $initialReservationDate->format('Y-m-d');
$initialReservationTargetMonth = $initialReservationDate->format('Y-m');
$initialReservationTargetMonthLabel = $initialReservationDate->format('Y年n月');

$reservationAddEnabled = true;
$reservationAddMessage = '';
$reservationAddGuidancePath = '';
$reservationAddGuidanceLabel = '';
$reservationEnabled = null;
$menuSelectionType = null;
$guestMin = null;
$guestMax = null;
$foodMenus = [];

if (($shopData['shop_type'] ?? null) !== 'food') {
  $reservationAddEnabled = false;
  $reservationAddMessage = 'この店舗では予約を追加できません。';
}
if ((int)($shopData['is_active'] ?? 0) !== 1) {
  $reservationAddEnabled = false;
  if ($reservationAddMessage === '') {
    $reservationAddMessage = '現在、この店舗では予約を追加できません。';
  }
}
if ($csrfToken === false) {
  $reservationAddEnabled = false;
  if ($reservationAddMessage === '') {
    $reservationAddMessage = '予約追加画面を初期化できませんでした。画面を再読み込みしてください。';
  }
}
$reservationSettings = getShopReservationSettings($shopId);
if ($reservationSettings === false) {
  $reservationAddEnabled = false;
  if ($reservationAddMessage === '') {
    $reservationAddMessage = '予約基本設定を取得できませんでした。画面を再読み込みしてください。';
  }
} elseif ($reservationSettings === null) {
  $reservationAddEnabled = false;
  if ($reservationAddMessage === '') {
    $reservationAddMessage = '予約基本設定が登録されていません。';
    $reservationAddGuidancePath = './client04_02.php';
    $reservationAddGuidanceLabel = '予約基本設定を確認する';
  }
} else {
  $reservationEnabledRaw = $reservationSettings['reservation_enabled'] ?? null;
  $menuSelectionTypeRaw = $reservationSettings['menu_selection_type'] ?? null;
  $guestMinRaw = $reservationSettings['guest_min'] ?? null;
  $guestMaxRaw = $reservationSettings['guest_max'] ?? null;
  $reservationEnabledValid = is_int($reservationEnabledRaw) || (is_string($reservationEnabledRaw) && ctype_digit($reservationEnabledRaw));
  $menuSelectionTypeValid = is_int($menuSelectionTypeRaw) || (is_string($menuSelectionTypeRaw) && ctype_digit($menuSelectionTypeRaw));
  $guestMinValid = is_int($guestMinRaw) || (is_string($guestMinRaw) && ctype_digit($guestMinRaw));
  $guestMaxValid = is_int($guestMaxRaw) || (is_string($guestMaxRaw) && ctype_digit($guestMaxRaw));
  $reservationEnabled = $reservationEnabledValid ? (int)$reservationEnabledRaw : null;
  $menuSelectionType = $menuSelectionTypeValid ? (int)$menuSelectionTypeRaw : null;
  $guestMin = $guestMinValid ? (int)$guestMinRaw : null;
  $guestMax = $guestMaxValid ? (int)$guestMaxRaw : null;
  if ($reservationEnabled !== 1) {
    $reservationAddEnabled = false;
    if ($reservationAddMessage === '') {
      $reservationAddMessage = '現在、予約受付は停止中です。';
      $reservationAddGuidancePath = './client04_02.php';
      $reservationAddGuidanceLabel = '予約基本設定を確認する';
    }
  } elseif (
    $guestMin === null ||
    $guestMax === null ||
    $guestMin < 1 ||
    $guestMin > 4 ||
    $guestMax < 1 ||
    $guestMax > 4 ||
    $guestMin > $guestMax
  ) {
    $reservationAddEnabled = false;
    if ($reservationAddMessage === '') {
      $reservationAddMessage = '予約人数の設定が正しくありません。予約基本設定を確認してください。';
      $reservationAddGuidancePath = './client04_02.php';
      $reservationAddGuidanceLabel = '予約基本設定を確認する';
    }
  } elseif ($menuSelectionType === null || in_array($menuSelectionType, [0, 1, 2], true) === false) {
    $reservationAddEnabled = false;
    if ($reservationAddMessage === '') {
      $reservationAddMessage = 'メニュー選択の設定が正しくありません。予約基本設定を確認してください。';
      $reservationAddGuidancePath = './client04_02.php';
      $reservationAddGuidanceLabel = '予約基本設定を確認する';
    }
  }
}
if (
  $reservationEnabled === 1 &&
  $guestMin !== null &&
  $guestMax !== null &&
  $guestMin >= 1 &&
  $guestMin <= 4 &&
  $guestMax >= 1 &&
  $guestMax <= 4 &&
  $guestMin <= $guestMax &&
  in_array($menuSelectionType, [1, 2], true)
) {
  $foodMenus = getActiveFoodMenusForReservationForm($shopId);
  if ($foodMenus === false) {
    $reservationAddEnabled = false;
    $foodMenus = [];
    if ($reservationAddMessage === '') {
      $reservationAddMessage = 'メニュー情報を取得できませんでした。画面を再読み込みしてください。';
    }
  } elseif ($menuSelectionType === 2 && empty($foodMenus)) {
    $reservationAddEnabled = false;
    if ($reservationAddMessage === '') {
      $reservationAddMessage = '予約時のメニュー選択が必須ですが、選択可能なメニューが登録されていません。';
      $reservationAddGuidancePath = './client04_03.php';
      $reservationAddGuidanceLabel = '食事メニュー管理を確認する';
    }
  }
}

/* 初期予約参照データ取得 */
$initialReservationReadReady = false;
$initialReservationCalendarHtml = null;
$initialReservationStatusHtml = null;
$initialReservationListHtml = null;
$initialReservationActionsHtml = clientReservationCalendarOverrideRenderEmptyActionsTag();
$initialReservationShopEligible = null;
$initialReservationShopUnavailableReason = '';
$initialReservationSelectedDate = '';
$initialReservationAvailabilityStatus = '';
$initialReservationDateLabel = '（未選択）';
$initialReservationDateDisplay = '選択してください';
$initialReservationRange = buildReservationReadCalendarRange($initialReservationTargetMonth);
if ($initialReservationRange !== null && $reservationSettings !== false) {
  $initialReservationSeats = getSeatsForReservationOccupancy($shopId);
  $initialReservationRows = getReservationWithSeatsReadRowsByDateRange(
    $shopId,
    $initialReservationRange['grid_start_date'],
    $initialReservationRange['grid_end_date']
  );
  $initialReservationOverrideRows = getReservationCalendarReadRowsByDateRange(
    $shopId,
    $initialReservationRange['grid_start_date'],
    $initialReservationRange['grid_end_date']
  );
  $initialReservationMenuRows = getReservationMenuReadRowsByDateRange(
    $shopId,
    $initialReservationDateValue,
    $initialReservationDateValue
  );
  if (
    $initialReservationSeats !== false &&
    $initialReservationRows !== false &&
    $initialReservationOverrideRows !== false &&
    $initialReservationMenuRows !== false
  ) {
    $initialReservationReadData = buildReservationReadDays(
      $shopId,
      $initialReservationRange['dates'],
      $shopData,
      $reservationSettings,
      $initialReservationSeats,
      $initialReservationRows,
      $initialReservationOverrideRows,
      $initialReservationMenuRows
    );
    $initialReservationToday = is_array($initialReservationReadData)
      ? ($initialReservationReadData['days'][$initialReservationDateValue] ?? null)
      : null;
    $initialReservationEligibility = is_array($initialReservationReadData)
      ? ($initialReservationReadData['shop_eligibility'] ?? null)
      : null;
    $initialShopEligible = is_array($initialReservationEligibility)
      ? ($initialReservationEligibility['eligible'] ?? null)
      : null;
    $initialShopUnavailableReason = is_array($initialReservationEligibility)
      ? ($initialReservationEligibility['reason'] ?? null)
      : null;
    $initialReservationCalendar = is_array($initialReservationReadData) && is_bool($initialShopEligible)
      ? clientReservationReadRenderCalendarTag(
        $initialReservationRange,
        $initialReservationReadData['days'] ?? [],
        $initialReservationDateValue,
        $initialShopEligible
      )
      : null;
    $initialReservationStatus = is_array($initialReservationToday) && is_bool($initialShopEligible)
      ? clientReservationReadRenderStatusTag($initialReservationToday, $initialShopEligible)
      : null;
    $initialReservationList = is_array($initialReservationToday)
      ? clientReservationReadRenderListTag($initialReservationToday['reservations'] ?? null)
      : null;
    $initialReservationOverridesByDate = buildReservationReadOverrideIndex($initialReservationOverrideRows);
    $initialReservationActionState = is_array($initialReservationToday) && is_bool($initialShopEligible) && is_array($initialReservationOverridesByDate)
      ? buildReservationCalendarOverrideActionState(
        $initialReservationDateValue,
        $initialReservationDateValue,
        $initialShopEligible && $csrfToken !== false,
        $shopData['closed_weekdays'] ?? null,
        $initialReservationOverridesByDate[$initialReservationDateValue] ?? null,
        $initialReservationToday['reservations'] ?? []
      )
      : false;
    $initialReservationActions = is_array($initialReservationActionState)
      ? clientReservationCalendarOverrideRenderActionsTag($initialReservationActionState)
      : null;
    $initialAvailabilityStatus = is_array($initialReservationToday)
      ? ($initialReservationToday['availability_status'] ?? null)
      : null;
    $initialShopEligibilityValid = is_bool($initialShopEligible) && (
      ($initialShopEligible === true && $initialShopUnavailableReason === null) ||
      (
        $initialShopEligible === false &&
        in_array(
          $initialShopUnavailableReason,
          ['shop_type', 'shop_inactive', 'settings_missing', 'settings_invalid', 'reservation_disabled'],
          true
        )
      )
    );
    if (
      is_string($initialReservationCalendar) &&
      is_string($initialReservationStatus) &&
      is_string($initialReservationList) &&
      $initialShopEligibilityValid &&
      in_array($initialAvailabilityStatus, ['normal', 'full', 'holiday', 'stopped'], true)
    ) {
      $initialReservationReadReady = true;
      $initialReservationCalendarHtml = $initialReservationCalendar;
      $initialReservationStatusHtml = $initialReservationStatus;
      $initialReservationListHtml = $initialReservationList;
      if (is_string($initialReservationActions)) {
        $initialReservationActionsHtml = $initialReservationActions;
      }
      $initialReservationShopEligible = $initialShopEligible;
      $initialReservationShopUnavailableReason = $initialShopUnavailableReason ?? '';
      $initialReservationSelectedDate = $initialReservationDateValue;
      $initialReservationAvailabilityStatus = $initialAvailabilityStatus;
      $initialWeekdays = ['日', '月', '火', '水', '木', '金', '土'];
      $initialWeekday = $initialWeekdays[(int)$initialReservationDate->format('w')];
      $initialReservationDateLabel = '（' . $initialReservationDate->format('Y年n月j日') . '・' . $initialWeekday . '）';
      $initialReservationDateDisplay = $initialReservationDate->format('Y年n月j日') . '（' . $initialWeekday . '）';
    }
  }
}
$noUpDateKeyHtml = htmlspecialchars((string)$noUpDateKey, ENT_QUOTES, 'UTF-8');
$csrfTokenHtml = $csrfToken === false ? '' : htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
$initialReservationDateValueHtml = htmlspecialchars($initialReservationDateValue, ENT_QUOTES, 'UTF-8');
$initialReservationTargetMonthHtml = htmlspecialchars($initialReservationTargetMonth, ENT_QUOTES, 'UTF-8');
$initialReservationTargetMonthLabelHtml = htmlspecialchars($initialReservationTargetMonthLabel, ENT_QUOTES, 'UTF-8');
$reservationAddMessageHtml = htmlspecialchars($reservationAddMessage, ENT_QUOTES, 'UTF-8');
$reservationAddGuidancePathHtml = htmlspecialchars($reservationAddGuidancePath, ENT_QUOTES, 'UTF-8');
$reservationAddGuidanceLabelHtml = htmlspecialchars($reservationAddGuidanceLabel, ENT_QUOTES, 'UTF-8');
$reservationAddEnabledValue = $reservationAddEnabled ? '1' : '0';
$reservationAddButtonDisabled = ' disabled aria-disabled="true"';
$reservationFormControlDisabled = $reservationAddEnabled ? '' : ' disabled';
$reservationAddFormDisabledClass = $reservationAddEnabled ? '' : ' is-disabled';
$menuSelectionTypeValue = $menuSelectionType === null ? '' : (string)$menuSelectionType;
$guestMinValue = $guestMin === null ? '' : (string)$guestMin;
$guestMaxValue = $guestMax === null ? '' : (string)$guestMax;
$reservationPersonInitialLabel = $guestMin === null ? '選択してください' : $guestMin . '名';
$initialReservationReadReadyValue = $initialReservationReadReady ? '1' : '0';
$initialReservationShopEligibleValue = $initialReservationReadReady
  ? ($initialReservationShopEligible ? '1' : '0')
  : '';
$initialReservationShopUnavailableReasonHtml = htmlspecialchars(
  $initialReservationShopUnavailableReason,
  ENT_QUOTES,
  'UTF-8'
);
$initialReservationSelectedDateHtml = htmlspecialchars($initialReservationSelectedDate, ENT_QUOTES, 'UTF-8');
$initialReservationAvailabilityStatusHtml = htmlspecialchars(
  $initialReservationAvailabilityStatus,
  ENT_QUOTES,
  'UTF-8'
);
$initialReservationDateLabelHtml = htmlspecialchars($initialReservationDateLabel, ENT_QUOTES, 'UTF-8');
$initialReservationDateDisplayHtml = htmlspecialchars($initialReservationDateDisplay, ENT_QUOTES, 'UTF-8');
$initialReservationCalendarBusy = $initialReservationReadReady ? 'false' : 'true';
if ($initialReservationReadReady === false) {
  $initialReservationCalendarHtml = <<<HTML
<div class="contents-calender">
  <div class="box-month">
    <button type="button" class="btn-prev is-inactive" disabled aria-disabled="true">
      <span>前月</span>
    </button>
    <span>{$initialReservationTargetMonthLabelHtml}</span>
    <button type="button" class="btn-next is-inactive" disabled aria-disabled="true">
      <span>翌月</span>
    </button>
  </div>
  <ul class="list-week">
    <li class="sun">日</li>
    <li>月</li>
    <li>火</li>
    <li>水</li>
    <li>木</li>
    <li>金</li>
    <li class="sat">土</li>
  </ul>
  <ul class="list-days"></ul>
</div>

HTML;
  $initialReservationStatusHtml = '<ul class="list-status" hidden></ul>';
  $initialReservationListHtml = '<ul class="list-customer" hidden></ul>';
}
$reservationAddMessageBlockHtml = '';
if ($reservationAddEnabled === false && $reservationAddMessageHtml !== '') {
  $reservationAddMessageBlockHtml = '<p class="reservation-add-message" data-reservation-local-message="1">' . $reservationAddMessageHtml;
  if ($reservationAddGuidancePathHtml !== '' && $reservationAddGuidanceLabelHtml !== '') {
    $reservationAddMessageBlockHtml .= ' <a href="' . $reservationAddGuidancePathHtml . '">' . $reservationAddGuidanceLabelHtml . '</a>';
  }
  $reservationAddMessageBlockHtml .= '</p>';
}
$reservationPersonOptionsHtml = '';
if ($guestMin !== null && $guestMax !== null && $guestMin >= 1 && $guestMax <= 4 && $guestMin <= $guestMax) {
  for ($person = $guestMin; $person <= $guestMax; $person++) {
    $personChecked = $person === $guestMin ? ' checked' : '';
    $personDisabled = $reservationAddEnabled ? '' : ' disabled';
    $reservationPersonOptionsHtml .= <<<HTML
                            <li>
                              <input type="radio" name="reservationPerson" value="{$person}" id="reservationPerson_{$person}" required{$personChecked}{$personDisabled}>
                              <label for="reservationPerson_{$person}">{$person}名</label>
                            </li>

HTML;
  }
}

$reservationMenuSlotsHtml = '';
if (in_array($menuSelectionType, [1, 2], true)) {
  for ($slotNumber = 1; $slotNumber <= 4; $slotNumber++) {
    $menuSlotName = 'reservationMenuSlot' . $slotNumber;
    $menuSlotVisible = $guestMin !== null && $slotNumber <= $guestMin;
    $menuSlotHidden = $menuSlotVisible ? '' : ' hidden';
    $menuSlotControlDisabled = $reservationAddEnabled && $menuSlotVisible ? '' : ' disabled';
    $menuSlotLabel = '利用者' . $slotNumber;
    $reservationMenuSlotsHtml .= <<<HTML
                        <div class="menu-slot" data-menu-slot="{$slotNumber}"{$menuSlotHidden}>
                          <span class="menu-slot__label">{$menuSlotLabel}</span>

HTML;
    if ($menuSelectionType === 1 && empty($foodMenus)) {
      $reservationMenuSlotsHtml .= <<<HTML
                          <input type="hidden" name="{$menuSlotName}" value="" data-menu-slot-value{$menuSlotControlDisabled}>
                          <span class="menu-slot__fixed-value">お席のみ</span>

HTML;
    } else {
      $menuInitialLabel = $menuSelectionType === 1 ? 'お席のみ' : '選択してください';
      $reservationMenuSlotsHtml .= <<<HTML
                          <div class="select-menu" data-selectbox>
                            <button
                              type="button"
                              class="selectbox__head"
                              aria-expanded="false"{$menuSlotControlDisabled}>
                              <input type="hidden" name="{$menuSlotName}" value="" data-selectbox-hidden data-menu-slot-value{$menuSlotControlDisabled}>
                              <span class="selectbox__value" data-selectbox-value>{$menuInitialLabel}</span>
                            </button>
                            <div class="list-wrapper">
                              <ul class="selectbox__panel">

HTML;
      if ($menuSelectionType === 1) {
        $seatOnlyId = $menuSlotName . 'SeatOnly';
        $reservationMenuSlotsHtml .= <<<HTML
                                <li>
                                  <input type="radio" name="{$menuSlotName}" value="" id="{$seatOnlyId}" checked{$menuSlotControlDisabled}>
                                  <label for="{$seatOnlyId}">お席のみ</label>
                                </li>

HTML;
      }
      foreach ($foodMenus as $foodMenu) {
        $menuId = isset($foodMenu['id']) && is_numeric($foodMenu['id']) ? (int)$foodMenu['id'] : 0;
        $menuName = isset($foodMenu['menu_name']) ? trim((string)$foodMenu['menu_name']) : '';
        if ($menuId < 1 || $menuName === '') {
          continue;
        }
        $menuIdHtml = htmlspecialchars((string)$menuId, ENT_QUOTES, 'UTF-8');
        $menuNameHtml = htmlspecialchars($menuName, ENT_QUOTES, 'UTF-8');
        $menuOptionId = $menuSlotName . 'Option' . $menuId;
        $reservationMenuSlotsHtml .= <<<HTML
                                <li>
                                  <input type="radio" name="{$menuSlotName}" value="{$menuIdHtml}" id="{$menuOptionId}"{$menuSlotControlDisabled}>
                                  <label for="{$menuOptionId}">{$menuNameHtml}</label>
                                </li>

HTML;
      }
      $reservationMenuSlotsHtml .= <<<HTML
                              </ul>
                            </div>
                          </div>

HTML;
    }
    $reservationMenuSlotsHtml .= <<<HTML
                        </div>

HTML;
  }
}
$reservationMenuBlockHtml = '';
if (in_array($menuSelectionType, [1, 2], true)) {
  $reservationMenuBlockHtml = <<<HTML
                  <div class="item-menu">
                    <dt>ご注文メニュー</dt>
                    <dd>
                      <div class="menu-slot-list">
{$reservationMenuSlotsHtml}                      </div>
                    </dd>
                  </div>

HTML;
}

print <<<HTML
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(加盟店)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/client04-04.css">
</head>
<body data-reservation-temp-move-active="{$reservationTempMoveGuardActiveValue}" data-reservation-temp-move-invalid="{$reservationTempMoveInvalidValue}">

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-04 status-client">
    <section class="container-left-menu menu-color04">
      <div class="title">飲食店予約</div>
      <nav>
        <a href="./client04_01.php" {$client04_01_active}><span>席管理</span></a>
        <a href="./client04_02.php" {$client04_02_active}><span>予約基本設定</span></a>
        <a href="./client04_03.php" {$client04_03_active}><span>食事メニュー管理</span></a>
        <a href="./client04_04.php" {$client04_04_active}><span>予約カレンダー</span></a>
        <a href="./client04_05.php" {$client04_05_active}><span>予約一覧</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color04">
      <div class="block_inner">
        <h2>予約カレンダー</h2>
        <p class="last-update">
          店舗名：<span>{$headerShopName}</span>
        </p>
        <article class="inner-calender" data-target-month="{$initialReservationTargetMonthHtml}" data-today="{$initialReservationDateValueHtml}" data-initial-read-ready="{$initialReservationReadReadyValue}" data-initial-shop-eligible="{$initialReservationShopEligibleValue}" data-initial-shop-unavailable-reason="{$initialReservationShopUnavailableReasonHtml}" aria-busy="{$initialReservationCalendarBusy}">
          <h3>月間カレンダー</h3>
          {$initialReservationCalendarHtml}
        </article>
        <article class="inner-details" data-initial-selected-date="{$initialReservationSelectedDateHtml}" data-initial-availability-status="{$initialReservationAvailabilityStatusHtml}" aria-busy="false">
          <h3>選択日の予約状況<span id="selectedReservationDateLabel">{$initialReservationDateLabelHtml}</span></h3>
          <div class="contents-details">
          {$initialReservationStatusHtml}
            <div class="box-btn">
              {$initialReservationActionsHtml}
              <button type="button" class="btn-is-add" id="reservationAddButton"{$reservationAddButtonDisabled}>
                <span>予約を追加</span>
              </button>
            </div>
            {$reservationAddMessageBlockHtml}
            {$initialReservationListHtml}
            <div class="add-new-card" id="reservationAddCard" hidden>
              <h4>予約を追加</h4>
              <form
                id="reservationAddForm"
                name="reservationAddForm"
                class="{$reservationAddFormDisabledClass}"
                data-reservation-form-enabled="{$reservationAddEnabledValue}"
                data-menu-selection-type="{$menuSelectionTypeValue}"
                data-guest-min="{$guestMinValue}"
                data-guest-max="{$guestMaxValue}">
                <input type="hidden" name="noUpDateKey" value="{$noUpDateKeyHtml}">
                <input type="hidden" name="csrfToken" id="csrfToken" value="{$csrfTokenHtml}">
                <input type="hidden" name="reservationDate" id="reservationDate" value="{$initialReservationSelectedDateHtml}">
                <dl>
                  <div>
                    <dt>予約日</dt>
                    <dd><span id="reservationDateDisplay">{$initialReservationDateDisplayHtml}</span></dd>
                  </div>
                  <div class="item-channel">
                    <dt class="is-required">予約経路</dt>
                    <dd>
                      <div>
                        <input type="radio" name="reservationRoute" id="reservationRouteTel" value="tel" required checked{$reservationFormControlDisabled}><label for="reservationRouteTel">電話</label>
                      </div>
                      <div>
                        <input type="radio" name="reservationRoute" id="reservationRouteOther" value="other" required{$reservationFormControlDisabled}><label for="reservationRouteOther">その他</label>
                      </div>
                    </dd>
                  </div>
                  <div class="item-person">
                    <dt class="is-required">人数</dt>
                    <dd>
                      <div class="select-person" data-selectbox>
                        <button
                          type="button"
                          class="selectbox__head"
                          aria-expanded="false"{$reservationFormControlDisabled}>
                          <input type="hidden" name="reservationPerson" value="{$guestMinValue}" data-selectbox-hidden{$reservationFormControlDisabled}>
                          <span class="selectbox__value" data-selectbox-value>{$reservationPersonInitialLabel}</span>
                        </button>
                        <div class="list-wrapper">
                          <ul class="selectbox__panel">
                            {$reservationPersonOptionsHtml}
                          </ul>
                        </div>
                      </div>
                    </dd>
                  </div>
                  <div class="item-seat">
                    <dt>席名</dt>
                    <dd><span class="reservation-seat-note">保存時に自動割当</span></dd>
                  </div>
                  {$reservationMenuBlockHtml}
                  <div>
                    <dt class="is-required">お客様名</dt>
                    <dd>
                      <input type="text" name="customerName" placeholder="黒川 太郎" required maxlength="101"{$reservationFormControlDisabled}>
                    </dd>
                  </div>
                  <div>
                    <dt class="is-required">ふりがな</dt>
                    <dd>
                      <input type="text" name="customerKana" placeholder="くろかわ たろう" required maxlength="101"{$reservationFormControlDisabled}>
                    </dd>
                  </div>
                  <div>
                    <dt class="is-required">電話番号</dt>
                    <dd>
                      <input type="text" name="customerTel" required maxlength="20"{$reservationFormControlDisabled}>
                    </dd>
                  </div>
                  <div>
                    <dt>メールアドレス</dt>
                    <dd>
                      <input type="text" name="customerEmail" maxlength="255"{$reservationFormControlDisabled}>
                    </dd>
                  </div>
                  <div>
                    <dt>宿泊宿名</dt>
                    <dd>
                      <input type="text" name="accommodationName" maxlength="100"{$reservationFormControlDisabled}>
                    </dd>
                  </div>
                  <div>
                    <dt class="position-top">備考 / ご要望</dt>
                    <dd>
                      <textarea name="reservationNote"{$reservationFormControlDisabled}></textarea>
                    </dd>
                  </div>
                  <div>
                    <dt class="position-top">店舗メモ</dt>
                    <dd>
                      <textarea name="shopMemo"{$reservationFormControlDisabled}></textarea>
                    </dd>
                  </div>
                </dl>
                <div class="box-btn">
                  <button type="button" class="btn-cancel" id="reservationAddCancelButton">キャンセル</button>
                  <button type="button" class="btn-confirmed" id="reservationAddSubmitButton"{$reservationFormControlDisabled}>保存</button>
                </div>
              </form>
            </div>
          </div>
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <!-- NOTE is-active付与でモーダル表示 -->
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>予約登録</p>
        <button type="button" class="btn-top-close" data-reservation-modal-close aria-label="閉じる" onclick="closeModal();"></button>
      </div>
      <div class="box-details">
        <p></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" data-reservation-modal-cancel onclick="closeModal();">閉じる</button>
          <button type="button" class="btn-confirm" data-reservation-modal-confirm hidden style="display: none;">変更する</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js?v=20260919-1" defer></script>
  <script src="../assets/js/modal.js" defer></script>
  <script src="./assets/js/client04_04.js?v=20260919-4" defer></script>
</body>
</html>

HTML;
