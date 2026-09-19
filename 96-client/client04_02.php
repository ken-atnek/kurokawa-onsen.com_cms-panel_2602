<?php
/*
 * [96-client/client04_02.php]
 *  【加盟店】予約基本設定
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';

/**
 * 保存済み定休日曜日を画面表示用に厳格検証
 *  正式な昇順・unique整数JSON配列だけを返す
 */
function normalizeClientReservationSettingsPageClosedWeekdays($value)
{
  if (is_string($value) === false) {
    return false;
  }
  $decoded = json_decode($value, true);
  if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false || array_is_list($decoded) === false) {
    return false;
  }
  $normalized = [];
  foreach ($decoded as $weekday) {
    if (is_int($weekday) === false || $weekday < 0 || $weekday > 6 || in_array($weekday, $normalized, true) === true) {
      return false;
    }
    $normalized[] = $weekday;
  }
  $sorted = $normalized;
  sort($sorted, SORT_NUMERIC);
  return $normalized === $sorted ? $normalized : false;
}
/**
 * 既存custom selectbox用HTML生成
 *  hiddenをPOST authorityとしradioは表示選択だけを担当する
 */
function renderClientReservationSettingsSelect($name, $options, $selectedValue, $idPrefix, $isDisabled)
{
  $selectedValue = (string)$selectedValue;
  $disabled = $isDisabled ? ' disabled' : '';
  $selectedLabel = '';
  $optionHtml = '';
  foreach ($options as $value => $label) {
    $value = (string)$value;
    $id = $idPrefix . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $value);
    $checked = $value === $selectedValue ? ' checked' : '';
    if ($checked !== '') {
      $selectedLabel = $label;
    }
    $optionHtml .= '<li><input type="radio" name="' . $name . 'Choice" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" id="' . $id . '"' . $checked . $disabled . '><label for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label></li>';
  }
  return '<div class="select-search-category" data-selectbox><button type="button" class="selectbox__head" aria-expanded="false"' . $disabled . '><input type="hidden" name="' . $name . '" value="' . htmlspecialchars($selectedValue, ENT_QUOTES, 'UTF-8') . '" data-selectbox-hidden><span class="selectbox__value" data-selectbox-value>' . htmlspecialchars($selectedLabel, ENT_QUOTES, 'UTF-8') . '</span></button><div class="list-wrapper"><ul class="selectbox__panel">' . $optionHtml . '</ul></div></div>';
}

$pagePrefix = 'cKey04-02_';
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = [];
$_SESSION[$noUpDateKey]['clientKey'] = $_SESSION['client_login']['account_id'] ?? null;
if (is_numeric($_SESSION[$noUpDateKey]['clientKey']) === false || (int)$_SESSION[$noUpDateKey]['clientKey'] < 1) {
  header('Location: ./logout.php');
  exit;
}

