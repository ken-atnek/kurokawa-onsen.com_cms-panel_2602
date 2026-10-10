<?php
/*
 * [96-master/master04_01.php]
 * 【管理】トップページ飲食店予約の店舗表示順変更
*/

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeFoodShopsSortJson.php';

/**
 * 表示順管理画面の文字列をHTML escapeする
 *  店名、画像URL、画面識別値を安全に出力する。
 */
function masterFoodShopOrderHtml($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$accountId = $_SESSION['master_login']['account_id'] ?? null;
if ((int)($_SESSION['master_login']['status'] ?? 0) !== 1 || !is_numeric($accountId) || (int)$accountId < 1) {
  header('Location: ./logout.php');
  exit;
}
$noUpDateKey = 'mKey04-01_' . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $value) {
  if ($key !== 'sKey' && $key !== 'master_login' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = ['masterKey' => (int)$accountId, 'foodShopOrderPage' => true];

$shops = getReservationFoodShopOrderRows();
$orderVersion = is_array($shops) ? buildReservationFoodShopOrderVersion($shops) : false;
$pageReady = is_array($shops) && is_string($orderVersion);
$jsonError = false;
if ($pageReady) {
  try {
    $jsonError = generateFoodShopsSortJson() !== true;
  } catch (Throwable $e) {
    $jsonError = true;
  }
}
$jsonErrorHtml = $jsonError ? '1' : '0';

print <<<HTML
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
  <meta name="robots" content="noindex,nofollow">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
  <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
  <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
  <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
  <link rel="stylesheet" href="../assets/css/master04-01.css">
</head>

<body>

HTML;
@include './inc_header.php';
print <<<HTML
  <main class="inner-04-01">
    <section class="container-left-menu menu-color04">
      <div class="title">飲食店予約</div>
      <nav>
        <a href="./master04_01.php" {$master04_01_active}><span>表示順</span></a>
      </nav>
    </section>
    <div class="main-contents menu-color04">
      <div class="block_inner">
        <h2>表示順管理</h2>
        <article class="inner-contents">
          <h3>表示順一覧</h3>
          <div class="contents-list">

HTML;
if (!$pageReady) {
  print '<p class="notice" role="alert">店舗一覧の取得に失敗しました。再度ページを開き直してください。</p>';
} elseif ($shops === []) {
  print '<p class="notice">並び替えできる店舗はありません。</p>';
} else {
  $keyHtml = masterFoodShopOrderHtml($noUpDateKey);
  $versionHtml = masterFoodShopOrderHtml($orderVersion);
  print <<<HTML
            <ul class="box-seat-list" id="foodShopOrderList" data-no-up-date-key="{$keyHtml}" data-order-version="{$versionHtml}">
              <li><div></div><div>店舗画像</div><div style="align-items: flex-start">店名</div></li>

HTML;
  foreach ($shops as $shop) {
    $shopId = (int)$shop['shop_id'];
    $shopNameHtml = masterFoodShopOrderHtml($shop['shop_name'] ?? '');
    $imagePath = $shop['main_image_path'] ?? null;
    $imageValid = is_string($imagePath) && preg_match(
      '~\A/db/images/shops/' . preg_quote(sprintf('%03d', $shopId), '~') . '/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z~iD',
      $imagePath
    ) === 1;
    $imageUrl = $imageValid ? DOMAIN_NAME_PREVIEW . $imagePath : '../assets/images/no-image.webp';
    $imageHtml = masterFoodShopOrderHtml($imageUrl);
    $imageAlt = $imageValid ? '店舗画像' : '店舗画像なし';
    $draggable = count($shops) > 1 ? ' draggable="true"' : ' disabled';
    print <<<HTML
              <li class="food-shop-order-row" data-shop-id="{$shopId}">
                <div class="item-control"><button type="button" class="food-shop-drag-handle"{$draggable} aria-label="店舗の並び替え"><span></span><span></span><span></span></button></div>
                <div class="item-image"><picture><img src="{$imageHtml}" alt="{$imageAlt}"></picture></div>
                <div class="item-name"><span>{$shopNameHtml}</span></div>
              </li>

HTML;
  }
  print <<<HTML
            </ul>

HTML;
  if (count($shops) > 1) {
    print '<p class="notice">並び順（表示順）はドラッグ＆ドロップで変更できます。</p>';
  }
}
print <<<HTML
          </div>
        </article>
        <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
      </div>
    </div>
  </main>
  <article class="modal-alert" id="foodShopOrderModal" data-page-json-error="{$jsonErrorHtml}" role="dialog" aria-modal="true" aria-label="表示順管理" aria-hidden="true">
    <div class="inner-modal">
      <div class="box-title">
        <p>表示順管理</p>
        <button type="button" class="btn-top-close" data-order-modal-close aria-label="閉じる"></button>
      </div>
      <div class="box-details">
        <p data-order-modal-message></p>
        <div class="box-btn">
          <button type="button" class="btn-cancel" data-order-modal-ok>閉じる</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="./assets/js/master04_01.js?18231910102026" defer></script>
</body>

</html>

HTML;
