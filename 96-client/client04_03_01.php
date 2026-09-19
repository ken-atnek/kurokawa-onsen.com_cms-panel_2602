<?php
/*
 * [96-client/client04_03_01.php]
 * 【加盟店】食事メニュー登録／編集
 */

require_once dirname(__DIR__) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';

/** フォームの本文・属性値をescapeする。 */
function foodMenuFormHtml($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
/** GET menuIdをASCII正整数として検証する。 */
function foodMenuGetId($value)
{
  if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
    return null;
  }
  $id = filter_var($value, FILTER_VALIDATE_INT);
  return $id === false || $id < 1 ? null : $id;
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
$headerShopName = foodMenuFormHtml($shop['shop_name'] ?? '');
$headerShopType = foodMenuFormHtml($shop['shop_type'] ?? '');

$edit = array_key_exists('menuId', $_GET);
$menu = null;
if ($edit) {
  $menuId = foodMenuGetId($_GET['menuId']);
  if ($menuId === null) {
    header('Location: ./client04_03.php');
    exit;
  }
  $menu = getFoodMenuForManagement($shopId, $menuId);
  if ($menu === null) {
    header('Location: ./client04_03.php');
    exit;
  }
}
$noUpDateKey = 'cKey04-03-form_' . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
foreach ($_SESSION as $key => $value) {
  if ($key !== 'sKey' && $key !== 'client_login' && $key !== 'client_csrf_token' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
$_SESSION[$noUpDateKey] = ['clientKey' => $accountId, 'shopId' => $shopId, 'foodMenuPage' => 'form'];
$csrfToken = getClientCsrfToken();
$menuVersion = is_array($menu) ? buildFoodMenuManagementVersion($menu) : null;
$pageReady = is_string($csrfToken) && (!$edit || (is_array($menu) && is_string($menuVersion)));

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
  <main class="inner-04-03-01 status-client">
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
    <div class="main-contents menu-color04"><div class="block_inner">
      <h2>食事メニュー管理</h2>

HTML;
if (!$pageReady) {
  print '<p class="notice" role="alert">メニュー情報を読み込めませんでした。保存を停止しています。ページを再読み込みしてください。</p>';
} else {
  $mode = $edit ? 'update' : 'create';
  $keyHtml = foodMenuFormHtml($noUpDateKey);
  $csrfHtml = foodMenuFormHtml($csrfToken);
  $idHtml = $edit ? foodMenuFormHtml($menu['id']) : '';
  $versionHtml = $edit ? foodMenuFormHtml($menuVersion) : '';
  $nameHtml = $edit ? foodMenuFormHtml($menu['menu_name']) : '';
  $priceHtml = $edit ? foodMenuFormHtml($menu['price']) : '';
  $descriptionHtml = $edit ? foodMenuFormHtml($menu['description'] ?? '') : '';
  $unlimitedChecked = !$edit || $menu['period_type'] === 1 ? ' checked' : '';
  $customChecked = $edit && $menu['period_type'] === 2 ? ' checked' : '';
  $startHtml = $edit && $menu['period_type'] === 2 ? foodMenuFormHtml($menu['period_start'] ?? '') : '';
  $endHtml = $edit && $menu['period_type'] === 2 ? foodMenuFormHtml($menu['period_end'] ?? '') : '';
  $activeChecked = !$edit || $menu['is_active'] === 1 ? ' checked' : '';
  $imageHtml = '';
  if (
    $edit && is_string($menu['image_path']) &&
    preg_match('~\A/db/images/shops/' . preg_quote(sprintf('%03d', $shopId), '~') . '/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z~iD', $menu['image_path']) === 1
  ) {
    $url = foodMenuFormHtml($menu['image_path']);
    $imageHtml = '<li data-image-kind="existing"><button type="button" class="food-menu-image-remove" aria-label="画像を削除"></button><picture><img src="' . $url . '" alt="メニュー画像"></picture></li>';
  }
  $hasImage = $imageHtml === '' ? '0' : '1';
  print <<<HTML
      <form id="foodMenuForm" data-mode="{$mode}" data-has-existing-image="{$hasImage}">
        <input type="hidden" name="noUpDateKey" value="{$keyHtml}">
        <input type="hidden" name="csrfToken" value="{$csrfHtml}">
        <input type="hidden" name="menuId" value="{$idHtml}">
        <input type="hidden" name="menuVersion" value="{$versionHtml}">
        <h3>メニュー情報</h3>
        <dl>
          <div class="box-name"><dt class="is-required">メニュー名</dt><dd><input type="text" name="menuName" value="{$nameHtml}" maxlength="100" required></dd></div>
          <div class="box-price"><dt class="is-required">料金</dt><dd><input type="text" name="menuPrice" value="{$priceHtml}" inputmode="numeric" required>円</dd></div>
          <div class="box-image"><dt class="position-top is-required">画像</dt><dd>
            <div class="select-image" id="foodMenuDropZone">
              <h4>画像をここにドラッグ＆ドロップ</h4><span>または</span>
              <button type="button" id="foodMenuSelectImage">＋ファイルを選択</button>
              <span>JPG / PNG / WebP｜最大5MB｜1枚</span>
              <input type="file" id="foodMenuImageFile" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden>
              <div class="wrap-caution" id="foodMenuImageError" role="alert" style="display:none" hidden></div>
            </div>
            <ul id="foodMenuImagePreview">{$imageHtml}</ul>
          </dd></div>
          <div><dt class="position-top is-required">説明文</dt><dd><textarea name="menuDescription" required>{$descriptionHtml}</textarea></dd></div>
          <div class="box-periode"><dt class="position-top is-required">掲載期間</dt><dd>
            <div class="wrap-dd-top"><input type="radio" name="menuPublicationPeriod" id="menuPublicationPeriodUnlimited" value="unlimited"{$unlimitedChecked}><label for="menuPublicationPeriodUnlimited">無期限</label></div>
            <div class="wrap-dd-bottom"><div class="item-check-box"><input type="radio" name="menuPublicationPeriod" id="menuPublicationPeriodCustom" value="custom"{$customChecked}><label for="menuPublicationPeriodCustom">期間を指定する</label></div>
              <div class="item-periode"><span>開始日</span><input type="date" name="menuPublicationStartDay" value="{$startHtml}"><i>〜</i><span>終了日</span><input type="date" name="menuPublicationEndDay" value="{$endHtml}"></div>
            </div>
          </dd></div>
          <div class="box-status"><dt class="is-required">状態</dt><dd><div class="item-toggle">
            <div class="wrap-toggle-button" data-tooltip-on="有効中 | 無効にする" data-tooltip-off="無効中 | 有効にする"><label class="toggle-button"><input type="checkbox" name="menuIsActive"{$activeChecked}></label></div>
          </div></dd></div>
        </dl>
        <div class="box-btn"><button type="button" class="btn-cancel" id="foodMenuCancel">キャンセル</button><button type="submit" class="btn-confirmed" id="foodMenuSave">保存</button></div>
      </form>

HTML;
}
print <<<HTML
      <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
    </div></div>
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
          <button type="button" class="btn-confirm" data-menu-modal-confirm hidden style="display:none">画像を削除</button>
        </div>
      </div>
    </div>
  </article>
  <script src="../assets/js/common.js" defer></script>
  <script src="../assets/js/dropZone.js" defer></script>
  <script src="./assets/js/client04_03_01.js" defer></script>
</body>
</html>

HTML;
