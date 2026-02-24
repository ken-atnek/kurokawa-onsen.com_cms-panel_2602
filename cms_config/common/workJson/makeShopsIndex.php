<?php
/*
 * [cms_config/common/workJson/makeShopsIndex.php]
 *  - 管理画面 -
 *  店舗情報登録／編集／削除後のJSONファイル作成 (全店舗情報)
 *
 * [初版]
 *  2026.2.18
 */

#===========================================#
# 基本設定
#-------------------------------------------#
require(__DIR__ . '/../../common/set_function.php');
require(__DIR__ . '/../../database/set_db.php');
require(__DIR__ . '/../../database/db_shops.php');
require(__DIR__ . '/../../database/db_shop_details.php');
require(__DIR__ . '/../../database/db_folders.php');
require(__DIR__ . '/../../database/db_photos.php');
#-------------------------------------------#
#===========================================#
#POSTチェック
# このスクリプトは全店舗の index JSON を再生成するため、引数は不要
#-------------------------------------------#
#店舗情報を取得
$shopsData = getShopList();
#店舗情報が無ければ処理終了
if ($shopsData === null) {
	#店舗情報無し：処理終了
	exit;
}
#-------------------------------------------#
#json保存先
#indexAll.json
require(__DIR__ . '/../../common/define.php');
$saveIndexAllDir = DEFINE_JSON_DIR_PATH . '/shops';
#-------------------------------------------#
#===========================================#
#書き込み用ディレクトリが無い場合はベースディレクトリ作成
#indexAll.json
if (!is_dir($saveIndexAllDir)) {
	@mkdir($saveIndexAllDir, 0777, true);
}
#書き込み用jsonファイルが無い場合はベースファイルを作成
$shopsIndexAllJson = 'shopsIndex.json';
if (!file_exists($saveIndexAllDir . '/' . $shopsIndexAllJson)) {
	makeJson($saveIndexAllDir, $shopsIndexAllJson);
}
#===========================================#
#店舗情報展開
#indexAll.json
if (is_array($shopsData) && count($shopsData) > 0) {
	#jsonデータ生成
	$shopIndexList = [];
	$writeData = [];
	foreach ($shopsData as $shop) {
		#公開中のみ処理
		if ($shop['is_public'] != 1) {
			continue;
		}
		#紹介情報取得
		$shopDetailsData = getShopDetailsData($shop['shop_id']);
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
		#jsonデータ生成
		$writeData = [
			'id' => $sId,
			'slug' => $shopNameEng,
			'category' => $shop['shop_type'] ?? '',
			'name' => formatTextareaForDB((string)($shop['shop_name'] ?? '')),
			'tel' => $shop['tel'] ?? '',
			'thumb' => $shopDetailsData['main_image_path'] ?? '',
			'leadCopy' => formatTextareaForDB((string)($shopDetailsData['intro_body'] ?? '')),
		];
		$shopIndexList[] = $writeData;
	}
	#JSONエンコード
	$news_json = json_encode($shopIndexList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	#ファイル書き込み
	$write_json = fopen($saveIndexAllDir . '/' . $shopsIndexAllJson, "w");
	fwrite($write_json, $news_json);
	fclose($write_json);
	@chmod($saveIndexAllDir . '/' . $shopsIndexAllJson, octdec('0666'));
} else {
	#表示可能リスト無し：空ファイル作成
	makeJson($saveIndexAllDir, $shopsIndexAllJson);
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


#===========================================#
#全JSON生成が完了した「最後」に実行
if (defined('DEFINE_JSON_MIRROR_ENABLE') && DEFINE_JSON_MIRROR_ENABLE) {
	mirrorDbSelectiveMasterByRsync(
		(string)DEFINE_JSON_MIRROR_SRC_DB_DIR,         #ミラー元 /db
		(string)DEFINE_JSON_MIRROR_DEST_DB_DIR,        #ミラー先 /db
		''                                           #旧仕様互換（未使用）
	);
}
#===========================================#
