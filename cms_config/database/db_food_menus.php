<?php
/*
 * [メニュー情報設定]
 */
require_once __DIR__ . '/../common/set_food_menu_function.php';
/**
 * 予約受付判定用の利用可能メニュー件数取得
 *  既存契約どおり取得失敗時は0を返す
 */
function countActiveFoodMenusForReservation($shopId = null)
{
	$menus = getActiveFoodMenusForReservationForm($shopId);
	return $menus === false ? 0 : count($menus);
}

/**
 * Web予約受付判定用の有効メニュー件数取得
 *  正常な0件とDBエラーを区別するため、DBエラー時はfalseを返す
 */
function getActiveFoodMenuCountForReservation($shopId = null)
{
	$menus = getActiveFoodMenusForReservationForm($shopId);
	return $menus === false ? false : count($menus);
}

/**
 * 予約追加画面用の有効メニュー一覧取得
 *  対象店舗のcurrent-validなusable menuだけを表示順で返す
 */
function getActiveFoodMenusForReservationForm($shopId = null)
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1) {
			return false;
		}

		$today = foodMenuTodayJst();
		$strSQL = "
			SELECT
				id,
				shop_id,
				menu_name,
				price,
				tax_included,
				image_path,
				description,
				period_type,
				period_start,
				period_end,
				is_active,
				is_deleted
			FROM
				food_menus
			WHERE
				shop_id = :shop_id
				AND is_deleted = 0
				AND is_active = 1
			ORDER BY
				sort_order ASC,
				id ASC
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		if ($newStmt->execute() !== true) {
			return false;
		}
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();
		if (!is_array($rows)) {
			return false;
		}
		$menus = [];
		foreach ($rows as $row) {
			if (isFoodMenuUsable($row, (int)$shopId, $today)) {
				$menus[] = ['id' => $row['id'], 'menu_name' => $row['menu_name']];
			}
		}
		return $menus;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約メニューJSON用の非削除menu行を取得
 *  current-valid判定は呼出元で行い、不正な旧行だけを除外できるようraw行を返す。
 */
function getFoodMenuRowsForReservationJson($shopId)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT id, shop_id, menu_name, price, tax_included, image_path, description, period_type, period_start, period_end, is_active, is_deleted FROM food_menus WHERE shop_id = :shop_id AND is_deleted = 0 ORDER BY sort_order ASC, id ASC');
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		return is_array($rows) ? $rows : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 予約登録用メニューmaster一覧取得
 *  対象shop所有のmenu IDのみ取得し、業務上の有効判定はcommon側で行う
 */
