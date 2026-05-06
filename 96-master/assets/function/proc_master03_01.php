<?php
/*
 * [96-master/assets/function/proc_master03_01.php]
 *  - 管理画面 -
 *  受注一覧：検索/ページング
 *
 * [初版]
 *  2026.5.4
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ EC-CUBE API 共通クライアント ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/api/eccube/eccube_api.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#店舗情報（EC関連）
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';
#受注情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
  'tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
);
/*
 * GraphQL文字列エスケープ
 */
function buildOrderStatusGraphqlString($value)
{
  $encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    throw new Exception('GraphQL文字列変換に失敗しました。');
  }
  return $encoded;
}
/*
 * ステータス名取得
 */
function getOrderStatusNameForList($statusId, $orderStatusList)
{
  foreach ($orderStatusList as $status) {
    if ((string)$status['id'] === (string)$statusId) {
      return preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
    }
  }
  return '';
}
/*
 * EC-CUBE受注ステータス更新
 */
function updateEccubeOrderStatusForList($eccubeOrderId, $statusId, $shippedAt = null)
{
  $args = [
    'eccube_order_id: ' . (int)$eccubeOrderId,
    'status_id: ' . (int)$statusId,
  ];
  if ((int)$statusId === 5 && $shippedAt !== null && trim((string)$shippedAt) !== '') {
    $args[] = 'shipped_at: ' . buildOrderStatusGraphqlString($shippedAt);
  }
  $query = "mutation {\n  UpdateOrderStatusMutation(\n    " . implode("\n    ", $args) . "\n  ) {\n    success\n    eccube_order_id\n    status_id\n    status_name\n    shipped_at\n    error\n  }\n}";
  $result = eccube_api_call($query);
  $payload = $result['UpdateOrderStatusMutation'] ?? null;
  return (is_array($payload) && (($payload['success'] ?? false) === true));
}

#=============#
# POSTチェック
#-------------#
#セッションキー
$noUpDateKey = isset($_POST['noUpDateKey']) ? $_POST['noUpDateKey'] : '';
#noUpDateKey は「画面インスタンス識別用」。
#画面遷移/マルチタブ等でキーが更新されている場合があるため、
#POSTキーが無効ならセッション側の現行キーへフォールバックする。
$currentNoUpDateKey = isset($_SESSION['sKey']) ? (string)$_SESSION['sKey'] : '';
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
  if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
    $noUpDateKey = $currentNoUpDateKey;
  } else {
    #AJAX向け：JSONでエラー返却（fetch側で画面リロード誘導）
    header('Content-Type: application/json; charset=UTF-8');
    $makeTag['status'] = 'error';
    $makeTag['title'] = 'セッションエラー';
    $makeTag['msg'] = 'セッションが切れました。ページを再読み込みしてください。';
    $makeTag['noUpDateKey'] = $currentNoUpDateKey;
    echo json_encode($makeTag);
    exit;
  }
}
#応答には常に現行のキーを含め、フロント側のhiddenを更新できるようにする
$makeTag['noUpDateKey'] = ($currentNoUpDateKey !== '' ? $currentNoUpDateKey : $noUpDateKey);

