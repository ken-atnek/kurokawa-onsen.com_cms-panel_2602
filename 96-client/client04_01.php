<?php
/*
 * [96-client/client04_01.php]
 *  【加盟店】席管理登録／編集
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';

/**
 * 席管理画面のHTML属性・本文をescape
 *  DB由来の席名をHTMLとして解釈させない
 */
function seatManagementHtml($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
/**
 * 既存のcustom selectbox構造を描画
 *  選択済みの超過定員もそのまま現在値として表示する
 */
function renderSeatManagementSelect($className, $fieldName, $options, $selected, $prefix, $disabled = false)
{
	$selectedValue = $selected === null ? '' : (string)$selected;
	$selectedLabel = $options[$selectedValue] ?? '--';
	$html = '<div class="' . seatManagementHtml($className) . '" data-selectbox>';
	$html .= '<input type="hidden" name="' . seatManagementHtml($fieldName) . '" value="' . seatManagementHtml($selectedValue) . '" data-selectbox-hidden>';
	$html .= '<button type="button" class="selectbox__head" aria-expanded="false"' . ($disabled ? ' disabled' : '') . '><span class="selectbox__value" data-selectbox-value>' . seatManagementHtml($selectedLabel) . '</span></button>';
	$html .= '<div class="list-wrapper"><ul class="selectbox__panel">';
	foreach ($options as $value => $label) {
		$id = $prefix . '-' . $fieldName . '-' . (string)$value;
		$html .= '<li><input type="radio" name="' . seatManagementHtml($fieldName) . '" value="' . seatManagementHtml($value) . '" id="' . seatManagementHtml($id) . '"' . ($selectedValue === (string)$value ? ' checked' : '') . ($disabled ? ' disabled' : '') . '><label for="' . seatManagementHtml($id) . '">' . seatManagementHtml($label) . '</label></li>';
	}
	$html .= '</ul></div></div>';
	return $html;
}
/**
 * 席の追加・編集フォームを描画
 *  保存値はendpointでfresh settingsと再検証する
 */
function renderSeatManagementForm($seat, $guestMax)
{
	$isEdit = is_array($seat);
	$prefix = $isEdit ? 'edit-' . $seat['id'] : 'create';
	$type = $isEdit ? $seat['type'] : null;
	$capacity = $isEdit ? $seat['capacity'] : null;
	$area = $isEdit ? $seat['counter_area'] : null;
	$capacityOptions = [];
	for ($n = 1; $n <= $guestMax; $n++) {
		$capacityOptions[(string)$n] = $n . '名';
	}
	if ($capacity !== null && $capacity > $guestMax) {
		$capacityOptions[(string)$capacity] = $capacity . '名（現在値）';
	}
	$areaOptions = [];
	foreach (range('A', 'H') as $letter) {
		$areaOptions[$letter] = 'エリア' . $letter;
	}
	$name = $isEdit ? seatManagementHtml($seat['name']) : '';
	$html = '<form class="inputForm ' . ($isEdit ? 'seat-edit-form' : 'box-add-new seat-create-form') . '"' . ($isEdit ? ' style="display:none"' : '') . '>';
	$html .= '<dl><div><dt>席名</dt><dd><input type="text" name="seatName" value="' . $name . '" maxlength="50" required></dd></div>';
	$html .= '<div><dt>席種</dt><dd>' . renderSeatManagementSelect('select-seat-type', 'seatType', ['1' => 'カウンター', '2' => 'テーブル'], $type, $prefix) . '</dd></div>';
	$html .= '<div><dt>定員</dt><dd>' . renderSeatManagementSelect('select-capacity', 'capacity', $capacityOptions, $capacity, $prefix, $type === 1) . '</dd></div>';
	$html .= '<div' . ($type === 1 ? '' : ' style="display:none"') . '><dt>カウンターエリア</dt><dd>' . renderSeatManagementSelect('select-counter-area', 'counterArea', $areaOptions, $area, $prefix, $type === 2) . '</dd></div></dl>';
	$html .= '<button type="submit" class="' . ($isEdit ? 'btn-submit' : 'btn-add') . '">' . ($isEdit ? '決定' : '追加') . '</button>';
	if ($isEdit) {
		$html .= '<button type="button" class="btn-cancel">キャンセル</button>';
	}
	return $html . '</form>';
}
$pagePrefix = 'cKey04-01_';
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $value) {
	if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
		unset($_SESSION[$key]);
	}
}
$_SESSION[$noUpDateKey] = ['clientKey' => $_SESSION['client_login']['account_id'] ?? null];
if (!is_numeric($_SESSION[$noUpDateKey]['clientKey']) || (int)$_SESSION[$noUpDateKey]['clientKey'] < 1) {
	header('Location: ./logout.php');
	exit;
}
$shopIdRaw = $_SESSION['client_login']['shop_id'] ?? null;
$shopId = is_int($shopIdRaw) ? $shopIdRaw : (is_string($shopIdRaw) && preg_match('/\A[1-9][0-9]*\z/D', $shopIdRaw) === 1 ? filter_var($shopIdRaw, FILTER_VALIDATE_INT) : false);
if (!is_int($shopId) || $shopId < 1) {
	header('Location: ./logout.php');
	exit;
}
$shop = getShops_FindById($shopId);
if (!is_array($shop) || (int)($shop['shop_id'] ?? 0) !== $shopId) {
	header('Location: ./logout.php');
	exit;
}
$headerShopName = seatManagementHtml($shop['shop_name'] ?? '');
$headerShopType = seatManagementHtml($shop['shop_type'] ?? '');
$csrfToken = getClientCsrfToken();
$settingsRaw = getShopReservationSettings($shopId);
$settings = is_array($settingsRaw) ? normalizeShopReservationSettingsData($settingsRaw) : false;
$seats = getNormalSeatsForManagement($shopId);
$orderVersion = is_array($seats) ? buildSeatManagementOrderVersion($seats) : false;
$pageReady = is_string($csrfToken) && is_array($settings) && is_array($seats) && is_string($orderVersion);
$versions = [];
if ($pageReady) {
	foreach ($seats as $seat) {
		$version = buildSeatManagementSeatVersion($seat);
		if (!is_string($version)) {
			$pageReady = false;
			break;
		}
		$versions[$seat['id']] = $version;
	}
}
$seatManagementStateAttributes = '';
if ($pageReady) {
	$seatManagementStateAttributes = ' id="seatManagementState" data-no-up-date-key="' . seatManagementHtml($noUpDateKey) . '" data-csrf-token="' . seatManagementHtml($csrfToken) . '" data-order-version="' . seatManagementHtml($orderVersion) . '"';
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
  <link rel="stylesheet" href="../assets/css/client04-01.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-01 status-client">
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
        <h2>席管理</h2>
        <article class="inner-contents"{$seatManagementStateAttributes}>
          <h3>席一覧</h3>

HTML;
if (!$pageReady) {
	print '<p class="notice" style="text-align:center;font-size:1.6rem;padding-block:1em;" role="alert">予約基本設定が確認できませんでした。<br>先に予約基本設定を行って下さい。現在操作を停止しています。</p>';
} else {
	$createFormHtml = renderSeatManagementForm(null, $settings['guest_max']);
	print <<<HTML
            <div class="contents-form">{$createFormHtml}<p class="notice">※ カウンター席の定員は1名固定です。</p></div>
            <ul class="box-seat-list" id="seatManagementList">
              <li><div></div><div>席名</div><div>席種</div><div>定員</div><div>カウンターエリア</div><div>状態</div><div></div></li>

HTML;
	foreach ($seats as $seat) {
		$id = $seat['id'];
		$typeLabel = $seat['type'] === 1 ? 'カウンター' : 'テーブル';
		$areaLabel = $seat['counter_area'] === null ? '-' : 'エリア' . $seat['counter_area'];
		$checked = $seat['is_active'] === 1 ? ' checked' : '';
		$seatVersionHtml = seatManagementHtml($versions[$id]);
		$seatNameHtml = seatManagementHtml($seat['name']);
		$areaLabelHtml = seatManagementHtml($areaLabel);
		$editFormHtml = renderSeatManagementForm($seat, $settings['guest_max']);
		print <<<HTML
              <li class="seat-row" data-seat-id="{$id}" data-seat-version="{$seatVersionHtml}">
                <div class="inner-list">
                  <div class="item-control"><button type="button" class="seat-drag-handle" draggable="true" aria-label="席の並び替え"><span></span><span></span><span></span></button></div>
                  <div class="item-name"><span>{$seatNameHtml}</span></div>
                  <div class="item-type"><span>{$typeLabel}</span></div>
                  <div class="item-capacity"><span>{$seat['capacity']}名</span></div>
                  <div class="item-area"><span>{$areaLabelHtml}</span></div>
                  <div class="item-toggle"><div class="wrap-toggle-button"><label class="toggle-button"><input type="checkbox" class="seat-active-toggle" aria-label="席の有効状態"{$checked}></label></div></div>
                  <nav><button type="button" class="btn-edit" data-tooltip="編集" aria-label="編集"></button><button type="button" class="btn-delate" data-tooltip="削除" aria-label="削除"></button></nav>
                </div>
                {$editFormHtml}
              </li>

HTML;
	}
	print <<<HTML
            </ul>
            <div class="wrap-notice">

HTML;
	if ($seats === []) {
		print '<p class="notice" style="font-size:1.6rem;font-weight:600;color:#CC1200;">登録されている席はありません。</p>';
	}
	print <<<HTML
              <p class="notice">※ 並び順はドラッグ＆ドロップで変更できます。カウンターの連続席判定にはこの表示順を使用します。</p>
            </div>

HTML;
}
print <<<HTML
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <article class="modal-alert" id="modalBlock" role="dialog" aria-modal="true" aria-label="席管理" aria-hidden="true">
    <div class="inner-modal">
      <div class="box-title"><p>席管理</p>
      <button type="button" class="btn-top-close" data-seat-modal-close aria-label="閉じる"></button>
    </div>
      <div class="box-details">
        <p data-seat-modal-message></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" data-seat-modal-cancel>閉じる</button>
          <button type="button" class="btn-confirm" data-seat-modal-confirm hidden>変更する</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/client04_01.js" defer></script>
</body>
</html>

HTML;
