<?php
/*
 * [96-master/assets/function/proc_master03_06.php]
 *  - 管理画面 -
 *  集計店舗一覧：検索／月移動
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
	'status' => '',
	'title' => '',
	'msg' => '',
	'target_month_label' => '',
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
#検索年
$searchYear = isset($_POST['searchYear']) ? $_POST['searchYear'] : '';
#検索月
$searchMonth = isset($_POST['searchMonth']) ? $_POST['searchMonth'] : '';
#-------------#

#-------------#
# セッション整合性チェック（保険的修復処理）
# ※ここでの修復結果は直後のswitch文の出力には影響しない。
#   switch文では $action に応じて $searchConditions を新たに構築し、
#   SESSIONを上書きするため、このブロックはあくまで
#   「セッションが壊れていた場合の応急処置」として機能する。
#   運用上はセッションキーが常に揃う前提だが、
#   予期しない状態（タブ複製・セッション部分消失等）への備え。
$searchConditionsSessionKey = 'searchConditions_master03_06';
$startYear = date('Y', time());
$startMonth = date('m', time());
$prevSearchConditions = isset($_SESSION[$searchConditionsSessionKey]) && is_array($_SESSION[$searchConditionsSessionKey]) ? $_SESSION[$searchConditionsSessionKey] : null;
if (!is_array($prevSearchConditions)) {
	$prevSearchConditions = [
		'searchYear' => $searchYear,
		'searchMonth' => $searchMonth,
	];
	$_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
}
$requiredKeys = ['searchYear', 'searchMonth'];
foreach ($requiredKeys as $requiredKey) {
	if (!array_key_exists($requiredKey, $prevSearchConditions)) {
		$prevSearchConditions = [
			'searchYear' => isset($prevSearchConditions['searchYear']) ? (int)$prevSearchConditions['searchYear'] : '',
			'searchMonth' => isset($prevSearchConditions['searchMonth']) ? (int)$prevSearchConditions['searchMonth'] : '',
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
			];
		}
		break;
	#デフォルト：全てクリア
	default: {
			$searchConditions = [
				'searchYear' => date('Y', time()),
				'searchMonth' => date('m', time()),
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

#================#
# 店舗集計一覧取得
#----------------#
#検索条件があれば適用して店舗集計一覧を取得
if (is_array($searchConditions) && count($searchConditions) > 0) {
	$targetYear = isset($searchConditions['searchYear']) ? (int)$searchConditions['searchYear'] : (int)$startYear;
	$targetMonth = isset($searchConditions['searchMonth']) ? (int)$searchConditions['searchMonth'] : (int)$startMonth;
	$shopsOrderList = getMasterShopsOrderSummaryByMonth($targetYear, $targetMonth);
} else {
	$shopsOrderList = array();
}

#===============#
# 表示用helper
#---------------#
/**
 * 文字列をHTMLエスケープして返す
 *  値が空の場合は '-' を返す
 */
function hOrderDetail($value)
{
	$value = trim((string)$value);
	return htmlspecialchars(($value === '') ? '-' : $value, ENT_QUOTES, 'UTF-8');
}
/**
 * 金額を数値フォーマットしてHTMLエスケープして返す
 */
function formatOrderDetailMoney($value)
{
	return htmlspecialchars(number_format((int)$value), ENT_QUOTES, 'UTF-8');
}
$rebateRateLabelHtml = htmlspecialchars((string)$rebateRateLabel, ENT_QUOTES, 'UTF-8');
$makeTag['target_month_label'] = $targetYear . '年' . $targetMonth . '月';
$makeTag['status'] = 'success';

#***** タグ生成開始 *****#
$makeTag['tag'] .= <<<HTML
          <ul id="setMasterShopsOrder">
            <li>
              <div>ID</div>
              <div>施設名</div>
              <div>注文件数</div>
              <div>売上合計</div>
              <div>商品合計</div>
              <div>協会R({$rebateRateLabelHtml})</div>
            </li>