#-------------#
#検索・ステータス変更
$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$allowedListStatusIds = ['1', '4', '5'];
if ($action === 'updateStatus') {
  header('Content-Type: application/json; charset=UTF-8');
  $orderIdRaw = isset($_POST['orderId']) ? trim((string)$_POST['orderId']) : '';
  $statusIdRaw = isset($_POST['statusId']) ? trim((string)$_POST['statusId']) : '';
  if ($orderIdRaw === '' || ctype_digit($orderIdRaw) === false || (int)$orderIdRaw < 1 || in_array((int)$statusIdRaw, [1, 4, 5], true) === false) {
    $makeTag['status'] = 'error';
    $makeTag['title'] = '更新エラー';
    $makeTag['msg'] = 'ステータス更新に必要な情報が不正です。';
    echo json_encode($makeTag);
    exit;
  }
  $orderData = getShopOrderForStatusUpdate((int)$orderIdRaw);
  if (empty($orderData) || (int)($orderData['eccube_order_id'] ?? 0) < 1) {
    $makeTag['status'] = 'error';
    $makeTag['title'] = '更新エラー';
    $makeTag['msg'] = '対象の受注情報を取得できませんでした。';
    echo json_encode($makeTag);
    exit;
  }
  $statusId = (int)$statusIdRaw;
  $statusName = getOrderStatusNameForList($statusId, $orderStatusList);
  $shippedAt = ($statusId === 5) ? date('Y-m-d H:i:s') : null;
  try {
    if (updateEccubeOrderStatusForList((int)$orderData['eccube_order_id'], $statusId, $shippedAt) !== true) {
      throw new Exception('EC-CUBEステータス更新に失敗しました。');
    }
    if (updateShopOrderStatusAfterEccube((int)$orderData['order_id'], $statusId, $statusName, $shippedAt) !== true) {
      throw new Exception('外部DBステータス更新に失敗しました。');
    }
    $makeTag['status'] = 'success';
    $makeTag['title'] = 'ステータス更新';
    $makeTag['msg'] = '対応状況を更新しました。';
    $makeTag['status_name'] = $statusName;
    $makeTag['changed_at'] = date('Y/m/d');
  } catch (Exception $e) {
    $makeTag['status'] = 'error';
    $makeTag['title'] = '更新エラー';
    $makeTag['msg'] = 'EC-CUBEへのステータス更新に失敗しました。';
  }
  echo json_encode($makeTag);
  exit;
}
#検索店舗ID
$searchShopId = isset($_POST['searchShopId']) ? (string)$_POST['searchShopId'] : '';
#ステータス
$searchStatus = isset($_POST['searchStatus']) ? (string)$_POST['searchStatus'] : '';
if ($searchStatus !== '' && in_array($searchStatus, $allowedListStatusIds, true) === false) {
  $searchStatus = '';
}
#注文日
$searchOrderDateFrom = isset($_POST['searchOrderDateFrom']) ? trim((string)$_POST['searchOrderDateFrom']) : '';
$searchOrderDateTo = isset($_POST['searchOrderDateTo']) ? trim((string)$_POST['searchOrderDateTo']) : '';
#注文番号
$searchOrderNo = isset($_POST['searchOrderNo']) ? trim((string)$_POST['searchOrderNo']) : '';
#注文者名
$searchOrdererName = isset($_POST['searchOrdererName']) ? trim((string)$_POST['searchOrdererName']) : '';
#メールアドレス
$searchOrdererEmail = isset($_POST['searchOrdererEmail']) ? trim((string)$_POST['searchOrdererEmail']) : '';
#電話番号
$searchOrdererTel = isset($_POST['searchOrdererTel']) ? trim((string)$_POST['searchOrdererTel']) : '';
#-------------#
#表示件数
$displayNumber = $initialDisplayNumber;
#ページ番号
$pageNumber = isset($_POST['pageNumber']) ? (int)$_POST['pageNumber'] : 1;
#-------------#
#前回の状態維持
$searchConditionsSessionKey = 'searchConditions_master03_01';
$prevSearchConditions = isset($_SESSION[$searchConditionsSessionKey]) && is_array($_SESSION[$searchConditionsSessionKey]) ? $_SESSION[$searchConditionsSessionKey] : null;
if (!is_array($prevSearchConditions)) {
  $prevSearchConditions = [
    'searchShopId' => $searchShopId,
    'searchStatus' => $searchStatus,
    'orderDateFrom' => $searchOrderDateFrom,
    'orderDateTo' => $searchOrderDateTo,
    'orderNo' => $searchOrderNo,
    'ordererName' => $searchOrdererName,
    'ordererEmail' => $searchOrdererEmail,
    'ordererTel' => $searchOrdererTel,
    'displayNumber' => $initialDisplayNumber,
    'pageNumber' => 1,
  ];
  $_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
}
$requiredKeys = ['searchStatus', 'orderDateFrom', 'orderDateTo', 'orderNo', 'ordererName', 'ordererEmail', 'ordererTel', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $prevSearchConditions)) {
    $prevSearchConditions = [
      'searchShopId' => $searchShopId,
      'searchStatus' => $searchStatus,
      'orderDateFrom' => $searchOrderDateFrom,
      'orderDateTo' => $searchOrderDateTo,
      'orderNo' => $searchOrderNo,
      'ordererName' => $searchOrdererName,
      'ordererEmail' => $searchOrdererEmail,
      'ordererTel' => $searchOrdererTel,
      'displayNumber' => isset($prevSearchConditions['displayNumber']) ? (int)$prevSearchConditions['displayNumber'] : $initialDisplayNumber,
      'pageNumber' => isset($prevSearchConditions['pageNumber']) ? (int)$prevSearchConditions['pageNumber'] : 1,
    ];
    $_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
    break;
  }
}
#-------------#
#検索条件配列生成してSESSIONに保存
switch ($action) {
  #条件で検索
  case 'search':
    $searchConditions = [
      'shopId' => isset($_POST['searchShopId']) ? trim((string)$_POST['searchShopId']) : '',
      'orderNo' => isset($_POST['searchOrderNo']) ? trim((string)$_POST['searchOrderNo']) : '',
      'ordererName' => isset($_POST['searchOrdererName']) ? trim((string)$_POST['searchOrdererName']) : '',
      'ordererEmail' => isset($_POST['searchOrdererEmail']) ? trim((string)$_POST['searchOrdererEmail']) : '',
      'ordererTel' => isset($_POST['searchOrdererTel']) ? trim((string)$_POST['searchOrdererTel']) : '',
      'statusId' => $searchStatus,
      'orderDateFrom' => isset($_POST['searchOrderDateFrom']) ? trim((string)$_POST['searchOrderDateFrom']) : '',
      'orderDateTo' => isset($_POST['searchOrderDateTo']) ? trim((string)$_POST['searchOrderDateTo']) : '',
      'displayNumber' => $displayNumber,
      'pageNumber' => $pageNumber,
    ];
    break;
  #リセット／デフォルト
  case 'reset':
  default:
    $searchConditions = [
      'shopId' => '',
      'orderNo' => '',
      'ordererName' => '',
      'ordererEmail' => '',
      'ordererTel' => '',
      'statusId' => '',
      'orderDateFrom' => '',
      'orderDateTo' => '',
      'displayNumber' => $displayNumber,
      'pageNumber' => 1,
    ];
    break;
}

