<?php
/*
 * [96-master/assets/function/proc_master03_06_01.php]
 *  - 管理画面 -
 *  集計店舗詳細：検索／月移動
 *
 * [初版]
 *  2026.5.9
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#受注情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_orders.php';

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
  'tag' => '',
  'pager' => '',
  'summary_tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
  'noUpDateKey' => '',
  'target_month_label' => '',
  'total_items' => 0,
  'total_pages' => 0,
  'page_number' => 0,
);

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
$action = isset($_POST['action']) ? $_POST['action'] : '';
#-------------#
#店舗ID
$shopId = isset($_POST['shopId']) ? (string)$_POST['shopId'] : '';
if (preg_match('/^[1-9][0-9]*$/', $shopId) !== 1) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'エラー';
  $makeTag['msg'] = '店舗IDが不正です。';
  echo json_encode($makeTag);
  exit;
}
$shopData = getShops_FindById($shopId);
if ($shopData === null) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'エラー';
  $makeTag['msg'] = '店舗情報が見つかりません。';
  echo json_encode($makeTag);
  exit;
}
#-------------#
#検索年
$searchYear = isset($_POST['searchYear']) ? $_POST['searchYear'] : '';
#検索月
$searchMonth = isset($_POST['searchMonth']) ? $_POST['searchMonth'] : '';
#-------------#
#表示件数
$displayNumber = isset($_POST['displayNumber']) ? (int)$_POST['displayNumber'] : (int)$initialDisplayNumber;
if (in_array($displayNumber, (array)$displayNumberList, true) === false) {
  $displayNumber = (int)$initialDisplayNumber;
}
#ページ番号
$pageNumber = isset($_POST['pageNumber']) ? (int)$_POST['pageNumber'] : 1;
if ($pageNumber < 1) {
  $pageNumber = 1;
}

#-------------#
# セッション整合性チェック（保険的修復処理）
# ※ここでの修復結果は直後のswitch文の出力には影響しない。
#   switch文では $action に応じて $searchConditions を新たに構築し、
#   SESSIONを上書きするため、このブロックはあくまで
#   「セッションが壊れていた場合の応急処置」として機能する。
#   運用上はセッションキーが常に揃う前提だが、
#   予期しない状態（タブ複製・セッション部分消失等）への備え。
$searchConditionsSessionKey = 'searchConditions_master03_06_01';
$startYear = date('Y', time());
$startMonth = date('m', time());
$prevSearchConditions = isset($_SESSION[$searchConditionsSessionKey]) && is_array($_SESSION[$searchConditionsSessionKey]) ? $_SESSION[$searchConditionsSessionKey] : null;
if (!is_array($prevSearchConditions)) {
  $prevSearchConditions = [
    'searchYear' => $searchYear,
    'searchMonth' => $searchMonth,
    'displayNumber' => $initialDisplayNumber,
    'pageNumber' => 1,
  ];
  $_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
}
$requiredKeys = ['searchYear', 'searchMonth', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
  if (!array_key_exists($requiredKey, $prevSearchConditions)) {
    $prevSearchConditions = [
      'searchYear' => isset($prevSearchConditions['searchYear']) ? (int)$prevSearchConditions['searchYear'] : '',
      'searchMonth' => isset($prevSearchConditions['searchMonth']) ? (int)$prevSearchConditions['searchMonth'] : '',
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
  case 'search': {
      $searchConditions = [
        'searchYear' => $searchYear,
        'searchMonth' => $searchMonth,
        'displayNumber' => $displayNumber,
        'pageNumber' => $pageNumber,
      ];
    }
    break;
  #デフォルト：全てクリア
  default: {
      $searchConditions = [
        'searchYear' => date('Y', time()),
        'searchMonth' => date('m', time()),
        'displayNumber' => $initialDisplayNumber,
        'pageNumber' => 1,
      ];
    }
    break;
}
#SESSIONに保存
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
$searchConditions = $_SESSION[$searchConditionsSessionKey];
if (preg_match('/^[0-9]{4}$/', (string)$searchConditions['searchYear']) === 1) {
  $validatedYear = (int)$searchConditions['searchYear'];
  if ($validatedYear < (int)$startAggregateYear || $validatedYear > (int)$startYear) {
    $validatedYear = (int)$startYear;
  }
} else {
  $validatedYear = (int)$startYear;
}
if (preg_match('/^(?:[1-9]|1[0-2])$/', (string)$searchConditions['searchMonth']) === 1) {
  $validatedMonth = (int)$searchConditions['searchMonth'];
} else {
  $validatedMonth = (int)$startMonth;
}
$searchConditions['searchYear'] = $validatedYear;
$searchConditions['searchMonth'] = $validatedMonth;
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
#対象年月
$targetYear = (int)$searchConditions['searchYear'];
$targetMonth = (int)$searchConditions['searchMonth'];

#==================#
# 店舗ごとの集計取得
#------------------#
#検索条件があれば適用して店舗集計一覧を取得
$shopsOrderData = getShopMonthlyOrderSummaryByShopId($shopId, $targetYear, $targetMonth);

#=============#
# 注文一覧取得
#-------------#
#月内の注文総件数
$shopMonthlyOrderCount = countShopMonthlyOrdersByShopId((int)$shopId, (int)$targetYear, (int)$targetMonth);
$totalPages = (int)ceil((int)$shopMonthlyOrderCount / (int)$displayNumber);
if ($totalPages < 1) {
  $totalPages = 1;
}
if ($pageNumber > $totalPages) {
  $pageNumber = $totalPages;
}
$offset = ($pageNumber - 1) * $displayNumber;
#月内の注文一覧
$shopMonthlyOrders = searchShopMonthlyOrdersByShopId((int)$shopId, (int)$targetYear, (int)$targetMonth, (int)$displayNumber, (int)$offset);
#注文ID配列を作成
$shopOrderIds = array();
if (is_array($shopMonthlyOrders) && count($shopMonthlyOrders) > 0) {
  foreach ($shopMonthlyOrders as $shopMonthlyOrder) {
    $orderId = isset($shopMonthlyOrder['order_id']) ? (int)$shopMonthlyOrder['order_id'] : 0;
    if ($orderId > 0) {
      $shopOrderIds[] = $orderId;
    }
  }
}
#注文商品の取得と注文IDごとの配列化
$shopOrderItemsByOrderId = array();
if (count($shopOrderIds) > 0) {
  $shopOrderItemsByOrderId = getShopOrderItemsByOrderIds($shopOrderIds);
}

#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#===============#
# 表示用helper
#---------------#
if (!function_exists('hOrderDetail')) {
  function hOrderDetail($value)
  {
    $value = trim((string)$value);
    return htmlspecialchars(($value === '') ? '-' : $value, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('formatOrderDetailMoney')) {
  function formatOrderDetailMoney($value)
  {
    return htmlspecialchars(number_format((int)$value), ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('formatOrderDate')) {
  function formatOrderDate($value)
  {
    $value = trim((string)$value);
    if ($value === '') {
      return '-';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars(date('Y/m/d', $timestamp), ENT_QUOTES, 'UTF-8');
  }
}
$rebateRateLabelHtml = htmlspecialchars((string)$rebateRateLabel, ENT_QUOTES, 'UTF-8');
$summaryOrderCount = (int)($shopsOrderData['order_count'] ?? 0);
$summarySalesTotal = (int)($shopsOrderData['sales_total'] ?? 0);
$summaryProductTotal = (int)($shopsOrderData['product_total'] ?? 0);
$summaryRebateAmount = (int)round($summaryProductTotal * (float)$rebateRate);
$summaryOrderCountHtml = htmlspecialchars(number_format($summaryOrderCount), ENT_QUOTES, 'UTF-8');
$summarySalesTotalHtml = formatOrderDetailMoney($summarySalesTotal);
$summaryProductTotalHtml = formatOrderDetailMoney($summaryProductTotal);
$summaryRebateAmountHtml = formatOrderDetailMoney($summaryRebateAmount);

#***** タグ生成開始 *****#
$makeTag['tag'] .= <<<HTML
          <ul id="setMasterShopsOrder">
            <li>
              <div>注文日・番号</div>
              <div>お客様情報</div>
              <div>商品</div>
              <div>個数</div>
              <div>小計</div>
              <div>進捗状況/変更日</div>
            </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopMonthlyOrders)) {
  $zIndexNo = count($shopMonthlyOrders);
  foreach ($shopMonthlyOrders as $orderIndex => $orderData) {
    $orderId = (int)($orderData['order_id'] ?? 0);
    $orderDate = formatOrderDate($orderData['ordered_at'] ?? '');
    $orderNo = hOrderDetail($orderData['order_no'] ?? '');
    $orderName = hOrderDetail($orderData['orderer_name'] ?? '');
    $orderPostalCode = hOrderDetail($orderData['orderer_postal_code'] ?? '');
    $orderAddress01 = hOrderDetail(($orderData['orderer_pref_name'] ?? '') . ($orderData['orderer_addr01'] ?? ''));
    $orderAddress02 = hOrderDetail($orderData['orderer_addr02'] ?? '');
    $orderTel = hOrderDetail($orderData['orderer_tel'] ?? '');
    $orderEmail = hOrderDetail($orderData['orderer_email'] ?? '');
    $deliveryFee = formatOrderDetailMoney($orderData['delivery_fee_total'] ?? 0);
    $returnedDeliveryStyle = ((int)($orderData['eccube_order_status_id'] ?? 0) === 9) ? ' style="text-decoration: line-through; color: #979797;"' : '';
    $paymentTotal = formatOrderDetailMoney($orderData['payment_total'] ?? 0);
    $goodsRowsHtml = '';
    $orderItems = isset($shopOrderItemsByOrderId[$orderId]) ? $shopOrderItemsByOrderId[$orderId] : array();
    foreach ($orderItems as $orderItem) {
      $goodsName = hOrderDetail($orderItem['product_name'] ?? '');
      $goodsQty = hOrderDetail($orderItem['quantity'] ?? 0);
      $itemTaxRate = isset($orderItem['tax_rate']) ? (int)$orderItem['tax_rate'] : 10;
      if ($itemTaxRate <= 0) {
        $itemTaxRate = 10;
      }
      $itemSubtotalIncludingTax = (int)round((int)($orderItem['subtotal'] ?? 0) * (1 + ($itemTaxRate / 100)));
      $goodsSubtotal = formatOrderDetailMoney($itemSubtotalIncludingTax);
      $currentItemStatus = trim((string)($orderItem['current_item_status'] ?? ''));
      $returnedItemStyle = ($currentItemStatus === 'returned_full') ? ' style="text-decoration: line-through; color: #979797;"' : '';
      $goodsRowsHtml .= <<<HTML
                  <li>
                    <div class="goods-name" {$returnedItemStyle}>{$goodsName}</div>
                    <div class="goods-pieces" {$returnedItemStyle}>{$goodsQty}</div>
                    <div class="goods-price" {$returnedItemStyle}><span>{$goodsSubtotal}</span></div>
                  </li>

HTML;
    }
    if ($goodsRowsHtml === '') {
      $goodsRowsHtml = <<<HTML
                  <li>
                    <div class="goods-name">商品情報なし</div>
                    <div class="goods-pieces">0</div>
                    <div class="goods-price"><span>0</span></div>
                  </li>

HTML;
    }
    $makeTag['tag'] .= <<<HTML
            <li>
              <div class="item-date">
                <span>{$orderDate}</span><i>{$orderNo}</i>
              </div>
              <div class="wrap-customer-info">
                <div class="inner-customer">
                  <div class="name">{$orderName}</div>
                  <div class="address">
                    <span>{$orderAddress01}</span>
                    <span>{$orderAddress02}</span>
                  </div>
                  <div class="tel">
                    <span>{$orderTel}</span>
                  </div>
                  <div class="mail">
                    <a href="mailto:{$orderEmail}">{$orderEmail}</a>
                  </div>
                </div>
              </div>
              <div class="wrap-goods">
                <ul>
                  {$goodsRowsHtml}
                </ul>
                <div class="item-shipping">
                  <div class="title">送料</div>
                  <div class="shipping-price" {$returnedDeliveryStyle}><span>{$deliveryFee}</span></div>
                </div>
                <div class="item-price">
                  <span>{$paymentTotal}</span>
                </div>
              </div>

HTML;
    #Liのz-index設定
    $zIndexStyle = 'style="z-index:' . ($zIndexNo - $orderIndex) . ';"';
    $targetOrderStatusId = (string)$orderData['eccube_order_status_id'];
    $targetOrderStatusName = '';
    if (!empty($orderStatusList)) {
      foreach ($orderStatusList as $status) {
        if ((string)$status['id'] === $targetOrderStatusId) {
          $targetOrderStatusName = preg_replace('/\r\n|\r|\n/', '', (string)$status['name']);
          break;
        }
      }
    }
    if ($targetOrderStatusName === '') {
      $targetOrderStatusName = (string)($orderData['eccube_order_status_name'] ?? '-');
    }
    $targetOrderStatusIdHtml = htmlspecialchars($targetOrderStatusId, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusNameHtml = htmlspecialchars($targetOrderStatusName, ENT_QUOTES, 'UTF-8');
    $targetOrderStatusClass = ($targetOrderStatusId !== '') ? ' is-selected' : '';
    $statusDate = formatOrderDate($orderData['status_changed_at'] ?? '');
    $makeTag['tag'] .= <<<HTML
              <div class="wrap-status">
                <div class="apply-status {$targetOrderStatusClass}" data-selectbox>
                  <button type="button" class="selectbox__head" aria-expanded="false">
                    <input type="hidden" name="List{$orderIndex}Status" value="{$targetOrderStatusIdHtml}" data-selectbox-hidden />
                    <span class="selectbox__value" data-selectbox-value>{$targetOrderStatusNameHtml}</span>
                    <i></i>
                  </button>
                  <div class="list-wrapper">
                    <ul class="selectbox__panel">

HTML;
    if (!empty($orderStatusList)) {
      foreach ($orderStatusList as $status) {
        $statusId = (string)$status['id'];
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
          case '9':
            $statusClass = 'status-completed';
            break;
        }
        $makeTag['tag'] .= <<<HTML
                      <!-- NOTE  インラインでz-indexを付与 -->
                      <li {$zIndexStyle}>
                        <input type="radio" name="orderStatus{$orderIndex}" value="{$statusId}" id="list{$orderIndex}-{$statusId}" data-order-status-radio {$checked}>
                        <label for="list{$orderIndex}-{$statusId}" class="{$statusClass}">{$statusName}</label>
                      </li>

HTML;
      }
    }
    $makeTag['tag'] .= <<<HTML
                    </ul>
                  </div>
                </div>
                <span class="date">{$statusDate}</span>
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
$makeTag['summary_tag'] = <<<HTML
<article id="setShopMonthlySummary" class="block-total-price">
  <h3>合計</h3>
  <ul>
    <li>
      <div></div>
      <div>注文件数</div>
      <div>売上合計</div>
      <div>商品合計</div>
      <div>協会R({$rebateRateLabelHtml})</div>
    </li>
    <li>
      <div class="item-name">
        <span><em>合計</em></span>
      </div>
      <div class="item-count">
        <span><em>{$summaryOrderCountHtml}</em></span>
      </div>
      <div class="item-price">
        <span><em>{$summarySalesTotalHtml}</em></span>
      </div>
      <div class="item-price">
        <span><em>{$summaryProductTotalHtml}</em></span>
      </div>
      <div class="item-rebate">
        <span><em>{$summaryRebateAmountHtml}</em></span>
      </div>
    </li>
  </ul>
  <p>※協会Rは商品合計をもとに算出しており、個別の合計と誤差が生じる場合があります。</p>
</article>
HTML;
$makeTag['status'] = 'success';
$makeTag['target_month_label'] = (int)$targetYear . '年' . (int)$targetMonth . '月';
$makeTag['total_items'] = (int)$shopMonthlyOrderCount;
$makeTag['total_pages'] = (int)$totalPages;
$makeTag['page_number'] = (int)$pageNumber;
$makeTag['pager'] = makePagerBoxTag((int)$pageNumber, (int)$totalPages, $pagerDisplayMax, 'movePage');
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
