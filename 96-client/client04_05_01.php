<?php
/*
 * [96-client/client04_05_01.php]
 * 【加盟店】予約詳細参照
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_read_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_list_render_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_detail.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_detail_edit_function.php';

/**
 * 予約詳細日時表示名生成
 *  DB日時を曜日付きの管理画面表示へ変換する
 */
function clientReservationDetailReadDateTimeLabel($dateTimeValue, $nullable = false)
{
  if ($nullable === true && $dateTimeValue === null) {
    return '---';
  }
  if (isReservationDetailReadDateTimeString($dateTimeValue) === false) {
    return null;
  }
  $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTimeValue, new DateTimeZone('Asia/Tokyo'));
  if ($dateTime instanceof DateTimeImmutable === false) {
    return null;
  }
  $weekdayLabels = ['日', '月', '火', '水', '木', '金', '土'];
  return $dateTime->format('Y/m/d') . '（' . $weekdayLabels[(int)$dateTime->format('w')] . '） ' . $dateTime->format('H:i');
}

$pagePrefix = 'cKey04-05-01_';
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

/* reservationIdチェック */
$reservationIdRaw = array_key_exists('reservationId', $_GET) ? $_GET['reservationId'] : null;
$reservationId = is_string($reservationIdRaw) === true
  ? normalizeReservationDetailReadInteger($reservationIdRaw, 1, null)
  : null;
if ($reservationId === null) {
  header("Location: ./client04_05.php");
  exit;
}

$shopId = normalizeReservationDetailReadInteger($_SESSION['client_login']['shop_id'] ?? null, 1, null);
if ($shopId !== null) {
  $shopData = getShops_FindById($shopId);
  $accountData = accounts_FindById(null, $shopId);
} else {
  header("Location: ./logout.php");
  exit;
}

