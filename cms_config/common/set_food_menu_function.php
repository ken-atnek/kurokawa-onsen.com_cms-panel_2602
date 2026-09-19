<?php
/*
 * [食事メニュー共通判定]
 */

/**
 * 保存済みmenuの整数値を厳格に正規化
 *  PDOの整数文字列は受け付け、小数や符号付き文字列は拒否する
 */
function normalizeFoodMenuContractInteger($value)
{
	if (is_int($value)) {
		return $value;
	}
	if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
		return null;
	}
	$result = filter_var($value, FILTER_VALIDATE_INT);
	return $result === false ? null : $result;
}

/**
 * 掲載期間の実在日付を検証
 *  DB値の自動補正は行わない
 */
function isFoodMenuContractDate($value)
{
	if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
		return false;
	}
	$year = (int)substr($value, 0, 4);
	$month = (int)substr($value, 5, 2);
	$day = (int)substr($value, 8, 2);
	return checkdate($month, $day, $year);
}

/**
 * Food Menuの現行application contractを判定
 *  新規保存targetだけは未採番のidを許容する
 */
function isFoodMenuCurrentValid($row, $shopId, $requireId = true)
{
	$shopId = normalizeFoodMenuContractInteger($shopId);
	if (!is_array($row) || $shopId === null || $shopId < 1) {
		return false;
	}
	$id = normalizeFoodMenuContractInteger($row['id'] ?? null);
	if (($requireId && ($id === null || $id < 1)) ||
		normalizeFoodMenuContractInteger($row['shop_id'] ?? null) !== $shopId ||
		normalizeFoodMenuContractInteger($row['is_deleted'] ?? null) !== 0 ||
		!in_array(normalizeFoodMenuContractInteger($row['is_active'] ?? null), [0, 1], true)) {
		return false;
	}
	$name = $row['menu_name'] ?? null;
	$description = $row['description'] ?? null;
	$imagePath = $row['image_path'] ?? null;
	if (!function_exists('mb_check_encoding') || !function_exists('mb_strlen') ||
		!is_string($name) || !mb_check_encoding($name, 'UTF-8') ||
		preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $name) !== 0 || mb_strlen($name, 'UTF-8') > 100 ||
		!is_string($description) || !mb_check_encoding($description, 'UTF-8') ||
		preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $description) !== 0 || strlen($description) > 65535 ||
		!is_string($imagePath) || strlen($imagePath) > 255) {
		return false;
	}
	$imagePattern = '~\A/db/images/shops/' . preg_quote(sprintf('%03d', $shopId), '~') .
		'/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z~iD';
	if (preg_match($imagePattern, $imagePath) !== 1) {
		return false;
	}
	$price = normalizeFoodMenuContractInteger($row['price'] ?? null);
	$periodType = normalizeFoodMenuContractInteger($row['period_type'] ?? null);
	if ($price === null || $price < 1 || $price > 2147483647 ||
		normalizeFoodMenuContractInteger($row['tax_included'] ?? null) !== 1 ||
		!in_array($periodType, [1, 2], true)) {
		return false;
	}
	$start = $row['period_start'] ?? null;
	$end = $row['period_end'] ?? null;
	if ($periodType === 1) {
		return $start === null && $end === null;
	}
	return isFoodMenuContractDate($start) && isFoodMenuContractDate($end) && $start <= $end;
}

/**
 * 予約で利用可能なmenuか判定
 *  呼出元が確定したJST当日を使用し、物理画像は再検証しない
 */
function isFoodMenuUsable($row, $shopId, $today)
{
	if (!isFoodMenuContractDate($today) || !isFoodMenuCurrentValid($row, $shopId) ||
		normalizeFoodMenuContractInteger($row['is_active']) !== 1) {
		return false;
	}
	if (normalizeFoodMenuContractInteger($row['period_type']) === 1) {
		return true;
	}
	return $row['period_start'] <= $today && $today <= $row['period_end'];
}

/**
 * 予約判定に使用する日本時間の当日を返す
 *  DB sessionのtimezoneを日付authorityにしない
 */
function foodMenuTodayJst()
{
	return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}
