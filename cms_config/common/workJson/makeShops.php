<?php
/*
 * [cms_config/common/workJson/makeShops.php]
 *  - 管理画面 -
 *  新規店舗登録／編集／削除後のJSONファイル作成
 *
 * [初版]
 *  2026.2.18
 */

#===========================================#
# 基本設定
#-------------------------------------------#
require(__DIR__ . '/../../common/set_function.php');
require(__DIR__ . '/../../common/set_contents.php');
require(__DIR__ . '/../../database/set_db.php');
require(__DIR__ . '/../../database/db_shops.php');
require(__DIR__ . '/../../database/db_shop_details.php');
require(__DIR__ . '/../../database/db_shop_items.php');
require(__DIR__ . '/../../database/db_folders.php');
require(__DIR__ . '/../../database/db_photos.php');
#-------------------------------------------#
#===========================================#
/**
 * 時刻を HH:MM に正規化（秒があれば削除）
 * 例: "06:00:00" -> "06:00", "6:00" -> "06:00"
 */
function normalizeTimeHm($time): string
{
	$time = trim((string)$time);
	if ($time === '') {
		return '';
	}
	if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
		return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
	}
	return $time;
}
#POSTチェック
# 優先順：CLI引数 -> POST/GET
$shopId = null;
if (PHP_SAPI === 'cli') {
	global $argv;
	$shopId = $argv[1] ?? null;
} else {
	$shopId = $_POST['shopId'] ?? $_GET['shopId'] ?? null;
}
if (!is_scalar($shopId) || !preg_match('/^[1-9][0-9]*$/', (string)$shopId)) {
	# shop_id が取得できない／不正：処理終了
	exit;
}
$shopId = (int)$shopId;
#-------------------------------------------#
#店舗情報取得
$shopData = getShops_FindById($shopId);
#店舗情報が無ければ処理終了
if ($shopData === null) {
	#店舗情報無し：処理終了
	exit;
} else {
	#JSON保存先
	require(__DIR__ . '/../../common/define.php');
	$saveDir = DEFINE_JSON_DIR_PATH . '/shops/details';
	#書き込み用ディレクトリが無い場合はベースディレクトリ作成
	if (!is_dir($saveDir)) {
		@mkdir($saveDir, 0777, true);
	}
	#JSONファイル名「001.json」の形式で保存
	$shopJson = sprintf('%03d', $shopId) . '.json';
	#書き込み用jsonファイルが無い場合はベースファイルを作成
	if (!file_exists($saveDir . '/' . $shopJson)) {
		makeJson($saveDir, $shopJson);
	}
}
#-------------------------------------------#
#===========================================#