#-------------#
#受注数取得：検索結果
$totalItems = countShopOrderList($searchConditions);
$totalPages = (int)ceil($totalItems / $displayNumber);
if ($totalPages < 1) {
  $totalPages = 1;
}
#総件数（ページャー用）
if ($pageNumber < 1) {
  $pageNumber = 1;
} elseif ($pageNumber > $totalPages) {
  $pageNumber = $totalPages;
}
$searchConditions['pageNumber'] = $pageNumber;
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
#=========#
# 受注一覧
#---------#
$orderList = searchShopOrderList($searchConditions, $pageNumber, $displayNumber);
$orderIds = array_map(function ($order) {
  return (int)($order['order_id'] ?? 0);
}, $orderList);
$orderItemsByOrderId = getShopOrderItemsByOrderIds($orderIds);

#===============#
# 日付フォーマット
#---------------#
function formatMasterOrderDate($value)
{
  $value = trim((string)$value);
  if ($value === '') {
    return '-';
  }
  $timestamp = strtotime($value);
  if ($timestamp === false) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  }
  #return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars(date('H:i', $timestamp), ENT_QUOTES, 'UTF-8') . '</i>';
  return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8');
}

#***** タグ生成開始 *****#
$makeTag['tag'] .= <<<HTML
            <ul>
              <li>
                <div>注文日・番号</div>
                <div>受注店舗・お客様情報</div>
                <div>商品</div>
                <div>個数</div>
                <div>小計</div>
                <div>進捗状況/変更日</div>
              </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($orderList)) {
  $zIndexNo = count($orderList);
  foreach ($orderList as $orderKey => $order) {
    $orderId = htmlspecialchars((string)($order['order_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $eccubeOrderId = htmlspecialchars((string)($order['eccube_order_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $orderNo = htmlspecialchars((string)($order['order_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererName = htmlspecialchars((string)($order['orderer_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererEmail = htmlspecialchars((string)($order['orderer_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererTel = htmlspecialchars((string)($order['orderer_tel'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererPostalCode = htmlspecialchars((string)($order['orderer_postal_code'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererPrefName = htmlspecialchars((string)($order['orderer_pref_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererAddr01 = htmlspecialchars((string)($order['orderer_addr01'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ordererAddr02 = htmlspecialchars((string)($order['orderer_addr02'] ?? ''), ENT_QUOTES, 'UTF-8');
    $shopName = htmlspecialchars((string)($order['shop_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $totalQuantity = htmlspecialchars(number_format((int)($order['total_quantity'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $itemSubtotal = htmlspecialchars(number_format((int)($order['item_subtotal'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $deliveryFeeTotal = htmlspecialchars(number_format((int)($order['delivery_fee_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $paymentTotal = htmlspecialchars(number_format((int)($order['payment_total'] ?? 0)), ENT_QUOTES, 'UTF-8');
    $orderedAt = formatMasterOrderDate($order['ordered_at'] ?? '');
    $updatedAt = formatMasterOrderDate($order['updated_at'] ?? '');
    $statusChangedAt = (array_key_exists('status_changed_at', $order)) ? formatMasterOrderDate($order['status_changed_at'] ?? '') : '-';
    $shippedAt = formatMasterOrderDate($order['shipped_at'] ?? '');
    $displayChangedAt = $statusChangedAt;
    $stockDeductedAt = formatMasterOrderDate($order['stock_deducted_at'] ?? '');
    $makeTag['tag'] .= <<<HTML
              <li>
                <div class="item-date">
                  <span>{$orderedAt}</span><i>{$orderNo}</i>
                </div>
                <div class="wrap-customer-info">
                  <div class="shop-name">
                    <span>{$shopName}</span>
                  </div>
                  <div class="inner-customer">
                    <div class="name">{$ordererName}</div>
                    <div class="address">
                      <span>{$ordererPostalCode} {$ordererPrefName}{$ordererAddr01}</span>
                      <span>{$ordererAddr02}</span>
                    </div>
                    <div class="tel">
                      <span>{$ordererTel}</span>
                    </div>
                    <div class="mail">
                      <a href="mailto:{$ordererEmail}">{$ordererEmail}</a>
                    </div>
                  </div>
                </div>
                <div class="wrap-goods">
                  <ul>

HTML;
    $orderItems = $orderItemsByOrderId[(int)($order['order_id'] ?? 0)] ?? [];
    if (!empty($orderItems)) {
      foreach ($orderItems as $orderItem) {
        $itemProductName = htmlspecialchars((string)($orderItem['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $itemQuantity = htmlspecialchars(number_format((int)($orderItem['quantity'] ?? 0)), ENT_QUOTES, 'UTF-8');
        $itemTaxRate = isset($orderItem['tax_rate']) ? (int)$orderItem['tax_rate'] : 10;
        if ($itemTaxRate <= 0) {
          $itemTaxRate = 10;
        }
        $itemSubtotalIncludingTax = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($itemTaxRate / 100)));
        $itemSubtotal = htmlspecialchars(number_format($itemSubtotalIncludingTax), ENT_QUOTES, 'UTF-8');
        $makeTag['tag'] .= <<<HTML
                    <li>
                      <div class="goods-name">{$itemProductName}</div>
                      <div class="goods-pieces">{$itemQuantity}</div>
                      <div class="goods-price"><span>{$itemSubtotal}</span></div>
                    </li>

HTML;
      }
    } else {
      $makeTag['tag'] .= <<<HTML
                    <li>
                      <div class="goods-name">購入商品情報なし</div>
                      <div class="goods-pieces">0</div>
                      <div class="goods-price"><span>0</span></div>
                    </li>

HTML;
    }
    $makeTag['tag'] .= <<<HTML
                  </ul>
                  <div class="item-shipping">
                    <div class="title">送料</div>
                    <div class="shipping-price"><span>{$deliveryFeeTotal}</span></div>
                  </div>
                  <div class="item-price">
                    <span>{$paymentTotal}</span>
                  </div>
                </div>

HTML;
    $targetOrderStatusId = (string)$order['eccube_order_status_id'];
    $targetOrderStatusName = getOrderStatusNameForList($targetOrderStatusId, $orderStatusList);
    if ($targetOrderStatusName === '') {
      $targetOrderStatusName = (string)($order['eccube_order_status_name'] ?? '-');
    }
    $targetOrderStatusIdHtml = htmlspecialchars($targetOrderStatusId, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusNameHtml = htmlspecialchars($targetOrderStatusName, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusClass = ($targetOrderStatusId !== '') ? ' is-selected' : '';
    $canChangeStatus = in_array((int)$targetOrderStatusId, [1, 4, 5], true);
    $makeTag['tag'] .= <<<HTML
                <div class="wrap-status">
                  <div class="apply-status{$targetOrderStatusClass}" data-selectbox data-order-id="{$orderId}" data-current-status="{$targetOrderStatusIdHtml}">

HTML;
    if ($canChangeStatus === false) {
      $makeTag['tag'] .= <<<HTML
                    <span class="selectbox__value">{$targetOrderStatusNameHtml}</span>
                  </div>

HTML;
    } else {
      #受注受付ステータスが選択されている場合
      #Liのz-index設定
      $zIndexStyle = 'style="z-index:' . ($zIndexNo - $orderKey) . ';"';
      $makeTag['tag'] .= <<<HTML
                    <button type="button" class="selectbox__head" aria-expanded="false">
                      <input type="hidden" name="List01Status" value="{$targetOrderStatusIdHtml}" data-selectbox-hidden>
                      <span class="selectbox__value" data-selectbox-value>{$targetOrderStatusNameHtml}</span>
                      <i></i>
                    </button>
                    <div class="list-wrapper">
                      <ul class="selectbox__panel">


HTML;
      if (!empty($orderStatusList)) {
        foreach ($orderStatusList as $status) {
          $statusId = (string)$status['id'];
          if (in_array($statusId, $allowedListStatusIds, true) === false) {
            continue;
          }
          $statusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
          #checked判定
          $checked = ($statusId === $targetOrderStatusId) ? ' checked' : '';
          #ステータスごとにクラス付与
          $statusClass = '';
          switch ($statusId) {
            case '1':
              $statusClass = 'status-registered';
              break;
            case '4':
              $statusClass = 'status-preparing';
              break;
            case '5':
              $statusClass = 'status-shipped';
              break;
            case '99':
              $statusClass = 'status-completed';
              break;
          }
          $makeTag['tag'] .= <<<HTML
                        <!-- NOTE  インラインでz-indexを付与 -->
                        <li {$zIndexStyle}>
                          <input type="radio" name="orderStatus{$orderKey}" value="{$statusId}" id="list{$orderKey}-{$statusId}" data-order-status-radio {$checked}>
                          <label for="list{$orderKey}-{$statusId}" class="{$statusClass}">{$statusName}</label>
                        </li>

HTML;
        }
      }
      $makeTag['tag'] .= <<<HTML
                      </ul>
                    </div>
                  </div>

HTML;
    }
    if ($displayChangedAt !== '-') {
      $makeTag['tag'] .= <<<HTML
                  <span class="date">{$displayChangedAt}</span>

HTML;
    }
    $makeTag['tag'] .= <<<HTML
                </div>
                <div class="box-note">
                  <span>メモ</span>
                  <textarea></textarea>
                </div>
              </li>

HTML;
  }
} else {
  $makeTag['tag'] .= <<<HTML
              <li class="no-data" style="display:flex;justify-content:center;align-items:center;padding:2em 0;">
                <div>該当するデータが存在しません。</div>
              </li>

HTML;
}
$makeTag['tag'] .= <<<HTML
            </ul>

HTML;
$makeTag['status'] = 'success';
$makeTag['total_items'] = $totalItems;
$makeTag['total_pages'] = $totalPages;
$makeTag['page_number'] = $pageNumber;
$makeTag['pager'] = makePagerBoxTag((int)$pageNumber, (int)$totalPages, $pagerDisplayMax, 'movePage');
$makeTag['noUpDateKey'] = $noUpDateKey;

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
