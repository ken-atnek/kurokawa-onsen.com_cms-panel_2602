<?php
require_once __DIR__ . '/jsonExportCommon.php';
require_once __DIR__ . '/../../common/define.php';
require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../common/set_contents.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shops.php';
require_once __DIR__ . '/../../database/db_shop_details.php';
require_once __DIR__ . '/../../database/db_shop_items.php';
require_once __DIR__ . '/../../database/db_shops_ec.php';
require_once __DIR__ . '/../../database/db_folders.php';
require_once __DIR__ . '/../../database/db_photos.php';
require_once __DIR__ . '/makeShopIndexJson.php';

/*
 * [店舗JSON] 時刻をHH:MM形式に整形
 */
function normalizeShopJsonTimeHm($time): string
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

/*
 * [店舗JSON] ベースファイル作成
 */
function createShopJsonFileIfMissing($saveDir, $fileName): bool
{
	if (!is_dir($saveDir) && mkdir($saveDir, 0777, true) === false) {
		return false;
	}
	if (!file_exists($saveDir . '/' . $fileName)) {
		if (file_put_contents($saveDir . '/' . $fileName, '', LOCK_EX) === false) {
			return false;
		}
		@chmod($saveDir . '/' . $fileName, octdec('0666'));
	}
	return true;
}

/*
 * [店舗JSON] 空ファイル書き込み
 */
function writeEmptyShopJsonFile($saveDir, $fileName): bool
{
	if (!is_dir($saveDir) && mkdir($saveDir, 0777, true) === false) {
		return false;
	}
	if (file_put_contents($saveDir . '/' . $fileName, '', LOCK_EX) === false) {
		return false;
	}
	@chmod($saveDir . '/' . $fileName, octdec('0666'));
	return true;
}

/*
 * [店舗JSON] 店舗詳細JSON生成
 */
function generateShopJson($shopId): bool
{
	$shopId = (int)$shopId;
	if ($shopId < 1) {
		return false;
	}
	$shopData = getShops_FindById($shopId);
	if ($shopData === null) {
		return false;
	}
	$saveDir = DEFINE_JSON_DIR_PATH . '/shops/details';
	$shopJson = sprintf('%03d', $shopId) . '.json';
	if (!is_dir($saveDir) && mkdir($saveDir, 0777, true) === false) {
		return false;
	}
	if (!file_exists($saveDir . '/' . $shopJson) && createShopJsonFileIfMissing($saveDir, $shopJson) === false) {
		return false;
	}
	if (!is_array($shopData) || count($shopData) < 1) {
		return writeEmptyShopJsonFile($saveDir, $shopJson);
	}
	if ((int)($shopData['is_active'] ?? 0) !== 1) {
		return writeEmptyShopJsonFile($saveDir, $shopJson);
	}
	$shopDetailsData = getShopDetailsData($shopId);
	if (is_array($shopDetailsData) && count($shopDetailsData) > 0) {
		$shopData = array_merge($shopData, $shopDetailsData);
	}
	$shopItemsData = getShopItemsData($shopId);
	$shop = $shopData;
	$sId = sprintf('%03d', (int)$shop['shop_id']);
	$shopNameEngRaw = isset($shop['shop_name_en']) ? (string)$shop['shop_name_en'] : '';
	$shopNameEng = preg_replace('/[\s　]+/u', '', $shopNameEngRaw);
	if ($shopNameEng === null) {
		$shopNameEng = '';
	}
	if ($shopNameEng === '') {
		$shopNameEng = 'shop-' . $sId;
	}
	$pickupItems = [];
	for ($i = 1; $i <= 3; $i++) {
		$imagePath = $shop['image_path_' . $i] ?? '';
		$title = $shop['image_title_' . $i] ?? '';
		$imagePath = is_string($imagePath) ? trim($imagePath) : '';
		$title = is_string($title) ? trim($title) : '';
		if ($imagePath === '') {
			continue;
		}
		$pickupItems[] = [
			'id' => 'pick-' . sprintf('%03d', $i),
			'title' => $title,
			'image' => $imagePath,
		];
	}
	$infoData = [];
	$infoData['hours'] = [
		[
			'label' => '営業時間',
			'timeRanges' => [
				[
					'open' => normalizeShopJsonTimeHm($shop['lunch_open_time'] ?? ''),
					'close' => normalizeShopJsonTimeHm($shop['lunch_close_time'] ?? ''),
					'note' => $shop['lunch_note'] ?? ''
				],
				[
					'open' => normalizeShopJsonTimeHm($shop['dinner_open_time'] ?? ''),
					'close' => normalizeShopJsonTimeHm($shop['dinner_close_time'] ?? ''),
					'note' => $shop['dinner_note'] ?? ''
				]
			]
		],
		[
			'label' => '店休日',
			'value' => $shop['regular_holiday_display'] ?? ''
		]
	];
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
	if ((int)($shop['is_email_public'] ?? 0) === 1) {
		$infoData['mail'] = $shop['email'] ?? '';
	}
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
	$onlineProductsCount = 0;
	if (function_exists('countShopPublicProducts')) {
		$onlineProductsCount = countShopPublicProducts($shopId);
	} else if (function_exists('countShopProductList')) {
		$onlineProductsCount = countShopProductList($shopId, ['displayFlg' => '1']);
	}
	$onlineProductsCount = (int)$onlineProductsCount;
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
		'onlineProductsCount' => $onlineProductsCount,
	];
	$json = json_encode($writeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		return false;
	}
	if (file_put_contents($saveDir . '/' . $shopJson, $json, LOCK_EX) === false) {
		return false;
	}
	@chmod($saveDir . '/' . $shopJson, octdec('0666'));
	return true;
}

/*
 * [店舗JSON] 店舗詳細・一覧JSON同期書き出し
 */
function syncFrontendShopJson(&$makeTag, $shopId)
{
	$okShop = generateShopJson($shopId);
	$okIndex = generateShopIndexJson();
	if ($okShop !== true || $okIndex !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		logFrontendJsonError('shop_json_export_failed', $shopId, null, [
			'shop_json' => $okShop,
			'index_json' => $okIndex,
		]);
		return false;
	}
	return true;
}

/*
 * [店舗JSON] 店舗詳細JSON同期書き出し
 */
function syncFrontendShopDetailJson(&$makeTag, $shopId)
{
	$okShop = generateShopJson($shopId);
	if ($okShop !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		logFrontendJsonError('shop_detail_json_export_failed', $shopId, null, [
			'shop_json' => $okShop,
		]);
		return false;
	}
	return true;
}