function getFoodMenuRowsForReservation($shopId = null, $menuIds = [])
{
	global $DB_CONNECT;
	try {
		if ($shopId === null || is_numeric($shopId) === false || (int)$shopId < 1 || is_array($menuIds) === false || empty($menuIds) === true) {
			return false;
		}

		$normalizedMenuIds = [];
		foreach (array_values($menuIds) as $menuId) {
			if (is_int($menuId) === true) {
				$normalizedMenuId = $menuId;
			} elseif (is_string($menuId) === true && ctype_digit($menuId) === true) {
				$normalizedMenuId = (int)$menuId;
			} else {
				return false;
			}
			if ($normalizedMenuId < 1) {
				return false;
			}
			$normalizedMenuIds[$normalizedMenuId] = $normalizedMenuId;
		}
		if (empty($normalizedMenuIds) === true) {
			return false;
		}
		$normalizedMenuIds = array_values($normalizedMenuIds);

		$placeholders = [];
		foreach ($normalizedMenuIds as $index => $menuId) {
			$placeholders[] = ':menu_id_' . $index;
		}

		$strSQL = "
			SELECT
				id,
				shop_id,
				menu_name,
				price,
				tax_included,
				image_path,
				description,
				period_type,
				period_start,
				period_end,
				is_active,
				is_deleted
			FROM
				food_menus
			WHERE
				shop_id = :shop_id
				AND id IN (" . implode(',', $placeholders) . ")
		";

		$newStmt = $DB_CONNECT->prepare($strSQL);
		$newStmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		foreach ($normalizedMenuIds as $index => $menuId) {
			$newStmt->bindValue(':menu_id_' . $index, $menuId, PDO::PARAM_INT);
		}
		$newStmt->execute();
		$rows = $newStmt->fetchAll(PDO::FETCH_ASSOC);
		$newStmt->closeCursor();

		$menusById = [];
		foreach ($rows ?: [] as $row) {
			$menusById[(int)$row['id']] = $row;
		}
		return $menusById;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * food menuのDB整数を厳格に正規化
 *  不正masterを管理画面で黙って補正しない
 */
function normalizeFoodMenuStoredInteger($value)
{
	if (is_int($value)) {
		return $value;
	}
	if (!is_string($value) || preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value) !== 1) {
		return null;
	}
	$result = filter_var($value, FILTER_VALIDATE_INT);
	return $result === false ? null : $result;
}

/**
 * 管理用master行をcanonical型へ変換
 *  旧データの画像・説明文NULLは表示用に保持する
 */
function normalizeFoodMenuManagementRow($row)
{
	if (!is_array($row) || !is_string($row['menu_name'] ?? null) || !function_exists('mb_check_encoding')) {
		return false;
	}
	$integers = [];
	foreach (['id', 'shop_id', 'price', 'tax_included', 'period_type', 'is_active', 'is_deleted', 'sort_order'] as $key) {
		$integers[$key] = normalizeFoodMenuStoredInteger($row[$key] ?? null);
		if ($integers[$key] === null) {
			return false;
		}
	}
	if ($integers['id'] < 1 || $integers['shop_id'] < 1 || $integers['sort_order'] < 0 ||
		!in_array($integers['tax_included'], [0, 1], true) || !in_array($integers['period_type'], [1, 2], true) ||
		!in_array($integers['is_active'], [0, 1], true) || $integers['is_deleted'] !== 0 ||
		!mb_check_encoding($row['menu_name'], 'UTF-8')) {
		return false;
	}
	$image = $row['image_path'] ?? null;
	$description = $row['description'] ?? null;
	$start = $row['period_start'] ?? null;
	$end = $row['period_end'] ?? null;
	if (($image !== null && (!is_string($image) || !mb_check_encoding($image, 'UTF-8'))) ||
		($description !== null && (!is_string($description) || !mb_check_encoding($description, 'UTF-8'))) ||
		($start !== null && !is_string($start)) || ($end !== null && !is_string($end))) {
		return false;
	}
	return [
		'id' => $integers['id'], 'shop_id' => $integers['shop_id'], 'menu_name' => $row['menu_name'],
		'price' => $integers['price'], 'tax_included' => $integers['tax_included'], 'image_path' => $image,
		'description' => $description, 'period_type' => $integers['period_type'], 'period_start' => $start,
		'period_end' => $end, 'is_active' => $integers['is_active'], 'is_deleted' => 0,
		'sort_order' => $integers['sort_order'],
	];
}

/**
 * 管理画面の非削除menu全件取得
 *  無効・掲載期間外も表示対象とする
 */
function getFoodMenusForManagement($shopId)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT id, shop_id, menu_name, price, tax_included, image_path, description, period_type, period_start, period_end, is_active, is_deleted, sort_order FROM food_menus WHERE shop_id = :shop_id AND is_deleted = 0 ORDER BY sort_order ASC, id ASC');
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		if (!is_array($rows)) {
			return false;
		}
		$result = [];
		foreach ($rows as $row) {
			$menu = normalizeFoodMenuManagementRow($row);
			if ($menu === false || $menu['shop_id'] !== $shopId) {
				return false;
			}
			$result[] = $menu;
		}
		return $result;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 管理対象menuを所有店舗・非削除条件で1件取得
 *  不在はnull、DB不良はfalseとする
 */
function getFoodMenuForManagement($shopId, $menuId)
{
	global $DB_CONNECT;
	if (!is_int($shopId) || $shopId < 1 || !is_int($menuId) || $menuId < 1) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare('SELECT id, shop_id, menu_name, price, tax_included, image_path, description, period_type, period_start, period_end, is_active, is_deleted, sort_order FROM food_menus WHERE id = :id AND shop_id = :shop_id AND is_deleted = 0 LIMIT 1');
		$stmt->bindValue(':id', $menuId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		return $row === false ? null : normalizeFoodMenuManagementRow($row);
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 非削除menuの同名をfresh DBで確認
 *  falseは重複なし、nullは取得失敗とする
 */
function hasFoodMenuManagementDuplicateName($shopId, $name, $excludeId = null)
{
	global $DB_CONNECT;
	try {
		$sql = 'SELECT id FROM food_menus WHERE shop_id = :shop_id AND is_deleted = 0 AND menu_name = :menu_name';
		if ($excludeId !== null) {
			$sql .= ' AND id <> :exclude_id';
		}
		$sql .= ' LIMIT 1';
		$stmt = $DB_CONNECT->prepare($sql);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$stmt->bindValue(':menu_name', $name, PDO::PARAM_STR);
		if ($excludeId !== null) {
			$stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
		}
		if ($stmt->execute() !== true) {
			return null;
		}
		$found = $stmt->fetch(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		return $found !== false;
	} catch (Throwable $e) {
		return null;
	}
}

/**
 * menu rowのcanonical stateから更新versionを作成
 *  updated_atの秒精度へ依存しない
 */
function buildFoodMenuManagementVersion($menu)
{
	$menu = normalizeFoodMenuManagementRow($menu);
	if ($menu === false) {
		return false;
	}
	$json = json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	return is_string($json) ? hash('sha256', $json) : false;
}

/**
 * 非削除menu全件の並び順versionを生成
 *  sort_order・id順に正規化済みの一覧を受け取る
 */
function buildFoodMenuManagementOrderVersion($menus)
{
	if (!is_array($menus) || !array_is_list($menus)) {
		return false;
	}
	$state = [];
	$seen = [];
	foreach ($menus as $row) {
		$menu = normalizeFoodMenuManagementRow($row);
		if ($menu === false || isset($seen[$menu['id']])) {
			return false;
		}
		$seen[$menu['id']] = true;
		$state[] = [$menu['id'], $menu['sort_order']];
	}
	$json = json_encode($state);
	return is_string($json) ? hash('sha256', $json) : false;
}

/**
 * 非削除menuを末尾順で作成
 *  呼出元procがshops mutexとtransactionを所有する
 */
function insertFoodMenuForManagement($shopId, $data)
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('INSERT INTO food_menus (shop_id, menu_name, price, tax_included, image_path, description, period_type, period_start, period_end, is_active, is_deleted, sort_order) VALUES (:shop_id, :menu_name, :price, 1, :image_path, :description, :period_type, :period_start, :period_end, :is_active, 0, :sort_order)');
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		foreach (['menu_name' => PDO::PARAM_STR, 'price' => PDO::PARAM_INT, 'image_path' => PDO::PARAM_STR, 'description' => PDO::PARAM_STR, 'period_type' => PDO::PARAM_INT, 'period_start' => PDO::PARAM_STR, 'period_end' => PDO::PARAM_STR, 'is_active' => PDO::PARAM_INT, 'sort_order' => PDO::PARAM_INT] as $field => $type) {
			$stmt->bindValue(':' . $field, $data[$field], $data[$field] === null ? PDO::PARAM_NULL : $type);
		}
		$ok = $stmt->execute();
		$stmt->closeCursor();
		return $ok === true && (int)$DB_CONNECT->lastInsertId() > 0;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * 変更されたmenu列だけを更新
 *  同値時は呼出元がこのhelperを呼ばない
 */
function updateFoodMenuForManagement($shopId, $menuId, $changes)
{
	global $DB_CONNECT;
	$allowed = ['menu_name' => PDO::PARAM_STR, 'price' => PDO::PARAM_INT, 'tax_included' => PDO::PARAM_INT, 'image_path' => PDO::PARAM_STR, 'description' => PDO::PARAM_STR, 'period_type' => PDO::PARAM_INT, 'period_start' => PDO::PARAM_STR, 'period_end' => PDO::PARAM_STR, 'is_active' => PDO::PARAM_INT];
	if (!is_array($changes) || $changes === [] || array_diff(array_keys($changes), array_keys($allowed)) !== []) {
		return false;
	}
	if (array_key_exists('tax_included', $changes) && $changes['tax_included'] !== 1) {
		return false;
	}
	try {
		$sets = [];
		foreach ($changes as $field => $value) {
			$sets[] = $field . ' = :' . $field;
		}
		$stmt = $DB_CONNECT->prepare('UPDATE food_menus SET ' . implode(', ', $sets) . ' WHERE id = :id AND shop_id = :shop_id AND is_deleted = 0');
		foreach ($changes as $field => $value) {
			$stmt->bindValue(':' . $field, $value, $value === null ? PDO::PARAM_NULL : $allowed[$field]);
		}
		$stmt->bindValue(':id', $menuId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true && $count === 1;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * menuの表示順だけを更新
 *  変更対象外の行をwriteしない
 */
function updateFoodMenuSortOrderForManagement($shopId, $menuId, $order)
{
	global $DB_CONNECT;
	try {
		$stmt = $DB_CONNECT->prepare('UPDATE food_menus SET sort_order = :sort_order WHERE id = :id AND shop_id = :shop_id AND is_deleted = 0');
		$stmt->bindValue(':sort_order', $order, PDO::PARAM_INT);
		$stmt->bindValue(':id', $menuId, PDO::PARAM_INT);
		$stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true && $count === 1;
	} catch (Throwable $e) {
		return false;
	}
}
