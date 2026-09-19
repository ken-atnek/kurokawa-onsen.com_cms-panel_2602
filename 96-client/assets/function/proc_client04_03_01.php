<?php
/*
 * [96-client/assets/function/proc_client04_03_01.php]
 *  食事メニュー登録・編集・単一画像draft
 */

require_once dirname(__DIR__, 3) . '/cms_config/common/define.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_food_menu_temp_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_csrf_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_reservation_settings.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_food_menus.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

header('Content-Type: application/json; charset=UTF-8');

/** Food MenuのJSON responseを生成する。 */
function foodMenuFormResponse($status, $title, $msg, $extra = [])
{
	return array_merge([
		'tag' => '',
		'status' => $status,
		'title' => $title,
		'msg' => $msg,
		'requiresConfirmation' => false,
		'warningCodes' => [],
		'postCommitWarnings' => [],
	], $extra);
}
/** transaction開始前の失敗を返す。 */
function foodMenuFormExit($title, $msg)
{
	echo json_encode(foodMenuFormResponse('error', $title, $msg), JSON_UNESCAPED_UNICODE);
	exit;
}
/** HTTP由来のASCII正整数だけを受け付ける。 */
function foodMenuFormPositiveInteger($value)
{
	if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
		return null;
	}
	$number = filter_var($value, FILTER_VALIDATE_INT);
	return $number === false || $number < 1 ? null : $number;
}
/** 実在する厳格なYYYY-MM-DDだけを受け付ける。 */
function foodMenuFormDate($value)
{
	if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
		return false;
	}
	$year = (int)substr($value, 0, 4);
	$month = (int)substr($value, 5, 2);
	$day = (int)substr($value, 8, 2);
	if (!checkdate($month, $day, $year)) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
	return $date !== false && $date->format('Y-m-d') === $value;
}
/** 必須のUTF-8文字列を表記を変えずに検証する。 */
function foodMenuFormText($value, $maxCharacters = null, $maxBytes = null)
{
	if (
		!is_string($value) || preg_match('/\A[\s\p{Z}\x{FEFF}]*\z/uD', $value) !== 0 ||
		!function_exists('mb_strlen')
	) {
		return false;
	}
	return ($maxCharacters === null || mb_strlen($value, 'UTF-8') <= $maxCharacters) &&
		($maxBytes === null || strlen($value) <= $maxBytes);
}
/** actionごとの完全なPOST shapeと値を検証する。 */
function normalizeFoodMenuFormRequest($post, $files)
{
	if (!is_array($post) || !is_array($files) || !is_string($post['action'] ?? null)) {
		return false;
	}
	$action = $post['action'];
	$common = ['noUpDateKey', 'csrfToken', 'action'];
	$business = [
		'menuName',
		'menuPrice',
		'menuDescription',
		'menuPublicationPeriod',
		'menuPublicationStartDay',
		'menuPublicationEndDay',
		'menuIsActive',
		'imageMode'
	];
	$fields = [
		'uploadTempImage' => [],
		'discardTempImage' => [],
		'abandonTempImage' => [],
		'create' => $business,
		'update' => array_merge(['menuId', 'menuVersion'], $business),
	];
	if (!isset($fields[$action])) {
		return false;
	}
	$allowed = array_merge($common, $fields[$action]);
	if (count($post) !== count($allowed) || array_diff(array_keys($post), $allowed) !== []) {
		return false;
	}
	foreach ($allowed as $field) {
		if (!is_string($post[$field] ?? null)) {
			return false;
		}
	}
	if ($post['noUpDateKey'] === '' || $post['csrfToken'] === '') {
		return false;
	}
	if ($action === 'uploadTempImage') {
		if (count($files) !== 1 || !array_key_exists('menuImage', $files) || !is_array($files['menuImage'])) {
			return false;
		}
	} elseif ($files !== []) {
		return false;
	}
	$result = ['action' => $action, 'key' => $post['noUpDateKey'], 'csrf' => $post['csrfToken']];
	if ($action === 'update') {
		$result['id'] = foodMenuFormPositiveInteger($post['menuId']);
		$result['version'] = $post['menuVersion'];
		if ($result['id'] === null || preg_match('/\A[0-9a-f]{64}\z/D', $result['version']) !== 1) {
			return false;
		}
	}
	if ($action !== 'create' && $action !== 'update') {
		return $result;
	}
	$price = foodMenuFormPositiveInteger($post['menuPrice']);
	$period = $post['menuPublicationPeriod'];
	$mode = $post['imageMode'];
	if (
		!foodMenuFormText($post['menuName'], 100) || !foodMenuFormText($post['menuDescription'], null, 65535) ||
		$price === null || $price > 2147483647 ||
		!in_array($period, ['unlimited', 'custom'], true) ||
		!in_array($post['menuIsActive'], ['0', '1'], true) ||
		!in_array($mode, ['keep', 'replace', 'remove'], true) ||
		($action === 'create' && $mode !== 'replace') ||
		($action === 'update' && $mode === 'remove')
	) {
		return false;
	}
	if ($period === 'custom' && (!foodMenuFormDate($post['menuPublicationStartDay']) ||
		!foodMenuFormDate($post['menuPublicationEndDay']) ||
		$post['menuPublicationStartDay'] > $post['menuPublicationEndDay'])) {
		return false;
	}
	$result['image_mode'] = $mode;
	$result['target'] = [
		'menu_name' => $post['menuName'],
		'price' => $price,
		'description' => $post['menuDescription'],
		'period_type' => $period === 'unlimited' ? 1 : 2,
		'period_start' => $period === 'unlimited' ? null : $post['menuPublicationStartDay'],
		'period_end' => $period === 'unlimited' ? null : $post['menuPublicationEndDay'],
		'is_active' => (int)$post['menuIsActive'],
	];
	return $result;
}
/** 拡張子・MIME・実画像type・容量の一致を確認する。 */
function foodMenuImageIsValid($path, $extension)
{
	$types = [
		'jpg' => ['image/jpeg', IMAGETYPE_JPEG],
		'jpeg' => ['image/jpeg', IMAGETYPE_JPEG],
		'png' => ['image/png', IMAGETYPE_PNG],
		'webp' => ['image/webp', IMAGETYPE_WEBP]
	];
	if (!isset($types[$extension]) || !is_file($path) || is_link($path)) {
		return false;
	}
	$size = filesize($path);
	if (!is_int($size) || $size < 1 || $size > 5 * 1024 * 1024) {
		return false;
	}
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$image = @getimagesize($path);
	return $finfo->file($path) === $types[$extension][0] &&
		is_array($image) && ($image[2] ?? null) === $types[$extension][1];
}
/** この画面のdraftが既定のtmp_upload内にあることを確認する。 */
function foodMenuDraftPath($draft)
{
	return foodMenuTempDraftPath($draft);
}
/** SESSIONで所有するdraftだけを削除する。 */
function foodMenuDiscardDraft($key)
{
	foodMenuRemoveSessionDraft($key);
}
/** 新画像を公開画像directoryへcopyし、生成pathを返す。 */
function foodMenuCopyFinalImage($shopId, $draft)
{
	$temp = foodMenuDraftPath($draft);
	if ($temp === false || !foodMenuImageIsValid($temp, $draft['extension'])) {
		return false;
	}
	$shopFolder = sprintf('%03d', $shopId);
	$root = rtrim(DEFINE_FILE_DIR_PATH, '/\\');
	$directory = $root . DIRECTORY_SEPARATOR . $shopFolder;
	if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
		return false;
	}
	$filename = 'food-menu-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('YmdHis') .
		'-' . bin2hex(random_bytes(16)) . '.' . $draft['extension'];
	$target = $directory . DIRECTORY_SEPARATOR . $filename;
	if (file_exists($target)) {
		return false;
	}
	if (!copy($temp, $target)) {
		if (is_file($target) && !is_link($target)) {
			@unlink($target);
		}
		return false;
	}
	return ['path' => $target, 'url' => '/db/images/shops/' . $shopFolder . '/' . $filename];
}
/** 旧画像は同一店舗の公開directory内の正規fileだけ削除する。 */
function foodMenuDeleteOldImage($shopId, $url)
{
	$prefix = '/db/images/shops/' . sprintf('%03d', $shopId) . '/';
	if (!is_string($url) || !str_starts_with($url, $prefix)) {
		return;
	}
	$name = substr($url, strlen($prefix));
	if (
		preg_match('/\A[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)\z/iD', $name) !== 1 ||
		$name === '.' || $name === '..'
	) {
		return;
	}
	$directory = realpath(rtrim(DEFINE_FILE_DIR_PATH, '/\\') . DIRECTORY_SEPARATOR . sprintf('%03d', $shopId));
	if ($directory === false) {
		return;
	}
	$file = $directory . DIRECTORY_SEPARATOR . $name;
	if (is_link($file) || !is_file($file) || realpath($file) !== $file) {
		return;
	}
	if (!@unlink($file)) {
		try {
			makeLog(['pageName' => 'proc_client04_03_01', 'reason' => '旧画像の削除失敗']);
		} catch (Throwable $e) {
			// Image cleanup must not turn a committed save into an error.
		}
	}
}
/** fresh settingsと利用可能menu件数から保存後warningを決める。 */
function foodMenuFormPostCommitWarnings($shopId)
{
	$raw = getShopReservationSettings($shopId);
	if ($raw === false) {
		return false;
	}
	if ($raw === null) {
		return [];
	}
	$settings = normalizeShopReservationSettingsData($raw);
	if ($settings === false) {
		return false;
	}
	if ($settings['menu_selection_type'] !== 2) {
		return [];
	}
	$count = getActiveFoodMenuCountForReservation($shopId);
	return $count === false ? false : ($count === 0 ? ['menu_required_no_active_menu'] : []);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	foodMenuFormExit('送信エラー', '送信方法が正しくありません。');
}
$request = normalizeFoodMenuFormRequest($_POST, $_FILES);
if ($request === false) {
	foodMenuFormExit('入力エラー', '送信された項目が正しくありません。');
}
$shopRaw = $_SESSION['client_login']['shop_id'] ?? null;
$shopId = is_int($shopRaw) ? $shopRaw : foodMenuFormPositiveInteger($shopRaw);
$instance = $_SESSION[$request['key']] ?? null;
if (
	!is_int($shopId) || $shopId < 1 || ($_SESSION['sKey'] ?? null) !== $request['key'] || !is_array($instance) ||
	($instance['foodMenuPage'] ?? null) !== 'form' || ($instance['shopId'] ?? null) !== $shopId ||
	($instance['clientKey'] ?? null) !== ($_SESSION['client_login']['account_id'] ?? null)
) {
	foodMenuFormExit('セッションエラー', 'ページを再読み込みしてください。');
}
if (validateClientCsrfToken($request['csrf']) !== true) {
	foodMenuFormExit('セッションエラー', 'ページを再読み込みしてください。');
}
$key = $request['key'];
if ($request['action'] === 'abandonTempImage') {
	$_SESSION[$key]['foodMenuAbandoned'] = true;
	foodMenuDiscardDraft($key);
	echo json_encode(foodMenuFormResponse('success', '画像', '一時画像を破棄しました。'), JSON_UNESCAPED_UNICODE);
	exit;
}
if ($request['action'] === 'discardTempImage') {
	foodMenuDiscardDraft($key);
	echo json_encode(foodMenuFormResponse('success', '画像', '一時画像を破棄しました。'), JSON_UNESCAPED_UNICODE);
	exit;
}
if ($request['action'] === 'uploadTempImage') {
	if (($_SESSION[$key]['foodMenuAbandoned'] ?? false) === true) {
		foodMenuFormExit('画像エラー', 'この画面では画像を保存できません。ページを再読み込みしてください。');
	}
	$file = $_FILES['menuImage'];
	$name = $file['name'] ?? null;
	$extension = is_string($name) ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : '';
	if (
		!is_string($name) || !is_string($file['tmp_name'] ?? null) ||
		($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($file['size'] ?? null) ||
		$file['size'] < 1 || $file['size'] > 5 * 1024 * 1024 ||
		!is_uploaded_file($file['tmp_name']) || !foodMenuImageIsValid($file['tmp_name'], $extension)
	) {
		foodMenuFormExit('画像エラー', 'JPG・PNG・WebPの5MB以下の画像を選択してください。');
	}
	$dir = __DIR__ . '/../../../tmp_upload';
	if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
		foodMenuFormExit('画像エラー', '画像を保存できませんでした。');
	}
	$filename = 'food-menu-' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('YmdHis') .
		'-' . bin2hex(random_bytes(16)) . '.' . $extension;
	$path = realpath($dir) . DIRECTORY_SEPARATOR . $filename;
	if (file_exists($path) || !move_uploaded_file($file['tmp_name'], $path)) {
		foodMenuFormExit('画像エラー', '画像を保存できませんでした。');
	}
	$oldDraft = $_SESSION['food_menu_image_drafts'][$key] ?? null;
	$_SESSION['food_menu_image_drafts'][$key] = [
		'path' => $path,
		'name' => $filename,
		'extension' => $extension,
		'mime' => (new finfo(FILEINFO_MIME_TYPE))->file($path),
		'size' => filesize($path),
	];
	$oldPath = foodMenuDraftPath($oldDraft);
	if ($oldPath !== false && $oldPath !== $path) {
		@unlink($oldPath);
	}
	echo json_encode(foodMenuFormResponse('success', '画像', '画像を受け付けました。', [
		'file_url' => '../tmp_upload/' . $filename,
		'file_name' => $filename,
	]), JSON_UNESCAPED_UNICODE);
	exit;
}
$draft = $_SESSION['food_menu_image_drafts'][$key] ?? null;
$draftPath = foodMenuDraftPath($draft);
if (($request['image_mode'] === 'replace' && ($draftPath === false ||
		!foodMenuImageIsValid($draftPath, $draft['extension']))) ||
	($request['image_mode'] === 'keep' && $draft !== null)
) {
	foodMenuFormExit('画像エラー', '画像の状態が正しくありません。画像を選び直してください。');
}
$transactionStarted = false;
$committed = false;
$wasEnabled = false;
$newFinal = null;
$oldImage = null;
$newMenuId = null;
$warningEvaluationFailed = false;
$response = foodMenuFormResponse('error', '保存エラー', '処理できませんでした。ページを再読み込みしてください。');
try {
	if (DB_Transaction(1) !== true) {
		throw new RuntimeException('begin_failed');
	}
	$transactionStarted = true;
	$shop = getReservationShopForUpdate($shopId);
	if (!is_array($shop) || (int)($shop['shop_id'] ?? 0) !== $shopId) {
		throw new RuntimeException('shop_lock_failed');
	}
	$wasEnabled = isReservationEnabledForShop($shopId);
	$target = $request['target'];
	$target['tax_included'] = 1;
	if ($request['action'] === 'update') {
		$current = getFoodMenuForManagement($shopId, $request['id']);
		if ($current === false) {
			throw new RuntimeException('menu_read_failed');
		}
		if ($current === null) {
			$response = foodMenuFormResponse('error', '更新エラー', 'メニューが見つかりません。ページを再読み込みしてください。');
			throw new RuntimeException('menu_missing');
		}
		$version = buildFoodMenuManagementVersion($current);
		if ($version === false || !hash_equals($version, $request['version'])) {
			$response = foodMenuFormResponse('error', '更新エラー', 'メニューの情報が変更されています。ページを再読み込みしてください。');
			throw new RuntimeException('menu_stale');
		}
		if ($request['image_mode'] === 'keep' && (!is_string($current['image_path']) || $current['image_path'] === '')) {
			$response = foodMenuFormResponse('error', '画像エラー', '画像がありません。画像を選択してください。');
			throw new RuntimeException('current_image_missing');
		}
	}
	$duplicate = hasFoodMenuManagementDuplicateName($shopId, $target['menu_name'], $request['id'] ?? null);
	if ($duplicate === null) {
		throw new RuntimeException('duplicate_read_failed');
	}
	if ($duplicate === true) {
		$response = foodMenuFormResponse('error', '入力エラー', '同じ名前のメニューが既にあります。');
		throw new RuntimeException('duplicate_name');
	}
	if ($request['image_mode'] === 'replace') {
		$newFinal = foodMenuCopyFinalImage($shopId, $draft);
		if ($newFinal === false) {
			$newFinal = null;
			throw new RuntimeException('final_copy_failed');
		}
		$target['image_path'] = $newFinal['url'];
	} else {
		$target['image_path'] = $current['image_path'];
	}
	$validationTarget = $target;
	$validationTarget['shop_id'] = $shopId;
	$validationTarget['is_deleted'] = 0;
	if ($request['action'] === 'update') {
		$validationTarget['id'] = $current['id'];
	}
	if (!isFoodMenuCurrentValid($validationTarget, $shopId, $request['action'] === 'update')) {
		$response = foodMenuFormResponse('error', '入力エラー', 'メニューの内容を確認してください。');
		throw new RuntimeException('menu_invalid');
	}
	if ($request['action'] === 'create') {
		$menus = getFoodMenusForManagement($shopId);
		if ($menus === false) {
			throw new RuntimeException('menu_list_failed');
		}
		$maxOrder = 0;
		foreach ($menus as $menu) {
			$maxOrder = max($maxOrder, $menu['sort_order']);
		}
		if ($maxOrder >= 2147483647) {
			throw new RuntimeException('sort_order_overflow');
		}
		$target['sort_order'] = $maxOrder + 1;
		if (insertFoodMenuForManagement($shopId, $target) !== true) {
			throw new RuntimeException('menu_insert_failed');
		}
		$newMenuId = (int)$DB_CONNECT->lastInsertId();
	} else {
		$changes = [];
		foreach ($target as $field => $value) {
			if ($current[$field] !== $value) {
				$changes[$field] = $value;
			}
		}
		if ($changes !== [] && updateFoodMenuForManagement($shopId, $request['id'], $changes) !== true) {
			throw new RuntimeException('menu_update_failed');
		}
		if ($newFinal !== null) {
			$oldImage = $current['image_path'];
		}
	}
	try {
		$warnings = foodMenuFormPostCommitWarnings($shopId);
	} catch (Throwable $warningError) {
		$warnings = false;
	}
	if ($warnings === false) {
		$warningEvaluationFailed = true;
		$warnings = [];
	}
	if (DB_Transaction(2) !== true) {
		throw new RuntimeException('commit_failed');
	}
	$transactionStarted = false;
	$committed = true;
	$response = foodMenuFormResponse('success', '食事メニュー管理', '保存しました。', ['postCommitWarnings' => $warnings, 'menuId' => $request['action'] === 'update' ? $request['id'] : $newMenuId]);
} catch (Throwable $e) {
	$rollbackConfirmed = !$transactionStarted;
	if ($transactionStarted && is_object($DB_CONNECT) && method_exists($DB_CONNECT, 'inTransaction') && $DB_CONNECT->inTransaction()) {
		try {
			$rollbackConfirmed = DB_Transaction(3) === true;
			if (!$rollbackConfirmed) {
				$response = foodMenuFormResponse('error', '保存エラー', '処理結果を確認できません。ページを再読み込みしてください。');
			}
		} catch (Throwable $rollbackError) {
			$response = foodMenuFormResponse('error', '保存エラー', '処理結果を確認できません。ページを再読み込みしてください。');
		}
	} elseif ($transactionStarted) {
		$response = foodMenuFormResponse('error', '保存エラー', '処理結果を確認できません。ページを再読み込みしてください。');
	}
	if ($rollbackConfirmed && $newFinal !== null && is_file($newFinal['path'])) {
		@unlink($newFinal['path']);
	}
}

if ($committed) {
	if ($warningEvaluationFailed) {
		try {
			makeLog(['pageName' => 'proc_client04_03_01', 'reason' => '食事メニュー保存後警告の判定失敗']);
		} catch (Throwable $logError) {
			// Committed save remains successful even if logging fails.
		}
	}
	if ($oldImage !== null) {
		foodMenuDeleteOldImage($shopId, $oldImage);
	}
	if ($request['image_mode'] === 'replace') {
		foodMenuDiscardDraft($key);
	}
	syncFrontendReservationBaseJson($response, $shopId);
	syncFrontendReservationAvailabilityMonthsJson($response, $shopId, $wasEnabled, false);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
