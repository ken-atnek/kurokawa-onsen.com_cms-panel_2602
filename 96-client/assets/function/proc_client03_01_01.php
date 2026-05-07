<?php
/*
 * [96-client/assets/function/proc_client03_01_01.php]
 *  - 加盟店管理画面 -
 *  受注詳細
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
#***** EC-CUBE API共通クライアント *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/api/eccube/eccube_api.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
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
function buildOrderDetailGraphqlString($value)
{
	$encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($encoded === false) {
		throw new Exception('GraphQL文字列変換に失敗しました。');
	}
	return $encoded;
}
/*
 * EC-CUBE受注 注文者・配送先更新
 */
function updateEccubeOrderCustomerShipping($eccubeOrderId, $data)
{
	$args = [
		'eccube_order_id: ' . (int)$eccubeOrderId,
		'name01: ' . buildOrderDetailGraphqlString($data['name01']),
		'name02: ' . buildOrderDetailGraphqlString($data['name02']),
		'kana01: ' . buildOrderDetailGraphqlString($data['kana01']),
		'kana02: ' . buildOrderDetailGraphqlString($data['kana02']),
		'company_name: ' . buildOrderDetailGraphqlString($data['company_name']),
		'email: ' . buildOrderDetailGraphqlString($data['email']),
		'phone_number: ' . buildOrderDetailGraphqlString($data['phone_number']),
		'postal_code: ' . buildOrderDetailGraphqlString($data['postal_code']),
		'addr01: ' . buildOrderDetailGraphqlString($data['addr01']),
		'addr02: ' . buildOrderDetailGraphqlString($data['addr02']),
		'shipping_name01: ' . buildOrderDetailGraphqlString($data['shipping_name01']),
		'shipping_name02: ' . buildOrderDetailGraphqlString($data['shipping_name02']),
		'shipping_kana01: ' . buildOrderDetailGraphqlString($data['shipping_kana01']),
		'shipping_kana02: ' . buildOrderDetailGraphqlString($data['shipping_kana02']),
		'shipping_company_name: ' . buildOrderDetailGraphqlString($data['shipping_company_name']),
		'shipping_phone_number: ' . buildOrderDetailGraphqlString($data['shipping_phone_number']),
		'shipping_postal_code: ' . buildOrderDetailGraphqlString($data['shipping_postal_code']),
		'shipping_addr01: ' . buildOrderDetailGraphqlString($data['shipping_addr01']),
		'shipping_addr02: ' . buildOrderDetailGraphqlString($data['shipping_addr02']),
	];
	if (isset($data['pref_id']) && is_numeric($data['pref_id']) && (int)$data['pref_id'] > 0) {
		$args[] = 'pref_id: ' . (int)$data['pref_id'];
	}
	if (isset($data['shipping_pref_id']) && is_numeric($data['shipping_pref_id']) && (int)$data['shipping_pref_id'] > 0) {
		$args[] = 'shipping_pref_id: ' . (int)$data['shipping_pref_id'];
	}
	$query = "mutation {\n  UpdateOrderCustomerShippingMutation(\n    " . implode("\n    ", $args) . "\n  ) {\n    success\n    eccube_order_id\n    error\n  }\n}";
	$result = eccube_api_call($query);
	$payload = $result['UpdateOrderCustomerShippingMutation'] ?? null;
	return (is_array($payload) && (($payload['success'] ?? false) === true));
}

#=============#
# POSTチェック
#-------------#
#セッションキー（画面インスタンス識別）
$noUpDateKey = isset($_POST['noUpDateKey']) ? (string)$_POST['noUpDateKey'] : '';
$currentNoUpDateKey = isset($_SESSION['sKey']) ? (string)$_SESSION['sKey'] : '';
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
	if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
		$noUpDateKey = $currentNoUpDateKey;
	} else {
		header('Content-Type: application/json; charset=UTF-8');
		$makeTag['status'] = 'error';
		$makeTag['title'] = 'セッションエラー';
		$makeTag['msg'] = 'セッションが切れました。<br>ページを再読み込みしてください。';
		echo json_encode($makeTag);
		exit;
	}
}
#-------------#
#shopId取得
$shopId = $_SESSION['client_login']['shop_id'] ?? null;
if ($shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId <= 0) {
	header('Content-Type: application/json; charset=UTF-8');
	$makeTag['status'] = 'error';
	$makeTag['title'] = 'セッションエラー';
	$makeTag['msg'] = '店舗情報が取得できませんでした。<br>再ログインしてください。';
	echo json_encode($makeTag);
	exit;
}
$shopId = (int)$shopId;

