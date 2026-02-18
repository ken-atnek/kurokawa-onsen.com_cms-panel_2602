<?php
/*
 * [96-master/assets/function/proc_master01_01.php]
 *  - 管理画面 -
 *  店舗一覧：検索/予約受付設定変更
 *
 * [初版]
 *  2026.2.16
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

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
	'tag' => '',
	'status' => '',
	'title' => '',
	'msg' => '',
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
#公開設定
$displayMode = isset($_POST['displayMode']) ? $_POST['displayMode'] : '';
#検索店舗ID
$searchShopId = isset($_POST['searchShopId']) ? $_POST['searchShopId'] : '';
#ステータス
$changeStatus = isset($_POST['status']) ? (string)$_POST['status'] : '';
#-------------#
#応募状況ステータス変更／お知らせモーダル表示
if ($action == 'changeStatus') {
	#=============#
	# POSTチェック
	#-------------#
	#店舗ID
	$statusChangeShopId = isset($_POST['changeShopId']) ? $_POST['changeShopId'] : '';
	#店舗名
	$statusChangeShopName = isset($_POST['shopName']) ? (string)$_POST['shopName'] : '';
	if ((int)$statusChangeShopId < 1 || ($changeStatus !== '0' && $changeStatus !== '1')) {
		header('Content-Type: application/json; charset=UTF-8');
		$makeTag['status'] = 'error';
		$makeTag['title'] = '公開設定変更エラー';
		$makeTag['msg'] = '公開設定の入力値が不正です。';
		echo json_encode($makeTag);
		exit;
	}
	try {
		#トランザクション開始
		# 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
		$result = DB_Transaction(1);
		if ($result == false) {
			#エラーログ出力
			$data = [
				'pageName' => 'proc_master01_01',
				'reason' => 'トランザクション開始失敗',
			];
			makeLog($data);
			$makeTag['status'] = 'error';
			$makeTag['title'] = '公開設定変更エラー';
			$makeTag['msg'] = 'トランザクション開始に失敗しました。';
		} else {
			#***** ステータス変更 *****#
			#登録用配列：初期化
			$dbFiledData = array();
			#登録情報セット
			$dbFiledData['is_public'] = array(':is_public', (int)$changeStatus, 1);
			$dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
			#更新用キー：初期化
			$dbFiledValue = array();
			$dbFiledValue['shop_id'] = array(':shop_id', $statusChangeShopId, 1);
			#処理モード：[1].新規追加｜[2].更新｜[3].削除
			$processFlg = 2;
			#DB更新
			#実行モード：[1].トランザクション｜[2].即実行
			$exeFlg = 2;
			$dbSuccessFlg = SQL_Process($DB_CONNECT, "shops", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
			if ($dbSuccessFlg == 1) {
				#DBコミット
				# 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
				DB_Transaction(2);
				$makeTag['status'] = 'success';
				$makeTag['title'] = '公開設定変更';
				$shopNameEsc = htmlspecialchars((string)$statusChangeShopName, ENT_QUOTES, 'UTF-8');
				if ($changeStatus === '1') {
					$makeTag['msg'] = $shopNameEsc . '様を<span style="font-weight:bold;">「公開」</span>に変更しました。';
				} else {
					$makeTag['msg'] = $shopNameEsc . '様を<span style="font-weight:bold;">「非公開」</span>に変更しました。';
				}
			} else {
				#更新失敗：ロールバックしてエラー返却
				DB_Transaction(3);
				$makeTag['status'] = 'error';
				$makeTag['title'] = '公開設定変更エラー';
				$makeTag['msg'] = '公開設定の更新に失敗しました。';
			}
		}
	} catch (Exception $e) {
		DB_Transaction(3);
		#エラーログ出力
		$data = [
			'pageName' => 'proc_master01_01',
			'reason' => '公開設定更新失敗',
			'errorMessage' => $e->getMessage(),
		];
		makeLog($data);
		$makeTag['status'] = 'error';
		$makeTag['title'] = '公開設定変更エラー';
		$makeTag['msg'] = 'トランザクション開始に失敗しました。';
	}
}
#-------------#
# セッション整合性チェック（保険的修復処理）
# ※ここでの修復結果は直後のswitch文の出力には影響しない。
#   switch文では $action に応じて $searchConditions を新たに構築し、
#   SESSIONを上書きするため、このブロックはあくまで
#   「セッションが壊れていた場合の応急処置」として機能する。
#   運用上はセッションキーが常に揃う前提だが、
#   予期しない状態（タブ複製・セッション部分消失等）への備え。
$searchConditionsSessionKey = 'searchConditions_master01_01';
$prevSearchConditions = isset($_SESSION[$searchConditionsSessionKey]) && is_array($_SESSION[$searchConditionsSessionKey]) ? $_SESSION[$searchConditionsSessionKey] : null;
if (!is_array($prevSearchConditions)) {
	$prevSearchConditions = [
		'shopId' => $searchShopId,
		'isPublic' => $displayMode !== '' ? $displayMode : '1',
	];
	$_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
}
$requiredKeys = ['shopId', 'isPublic'];
foreach ($requiredKeys as $requiredKey) {
	if (!array_key_exists($requiredKey, $prevSearchConditions)) {
		$fixedIsPublic = isset($prevSearchConditions['isPublic']) ? (string)$prevSearchConditions['isPublic'] : $displayMode;
		if ($fixedIsPublic !== '0' && $fixedIsPublic !== '1') {
			$fixedIsPublic = '1';
		}
		$prevSearchConditions = [
			'shopId' => isset($prevSearchConditions['shopId']) ? (int)$prevSearchConditions['shopId'] : '',
			'isPublic' => $fixedIsPublic,
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
				'shopId' => $searchShopId,
				'isPublic' => $displayMode !== '' ? $displayMode : '1',
			];
		}
		break;
	#公開設定変更：切替後は「切替後のステータス」で一覧を再表示
	case 'changeStatus': {
			$searchConditions = [
				'shopId' => '',
				'isPublic' => $changeStatus !== '' ? $changeStatus : '1',
			];
		}
		break;
	#リセット：店名クリア＋公開に戻す
	case 'reset': {
			$searchConditions = [
				'shopId' => '',
				'isPublic' => '1',
			];
		}
		break;
	#デフォルト：全てクリア
	default: {
			$searchConditions = [
				'shopId' => $searchShopId,
				'isPublic' => '1',
			];
		}
		break;
}
#SESSIONに保存
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
$searchConditions = $_SESSION[$searchConditionsSessionKey];
$displayMode = isset($searchConditions['isPublic']) && (string)$searchConditions['isPublic'] !== '' ? (string)$searchConditions['isPublic'] : '1';

#フロント側で検索条件ラジオのcheckedを同期するために返却
$makeTag['displayMode'] = $displayMode;

#フロント側で店名選択状態を同期するために返却
$makeTag['searchShopId'] = isset($searchConditions['shopId']) ? (string)$searchConditions['shopId'] : '';

#店舗一覧取得
$shopsList = searchShopList($searchConditions);

#***** タグ生成開始 *****#
$makeTag['tag'] .= <<<HTML
            <ul>
              <li>
                <div>ID</div>
                <div>店舗情報</div>
                <div>写真</div>
                <div>基本情報</div>
                <div>紹介</div>
                <div>おすすめ<i>商品</i></div>
                <div><span>公開状況</span><span>設定変更</span></div>
                <div>サイト<i>確認</i></div>
              </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopsList)) {
	#inline JS用エスケープ宣言
	$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
	foreach ($shopsList as $shop) {
		$shopId = htmlspecialchars($shop['shop_id'], ENT_QUOTES, 'UTF-8');
		$shopName = htmlspecialchars($shop['shop_name'], ENT_QUOTES, 'UTF-8');
		$shopNameEngRaw = isset($shop['shop_name_en']) ? (string)$shop['shop_name_en'] : '';
		#URL用：スペース等（半角/全角含むホワイトスペース）を全て削除
		$shopNameEng = preg_replace('/[\s　]+/u', '', $shopNameEngRaw);
		#改行を除去して１行にまとめる
		$shopName = preg_replace('/\r\n|\r|\n/', '', $shopName);
		$shopTel = htmlspecialchars($shop['tel'], ENT_QUOTES, 'UTF-8');
		$shopFax = htmlspecialchars($shop['fax'], ENT_QUOTES, 'UTF-8');
		#公開状態
		$isPublic = ($shop['is_public'] == 1) ? 'is-active' : 'is-inactive';
		$statusLabel = ($shop['is_public'] == 1) ? '公開中' : '非公開';
		$nextStatus = ($shop['is_public'] == 1) ? '0' : '1';
		$nextStatusLabel = ($shop['is_public'] == 1) ? '非公開へ' : '公開へ';
		#サイト確認URL
		$websiteUrl = 'https://demo-kurokawa-onsen.tuna-pic.co.jp/shops/' . rawurlencode($shopNameEng) . '/';
		$websiteUrl = htmlspecialchars($websiteUrl, ENT_QUOTES, 'UTF-8');
		#inline JS用エスケープ（属性崩壊・注入対策）
		$shopIdJs = json_encode($shopId, $jsonHex);
		$shopNameJs = json_encode($shopName, $jsonHex);
		$nextStatusJs = json_encode($nextStatus, $jsonHex);
		$makeTag['tag'] .= <<<HTML
              <li>
                <div class="id">{$shopId}</div>
                <div class="wrap_shop-info">
                  <div class="name">{$shopName}</div>
                  <div class="tel">
                    <span>{$shopTel}</span>
                  </div>
                  <div class="fax">
                    <span>{$shopFax}</span>
                  </div>
                </div>
                <div class="item_photo">
                  <a href="./master01_01_01.php?shopId={$shopId}"></a>
                </div>
                <div class="item_edit">
                  <a href="./master01_02.php?method=edit&shopId={$shopId}"></a>
                </div>
                <div class="item_edit">
                  <a href="./master01_01_02.php?shopId={$shopId}"></a>
                </div>
                <div class="item_edit">
                  <a href="./master01_01_03.php?shopId={$shopId}"></a>
                </div>
                <div class="item_status">
                  <!-- NOTE ↑公開中→[is-active] / 非公開→[is-inactive] -->
                  <div class="status {$isPublic}">
                    <span></span>
                  </div>
                  <div class="btn">
                    <button type="button" onclick='changeStatus({$shopIdJs},{$shopNameJs},{$nextStatusJs})'>{$nextStatusLabel}</button>
                  </div>
                </div>
                <div class="item_site">
                  <a href="{$websiteUrl}" target="_blank"></a>
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
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
