<?php
/*
 * [cms_config/common/workJson/makeShops.php]
 *  店舗詳細JSON生成 CLIラッパー
 */

require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../common/set_contents.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shops.php';
require_once __DIR__ . '/../../database/db_shop_details.php';
require_once __DIR__ . '/../../database/db_shop_items.php';
require_once __DIR__ . '/../../database/db_folders.php';
require_once __DIR__ . '/../../database/db_photos.php';
require_once __DIR__ . '/makeShopJson.php';

$shopId = null;
if (PHP_SAPI === 'cli') {
	global $argv;
	$shopId = $argv[1] ?? null;
} else {
	$shopId = $_POST['shopId'] ?? $_GET['shopId'] ?? null;
}

if (!is_scalar($shopId) || !preg_match('/^[1-9][0-9]*$/', (string)$shopId)) {
	exit(1);
}

$ok = generateShopJson((int)$shopId);
exit($ok === true ? 0 : 1);
