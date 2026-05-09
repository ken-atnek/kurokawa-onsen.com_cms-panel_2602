<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
/*
 * [delivery_slip.php]
 *  - 【加盟店】納品書画面 -
 *  納品書PDF用HTMLをJSONで返すAPI
 *
 * mode:
 *  - checkDeliverySlipData: 出力可否チェック
 *  - makeDeliverySlipHtml : PDF描画用HTML生成
 */

require_once '../../../cms_config/common/define.php';
require_once '../../../cms_config/common/set_function.php';
require_once '../../../cms_config/common/set_contents.php';
require_once '../../../cms_config/database/set_db.php';
require_once '../../../cms_config/database/db_accounts.php';
require_once '../../../cms_config/database/db_shops.php';
require_once '../../../cms_config/database/db_orders.php';

$mode = isset($_REQUEST['mode']) ? (string)$_REQUEST['mode'] : '';
$shopIdRaw = isset($_REQUEST['shopId']) ? (string)$_REQUEST['shopId'] : '';
$orderIdRaw = isset($_REQUEST['orderId']) ? (string)$_REQUEST['orderId'] : '';

if (ctype_digit($shopIdRaw) === false || (int)$shopIdRaw < 1 || ctype_digit($orderIdRaw) === false || (int)$orderIdRaw < 1) {
  outputDeliverySlipPdfJsonAndExit('error', '不正なリクエストです。', '', $shopIdRaw, $orderIdRaw);
}

$shopId = (int)$shopIdRaw;
$orderId = (int)$orderIdRaw;
$shopData = getShops_FindById($shopId);
if (empty($shopData)) {
  outputDeliverySlipPdfJsonAndExit('error', '店舗情報が見つかりません。', '', $shopIdRaw, $orderIdRaw);
}
$orderDetail = getShopOrderById($orderId, $shopId);
if (empty($orderDetail)) {
  outputDeliverySlipPdfJsonAndExit('error', '対象受注が見つかりません。', '', $shopIdRaw, $orderIdRaw);
}
$orderItemsByOrderId = getShopOrderItemsByOrderIds([(int)$orderDetail['order_id']]);
$orderItems = $orderItemsByOrderId[(int)$orderDetail['order_id']] ?? [];

$deliverySlipItems = [];
foreach ($orderItems as $orderItem) {
  if (trim((string)($orderItem['current_item_status'] ?? '')) === 'returned_full') {
    continue;
  }
  $deliverySlipItems[] = $orderItem;
}

if ($mode === 'checkDeliverySlipData') {
  if (empty($deliverySlipItems)) {
    outputDeliverySlipPdfJsonAndExit('error', '納品書に出力できる商品がありません。', '', $shopIdRaw, $orderIdRaw);
  }
  outputDeliverySlipPdfJsonAndExit('success', '', '', $shopIdRaw, $orderIdRaw);
}
if ($mode === 'makeDeliverySlipHtml') {
  if (empty($deliverySlipItems)) {
    outputDeliverySlipPdfJsonAndExit('error', '納品書に出力できる商品がありません。', '', $shopIdRaw, $orderIdRaw);
  }
  $html = buildDeliverySlipPdfHtml($orderDetail, $deliverySlipItems, $shopData);
  outputDeliverySlipPdfJsonAndExit('success', '', $html, $shopIdRaw, $orderIdRaw);
}
outputDeliverySlipPdfJsonAndExit('error', '不正なリクエストです。', '', $shopIdRaw, $orderIdRaw);

/**
 * HTMLエスケープ
 */
function hPdfValue($value): string
{
  return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}
/**
 * 納品書表示用の日付形式
 */
function formatPdfDateValue($value): string
{
  $value = trim((string)$value);
  if ($value === '') {
    return '-';
  }
  $ts = strtotime($value);
  if ($ts === false) {
    return hPdfValue($value);
  }
  return '<span>' . htmlspecialchars(date('Y/m/d', $ts), ENT_QUOTES, 'UTF-8') . '</span>' . '<span>' . htmlspecialchars(date('H:i', $ts), ENT_QUOTES, 'UTF-8') . '</span>';
}
/**
 * 郵便番号を000-0000へ整形
 */