HTML;
#総注文件数
$sumOrderItems = 0;
#総売上合計
$sumTotalSales = 0;
#総商品売上合計
$sumItemSales = 0;
#協会R合計
$sumSalesRebate = 0;
if (!empty($shopsOrderList)) {
	foreach ($shopsOrderList as $shopOrder) {
		#店舗ID
		$shopId = htmlspecialchars($shopOrder['shop_id'], ENT_QUOTES, 'UTF-8');
		#店舗名
		$statusNameHtml = hOrderDetail($shopOrder['shop_name'] ?? '');
		#注文件数
		$orderItems = (int)($shopOrder['order_count'] ?? 0);
		$orderItemsHtml = htmlspecialchars(number_format($orderItems), ENT_QUOTES, 'UTF-8');
		#売上合計
		$totalSales = (int)($shopOrder['sales_total'] ?? 0);
		$totalSalesHtml = formatOrderDetailMoney($totalSales);
		#商品合計
		$itemSales = (int)($shopOrder['product_total'] ?? 0);
		$itemSalesHtml = formatOrderDetailMoney($itemSales);
		#協会R
		$salesRebate = (int)round($itemSales * $rebateRate);
		$salesRebateHtml = formatOrderDetailMoney($salesRebate);
		#--------------#
		#合計加算
		$sumOrderItems = $sumOrderItems + $orderItems;
		$sumTotalSales = $sumTotalSales + $totalSales;
		$sumItemSales = $sumItemSales + $itemSales;
		$sumSalesRebate = $sumSalesRebate + $salesRebate;
		$makeTag['tag'] .= <<<HTML
            <li>
              <div class="item-id">
                <span>{$shopId}</span>
              </div>
              <div class="item-name">
                <a href="./master03_06_01.php?shopId={$shopId}&searchYear={$searchConditions['searchYear']}&searchMonth={$searchConditions['searchMonth']}"></a>
                <span>{$statusNameHtml}</span>
              </div>
              <div class="item-count">
                <span>{$orderItemsHtml}</span>
              </div>
              <div class="item-price">
                <span>{$totalSalesHtml}</span>
              </div>
              <div class="item-price">
                <span>{$itemSalesHtml}</span>
              </div>
              <div class="item-price">
                <span>{$salesRebateHtml}</span>
              </div>
            </li>

HTML;
	}
} else {
	$makeTag['tag'] .= <<<HTML
            <li>
              <div class="item-id">
                <span></span>
              </div>
              <div class="item-name">
                <span>対象店舗なし</span>
              </div>
              <div class="item-count">
                <span>0</span>
              </div>
              <div class="item-price">
                <span>0</span>
              </div>
              <div class="item-price">
                <span>0</span>
              </div>
              <div class="item-price">
                <span>0</span>
              </div>
            </li>

HTML;
}
#表示用加工
$sumOrderItemsHtml = htmlspecialchars(number_format($sumOrderItems), ENT_QUOTES, 'UTF-8');
$sumTotalSalesHtml = formatOrderDetailMoney($sumTotalSales);
$sumItemSalesHtml = formatOrderDetailMoney($sumItemSales);
$sumSalesRebateHtml = formatOrderDetailMoney($sumSalesRebate);
$makeTag['tag'] .= <<<HTML
            <li>
              <div class="item-id"></div>
              <div class="item-name">
                <span><em>合計</em></span>
              </div>
              <div class="item-count">
                <span><em>{$sumOrderItemsHtml}</em></span>
              </div>
              <div class="item-price">
                <span><em>{$sumTotalSalesHtml}</em></span>
              </div>
              <div class="item-price">
                <span><em>{$sumItemSalesHtml}</em></span>
              </div>
              <div class="item-rebate">
                <span><em>{$sumSalesRebateHtml}</em></span>
              </div>
            </li>
          </ul>

HTML;
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
