<?php
/*
 * [cms_config/common/workJson/makeShopsIndex.php]
 *  店舗一覧JSON生成 CLIラッパー
 */

require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shops.php';
require_once __DIR__ . '/../../database/db_shop_details.php';
require_once __DIR__ . '/../../database/db_folders.php';
require_once __DIR__ . '/../../database/db_photos.php';
require_once __DIR__ . '/makeShopIndexJson.php';

$ok = generateShopIndexJson();
exit($ok === true ? 0 : 1);
