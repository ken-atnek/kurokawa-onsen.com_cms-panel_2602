<?php
/*
 * [96-client/assets/function/proc_client04_05_01.php]
 * 【加盟店】飲食店予約登録処理
 */

require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_seats.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_calender.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservations.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_reservation_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

date_default_timezone_set('Asia/Tokyo');

header('Content-Type: application/json; charset=UTF-8');

/**
 * 管理画面予約応答生成
 *  既存Ajaxの4field形式へ統一する
 */
function clientReservationResponse($status, $title, $msg)
{
  return [
    'tag' => '',
    'status' => $status,
    'title' => $title,
    'msg' => $msg,
  ];
}
/**
 * transaction開始前エラー応答
 *  JSONを返して処理を終了する
 */
function clientReservationExit($title, $msg)
{
  echo json_encode(clientReservationResponse('error', $title, $msg), JSON_UNESCAPED_UNICODE);
  exit;
}
/**
 * Unicode空白判定
 *  不正UTF-8を含む文字列も安全側で空白扱いにする
 */
function clientReservationBlank($value)
{
  if (is_string($value) === false) {
    return true;
  }
  $result = preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $value);
  return $result !== 0;
}
/**
 * UTF-8文字数取得
 *  mbstring利用可能時は文字数、それ以外はbyte数を返す
 */
function clientReservationLength($value)
{
  return function_exists('mb_strlen') === true ? mb_strlen($value, 'UTF-8') : strlen($value);
}
/**
 * optional文字列正規化
 *  missing・空白のみはNULL、型不正はfalseを返す
 */
function clientReservationOptional($value)
{
  if ($value === null) {
    return null;
  }
  if (is_string($value) === false) {
    return false;
  }
  $blankResult = preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $value);
  if ($blankResult === false) {
    return false;
  }
  return $blankResult === 1 ? null : $value;
}
/**
 * 正のASCII整数文字列変換
 *  先頭ゼロやPHP_INT_MAX超過を受け付けない
 */
function clientReservationPositiveInt($value)
{
  if (is_string($value) === false || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
    return null;
  }
  $max = (string)PHP_INT_MAX;
  if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
    return null;
  }
  return (int)$value;
}
/**
 * 予約日形式検証
 *  YYYY-MM-DD形式の実在日だけを許可する
 */
function clientReservationDate($value)
{
  if (is_string($value) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
    return false;
  }
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
  $errors = DateTimeImmutable::getLastErrors();
  return $date !== false &&
    ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) &&
    $date->format('Y-m-d') === $value;
}
/**
 * 管理画面予約POST正規化
 *  transaction前に型・形式・上限を検証する
 */