#-------------#
#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : null;
#確認／修正／登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#受注ID
$orderId = isset($_POST['orderId']) ? (string)$_POST['orderId'] : '';
#-------------#
#注文者名
$userFirstName = isset($_POST['userFirstName']) ? (string)$_POST['userFirstName'] : '';
$userLastName = isset($_POST['userLastName']) ? (string)$_POST['userLastName'] : '';
#注文者名（カナ）
$userFirstNameKana = isset($_POST['userFirstNameKana']) ? (string)$_POST['userFirstNameKana'] : '';
$userLastNameKana = isset($_POST['userLastNameKana']) ? (string)$_POST['userLastNameKana'] : '';
#住所
$userPostalCode = isset($_POST['userPostalCode']) ? (string)$_POST['userPostalCode'] : '';
$userAddress01 = isset($_POST['userAddress01']) ? (string)$_POST['userAddress01'] : '';
$userAddress02 = isset($_POST['userAddress02']) ? (string)$_POST['userAddress02'] : '';
$userAddress03 = isset($_POST['userAddress03']) ? (string)$_POST['userAddress03'] : '';
#会社名
$userCompanyName = isset($_POST['userCompanyName']) ? (string)$_POST['userCompanyName'] : '';
#メールアドレス
$userEmail = isset($_POST['userEmail']) ? (string)$_POST['userEmail'] : '';
#電話番号
$userTel = isset($_POST['userTel']) ? (string)$_POST['userTel'] : '';
#お問い合わせ
$userInquiry = isset($_POST['userInquiry']) ? (string)$_POST['userInquiry'] : '';
#-------------#
#出荷先名
$shippingUserFirstName = isset($_POST['shippingUserFirstName']) ? (string)$_POST['shippingUserFirstName'] : '';
$shippingUserLastName = isset($_POST['shippingUserLastName']) ? (string)$_POST['shippingUserLastName'] : '';
#出荷先名（カナ）
$shippingUserFirstNameKana = isset($_POST['shippingUserFirstNameKana']) ? (string)$_POST['shippingUserFirstNameKana'] : '';
$shippingUserLastNameKana = isset($_POST['shippingUserLastNameKana']) ? (string)$_POST['shippingUserLastNameKana'] : '';
#出荷先住所
$shippingUserPostalCode = isset($_POST['shippingUserPostalCode']) ? (string)$_POST['shippingUserPostalCode'] : '';
$shippingUserAddress01 = isset($_POST['shippingUserAddress01']) ? (string)$_POST['shippingUserAddress01'] : '';
$shippingUserAddress02 = isset($_POST['shippingUserAddress02']) ? (string)$_POST['shippingUserAddress02'] : '';
$shippingUserAddress03 = isset($_POST['shippingUserAddress03']) ? (string)$_POST['shippingUserAddress03'] : '';
#出荷先会社名
$shippingUserCompanyName = isset($_POST['shippingUserCompanyName']) ? (string)$_POST['shippingUserCompanyName'] : '';
#出荷先電話番号
$shippingUserTel = isset($_POST['shippingUserTel']) ? (string)$_POST['shippingUserTel'] : '';
#お届け日
$shippingUserDeliveryDate = isset($_POST['shippingUserDeliveryDate']) ? (string)$_POST['shippingUserDeliveryDate'] : '';
#お届け時間帯
$shippingUserDeliveryTime = isset($_POST['shippingUserDeliveryTime']) ? (string)$_POST['shippingUserDeliveryTime'] : '';
#出荷用メモ
$shippingUserShippingMemo = isset($_POST['shippingUserShippingMemo']) ? (string)$_POST['shippingUserShippingMemo'] : '';