$reservationDetail = getReservationDetail($shopId, $reservationId);
if ($reservationDetail === null) {
  header("Location: ./client04_05.php");
  exit;
}
$reservationDetailHasError = ($reservationDetail === false);
$reservationDetailSeatRows = [];
$reservationDetailMenuRows = [];
$reservationDetailMenuSelectionType = null;
$reservationDetailActiveMenuRows = [];
$reservationDetailEditVersion = false;
$reservationDetailEditAvailable = false;
$reservationDetailMenuRowsEditable = false;
if ($reservationDetailHasError === false) {
  $reservationDetailSeatRows = getReservationDetailSeatRows($shopId, $reservationId);
  $reservationDetailHasError = ($reservationDetailSeatRows === false);
}
if ($reservationDetailHasError === false) {
  $reservationDetailMenuRows = getReservationDetailMenuRows($shopId, $reservationId);
  $reservationDetailHasError = ($reservationDetailMenuRows === false);
}
if ($reservationDetailHasError === false) {
  if (isReservationDetailReadMenuRowsValid($reservationDetailMenuRows, $reservationId, $reservationDetail['party_size']) === false) {
    $reservationDetailHasError = true;
  } else {
    $reservationDetailMenuRowsEditable = normalizeReservationDetailEditMenuRows(
      $reservationDetailMenuRows,
      $reservationId,
      $reservationDetail['party_size']
    ) !== false;
  }
}
if ($reservationDetailHasError === false) {
  $reservationDetailEditAvailable = $reservationDetailMenuRowsEditable;
}
if ($reservationDetailEditAvailable === true) {
  $reservationDetailSettings = getShopReservationSettings($shopId);
  $reservationDetailMenuSelectionType = is_array($reservationDetailSettings) === true
    ? normalizeReservationDetailEditInteger($reservationDetailSettings['menu_selection_type'] ?? null, 0, 2)
    : null;
  if ($reservationDetailMenuSelectionType === null) {
    $reservationDetailEditAvailable = false;
  } elseif ($reservationDetailMenuSelectionType !== 0) {
    $reservationDetailActiveMenuRows = getActiveFoodMenusForReservationForm($shopId);
    if (is_array($reservationDetailActiveMenuRows) === false) {
      $reservationDetailActiveMenuRows = [];
      $reservationDetailEditAvailable = false;
    }
  }
}
if ($reservationDetailEditAvailable === true) {
  $activeMenuIds = [];
  $normalizedActiveMenuRows = [];
  foreach ($reservationDetailActiveMenuRows as $activeMenuRow) {
    $activeMenuId = normalizeReservationDetailEditInteger($activeMenuRow['id'] ?? null, 1, null);
    $activeMenuName = $activeMenuRow['menu_name'] ?? null;
    if (
      $activeMenuId === null ||
      is_string($activeMenuName) === false ||
      reservationDetailEditBlankResult($activeMenuName) !== false ||
      isset($activeMenuIds[$activeMenuId]) === true
    ) {
      $reservationDetailEditAvailable = false;
      break;
    }
    $activeMenuIds[$activeMenuId] = true;
    $normalizedActiveMenuRows[] = [
      'id' => $activeMenuId,
      'menu_name' => $activeMenuName,
    ];
  }
  $reservationDetailActiveMenuRows = $normalizedActiveMenuRows;
}
if ($reservationDetailEditAvailable === true) {
  $reservationDetailEditVersion = buildReservationDetailEditVersion(
    $reservationDetail,
    $reservationDetailMenuRows,
    $reservationDetailMenuSelectionType
  );
  if (
    is_string($reservationDetailEditVersion) === false ||
    preg_match('/\A[0-9a-f]{64}\z/D', $reservationDetailEditVersion) !== 1 ||
    is_string($csrfToken) === false ||
    preg_match('/\A[0-9a-f]{64}\z/D', $csrfToken) !== 1
  ) {
    $reservationDetailEditAvailable = false;
  }
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

if ($reservationDetailHasError === true) {
  $reservationDetailContentTag = '<p>予約情報を取得できませんでした。ページを再読み込みしてください。</p>';
} else {
  $reservationDateLabel = clientReservationListDateLabel($reservationDetail['reservation_date']);
  $createdAtLabel = clientReservationDetailReadDateTimeLabel($reservationDetail['created_at']);
  $updatedAtLabel = clientReservationDetailReadDateTimeLabel($reservationDetail['updated_at']);
  $cancelledAtLabel = clientReservationDetailReadDateTimeLabel($reservationDetail['cancelled_at'], true);
  $reservationRouteLabel = clientReservationReadRouteLabel($reservationDetail['reservation_route']);
  $reservationStatusLabel = clientReservationReadStatusLabel($reservationDetail['status']);
  if (
    $reservationDateLabel === null ||
    $createdAtLabel === null ||
    $updatedAtLabel === null ||
    $cancelledAtLabel === null ||
    $reservationRouteLabel === '' ||
    $reservationStatusLabel === ''
  ) {
    $reservationDetailContentTag = '<p>予約情報を取得できませんでした。ページを再読み込みしてください。</p>';
  } else {
    $seatLabels = [];
    foreach ($reservationDetailSeatRows as $seatRow) {
      $seatLabels[] = $seatRow['seat_name_snapshot'];
    }
    $seatLabel = empty($seatLabels) === true ? '---' : implode(' / ', $seatLabels);
    $menuLabels = [];
    foreach ($reservationDetailMenuRows as $menuRow) {
      $taxLabel = $menuRow['tax_included_snapshot'] === 1 ? '税込' : '税別';
      $menuLabels[] = $menuRow['guest_no'] . '人目：' . $menuRow['menu_name_snapshot'] . '（' . number_format($menuRow['menu_price_snapshot']) . '円・' . $taxLabel . '）';
    }
    $menuLabel = empty($menuLabels) === true ? '---' : implode(' / ', $menuLabels);
    $reservationIdHtml = (int)$reservationDetail['id'];
    $reservationDateHtml = clientReservationReadEscape($reservationDateLabel);
    $createdAtHtml = clientReservationReadEscape($createdAtLabel);
    $updatedAtHtml = clientReservationReadEscape($updatedAtLabel);
    $cancelledAtHtml = clientReservationReadEscape($cancelledAtLabel);
    $seatLabelHtml = clientReservationReadEscape($seatLabel);
    $customerNameHtml = clientReservationReadEscape($reservationDetail['customer_name']);
    $customerKanaHtml = clientReservationReadEscape($reservationDetail['customer_kana']);
    $customerTelHtml = clientReservationReadEscape($reservationDetail['customer_tel']);
    $customerEmailHtml = clientReservationReadEscape($reservationDetail['customer_email'] ?? '');
    $partySizeHtml = (int)$reservationDetail['party_size'];
    $menuLabelHtml = clientReservationReadEscape($menuLabel);
    $accommodationNameHtml = clientReservationReadEscape($reservationDetail['accommodation_name'] ?? '');
    $customerNoteHtml = clientReservationReadEscape($reservationDetail['customer_note'] ?? '');
    $reservationStatusHtml = clientReservationReadEscape($reservationStatusLabel);
    $shopMemoHtml = clientReservationReadEscape($reservationDetail['shop_memo'] ?? '');
    $noUpDateKeyHtml = clientReservationReadEscape($noUpDateKey);
    $csrfTokenHtml = clientReservationReadEscape($csrfToken);
    $reservationDetailEditVersionHtml = $reservationDetailEditAvailable === true
      ? clientReservationReadEscape($reservationDetailEditVersion)
      : '';
    $reservationDetailEditControlDisabled = $reservationDetailEditAvailable === true ? '' : ' disabled';
    $reservationDetailEditFormAttributes = $reservationDetailEditAvailable === true
      ? ' data-reservation-detail-edit-form data-menu-selection-type="' . $reservationDetailMenuSelectionType . '"'
      : '';
    $reservationDetailEditMetadataHtml = $reservationDetailEditAvailable === true
      ? <<<HTML
          <input type="hidden" value="{$noUpDateKeyHtml}" data-reservation-detail-no-update-key>
          <input type="hidden" value="{$csrfTokenHtml}" data-reservation-detail-csrf-token>
          <input type="hidden" value="{$reservationIdHtml}" data-reservation-detail-reservation-id>
          <input type="hidden" value="{$reservationDetailEditVersionHtml}" data-reservation-detail-version>

HTML
      : '';
    $reservationDetailEditNoticeHtml = $reservationDetailEditAvailable === true
      ? ''
      : '          <p>予約情報の編集機能を利用できません。ページを再読み込みしてください。</p>';
    $reservationRouteConfig = [
      1 => ['value' => 'web', 'label' => 'Web'],
      2 => ['value' => 'tel', 'label' => '電話'],
      3 => ['value' => 'other', 'label' => 'その他'],
    ];
    $reservationRouteOptionsHtml = '';
    foreach ($reservationRouteConfig as $routeId => $routeConfig) {
      $routeValueHtml = clientReservationReadEscape($routeConfig['value']);
      $routeLabelHtml = clientReservationReadEscape($routeConfig['label']);
      $routeOptionId = 'reservationRoute' . ucfirst($routeConfig['value']);
      $routeChecked = $routeId === (int)$reservationDetail['reservation_route'] ? ' checked' : '';
      $reservationRouteOptionsHtml .= <<<HTML
                    <div>
                      <input type="radio" name="reservationRoute" id="{$routeOptionId}" value="{$routeValueHtml}" data-reservation-detail-field{$routeChecked}{$reservationDetailEditControlDisabled}>
                      <label for="{$routeOptionId}">{$routeLabelHtml}</label>
                    </div>

HTML;
    }
    $reservationPersonOptionsHtml = '';
    foreach (range(1, 4) as $personCount) {
      $personOptionId = 'reservationPerson_' . str_pad((string)$personCount, 2, '0', STR_PAD_LEFT);
      $personChecked = $personCount === $partySizeHtml ? ' checked' : '';
      $reservationPersonOptionsHtml .= <<<HTML
                          <li>
                            <input type="radio" name="reservationPersonOption" value="{$personCount}" id="{$personOptionId}" data-reservation-person-option{$personChecked}{$reservationDetailEditControlDisabled}>
                            <label for="{$personOptionId}">{$personCount}名</label>
                          </li>

HTML;
    }
    $reservationPersonControlHtml = <<<HTML
                    <div class="select-person is-selected" data-selectbox data-reservation-person-control>
                      <button type="button" class="selectbox__head" aria-expanded="false"{$reservationDetailEditControlDisabled}>
                        <input type="hidden" name="reservationPerson" value="{$partySizeHtml}" data-selectbox-hidden data-reservation-person-value data-reservation-detail-field{$reservationDetailEditControlDisabled}>
                        <span class="selectbox__value" data-selectbox-value>{$partySizeHtml}名</span>
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">
                          {$reservationPersonOptionsHtml}
                        </ul>
                      </div>
                    </div>

HTML;
    $detailMenuRowsByGuestNo = [];
    foreach ($reservationDetailMenuRows as $menuRow) {
      $detailMenuRowsByGuestNo[$menuRow['guest_no']] = $menuRow;
    }
    if ($reservationDetailMenuSelectionType === 0 || $reservationDetailMenuSelectionType === null) {
      $reservationMenuControlHtml = <<<HTML
              <div class="item-menu">
                <dt>ご注文メニュー</dt>
                <dd><span>{$menuLabelHtml}</span></dd>
              </div>

HTML;
    } else {
      $reservationMenuControlHtml = '';
      foreach (range(1, 4) as $guestNo) {
        $currentMenuRow = $detailMenuRowsByGuestNo[$guestNo] ?? null;
        $initialMenuId = $currentMenuRow['menu_id'] ?? null;
        $initialMenuValue = $initialMenuId === null ? '' : (string)$initialMenuId;
        $initialMenuValueHtml = clientReservationReadEscape($initialMenuValue);
        $slotHidden = $guestNo > $partySizeHtml ? ' hidden' : '';
        $menuOptionName = 'reservationMenuOption' . $guestNo;
        $menuOptionsHtml = '';
        $menuCurrentLabel = '選択してください';
        $menuSelectedClass = '';
        if ($reservationDetailMenuSelectionType === 1) {
          $noneOptionId = 'reservationMenu_' . $guestNo . '_none';
          $noneChecked = $currentMenuRow === null ? ' checked' : '';
          $menuOptionsHtml .= <<<HTML
                              <li>
                                <input type="radio" name="{$menuOptionName}" value="" id="{$noneOptionId}" data-reservation-menu-option{$noneChecked}{$reservationDetailEditControlDisabled}>
                                <label for="{$noneOptionId}">お席のみ</label>
                              </li>

HTML;
          if ($currentMenuRow === null) {
            $menuCurrentLabel = 'お席のみ';
            $menuSelectedClass = ' is-selected';
          }
        } elseif ($currentMenuRow === null && $guestNo <= $partySizeHtml) {
          $historicalMissingOptionId = 'reservationMenu_' . $guestNo . '_historical_missing';
          $menuOptionsHtml .= <<<HTML
                              <li>
                                <input type="radio" name="{$menuOptionName}" value="" id="{$historicalMissingOptionId}" data-reservation-menu-option checked{$reservationDetailEditControlDisabled}>
                                <label for="{$historicalMissingOptionId}">未選択（保存済み）</label>
                              </li>

HTML;
          $menuCurrentLabel = '未選択（保存済み）';
          $menuSelectedClass = ' is-selected';
        }

        if ($currentMenuRow !== null) {
          $historicalOptionId = 'reservationMenu_' . $guestNo . '_historical';
          $historicalMenuNameHtml = clientReservationReadEscape($currentMenuRow['menu_name_snapshot'] . '（予約時）');
          $menuOptionsHtml .= <<<HTML
                              <li>
                                <input type="radio" name="{$menuOptionName}" value="{$initialMenuValueHtml}" id="{$historicalOptionId}" data-reservation-menu-option data-historical-menu-option checked{$reservationDetailEditControlDisabled}>
                                <label for="{$historicalOptionId}">{$historicalMenuNameHtml}</label>
                              </li>

HTML;
          $menuCurrentLabel = $currentMenuRow['menu_name_snapshot'] . '（予約時）';
          $menuSelectedClass = ' is-selected';
        }
        foreach ($reservationDetailActiveMenuRows as $activeMenuRow) {
          if ($initialMenuId !== null && $activeMenuRow['id'] === $initialMenuId) {
            continue;
          }
          $activeMenuIdHtml = (int)$activeMenuRow['id'];
          $activeMenuNameHtml = clientReservationReadEscape($activeMenuRow['menu_name']);
          $activeMenuOptionId = 'reservationMenu_' . $guestNo . '_' . $activeMenuIdHtml;
          $menuOptionsHtml .= <<<HTML
                              <li>
                                <input type="radio" name="{$menuOptionName}" value="{$activeMenuIdHtml}" id="{$activeMenuOptionId}" data-reservation-menu-option{$reservationDetailEditControlDisabled}>
                                <label for="{$activeMenuOptionId}">{$activeMenuNameHtml}</label>
                              </li>

HTML;
        }
        $menuCurrentLabelHtml = clientReservationReadEscape($menuCurrentLabel);
        $reservationMenuControlHtml .= <<<HTML
              <div class="item-menu" data-reservation-menu-slot="{$guestNo}" data-initial-menu-id="{$initialMenuValueHtml}"{$slotHidden}>
                <dt>{$guestNo}人目のメニュー</dt>
                <dd>
                  <div class="select-menu{$menuSelectedClass}" data-selectbox>
                    <button type="button" class="selectbox__head" aria-expanded="false"{$reservationDetailEditControlDisabled}>
                      <input type="hidden" name="reservationMenu[]" value="{$initialMenuValueHtml}" data-selectbox-hidden data-reservation-menu-value data-reservation-detail-field{$reservationDetailEditControlDisabled}>
                      <span class="selectbox__value" data-selectbox-value>{$menuCurrentLabelHtml}</span>
                    </button>
                    <div class="list-wrapper">
                      <ul class="selectbox__panel">
                        {$menuOptionsHtml}
                      </ul>
                    </div>
                  </div>
                </dd>
              </div>

HTML;
      }
    }
    $reservationStatusControlHtml = '<span>' . $reservationStatusHtml . '</span>';
    if (is_string($csrfToken) === true && preg_match('/\A[0-9a-f]{64}\z/D', $csrfToken) === 1) {
      $reservationStatusConfig = [
        1 => ['value' => 'confirmed', 'label' => '確定'],
        2 => ['value' => 'visited', 'label' => '来店済み'],
        3 => ['value' => 'canceled', 'label' => 'キャンセル'],
        4 => ['value' => 'noShow', 'label' => '無断キャンセル'],
      ];
      $currentReservationStatus = (int)$reservationDetail['status'];
      $allowedReservationStatuses = in_array($currentReservationStatus, [3, 4], true) ? [3, 4] : [1, 2, 3, 4];
      $currentReservationStatusValue = $reservationStatusConfig[$currentReservationStatus]['value'];
      $currentReservationStatusValueHtml = clientReservationReadEscape($currentReservationStatusValue);
      $reservationStatusOptionsHtml = '';
      foreach ($allowedReservationStatuses as $statusId) {
        $statusValue = $reservationStatusConfig[$statusId]['value'];
        $statusLabel = $reservationStatusConfig[$statusId]['label'];
        $statusValueHtml = clientReservationReadEscape($statusValue);
        $statusLabelHtml = clientReservationReadEscape($statusLabel);
        $statusOptionId = 'reservationStatusOption' . $statusId;
        $statusChecked = $statusId === $currentReservationStatus ? ' checked' : '';
        $reservationStatusOptionsHtml .= <<<HTML
                              <li>
                                <input type="radio" name="reservationStatusOption" value="{$statusValueHtml}" id="{$statusOptionId}" data-reservation-status-option{$statusChecked}>
                                <label for="{$statusOptionId}">{$statusLabelHtml}</label>
                              </li>

HTML;
      }
      $reservationStatusControlHtml = <<<HTML
                    <div class="select-menu is-selected" data-selectbox data-reservation-status-control data-current-status="{$currentReservationStatusValueHtml}">
                      <button type="button" class="selectbox__head" aria-expanded="false">
                        <input type="hidden" value="{$currentReservationStatusValueHtml}" data-selectbox-hidden data-reservation-status-value>
                        <span class="selectbox__value" data-selectbox-value>{$reservationStatusHtml}</span>
                      </button>
                      <div class="list-wrapper">
                        <ul class="selectbox__panel">
                          {$reservationStatusOptionsHtml}
                        </ul>
                      </div>
                    </div>
                    <input type="hidden" value="{$noUpDateKeyHtml}" data-reservation-status-no-update-key>
                    <input type="hidden" value="{$csrfTokenHtml}" data-reservation-status-csrf-token>
                    <input type="hidden" value="{$reservationIdHtml}" data-reservation-status-reservation-id>

HTML;
    }
    $reservationDetailContentTag = <<<HTML
        <form id="reservationDetailEditForm"{$reservationDetailEditFormAttributes}>
          {$reservationDetailEditMetadataHtml}
          {$reservationDetailEditNoticeHtml}
          <article class="block-overview">
            <h3>予約概要</h3>
            <dl>
              <div>
                <dt>予約ID</dt>
                <dd><span>#{$reservationIdHtml}</span></dd>
              </div>
              <div>
                <dt>来店日</dt>
                <dd>
                  <span>{$reservationDateHtml}</span>
                  <p>※来店日はこの画面では変更できません。日付を変更する場合は、いったんキャンセルのうえ、新しい日付で予約を取り直してください。</p>
                </dd>
              </div>
              <div>
                <dt>予約受付日時</dt>
                <dd><span>{$createdAtHtml}</span></dd>
              </div>
              <div>
                <dt>最終更新日時</dt>
                <dd><span>{$updatedAtHtml}</span></dd>
              </div>
              <div>
                <dt>キャンセル日時</dt>
                <dd><span>{$cancelledAtHtml}</span></dd>
              </div>
              <div>
                <dt>割当席</dt>
                <dd>
                  <span>{$seatLabelHtml}</span>
                  <p>※割り当て席はこの画面では変更できません。席変更機能は現在準備中です。</p>
                </dd>
              </div>
            </dl>
          </article>
          <article class="block-customer-info">
            <h3>お客様情報</h3>
            <dl>
              <div>
                <dt>お客様名</dt>
                <dd><input type="text" name="customerName" value="{$customerNameHtml}" maxlength="101" data-reservation-detail-field{$reservationDetailEditControlDisabled}></dd>
              </div>
              <div>
                <dt>ふりがな</dt>
                <dd><input type="text" name="customerKana" value="{$customerKanaHtml}" maxlength="101" data-reservation-detail-field{$reservationDetailEditControlDisabled}></dd>
              </div>
              <div>
                <dt>電話番号</dt>
                <dd><input type="text" name="customerTel" value="{$customerTelHtml}" maxlength="20" data-reservation-detail-field{$reservationDetailEditControlDisabled}></dd>
              </div>
              <div>
                <dt>メールアドレス</dt>
                <dd><input type="text" name="customerEmail" value="{$customerEmailHtml}" maxlength="255" data-reservation-detail-field{$reservationDetailEditControlDisabled}></dd>
              </div>
              <div class="item-channel">
                <dt>予約経路</dt>
                <dd>{$reservationRouteOptionsHtml}</dd>
              </div>
              <div class="item-person">
                <dt>人数</dt>
                <dd>{$reservationPersonControlHtml}</dd>
              </div>
                {$reservationMenuControlHtml}
              <div>
                <dt>宿泊宿名</dt>
                <dd><input type="text" name="accommodationName" value="{$accommodationNameHtml}" maxlength="100" data-reservation-detail-field{$reservationDetailEditControlDisabled}></dd>
              </div>
              <div>
                <dt class="position-top">備考 / ご要望</dt>
                <dd><textarea name="reservationNote" data-reservation-detail-field{$reservationDetailEditControlDisabled}>{$customerNoteHtml}</textarea></dd>
              </div>
            </dl>
          </article>
          <article class="block-management">
            <h3>管理情報</h3>
            <dl>
              <div class="item-menu item-reservation-status">
                <dt>予約ステータス</dt>
                <dd>{$reservationStatusControlHtml}</dd>
              </div>
              <div>
                <dt class="position-top">店舗メモ</dt>
                <dd><textarea name="shopMemo" data-reservation-detail-field{$reservationDetailEditControlDisabled}>{$shopMemoHtml}</textarea></dd>
              </div>
            </dl>
          </article>
          <div class="box-btn">
            <button type="button" class="btn-cancel" data-reservation-detail-back onclick="location.href='./client04_05.php';">一覧へ戻る</button>
            <button type="button" class="btn-confirmed" data-reservation-detail-save{$reservationDetailEditControlDisabled}>保存</button>
          </div>
        </form>

HTML;
  }
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
  <link rel="stylesheet" href="../assets/css/client04-05.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-05-01 status-client">
    <section class="container-left-menu menu-color04">
      <div class="title">飲食店予約</div>
      <nav>
        <a href="./client04_01.php" {$client04_01_active}><span>席管理</span></a>
        <a href="./client04_02.php" {$client04_02_active}><span>予約基本設定</span></a>
        <a href="./client04_03.php" {$client04_03_active}><span>食事メニュー管理</span></a>
        <a href="./client04_04.php" {$client04_04_active}><span>予約カレンダー</span></a>
        <a href="./client04_05.php" {$client04_05_01_active}><span>予約一覧</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color04">
      <div class="block_inner">
        <h2>予約詳細</h2>
        {$reservationDetailContentTag}
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <script src="../assets/js/common.js?v=20260919-1" defer></script>
  <script src="./assets/js/client04_05_01.js?v=20260919-1" defer></script>
  <script src="./assets/js/client04_05_01_status.js" defer></script>
</body>
</html>

HTML;
