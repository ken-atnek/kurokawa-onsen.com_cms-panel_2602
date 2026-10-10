<?php
require_once __DIR__ . '/../../common/define.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_reservation_settings.php';

/*
 * [飲食店表示順JSON] 対象店舗のIDだけを表示順に書き出す
 */
function generateFoodShopsSortJson(): bool
{
	$shops = getReservationFoodShopOrderRows();
	if (!is_array($shops)) {
		return false;
	}
	$shopIds = array_map(static function ($shop) {
		return sprintf('%03d', $shop['shop_id']);
	}, $shops);
	$json = json_encode($shopIds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		return false;
	}
	$saveDir = DEFINE_JSON_DIR_PATH . '/shops';
	if (!is_dir($saveDir) && mkdir($saveDir, 0777, true) === false) {
		return false;
	}
	$path = $saveDir . '/foodShopsSort.json';
	if (file_put_contents($path, $json, LOCK_EX) === false) {
		return false;
	}
	@chmod($path, octdec('0666'));
	return true;
}