function clientReservationNormalizePost($post, $today)
{
  $allowedKeys = [
    'noUpDateKey',
    'csrfToken',
    'reservationDate',
    'reservationPerson',
    'reservationRoute',
    'customerName',
    'customerKana',
    'customerTel',
    'customerEmail',
    'reservationMenu',
    'accommodationName',
    'reservationNote',
    'shopMemo',
  ];
  foreach (array_keys($post) as $key) {
    if (is_string($key) === false || in_array($key, $allowedKeys, true) === false) {
      return [false, '送信された項目が正しくありません。'];
    }
  }
  $reservationDate = $post['reservationDate'] ?? null;
  if (clientReservationDate($reservationDate) === false || $reservationDate < $today) {
    return [false, '予約日を正しく入力してください。'];
  }
  $partySize = clientReservationPositiveInt($post['reservationPerson'] ?? null);
  if ($partySize === null || $partySize > 4) {
    return [false, '予約人数は1名から4名で入力してください。'];
  }
  $routeMap = ['tel' => 2, 'other' => 3];
  $route = $post['reservationRoute'] ?? null;
  if (is_string($route) === false || isset($routeMap[$route]) === false) {
    return [false, '予約経路を正しく選択してください。'];
  }
  $customerName = normalizeReservationCustomerIdentityValue($post['customerName'] ?? null);
  $customerKana = normalizeReservationCustomerIdentityValue($post['customerKana'] ?? null);
  $customerTel = $post['customerTel'] ?? null;
  if (
    $customerName === null ||
    $customerKana === null ||
    clientReservationBlank($customerTel) === true ||
    clientReservationLength($customerTel) > 20
  ) {
    return [false, 'お客様情報を正しく入力してください。氏名とフリガナは姓名の間を空けて入力してください。'];
  }
  $email = clientReservationOptional($post['customerEmail'] ?? null);
  $accommodationName = clientReservationOptional($post['accommodationName'] ?? null);
  $customerNote = clientReservationOptional($post['reservationNote'] ?? null);
  $shopMemo = clientReservationOptional($post['shopMemo'] ?? null);
  if ($email === false || $accommodationName === false || $customerNote === false || $shopMemo === false) {
    return [false, '任意項目の形式が正しくありません。'];
  }
  if ($email !== null && (clientReservationLength($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
    return [false, 'メールアドレスを正しく入力してください。'];
  }
  if ($accommodationName !== null && clientReservationLength($accommodationName) > 100) {
    return [false, '宿泊施設名は100文字以内で入力してください。'];
  }
  if (($customerNote !== null && strlen($customerNote) > 65535) || ($shopMemo !== null && strlen($shopMemo) > 65535)) {
    return [false, '備考が上限を超えています。'];
  }
  $rawMenus = $post['reservationMenu'] ?? [];
  if (is_array($rawMenus) === false || (empty($rawMenus) === false && array_keys($rawMenus) !== range(0, count($rawMenus) - 1))) {
    return [false, 'メニューの指定が正しくありません。'];
  }
  $menuSlots = [];
  foreach ($rawMenus as $rawMenuId) {
    if (is_string($rawMenuId) === false) {
      return [false, 'メニューの指定が正しくありません。'];
    }
    if ($rawMenuId === '') {
      $menuSlots[] = null;
      continue;
    }
    $menuId = clientReservationPositiveInt($rawMenuId);
    if ($menuId === null) {
      return [false, 'メニューの指定が正しくありません。'];
    }
    $menuSlots[] = $menuId;
  }
  $reservationData = [
    'reservation_date' => $reservationDate,
    'party_size' => $partySize,
    'reservation_route' => $routeMap[$route],
    'customer_name' => $customerName,
    'customer_kana' => $customerKana,
    'customer_tel' => $customerTel,
    'customer_email' => $email,
    'accommodation_name' => $accommodationName,
    'customer_note' => $customerNote,
    'shop_memo' => $shopMemo,
  ];
  return [true, null, 'party' => $partySize, 'menus' => $menuSlots, 'data' => $reservationData];
}
/**
 * menu type別slot正規化
 *  type0は無視し、type1・2はparty_size分の位置を維持する
 */
function clientReservationMenus($menus, $partySize, $menuSelectionType)
{
  if ($menuSelectionType === 0) {
    return [];
  }
  if (in_array($menuSelectionType, [1, 2], true) === false || count($menus) !== $partySize) {
    return null;
  }
  if ($menuSelectionType === 2 && in_array(null, $menus, true) === true) {
    return null;
  }
  return array_values($menus);
}
/**
 * C3-A結果応答変換
 *  内部reasonを管理画面向けメッセージへ変換する
 */
function clientReservationMap($result)
{
  if (is_array($result) === true && ($result['success'] ?? false) === true) {
    return clientReservationResponse('success', '予約登録', '予約を登録しました。');
  }
  if (($result['reason'] ?? null) === 'invalid_menu') {
    return clientReservationResponse('error', 'メニューエラー', '選択されたメニューを確認してください。');
  }
  if (($result['reason'] ?? null) === 'allocation_failed') {
    $responses = [
      'full' => ['満席', '選択された日時は満席です。'],
      'regular_holiday' => ['定休日', '選択された日は定休日です。'],
      'shop_holiday' => ['店休日', '選択された日は店休日です。'],
      'acceptance_stopped' => ['受付停止', '選択された日は予約受付を停止しています。'],
    ];
    $allocationReason = $result['allocation_reason'] ?? '';
    if (isset($responses[$allocationReason])) {
      return clientReservationResponse('error', $responses[$allocationReason][0], $responses[$allocationReason][1]);
    }
  }
  return clientReservationResponse('error', '登録エラー', '予約登録に失敗しました。');
}
$noUpDateKey = is_string($_POST['noUpDateKey'] ?? null) ? $_POST['noUpDateKey'] : '';
$currentNoUpDateKey = (string)($_SESSION['sKey'] ?? '');
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
  if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
    $noUpDateKey = $currentNoUpDateKey;
  } else {
    clientReservationExit('セッションエラー', 'セッションが切れました。ページを再読み込みしてください。');
  }
}
$sessionShopId = $_SESSION['client_login']['shop_id'] ?? null;
if (is_int($sessionShopId) === true) {
  $shopId = $sessionShopId;
} elseif (is_string($sessionShopId) === true && preg_match('/\A[1-9][0-9]*\z/D', $sessionShopId) === 1) {
  $shopId = (int)$sessionShopId;
} else {
  clientReservationExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。');
}
if ($shopId < 1) {
  clientReservationExit('セッションエラー', '店舗情報が取得できませんでした。再ログインしてください。');
}
if (validateClientCsrfToken($_POST['csrfToken'] ?? null) !== true) {
  clientReservationExit('セッションエラー', '画面を再読み込みして再度操作してください。');
}
$normalizedPost = clientReservationNormalizePost($_POST, date('Y-m-d'));
if ($normalizedPost[0] !== true) {
  clientReservationExit('入力エラー', $normalizedPost[1]);
}
$transactionStarted = false;
$response = clientReservationResponse('error', '登録エラー', '予約登録に失敗しました。');
try {
  if (DB_Transaction(1) !== true) {
    $response = clientReservationResponse('error', '登録エラー', '予約登録を開始できませんでした。');
  } else {
    $transactionStarted = true;
    $lockedShop = getReservationShopForUpdate($shopId);
    if ($lockedShop === false) {
      $response = clientReservationResponse('error', '登録エラー', '店舗の予約情報を確認できませんでした。');
    } elseif ($lockedShop === null || (int)($lockedShop['shop_id'] ?? 0) !== $shopId) {
      $response = clientReservationResponse('error', '予約受付不可', '店舗の予約情報を確認できませんでした。');
    } else {
      $shop = getReservationShopForOccupancy($shopId);
      $settings = getShopReservationSettings($shopId);
      if ($shop === false || $settings === false) {
        $response = clientReservationResponse('error', '登録エラー', '予約設定を確認できませんでした。');
      } elseif ($shop === null || ($shop['shop_type'] ?? null) !== 'food' || (int)($shop['is_active'] ?? 0) !== 1) {
        $response = clientReservationResponse('error', '予約受付不可', 'この店舗では予約を登録できません。');
      } elseif ($settings === null || (int)($settings['reservation_enabled'] ?? 0) !== 1) {
        $response = clientReservationResponse('error', '予約受付不可', '現在、予約受付は利用できません。');
      } else {
        $guestMin = normalizeReservationRegistrationInteger($settings['guest_min'] ?? null, 1, 4);
        $guestMax = normalizeReservationRegistrationInteger($settings['guest_max'] ?? null, 1, 4);
        $menuSelectionType = normalizeReservationRegistrationInteger($settings['menu_selection_type'] ?? null, 0, 2);
        if ($guestMin === null || $guestMax === null || $guestMin > $guestMax || $menuSelectionType === null) {
          $response = clientReservationResponse('error', '登録エラー', '予約設定を確認できませんでした。');
        } elseif ($normalizedPost['party'] < $guestMin || $normalizedPost['party'] > $guestMax) {
          $response = clientReservationResponse('error', '予約受付不可', '予約可能な人数の範囲を確認してください。');
        } else {
          $menuSelections = clientReservationMenus($normalizedPost['menus'], $normalizedPost['party'], $menuSelectionType);
          if ($menuSelections === null) {
            $response = clientReservationResponse('error', 'メニューエラー', '人数分のメニュー選択を確認してください。');
          } else {
            $result = executeReservationRegistration($shopId, $normalizedPost['data'], $menuSelections);
            $response = clientReservationMap($result);
          }
        }
      }
    }
    if ($response['status'] === 'success') {
      if (DB_Transaction(2) === true) {
        $transactionStarted = false;
      } else {
        $response = clientReservationResponse('error', '登録エラー', '予約登録に失敗しました。');
      }
    }
  }
} catch (Throwable $e) {
  $response = clientReservationResponse('error', '登録エラー', '予約登録に失敗しました。');
}
if ($transactionStarted === true && is_object($DB_CONNECT) === true && method_exists($DB_CONNECT, 'inTransaction') === true && $DB_CONNECT->inTransaction() === true) {
  try {
    DB_Transaction(3);
  } catch (Throwable $e) {
    # 応答は登録失敗のまま返す
  }
}

if ($transactionStarted === false && $response['status'] === 'success') {
  syncFrontendReservationAvailabilityDayJson($response, $shopId, $normalizedPost['data']['reservation_date']);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
