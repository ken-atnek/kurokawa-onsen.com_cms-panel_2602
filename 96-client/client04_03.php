<?php
/*
 * [96-client/client04_03.php]
 *  【加盟店】食事メニュー一覧
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';

/** 管理画面の本文・属性値をescapeする。 */
function foodMenuListHtml($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$shopRaw = $_SESSION['client_login']['shop_id'] ?? null;
$shopId = is_int($shopRaw) ? $shopRaw : (is_string($shopRaw) && preg_match('/\A[1-9][0-9]*\z/D', $shopRaw) === 1 ? filter_var($shopRaw, FILTER_VALIDATE_INT) : false);
$accountId = $_SESSION['client_login']['account_id'] ?? null;
if (!is_int($shopId) || $shopId < 1 || !is_numeric($accountId) || (int)$accountId < 1) {
  header('Location: ./logout.php');
  exit;
}
$shop = getShops_FindById($shopId);
if (!is_array($shop) || (int)($shop['shop_id'] ?? 0) !== $shopId) {
  header('Location: ./logout.php');
  exit;
}
$headerShopName = foodMenuListHtml($shop['shop_name'] ?? '');
$headerShopType = foodMenuListHtml($shop['shop_type'] ?? '');

$noUpDateKey = 'cKey04-03_' . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $value) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = ['clientKey' => $accountId, 'shopId' => $shopId, 'foodMenuPage' => 'list'];
$csrfToken = getClientCsrfToken();
$menus = getFoodMenusForManagement($shopId);
$orderVersion = is_array($menus) ? buildFoodMenuManagementOrderVersion($menus) : false;
$versions = [];
$pageReady = is_string($csrfToken) && is_array($menus) && is_string($orderVersion);
if ($pageReady) {
  foreach ($menus as $menu) {
    $version = buildFoodMenuManagementVersion($menu);
    if (!is_string($version)) {
      $pageReady = false;
      break;
    }
    $versions[$menu['id']] = $version;
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
  <link rel="stylesheet" href="../assets/css/client04-03.css">
</head>
<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-03 status-client">
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
        <h2>食事メニュー管理</h2>
        <article class="inner-contents">
          <h3>メニュー一覧

HTML;
if ($pageReady) {
  print '<button type="button" id="foodMenuCreate"><span>新規追加</span></button>';
}
print <<<HTML
          </h3>
          <div class="contents-list">

HTML;
if (!$pageReady) {
  print '<p class="notice" role="alert">メニュー情報を読み込めませんでした。操作を停止しています。ページを再読み込みしてください。</p>';
} else {
  $keyHtml = foodMenuListHtml($noUpDateKey);
  $csrfHtml = foodMenuListHtml($csrfToken);
  $orderHtml = foodMenuListHtml($orderVersion);
  print <<<HTML
            <ul class="box-seat-list" id="foodMenuList" data-no-up-date-key="{$keyHtml}" data-csrf-token="{$csrfHtml}" data-order-version="{$orderHtml}">
                <li><div></div><div>画像</div><div style="align-items: flex-start">メニュー名</div><div>料金</div><div>掲載期間</div><div>状態</div><div></div></li>

HTML;
  foreach ($menus as $menu) {
    $id = $menu['id'];
    $versionHtml = foodMenuListHtml($versions[$id]);
    $nameHtml = foodMenuListHtml($menu['menu_name']);
    $priceHtml = foodMenuListHtml(number_format($menu['price']) . '円');
    $checked = $menu['is_active'] === 1 ? ' checked' : '';
    $imageHtml = '';
    $imagePath = $menu['image_path'];
    if (is_string($imagePath) && preg_match('~\A/db/images/shops/' . preg_quote(sprintf('%03d', $shopId), '~') . '/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z~iD', $imagePath) === 1) {
      $imageUrl = foodMenuListHtml($imagePath);
      $imageHtml = '<picture><img src="' . $imageUrl . '" alt="メニュー画像"></picture>';
    }
    if ($menu['period_type'] === 1) {
      $periodHtml = '<div style="font-size: max(12px, 0.875em);">無期限</div>';
    } else {
      $start = is_string($menu['period_start']) ? foodMenuListHtml(str_replace('-', '/', $menu['period_start'])) : '';
      $end = is_string($menu['period_end']) ? foodMenuListHtml(str_replace('-', '/', $menu['period_end'])) : '';
      $periodHtml = '<span>' . $start . '</span><span>' . $end . '</span>';
    }
    print <<<HTML
                <li class="food-menu-row" data-menu-id="{$id}" data-menu-version="{$versionHtml}">
                  <div class="item-control"><button type="button" class="food-menu-drag-handle" draggable="true" aria-label="メニューの並び替え"><span></span><span></span><span></span></button></div>
                  <div class="item-image">{$imageHtml}</div>
                  <div class="item-name"><span>{$nameHtml}</span></div>
                  <div class="item-price"><span>{$priceHtml}</span></div>
                  <div class="item-periode">{$periodHtml}</div>
                  <div class="item-toggle"><div class="wrap-toggle-button" data-tooltip-on="有効中 | 無効にする" data-tooltip-off="無効中 | 有効にする"><label class="toggle-button"><input type="checkbox" class="food-menu-toggle" aria-label="メニューの有効状態"{$checked}></label></div></div>
                  <nav><button type="button" class="btn-edit" data-tooltip="編集" aria-label="編集"></button></nav>
                </li>

HTML;
  }
  print <<<HTML
              </ul>

HTML;
  if ($menus === []) {
    print '<p class="notice">登録されているメニューはありません。</p>';
  }
  print <<<HTML
              <p class="notice">並び順（表示順）はドラッグ＆ドロップで変更できます。</p>

HTML;
}
print <<<HTML
          </div>
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <article class="modal-alert" id="foodMenuModal" role="dialog" aria-modal="true" aria-label="食事メニュー管理" aria-hidden="true">
    <div class="inner-modal">
      <div class="box-title">
        <p>食事メニュー管理</p>
        <button type="button" class="btn-top-close" data-menu-modal-close aria-label="閉じる"></button>
      </div>
      <div class="box-details">
        <p data-menu-modal-message></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" data-menu-modal-ok>閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/client04_03.js" defer></script>
</body>
</html>

HTML;