function formatPdfPostalCode($value): string
{
  $raw = trim((string)$value);
  $digits = preg_replace('/\D/u', '', $raw);
  if (is_string($digits) && preg_match('/^\d{7}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 4), ENT_QUOTES, 'UTF-8');
  }
  return hPdfValue($raw);
}
/**
 * 電話番号をハイフン付きへ整形
 */
function formatPdfTel($value): string
{
  $raw = trim((string)$value);
  if ($raw === '') {
    return '';
  }
  if (strpos($raw, '-') !== false) {
    return hPdfValue($raw);
  }
  $digits = preg_replace('/\D/u', '', $raw);
  if (!is_string($digits) || $digits === '') {
    return hPdfValue($raw);
  }
  if (preg_match('/^0[789]0\d{8}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7, 4), ENT_QUOTES, 'UTF-8');
  }
  if (preg_match('/^0120\d{6}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . '-' . substr($digits, 7, 3), ENT_QUOTES, 'UTF-8');
  }
  if (preg_match('/^0967\d{6}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 4), ENT_QUOTES, 'UTF-8');
  }
  if (preg_match('/^096\d{7}$/', $digits) === 1 || preg_match('/^\d{10}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4), ENT_QUOTES, 'UTF-8');
  }
  if (preg_match('/^\d{11}$/', $digits) === 1) {
    return htmlspecialchars(substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7, 4), ENT_QUOTES, 'UTF-8');
  }
  return hPdfValue($raw);
}
/**
 * 納品書HTMLを生成
 */
function buildDeliverySlipPdfHtml(array $orderDetail, array $orderItems, array $shopData): string
{
  $ordererName = trim((string)($orderDetail['orderer_name'] ?? ''));
  $ordererPostalCode = trim((string)($orderDetail['orderer_postal_code'] ?? ''));
  $ordererPrefName = trim((string)($orderDetail['orderer_pref_name'] ?? ''));
  $ordererAddr01 = trim((string)($orderDetail['orderer_addr01'] ?? ''));
  $ordererAddr02 = trim((string)($orderDetail['orderer_addr02'] ?? ''));
  $ordererTel = trim((string)($orderDetail['orderer_tel'] ?? ''));
  $shippingName = trim((string)($orderDetail['shipping_name'] ?? ''));
  $shippingPostalCode = trim((string)($orderDetail['shipping_postal_code'] ?? ''));
  $shippingPrefName = trim((string)($orderDetail['shipping_pref_name'] ?? ''));
  $shippingAddr01 = trim((string)($orderDetail['shipping_addr01'] ?? ''));
  $shippingAddr02 = trim((string)($orderDetail['shipping_addr02'] ?? ''));
  $shippingTel = trim((string)($orderDetail['shipping_tel'] ?? ''));

  $toName = ($shippingName !== '') ? $shippingName : $ordererName;
  $toPostalCode = ($shippingPostalCode !== '') ? $shippingPostalCode : $ordererPostalCode;
  $toPrefName = ($shippingPrefName !== '') ? $shippingPrefName : $ordererPrefName;
  $toAddr01 = ($shippingAddr01 !== '') ? $shippingAddr01 : $ordererAddr01;
  $toAddr02 = ($shippingAddr02 !== '') ? $shippingAddr02 : $ordererAddr02;
  $toTel = ($shippingTel !== '') ? $shippingTel : $ordererTel;

  $itemSubtotalTotal = 0;
  $tax10Total = 0;
  $tax8Total = 0;
  $itemRowsHtml = '';
  foreach ($orderItems as $orderItem) {
    $taxRate = (int)($orderItem['tax_rate'] ?? 10);
    if ($taxRate <= 0) {
      $taxRate = 10;
    }
    $unitPriceTaxIn = (int)round((int)($orderItem['unit_price'] ?? 0) * (1 + ($taxRate / 100)));
    $subtotalTaxIn = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($taxRate / 100)));
    $quantity = (int)($orderItem['quantity'] ?? 0);
    $itemSubtotalTotal += $subtotalTaxIn;
    if ($taxRate === 8) {
      $tax8Total += $subtotalTaxIn;
    } else {
      $tax10Total += $subtotalTaxIn;
    }
    $itemProductName = hPdfValue($orderItem['product_name'] ?? '');
    $itemTaxRate = hPdfValue((string)$taxRate);
    $itemQuantity = hPdfValue((string)$quantity);
    $itemUnitPrice = hPdfValue(number_format($unitPriceTaxIn));
    $itemSubtotal = hPdfValue(number_format($subtotalTaxIn));
    $itemRowsHtml .= <<<HTML
      <li>
        <div class="item-name">{$itemProductName}</div>
        <div><span>{$itemTaxRate}%</span></div>
        <div>{$itemQuantity}</div>
        <div class="item-price">{$itemUnitPrice}</div>
        <div class="item-price">{$itemSubtotal}</div>
      </li>

HTML;
  }

  $deliveryFee = (int)($orderDetail['delivery_fee_total'] ?? 0);
  $paymentTotal = (int)($orderDetail['payment_total'] ?? 0);
  $tax10Amount = max(0, $tax10Total - (int)round($tax10Total / 1.10));
  $tax8Amount = max(0, $tax8Total - (int)round($tax8Total / 1.08));

  $toPostalCodeHtml = formatPdfPostalCode($toPostalCode);
  $toAddressHtml = hPdfValue($toPrefName . $toAddr01 . $toAddr02);
  $toNameHtml = hPdfValue($toName);
  $toTelHtml = formatPdfTel($toTel);

  $shopName = hPdfValue($shopData['shop_name'] ?? '');
  $shopPostalCode = formatPdfPostalCode($shopData['postal_code'] ?? '');
  $shopPrefName = hPdfValue('熊本県');
  $shopAddr01 = hPdfValue($shopData['address1'] ?? '');
  $shopAddr02 = hPdfValue($shopData['address2'] ?? '');
  $shopTel = formatPdfTel($shopData['tel'] ?? '');

  $orderDateHtml = formatPdfDateValue($orderDetail['ordered_at'] ?? '');
  $orderNoHtml = hPdfValue($orderDetail['order_no'] ?? '');
  $paymentTotalText = hPdfValue(number_format($paymentTotal));
  $itemSubtotalTotalText = hPdfValue(number_format($itemSubtotalTotal));
  $deliveryFeeText = hPdfValue(number_format($deliveryFee));
  $tax10TotalText = hPdfValue(number_format($tax10Total));
  $tax10AmountText = hPdfValue(number_format($tax10Amount));
  $tax8TotalText = hPdfValue(number_format($tax8Total));
  $tax8AmountText = hPdfValue(number_format($tax8Amount));
  $shopNoteHtml = hPdfValue($orderDetail['note'] ?? '');

  $taxDetailHtml = '';
  if ($tax10Total > 0) {
    $taxDetailHtml .= '<p>10%対象:<span> ¥' . $tax10TotalText . '</span> 内消費税<span>¥' . $tax10AmountText . '</span></p>';
  }
  if ($tax8Total > 0) {
    $taxDetailHtml .= '<p>8%対象:<span> ¥' . $tax8TotalText . '</span> 内消費税<span>¥' . $tax8AmountText . '</span></p>';
  }

  return <<<HTML
<div id="pdfTarget" class="pdf-target area-invoice">
  <h2>お買上げ明細書（納品書）</h2>
  <article class="block-head">
    <div class="box-left">
      <span class="post-number">{$toPostalCodeHtml}</span>
      <address><span>{$toAddressHtml}</span></address>
      <h3>{$toNameHtml}</h3>
      <span>TEL: {$toTelHtml}</span>
    </div>
    <div class="box-right">
      <ul>
        <li>
          <h4>販売元</h4>
          <h5>黒川温泉観光協会</h5>
          <address>
            <span>〒869-2402</span>
            <span>熊本県阿蘇郡南小国町満願寺黒川温泉6595-3 べっちん館</span>
          </address>
          <span>TEL: 0967-48-8130</span>
        </li>
        <li>
          <h4>発送元</h4>
          <h5>{$shopName}</h5>
          <address>
            <span>〒{$shopPostalCode}</span>
            <span>{$shopPrefName}{$shopAddr01}{$shopAddr02}</span>
          </address>
          <span>TEL: {$shopTel}</span>
        </li>
      </ul>
    </div>
  </article>
  <p class="announce">このたびはお買上げいただきありがとうございます。<br>品質には万全を期してお届けさせていただきます。<br>ご確認くださいませ。</p>
  <article class="block-details">
    <div class="box-price">
      <p>総合計金額</p>
      <span>{$paymentTotalText}</span>
    </div>
    <h3>お買い上げ明細</h3>
    <dl>
      <div>
        <dt>ご注文日</dt>
        <dd>{$orderDateHtml}</dd>
      </div>
      <div>
        <dt>注文番号</dt>
        <dd><span>{$orderNoHtml}</span></dd>
      </div>
    </dl>
    <ul>
      <li>
        <div>商品名</div>
        <div>税率</div>
        <div>数量</div>
        <div class="item-price">単価（税込）</div>
        <div class="item-price" style="text-align: center">金額（税込み）</div>
      </li>
      {$itemRowsHtml}
    </ul>
  </article>
  <article class="block-bottom">
    <dl>
      <div>
        <dt>商品合計</dt>
        <dd>¥{$itemSubtotalTotalText}</dd>
      </div>
      <div>
        <dt>送料</dt>
        <dd>¥{$deliveryFeeText}</dd>
      </div>
      <div>
        <dt><em>合計</em></dt>
        <dd><em>¥{$paymentTotalText}</em></dd>
      </div>
    </dl>
    {$taxDetailHtml}
  </article>
  <div class="item-note">
    <h4>＜備考＞</h4>
    <p>{$shopNoteHtml}</p>
  </div>
</div>

HTML;
}
/**
 * APIレスポンスをJSONで返却して終了
 */
function outputDeliverySlipPdfJsonAndExit(string $status, string $msg = '', string $html = '', string $shopId = '', string $orderId = ''): void
{
  echo json_encode(
    [
      'status' => $status,
      'msg' => $msg,
      'html' => $html,
      'shop_id' => $shopId,
      'order_id' => $orderId,
    ],
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
  );
  exit;
}