#===========================================#
# 店舗が無効（削除等）の場合は空ファイルにして終了
if (!is_array($shopData) || (int)($shopData['is_active'] ?? 0) !== 1) {
	makeJson($saveDir, $shopJson);
	exit;
}
#表示可能データあればベースデータ生成
if (is_array($shopData) && count($shopData) > 0) {
	#紹介情報取得
	$shopDetailsData = getShopDetailsData($shopId);
	#紹介情報があれば店舗情報にマージ
	if (is_array($shopDetailsData) && count($shopDetailsData) > 0) {
		$shopData = array_merge($shopData, $shopDetailsData);
	}
	#アイテム情報取得（pickup/recommended 固定3枠）
	$shopItemsData = getShopItemsData($shopId);
	#JSONデータ生成
	$shop = $shopData;
	#ID
	$sId = sprintf('%03d', (int)$shop['shop_id']);
	$shopNameEngRaw = isset($shop['shop_name_en']) ? (string)$shop['shop_name_en'] : '';
	#URL用：スペース等（半角/全角含むホワイトスペース）を全て削除
	$shopNameEng = preg_replace('/[\s　]+/u', '', $shopNameEngRaw);
	if ($shopNameEng === null) {
		$shopNameEng = '';
	}
	if ($shopNameEng === '') {
		$shopNameEng = 'shop-' . $sId;
	}
	#pickupItems（shop['image_path_1..3'] / shop['image_title_1..3']）
	$pickupItems = [];
	for ($i = 1; $i <= 3; $i++) {
		$imagePath = $shop['image_path_' . $i] ?? '';
		$title = $shop['image_title_' . $i] ?? '';
		$imagePath = is_string($imagePath) ? trim($imagePath) : '';
		$title = is_string($title) ? trim($title) : '';
		# 画像パスが無い枠は出力しない（フロント前提：image 必須）
		if ($imagePath === '') {
			continue;
		}
		$pickupItems[] = [
			'id' => 'pick-' . sprintf('%03d', $i),
			'title' => $title,
			'image' => $imagePath,
		];
	}
	#営業情報
	$infoData = [];
	#営業時間
	$infoData['hours'] = [
		[
			'label' => '営業時間',
			'timeRanges' => [
				[
					'open' => normalizeTimeHm($shop['lunch_open_time'] ?? ''),
					'close' => normalizeTimeHm($shop['lunch_close_time'] ?? ''),
					'note' => $shop['lunch_note'] ?? ''
				],
				[
					'open' => normalizeTimeHm($shop['dinner_open_time'] ?? ''),
					'close' => normalizeTimeHm($shop['dinner_close_time'] ?? ''),
					'note' => $shop['dinner_note'] ?? ''
				]
			]
		],
		[
			'label' =>  '店休日',
			'value' => $shop['regular_holiday_display'] ?? ''
		]
	];
	# closed_weekdays はDBも JSON 形式（例: [1,2]）が基本。念のためCSVもフォールバック。
	$closedWeekdays = [];
	$closedRaw = $shop['closed_weekdays'] ?? null;
	if (is_string($closedRaw) && $closedRaw !== '') {
		$decoded = json_decode($closedRaw, true);
		if (is_array($decoded)) {
			$closedWeekdays = array_values(array_map('intval', $decoded));
		} else {
			$parts = array_map('trim', explode(',', $closedRaw));
			$parts = array_values(array_filter($parts, static function ($v) {
				return $v !== '';
			}));
			$closedWeekdays = array_values(array_map('intval', $parts));
		}
	}
	$infoData['closedWeekdays'] = $closedWeekdays;
	$infoData['address'] = [
		'postalCode' => $shop['postal_code'] ?? '',
		'full' => trim(($shop['address1'] ?? '') . ' ' . ($shop['address2'] ?? '') . ' ' . ($shop['address3'] ?? ''))
	];
	$infoData['mapUrl'] = $shop['map_url'] ?? '';
	$infoData['mapLinkUrl'] = $shop['map_link_url'] ?? '';
	$infoData['tel'] = $shop['tel'] ?? '';
	$infoData['fax'] = $shop['fax'] ?? '';
	$infoData['web'] = $shop['website_url'] ?? '';
	# email は公開設定 is_email_public=1 の場合のみ出力
	if ((int)($shop['is_email_public'] ?? 0) === 1) {
		$infoData['mail'] = $shop['email'] ?? '';
	}
	#おすすめ商品（shop_items: recommended slot=1..3）
	$recommendedProducts = [];
	$recommendedSlots = $shopItemsData['recommended'] ?? [];
	for ($j = 1; $j <= 3; $j++) {
		$row = $recommendedSlots[$j] ?? null;
		if (!is_array($row)) {
			continue;
		}
		if ((int)($row['is_active'] ?? 0) !== 1) {
			continue;
		}
		$imagePath = $row['image_path'] ?? null;
		$title = $row['title'] ?? '';
		$description = $row['description'] ?? '';
		$priceYen = $row['price_yen'] ?? null;
		$price = '';
		if ($priceYen !== null && $priceYen !== '') {
			$price = '¥' . number_format((int)$priceYen);
		}
		if (($imagePath === null || $imagePath === '') && ($title === null || $title === '') && ($description === null || $description === '') && $price === '') {
			continue;
		}
		$recommendedProducts[] = [
			'id' => 'rec-' . sprintf('%03d', $j),
			'name' => (string)($title ?? ''),
			'price' => $price,
			'image' => (string)($imagePath ?? ''),
			'description' => (string)($description ?? ''),
		];
	}

	#書き出しデータ整形（詳細JSONは単一オブジェクト）
	$writeData = [
		'id' => $sId,
		'slug' => $shopNameEng,
		'category' => $shop['shop_type'] ?? '',
		'name' => formatTextareaForDB((string)($shop['shop_name'] ?? '')),
		'statusFallbackKey' => 'open',
		'heroImage' => $shop['main_image_path'] ?? '',
		'leadCopy' => formatTextareaForDB((string)($shop['intro_body'] ?? '')),
		'pickupItems' => $pickupItems,
		'info' => $infoData,
		'recommendedProducts' => $recommendedProducts,
		'onlineShopUrl' => null,
		'onlineProductsCount' => 0,
	];
	#JSONエンコード
	$news_json = json_encode($writeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	#ファイル書き込み
	$write_json = fopen($saveDir . '/' . $shopJson, "w");
	fwrite($write_json, $news_json);
	fclose($write_json);
	@chmod($saveDir . '/' . $shopJson, octdec('0666'));
} else {
	#表示可能リスト無し：空ファイル作成
	makeJson($saveDir, $shopJson);
}
#===========================================#
#jsonベースファイルを作成：一覧用
function makeJson($saveDir, $makeJson)
{
	#ディレクトリが無い場合は作成
	if (!is_dir($saveDir)) {
		@mkdir($saveDir, 0777, true);
	}
	#ファイルがないなら空ファイル作成
	if (!file_exists($saveDir . '/' . $makeJson)) {
		$news_json = '';
		$write_json = fopen($saveDir . '/' . $makeJson, "w");
		fwrite($write_json, $news_json);
		fclose($write_json);
		#パーミッション変更
		@chmod($saveDir . '/' . $makeJson, octdec("0666"));
	}
}
#===========================================#