$shopIdRaw = $_SESSION['client_login']['shop_id'] ?? null;
if ((is_int($shopIdRaw) === false && (is_string($shopIdRaw) === false || ctype_digit($shopIdRaw) === false)) || (int)$shopIdRaw < 1) {
  header('Location: ./logout.php');
  exit;
}
$shopId = (int)$shopIdRaw;
$shopData = getShops_FindById($shopId);
if (is_array($shopData) === false || (int)($shopData['shop_id'] ?? 0) !== $shopId) {
  header('Location: ./logout.php');
  exit;
}
$headerShopName = htmlspecialchars((string)($shopData['shop_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$headerShopType = htmlspecialchars((string)($shopData['shop_type'] ?? ''), ENT_QUOTES, 'UTF-8');

$pageError = '';
$saveEnabled = true;
$settingsRaw = getShopReservationSettings($shopId);
if ($settingsRaw === false) {
  $pageError = '予約基本設定を取得できませんでした。ページを再読み込みしてください。';
  $saveEnabled = false;
  $settings = null;
} elseif ($settingsRaw === null) {
  $settings = [
    'reservation_enabled' => 0,
    'menu_selection_type' => 0,
    'accept_start_days_before' => null,
    'accept_end_days_before' => 0,
    'guest_min' => 1,
    'guest_max' => 4,
  ];
} else {
  $settings = normalizeShopReservationSettingsData($settingsRaw);
  if ($settings === false) {
    $pageError = '保存済みの予約基本設定を確認できませんでした。管理者へお問い合わせください。';
    $saveEnabled = false;
    $settings = null;
  }
}

$reservationShop = getReservationShopForOccupancy($shopId);
$closedWeekdays = is_array($reservationShop)
  ? normalizeClientReservationSettingsPageClosedWeekdays($reservationShop['closed_weekdays'] ?? null)
  : false;
if ($closedWeekdays === false) {
  $pageError = '保存済みの定休日設定を確認できませんでした。管理者へお問い合わせください。';
  $saveEnabled = false;
}
$csrfToken = getClientCsrfToken();
if ($csrfToken === false) {
  $pageError = '保存用セッションを準備できませんでした。ページを再読み込みしてください。';
  $saveEnabled = false;
}

$displaySettings = $settings ?: [
  'reservation_enabled' => 0,
  'menu_selection_type' => 0,
  'accept_start_days_before' => null,
  'accept_end_days_before' => 0,
  'guest_min' => 1,
  'guest_max' => 4,
];
$closedWeekdays = is_array($closedWeekdays) ? $closedWeekdays : [];
$acceptStartValue = $displaySettings['accept_start_days_before'] === null ? 'unlimited' : (string)$displaySettings['accept_start_days_before'];
$acceptStartSelect = renderClientReservationSettingsSelect('acceptStartDaysBefore', ['unlimited' => '無期限', 90 => '90日', 60 => '60日', 30 => '30日'], $acceptStartValue, 'acceptStart', $saveEnabled === false);
$acceptEndSelect = renderClientReservationSettingsSelect('acceptEndDaysBefore', [0 => '当日', 1 => '1日前', 3 => '3日前', 7 => '1週間前'], $displaySettings['accept_end_days_before'], 'acceptEnd', $saveEnabled === false);
$guestMinSelect = renderClientReservationSettingsSelect('guestMin', [1 => '1名', 2 => '2名', 3 => '3名', 4 => '4名'], $displaySettings['guest_min'], 'guestMin', $saveEnabled === false);
$guestMaxSelect = renderClientReservationSettingsSelect('guestMax', [1 => '1名', 2 => '2名', 3 => '3名', 4 => '4名'], $displaySettings['guest_max'], 'guestMax', $saveEnabled === false);
$disabled = $saveEnabled ? '' : ' disabled';
$errorHtml = $pageError === '' ? '' : '<p class="reservation-settings-error" role="alert">' . htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') . '</p>';
$csrfTokenHtml = htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8');
$noUpDateKeyHtml = htmlspecialchars($noUpDateKey, ENT_QUOTES, 'UTF-8');
$reservationEnabled0Checked = $displaySettings['reservation_enabled'] === 0 ? ' checked' : '';
$reservationEnabled1Checked = $displaySettings['reservation_enabled'] === 1 ? ' checked' : '';
$menuSelectionType0Checked = $displaySettings['menu_selection_type'] === 0 ? ' checked' : '';
$menuSelectionType1Checked = $displaySettings['menu_selection_type'] === 1 ? ' checked' : '';
$menuSelectionType2Checked = $displaySettings['menu_selection_type'] === 2 ? ' checked' : '';

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
  <link rel="stylesheet" href="../assets/css/client04-02.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-02 status-client">
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
        <h2>予約基本設定</h2>
        {$errorHtml}
        <form name="inputForm" class="inputForm" id="reservationSettingsForm">
          <input type="hidden" name="noUpDateKey" value="{$noUpDateKeyHtml}">
          <input type="hidden" name="csrfToken" value="{$csrfTokenHtml}">
          <h3>予約基本設定</h3>
          <dl>
            <div class="box-name"><dt>店舗名</dt><dd><span>{$headerShopName}</span></dd></div>
            <div class="box-check"><dt>予約受付</dt><dd>
              <div><input type="radio" name="reservationEnabled" id="reservationEnabled0" value="0"{$disabled}{$reservationEnabled0Checked}><label for="reservationEnabled0">使用しない</label></div>
              <div><input type="radio" name="reservationEnabled" id="reservationEnabled1" value="1"{$disabled}{$reservationEnabled1Checked}><label for="reservationEnabled1">使用する</label></div>
            </dd></div>
            <div class="box-check"><dt>食事メニュー選択</dt><dd>
              <div><input type="radio" name="menuSelectionType" id="menuSelectionType0" value="0"{$disabled}{$menuSelectionType0Checked}><label for="menuSelectionType0">利用しない</label></div>
              <div><input type="radio" name="menuSelectionType" id="menuSelectionType1" value="1"{$disabled}{$menuSelectionType1Checked}><label for="menuSelectionType1">利用する（任意）</label></div>
              <div><input type="radio" name="menuSelectionType" id="menuSelectionType2" value="2"{$disabled}{$menuSelectionType2Checked}><label for="menuSelectionType2">利用する（必須）</label></div>
            </dd></div>
            <div class="box-periode"><dt>予約受付期間</dt><dd><div class="wrap-dd"><span>何日前から受付</span>{$acceptStartSelect}</div><i>〜</i><div class="wrap-dd"><span>何日前まで受付</span>{$acceptEndSelect}</div></dd></div>
            <div class="box-person"><dt>最小予約人数</dt><dd>{$guestMinSelect}</dd></div>
            <div class="box-person"><dt>最大予約人数</dt><dd>{$guestMaxSelect}</dd></div>
            <div class="box-holiday"><dt>定休日</dt><dd>

HTML;
$weekdayLabels = [0 => '日', 1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土'];
foreach ($weekdayLabels as $weekday => $label) {
  $checked = in_array($weekday, $closedWeekdays, true) ? ' checked' : '';
  print '<div><input type="checkbox" name="closedWeekdays[]" id="closedWeekday' . $weekday . '" value="' . $weekday . '"' . $checked . $disabled . '><label for="closedWeekday' . $weekday . '">' . $label . '</label></div>';
}
print <<<HTML
            </dd></div>
          </dl>
          <div class="box-btn">
            <button type="button" class="btn-cancel" id="reservationSettingsResetButton" hidden style="display:none"{$disabled}>元に戻す</button>
            <button type="submit" class="btn-confirmed" id="reservationSettingsSaveButton"{$disabled}>保存</button>
          </div>
        </form>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <article class="modal-alert" id="modalBlock">
    <div class="inner-modal">
      <div class="box-title">
        <p>予約基本設定</p>
        <button type="button" class="btn-top-close" data-settings-modal-close></button>
      </div>
      <div class="box-details">
        <p></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" data-settings-modal-cancel>閉じる</button>
          <button type="button" class="btn-confirm" data-settings-modal-confirm hidden>変更する</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/client04_02.js" defer></script>
</body>
</html>

HTML;
