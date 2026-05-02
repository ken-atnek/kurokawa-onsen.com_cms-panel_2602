<?php
/*
 * [96-client/assets/function/proc_client03_02.php]
 *  - 管理画面 -
 *  商品一覧：検索/確認ページリンク
 *
 * [初版]
 *  2026.4.29
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#店舗情報（EC関連）
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';

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
#検索・ステータス変更
$action = isset($_POST['action']) ? $_POST['action'] : '';
#商品名・商品ID
$searchProduct = isset($_POST['searchProduct']) ? $_POST['searchProduct'] : '';
#カテゴリ
$searchCategory = isset($_POST['select-search-category']) ? $_POST['select-search-category'] : '';
#公開設定
$displayFlg = isset($_POST['displayFlg']) ? $_POST['displayFlg'] : '';
#-------------#
#表示件数
$displayNumber = isset($_POST['displayNumber']) ? intval($_POST['displayNumber']) : $initialDisplayNumber;
if ($displayNumber < 1) {
	$displayNumber = $initialDisplayNumber;
}
#ページ番号
$pageNumber = isset($_POST['pageNumber']) ? intval($_POST['pageNumber']) : 1;
#-------------#
#前回の状態維持
$searchConditionsSessionKey = 'searchConditions_client03_02';
$prevSearchConditions = isset($_SESSION[$searchConditionsSessionKey]) && is_array($_SESSION[$searchConditionsSessionKey]) ? $_SESSION[$searchConditionsSessionKey] : null;
if (!is_array($prevSearchConditions)) {
	$prevSearchConditions = [
		'searchProduct' => $searchProduct,
		'searchCategory' => $searchCategory,
		'displayFlg' => $displayFlg,
		'displayNumber' => $initialDisplayNumber,
		'pageNumber' => 1,
	];
	$_SESSION[$searchConditionsSessionKey] = $prevSearchConditions;
}
$requiredKeys = ['searchProduct', 'searchCategory', 'displayFlg', 'displayNumber', 'pageNumber'];
foreach ($requiredKeys as $requiredKey) {
	if (!array_key_exists($requiredKey, $prevSearchConditions)) {
		$prevSearchConditions = [
			'searchProduct' => $searchProduct,
			'searchCategory' => $searchCategory,
			'displayFlg' => $displayFlg,
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
				'searchProduct' => $searchProduct,
				'searchCategory' => $searchCategory,
				'displayFlg' => $displayFlg,
				'displayNumber' => $displayNumber,
				'pageNumber' => $pageNumber,
			];
		}
		break;
	#リセット：店名クリア＋公開に戻す
	case 'reset': {
			$searchConditions = [
				'searchProduct' => '',
				'searchCategory' => '',
				'displayFlg' => '0',
				'displayNumber' => $initialDisplayNumber,
				'pageNumber' => 1,
			];
		}
		break;
	#デフォルト：全てクリア
	default: {
			$searchConditions = [
				'searchProduct' => '',
				'searchCategory' => '',
				'displayFlg' => '0',
				'displayNumber' => $initialDisplayNumber,
				'pageNumber' => 1,
			];
		}
		break;
}
$_SESSION[$searchConditionsSessionKey] = $searchConditions;

#商品数取得：検索結果
$totalItems = countShopProductList($shopId, $searchConditions);
#総件数（ページャー用）
$totalPages = (int)ceil($totalItems / $displayNumber);
if ($totalPages < 1) {
	$totalPages = 1;
}
if ($pageNumber < 1) {
	$pageNumber = 1;
} elseif ($pageNumber > $totalPages) {
	$pageNumber = $totalPages;
}
$searchConditions['pageNumber'] = $pageNumber;
$_SESSION[$searchConditionsSessionKey] = $searchConditions;
#=========#
# 商品一覧
#---------#
$shopProductList = searchShopProductList($shopId, $searchConditions, $pageNumber, $displayNumber);

#***** タグ生成開始 *****#
$makeTag['tag'] .= <<<HTML
            <ul>
              <li>
                <div>ID</div>
                <div>画像</div>
                <div>商品名</div>
                <div>価格</div>
                <div>在庫数</div>
                <div>公開状態</div>
                <div>登録日</div>
                <div>更新日</div>
                <div>確認</div>
              </li>

HTML;
#表示可能リストあればループで差し込む
if (!empty($shopProductList)) {
	#inline JS用エスケープ宣言
	$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
	foreach ($shopProductList as $product) {
		#店舗ID：ゼロ埋め3桁
		$productShopId = str_pad($product['shop_id'], 3, '0', STR_PAD_LEFT);
		#商品情報
		$productId = htmlspecialchars($product['product_id'], ENT_QUOTES, 'UTF-8');
		$productName = htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8');
		$editUrl = './client03_03.php?method=edit&productId=' . rawurlencode((string)$product['product_id']);
		$editUrlHtml = htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8');
		$imageNoImagePath = '../assets/images/no-image.webp';
		$imageSrc = $imageNoImagePath;
		$storagePath = isset($product['main_image_storage_path']) ? trim((string)$product['main_image_storage_path']) : '';
		$storagePath = ltrim($storagePath, '/');
		if ($storagePath !== '' && strpos($storagePath, '..') === false) {
			$imageSrc = rtrim(DOMAIN_NAME, '/') . '/db/images/' . $storagePath;
		}
		$imageSrcHtml = htmlspecialchars($imageSrc, ENT_QUOTES, 'UTF-8');
		$variantCount = isset($product['variant_count']) ? (int)$product['variant_count'] : 0;
		$isPriceStockUnset = ((int)$product['price'] === 0 && $product['stock'] === null);
		if ($variantCount > 0 && $isPriceStockUnset) {
			$productPriceWithTax = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
			$productStock = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
		} elseif ($variantCount > 0) {
			#税込価格
			#$taxRate = ($product['tax_rate'] / 100) ?? 0;
			#$productPriceWithTax = (int)round($product['price'] * (1 + $taxRate));
			$productPriceWithTax = (int)round($product['price']);
			$productPriceWithTax = htmlspecialchars(number_format($productPriceWithTax) . '～', ENT_QUOTES, 'UTF-8');
			$productStock = htmlspecialchars('規格確認', ENT_QUOTES, 'UTF-8');
		} elseif ($isPriceStockUnset) {
			$productPriceWithTax = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
			$productStock = htmlspecialchars('未設定', ENT_QUOTES, 'UTF-8');
		} else {
			#税込価格
			#$taxRate = ($product['tax_rate'] / 100) ?? 0;
			#$productPriceWithTax = (int)round($product['price'] * (1 + $taxRate));
			$productPriceWithTax = (int)round($product['price']);
			$productPriceWithTax = htmlspecialchars(number_format($productPriceWithTax), ENT_QUOTES, 'UTF-8');
			#在庫数
			if ((int)$product['stock_unlimited'] === 1) {
				$productStock = '無制限';
			} else {
				$productStock = ($product['stock'] === null) ? '-' : htmlspecialchars((string)$product['stock'], ENT_QUOTES, 'UTF-8');
			}
		}
		#公開状態
		$isPublic = ((int)$product['status'] === 1) ? 'is-active' : 'is-inactive';
		$statusLabel = ((int)$product['status'] === 1) ? '公開中' : '非公開';
		#登録日・更新日
		$createdAtYMD = date('Y/m/d', strtotime($product['created_at']));
		$createdAtTime = date('H:i', strtotime($product['created_at']));
		$createdAtHtml = htmlspecialchars($createdAtYMD, ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars($createdAtTime, ENT_QUOTES, 'UTF-8') . '</i>';
		$updatedAtYMD = date('Y/m/d', strtotime($product['updated_at']));
		$updatedAtTime = date('H:i', strtotime($product['updated_at']));
		$updatedAtHtml = htmlspecialchars($updatedAtYMD, ENT_QUOTES, 'UTF-8') . ' <i>' . htmlspecialchars($updatedAtTime, ENT_QUOTES, 'UTF-8') . '</i>';
		#更新日が登録日と同じなら登録日のみ表示
		if ($createdAtYMD === $updatedAtYMD) {
			$updatedAtHtml = '-';
		}
		#inline JS用エスケープ（属性崩壊・注入対策）
		$productIdJs = json_encode($productId, $jsonHex);
		#サイト確認URL
		$websiteUrl = 'https://kurokawa-onsen.com/shops/' . $productShopId . '/products/' . $productIdJs;
		$websiteUrl = htmlspecialchars($websiteUrl, ENT_QUOTES, 'UTF-8');
		$makeTag['tag'] .= <<<HTML
              <li>
                <div class="item-id">
                  <span>{$productId}</span>
                </div>
                <div class="item-image">
                  <picture>
                    <source src="{$imageSrcHtml}">
                    <img src="{$imageSrcHtml}" alt="{$productName}">
                  </picture>
                </div>
                <div class="item-name">
                  <a href="{$editUrlHtml}"></a>
                  <span>{$productName}</span>
                </div>
                <div class="item-price">
                  <span>{$productPriceWithTax}</span>
                </div>
                <div class="item-stock">
                  <span>{$productStock}</span>
                </div>
                <div class="item-status">
                  <span class="{$isPublic}">{$statusLabel}</span>
                </div>
                <div class="item-date">
                  <span>{$createdAtHtml}</span>
                </div>
                <div class="item-date">
                  <span>{$updatedAtHtml}</span>
                </div>
                <div class="item-check">
                  <a href="#" target="_blank" data-tooltip="表示サイト確認" aria-label="表示サイト確認"></a>
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
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
