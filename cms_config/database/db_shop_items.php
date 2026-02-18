<?php
/*
 * [おすすめ商品情報取得]
 *  引数
 *   $shopId：店舗ID
 */
function getShopItemsData(int $shopId): array
{
	global $DB_CONNECT;
	#まず3枠を必ず用意（DBが空でも画面は3枠出せる）
	$data = [
		1 => defaultShopItemRow($shopId, 'recommended', 1),
		2 => defaultShopItemRow($shopId, 'recommended', 2),
		3 => defaultShopItemRow($shopId, 'recommended', 3),
	];
	try {
		#SQL定義
		$strSQL = "
            SELECT
                shop_id, item_type, slot,
                title, description, price_yen, image_path, image_title, is_active
            FROM shop_items
            WHERE shop_id = :shop_id
            ORDER BY item_type, slot
        ";
		#プリペアードステートメント作成
		$newStmt = $DB_CONNECT->prepare($strSQL);
		#変数バインド
		$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		#SQL実行
		$newStmt->execute();
		#実行結果取得
		$shops = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		#固定3枠の空データを先に用意（フォーム表示が簡単になる）
		$data = [
			'pickup' => [
				1 => defaultShopItemRow($shopId, 'pickup', 1),
				2 => defaultShopItemRow($shopId, 'pickup', 2),
				3 => defaultShopItemRow($shopId, 'pickup', 3),
			],
			'recommended' => [
				1 => defaultShopItemRow($shopId, 'recommended', 1),
				2 => defaultShopItemRow($shopId, 'recommended', 2),
				3 => defaultShopItemRow($shopId, 'recommended', 3),
			],
		];
		#DBにある行で上書き
		foreach ($shops as $r) {
			$type = $r['item_type'];
			$slot = (int)$r['slot'];
			if (!isset($data[$type][$slot])) {
				continue; #想定外データは無視
			}
			#数値系は型を揃える（フォーム・JSON生成で事故りにくい）
			$r['slot'] = $slot;
			$r['is_active'] = (int)$r['is_active'];
			$r['price_yen'] = ($r['price_yen'] === null) ? null : (int)$r['price_yen'];
			$data[$type][$slot] = array_merge($data[$type][$slot], $r);
		}
		return $data;
	} catch (PDOException $e) {
		#本番は echo ではなくログへ
		throw $e;
	}
}
/**
 * 固定枠のデフォルト行
 */
function defaultShopItemRow(int $shopId, string $type, int $slot): array
{
	return [
		'shop_id'      => $shopId,
		'item_type'    => $type,
		'slot'         => $slot,
		'title'        => null,
		'description'  => null,
		'price_yen'    => null,
		'image_path'   => null,
		'image_title'  => null,
		'is_active'    => 1,
	];
}


function hasRecommendedItems(int $shopId): bool
{
	global $DB_CONNECT;
	#SQL定義
	$strSQL = "
        SELECT 1
        FROM shop_items
        WHERE shop_id = :shop_id
          AND item_type = 'recommended'
          AND is_active = 1
          AND (
               (title IS NOT NULL AND title <> '')
            OR (description IS NOT NULL AND description <> '')
            OR  price_yen IS NOT NULL
            OR (image_path IS NOT NULL AND image_path <> '')
            OR (image_title IS NOT NULL AND image_title <> '')
          )
        LIMIT 1
    ";
	#プリペアードステートメント作成
	$newStmt = $DB_CONNECT->prepare($strSQL);
	#変数バインド
	$newStmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
	#SQL実行
	$newStmt->execute();
	#実行結果取得
	$exists = (bool)$newStmt->fetchColumn();
	#ステートメントクローズ
	$newStmt->closeCursor();
	#応答
	return $exists;
}