#***** タグ生成開始 *****#
switch ($action) {
	case 'saveOrderDetail': {
			$getPostValue = function ($key) {
				return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
			};
			$validationErrors = [];
			$saveOrderId = $getPostValue('orderId');
			if ($saveOrderId === '' || ctype_digit($saveOrderId) === false || (int)$saveOrderId < 1) {
				$validationErrors[] = '受注IDが不正です。';
			}
			$requiredFields = [
				'userFirstName' => '注文者 姓',
				'userLastName' => '注文者 名',
				'userEmail' => 'メールアドレス',
				'userTel' => '電話番号',
				'userPostalCode' => '注文者 郵便番号',
				'userAddress02' => '注文者 住所',
				'shippingUserFirstName' => '配送先 姓',
				'shippingUserLastName' => '配送先 名',
				'shippingUserTel' => '配送先 電話番号',
				'shippingUserPostalCode' => '配送先 郵便番号',
				'shippingUserAddress02' => '配送先 住所',
			];
			foreach ($requiredFields as $fieldName => $fieldLabel) {
				if ($getPostValue($fieldName) === '') {
					$validationErrors[] = $fieldLabel . 'は必須です。';
				}
			}
			$ordererPrefId = $getPostValue('ordererPrefId');
			$shippingPrefId = $getPostValue('shippingPrefId');
			if ($ordererPrefId !== '' && ctype_digit($ordererPrefId) === false) {
				$validationErrors[] = '注文者都道府県IDが不正です。';
			}
			if ($shippingPrefId !== '' && ctype_digit($shippingPrefId) === false) {
				$validationErrors[] = '配送先都道府県IDが不正です。';
			}
			$orderData = [];
			if (empty($validationErrors)) {
				$orderData = getShopOrderById((int)$saveOrderId, (int)$shopId);
				if (empty($orderData)) {
					$validationErrors[] = '対象の受注情報を取得できませんでした。';
				} elseif (empty($orderData['eccube_order_id']) || (int)$orderData['eccube_order_id'] < 1) {
					$validationErrors[] = 'EC-CUBE受注IDを取得できませんでした。';
				}
			}
			if (empty($validationErrors) && $ordererPrefId === '') {
				if ($getPostValue('userPostalCode') !== (string)($orderData['orderer_postal_code'] ?? '') || $getPostValue('userAddress02') !== (string)($orderData['orderer_addr01'] ?? '') || $getPostValue('userAddress03') !== (string)($orderData['orderer_addr02'] ?? '')) {
					$validationErrors[] = '注文者都道府県IDを取得できませんでした。郵便番号から住所を再取得してください。';
				}
			}
			if (empty($validationErrors) && $shippingPrefId === '') {
				if ($getPostValue('shippingUserPostalCode') !== (string)($orderData['shipping_postal_code'] ?? '') || $getPostValue('shippingUserAddress02') !== (string)($orderData['shipping_addr01'] ?? '') || $getPostValue('shippingUserAddress03') !== (string)($orderData['shipping_addr02'] ?? '')) {
					$validationErrors[] = '配送先都道府県IDを取得できませんでした。郵便番号から住所を再取得してください。';
				}
			}
			if (!empty($validationErrors)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = '入力エラー';
				$makeTag['msg'] = implode('<br>', $validationErrors);
				break;
			}
			$eccubeUpdateData = [
				'name01' => $getPostValue('userFirstName'),
				'name02' => $getPostValue('userLastName'),
				'kana01' => $getPostValue('userFirstNameKana'),
				'kana02' => $getPostValue('userLastNameKana'),
				'company_name' => $getPostValue('userCompanyName'),
				'email' => $getPostValue('userEmail'),
				'phone_number' => $getPostValue('userTel'),
				'postal_code' => $getPostValue('userPostalCode'),
				'pref_id' => $ordererPrefId,
				'addr01' => $getPostValue('userAddress02'),
				'addr02' => $getPostValue('userAddress03'),
				'shipping_name01' => $getPostValue('shippingUserFirstName'),
				'shipping_name02' => $getPostValue('shippingUserLastName'),
				'shipping_kana01' => $getPostValue('shippingUserFirstNameKana'),
				'shipping_kana02' => $getPostValue('shippingUserLastNameKana'),
				'shipping_company_name' => $getPostValue('shippingUserCompanyName'),
				'shipping_phone_number' => $getPostValue('shippingUserTel'),
				'shipping_postal_code' => $getPostValue('shippingUserPostalCode'),
				'shipping_pref_id' => $shippingPrefId,
				'shipping_addr01' => $getPostValue('shippingUserAddress02'),
				'shipping_addr02' => $getPostValue('shippingUserAddress03'),
			];
			try {
				if (updateEccubeOrderCustomerShipping((int)$orderData['eccube_order_id'], $eccubeUpdateData) !== true) {
					throw new Exception('EC-CUBE受注情報の更新に失敗しました。');
				}
				$normalizeDbString = function ($value) {
					$value = trim((string)$value);
					return ($value !== '') ? $value : null;
				};
				$ordererName01 = $normalizeDbString($getPostValue('userFirstName'));
				$ordererName02 = $normalizeDbString($getPostValue('userLastName'));
				$ordererName = trim((string)$ordererName01 . ' ' . (string)$ordererName02);
				$ordererName = ($ordererName !== '') ? $ordererName : null;
				$shippingName01 = $normalizeDbString($getPostValue('shippingUserFirstName'));
				$shippingName02 = $normalizeDbString($getPostValue('shippingUserLastName'));
				$shippingName = trim((string)$shippingName01 . ' ' . (string)$shippingName02);
				$shippingName = ($shippingName !== '') ? $shippingName : null;
				$ordererPrefIdValue = ($ordererPrefId !== '' && is_numeric($ordererPrefId) && (int)$ordererPrefId > 0) ? (int)$ordererPrefId : null;
				$shippingPrefIdValue = ($shippingPrefId !== '' && is_numeric($shippingPrefId) && (int)$shippingPrefId > 0) ? (int)$shippingPrefId : null;
				$ordererPrefName = $getPostValue('ordererPrefName') !== '' ? $getPostValue('ordererPrefName') : $getPostValue('userAddress01');
				$shippingPrefName = $getPostValue('shippingPrefName') !== '' ? $getPostValue('shippingPrefName') : $getPostValue('shippingUserAddress01');
				$dbFiledData = array();
				$dbFiledData['orderer_name'] = array(':orderer_name', $ordererName, ($ordererName === null) ? 2 : 0);
				$dbFiledData['orderer_name01'] = array(':orderer_name01', $ordererName01, ($ordererName01 === null) ? 2 : 0);
				$dbFiledData['orderer_name02'] = array(':orderer_name02', $ordererName02, ($ordererName02 === null) ? 2 : 0);
				$dbFiledData['orderer_kana01'] = array(':orderer_kana01', $normalizeDbString($getPostValue('userFirstNameKana')), ($normalizeDbString($getPostValue('userFirstNameKana')) === null) ? 2 : 0);
				$dbFiledData['orderer_kana02'] = array(':orderer_kana02', $normalizeDbString($getPostValue('userLastNameKana')), ($normalizeDbString($getPostValue('userLastNameKana')) === null) ? 2 : 0);
				$dbFiledData['orderer_company_name'] = array(':orderer_company_name', $normalizeDbString($getPostValue('userCompanyName')), ($normalizeDbString($getPostValue('userCompanyName')) === null) ? 2 : 0);
				$dbFiledData['orderer_email'] = array(':orderer_email', $normalizeDbString($getPostValue('userEmail')), ($normalizeDbString($getPostValue('userEmail')) === null) ? 2 : 0);
				$dbFiledData['orderer_tel'] = array(':orderer_tel', $normalizeDbString($getPostValue('userTel')), ($normalizeDbString($getPostValue('userTel')) === null) ? 2 : 0);
				$dbFiledData['orderer_postal_code'] = array(':orderer_postal_code', $normalizeDbString($getPostValue('userPostalCode')), ($normalizeDbString($getPostValue('userPostalCode')) === null) ? 2 : 0);
				$dbFiledData['orderer_pref_id'] = array(':orderer_pref_id', $ordererPrefIdValue, ($ordererPrefIdValue === null) ? 2 : 1);
				$dbFiledData['orderer_pref_name'] = array(':orderer_pref_name', $normalizeDbString($ordererPrefName), ($normalizeDbString($ordererPrefName) === null) ? 2 : 0);
				$dbFiledData['orderer_addr01'] = array(':orderer_addr01', $normalizeDbString($getPostValue('userAddress02')), ($normalizeDbString($getPostValue('userAddress02')) === null) ? 2 : 0);
				$dbFiledData['orderer_addr02'] = array(':orderer_addr02', $normalizeDbString($getPostValue('userAddress03')), ($normalizeDbString($getPostValue('userAddress03')) === null) ? 2 : 0);
				$dbFiledData['shipping_name'] = array(':shipping_name', $shippingName, ($shippingName === null) ? 2 : 0);
				$dbFiledData['shipping_name01'] = array(':shipping_name01', $shippingName01, ($shippingName01 === null) ? 2 : 0);
				$dbFiledData['shipping_name02'] = array(':shipping_name02', $shippingName02, ($shippingName02 === null) ? 2 : 0);
				$dbFiledData['shipping_kana01'] = array(':shipping_kana01', $normalizeDbString($getPostValue('shippingUserFirstNameKana')), ($normalizeDbString($getPostValue('shippingUserFirstNameKana')) === null) ? 2 : 0);
				$dbFiledData['shipping_kana02'] = array(':shipping_kana02', $normalizeDbString($getPostValue('shippingUserLastNameKana')), ($normalizeDbString($getPostValue('shippingUserLastNameKana')) === null) ? 2 : 0);
				$dbFiledData['shipping_company_name'] = array(':shipping_company_name', $normalizeDbString($getPostValue('shippingUserCompanyName')), ($normalizeDbString($getPostValue('shippingUserCompanyName')) === null) ? 2 : 0);
				$dbFiledData['shipping_tel'] = array(':shipping_tel', $normalizeDbString($getPostValue('shippingUserTel')), ($normalizeDbString($getPostValue('shippingUserTel')) === null) ? 2 : 0);
				$dbFiledData['shipping_postal_code'] = array(':shipping_postal_code', $normalizeDbString($getPostValue('shippingUserPostalCode')), ($normalizeDbString($getPostValue('shippingUserPostalCode')) === null) ? 2 : 0);
				$dbFiledData['shipping_pref_id'] = array(':shipping_pref_id', $shippingPrefIdValue, ($shippingPrefIdValue === null) ? 2 : 1);
				$dbFiledData['shipping_pref_name'] = array(':shipping_pref_name', $normalizeDbString($shippingPrefName), ($normalizeDbString($shippingPrefName) === null) ? 2 : 0);
				$dbFiledData['shipping_addr01'] = array(':shipping_addr01', $normalizeDbString($getPostValue('shippingUserAddress02')), ($normalizeDbString($getPostValue('shippingUserAddress02')) === null) ? 2 : 0);
				$dbFiledData['shipping_addr02'] = array(':shipping_addr02', $normalizeDbString($getPostValue('shippingUserAddress03')), ($normalizeDbString($getPostValue('shippingUserAddress03')) === null) ? 2 : 0);
				$dbFiledData['note'] = array(':note', $normalizeDbString($getPostValue('productDescription')), ($normalizeDbString($getPostValue('productDescription')) === null) ? 2 : 0);
				$dbFiledData['updated_at'] = array(':updated_at', date('Y-m-d H:i:s'), 0);
				$dbFiledValue = array();
				$dbFiledValue['order_id'] = array(':order_id', (int)$saveOrderId, 1);
				$dbFiledValue['shop_id'] = array(':shop_id', (int)$shopId, 1);
				$dbFiledValue['is_active'] = array(':is_active', 1, 1);
				$dbSuccessFlg = SQL_Process($DB_CONNECT, 'shop_orders', $dbFiledData, $dbFiledValue, 2, 2);
				if ($dbSuccessFlg != 1) {
					throw new Exception('外部DB受注情報の更新に失敗しました。');
				}
				$makeTag['status'] = 'success';
				$makeTag['title'] = '受注情報保存';
				$makeTag['msg'] = '保存しました。';
			} catch (Exception $e) {
				makeLog([
					'pageName' => 'proc_client03_01_01',
					'reason' => '受注詳細保存失敗',
					'errorMessage' => $e->getMessage(),
				]);
				$makeTag['status'] = 'error';
				$makeTag['title'] = '受注情報保存エラー';
				$makeTag['msg'] = $e->getMessage();
			}
		}
		break;
	default: {
			$makeTag['status'] = 'error';
			$makeTag['title'] = '不正なアクセス';
			$makeTag['msg'] = '不正なアクセスが検出されました。<br>ページを再読み込みしてください。';
		}
}
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
