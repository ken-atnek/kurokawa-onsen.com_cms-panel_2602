<?php
require_once __DIR__ . '/../../common/define.php';
require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shops.php';
require_once __DIR__ . '/../../database/db_shop_details.php';
require_once __DIR__ . '/../../database/db_folders.php';
require_once __DIR__ . '/../../database/db_photos.php';

/*
 * [店舗一覧JSON] ベースファイル作成
 */
function createShopIndexJsonFileIfMissing($saveDir, $fileName): bool
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
 * [店舗一覧JSON] 店舗一覧JSON生成
 */
function generateShopIndexJson(): bool
{
	$shopsData = getShopList();
	if ($shopsData === null) {
		return false;
	}
	$saveIndexAllDir = DEFINE_JSON_DIR_PATH . '/shops';
	$shopsIndexAllJson = 'shopsIndex.json';
	if (!is_dir($saveIndexAllDir) && mkdir($saveIndexAllDir, 0777, true) === false) {
		return false;
	}
	if (!file_exists($saveIndexAllDir . '/' . $shopsIndexAllJson) && createShopIndexJsonFileIfMissing($saveIndexAllDir, $shopsIndexAllJson) === false) {
		return false;
	}
	if (is_array($shopsData) && count($shopsData) > 0) {
		$shopIndexList = [];
		foreach ($shopsData as $shop) {
			if ($shop['is_public'] != 1) {
				continue;
			}
			$shopDetailsData = getShopDetailsData($shop['shop_id']);
			$sId = sprintf('%03d', (int)$shop['shop_id']);
			$shopNameEngRaw = isset($shop['shop_name_en']) ? (string)$shop['shop_name_en'] : '';
			$shopNameEng = preg_replace('/[\s　]+/u', '', $shopNameEngRaw);
			if ($shopNameEng === null) {
				$shopNameEng = '';
			}
			if ($shopNameEng === '') {
				$shopNameEng = 'shop-' . $sId;
			}
			$shopIndexList[] = [
				'id' => $sId,
				'slug' => $shopNameEng,
				'category' => $shop['shop_type'] ?? '',
				'name' => formatTextareaForDB((string)($shop['shop_name'] ?? '')),
				'tel' => $shop['tel'] ?? '',
				'thumb' => $shopDetailsData['main_image_path'] ?? '',
				'leadCopy' => formatTextareaForDB((string)($shopDetailsData['intro_body'] ?? '')),
			];
		}
		$json = json_encode($shopIndexList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return false;
		}
		if (file_put_contents($saveIndexAllDir . '/' . $shopsIndexAllJson, $json, LOCK_EX) === false) {
			return false;
		}
		@chmod($saveIndexAllDir . '/' . $shopsIndexAllJson, octdec('0666'));
	} else if (createShopIndexJsonFileIfMissing($saveIndexAllDir, $shopsIndexAllJson) === false) {
		return false;
	}
	return true;
}
