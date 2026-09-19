<?php
require_once __DIR__ . '/jsonExportCommon.php';
require_once __DIR__ . '/../../common/define.php';
require_once __DIR__ . '/../../common/set_function.php';
require_once __DIR__ . '/../../common/set_food_menu_function.php';
require_once __DIR__ . '/../../common/set_reservation_function.php';
require_once __DIR__ . '/../../database/set_db.php';
require_once __DIR__ . '/../../database/db_shops.php';
require_once __DIR__ . '/../../database/db_reservation_settings.php';
require_once __DIR__ . '/../../database/db_food_menus.php';
require_once __DIR__ . '/../../database/db_seats.php';
require_once __DIR__ . '/../../database/db_reservation_calender.php';
require_once __DIR__ . '/../../database/db_reservations.php';
require_once __DIR__ . '/../../database/db_reservation_json_queue.php';

/**
 * [予約JSON] 店舗別予約JSONディレクトリパス生成
 *  フロント公開db配下の reservations/{3桁shop_id} を返す。
 */
function buildReservationJsonDir($shopId)
{
	$shopId = (int)$shopId;
	if ($shopId < 1) {
		return false;
	}
	return rtrim(DEFINE_JSON_DIR_PATH, '/\\') . '/shops/reservations/' . sprintf('%03d', $shopId);
}

/**
 * [予約JSON] ISO8601更新日時生成
 *  JSON出力時点のAsia/Tokyo日時を固定形式で返す。
 */
function buildReservationJsonUpdatedAt()
{
	return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DateTimeInterface::ATOM);
}

/**
 * [予約JSON] ファイル別の書き出しロック取得
 *  DB読取からrenameまで固定lockファイルを保持する。
 */
function lockReservationJsonFile($shopId, $fileName)
{
	if (!in_array($fileName, ['basic.json', 'menus.json'], true) &&
		preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\.json\z/D', $fileName) !== 1) {
		return false;
	}
	$dir = buildReservationJsonDir($shopId);
	if ($dir === false || (!is_dir($dir) && @mkdir($dir, 0777, true) === false && !is_dir($dir))) {
		return false;
	}
	$lock = @fopen($dir . '/' . $fileName . '.lock', 'c');
	if ($lock === false) {
		return false;
	}
	if (flock($lock, LOCK_EX) !== true) {
		fclose($lock);
		return false;
	}
	return $lock;
}

/**
 * [予約JSON] atomic JSON書き込み
 *  一時ファイルへ出力後renameし、不完全なJSON露出を避ける。
 */
function writeReservationJsonFile($shopId, $fileName, array $writeData)
{
	if (is_string($fileName) === false || preg_match('/\A[A-Za-z0-9._-]+\.json\z/D', $fileName) !== 1) {
		return false;
	}
	$dir = buildReservationJsonDir($shopId);
	if ($dir === false) {
		return false;
	}
	if (!is_dir($dir) && @mkdir($dir, 0777, true) === false && !is_dir($dir)) {
		return false;
	}
	$json = json_encode($writeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		return false;
	}
	$filePath = $dir . '/' . $fileName;
	$tempPath = $dir . '/.' . $fileName . '.' . bin2hex(random_bytes(8)) . '.tmp';
	if (file_put_contents($tempPath, $json, LOCK_EX) === false) {
		@unlink($tempPath);
		return false;
	}
	@chmod($tempPath, octdec('0666'));
	if (rename($tempPath, $filePath) === false) {
		@unlink($tempPath);
		return false;
	}
	@chmod($filePath, octdec('0666'));
	return true;
}

/**
 * [予約JSON] 予約基本設定JSON生成
 *  settings行が存在する店舗のみbasic.jsonを書き出す。
 */
function generateReservationBasicJson($shopId)
{
	$shopId = (int)$shopId;
	if ($shopId < 1) {
		return false;
	}
	$lock = lockReservationJsonFile($shopId, 'basic.json');
	if ($lock === false) {
		return false;
	}
	try {
		$shop = getReservationShopForOccupancy($shopId);
		$settingsRaw = getShopReservationSettings($shopId);
		if (is_array($shop) === false || is_array($settingsRaw) === false) {
			return false;
		}
		$settings = normalizeShopReservationSettingsData($settingsRaw);
		if ($settings === false) {
			return false;
		}
		$writeData = [
			'shopId' => sprintf('%03d', $shopId),
			'reservationEnabled' => isReservationEnabledForShop($shopId),
			'menuSelectionType' => (int)$settings['menu_selection_type'],
			'acceptancePeriod' => [
				'startDaysBefore' => $settings['accept_start_days_before'] === null ? null : (int)$settings['accept_start_days_before'],
				'endDaysBefore' => (int)$settings['accept_end_days_before'],
			],
			'guestRange' => [
				'min' => (int)$settings['guest_min'],
				'max' => (int)$settings['guest_max'],
			],
			'regularHolidays' => normalizeRegularHolidays($shop['closed_weekdays'] ?? null),
			'updatedAt' => buildReservationJsonUpdatedAt(),
		];
		return writeReservationJsonFile($shopId, 'basic.json', $writeData);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * [予約JSON] 予約メニューJSON生成
 *  current-validなusable menuのみmenus配列へ出力する。
 */
function generateReservationMenusJson($shopId)
{
	$shopId = (int)$shopId;
	if ($shopId < 1) {
		return false;
	}
	$lock = lockReservationJsonFile($shopId, 'menus.json');
	if ($lock === false) {
		return false;
	}
	try {
		$settingsRaw = getShopReservationSettings($shopId);
		if ($settingsRaw === false) {
			return false;
		}
		$settings = is_array($settingsRaw) ? normalizeShopReservationSettingsData($settingsRaw) : null;
		if ($settingsRaw !== null && $settings === false) {
			return false;
		}
		$rows = getFoodMenuRowsForReservationJson($shopId);
		if (is_array($rows) === false) {
			return false;
		}
		$today = foodMenuTodayJst();
		$menus = [];
		foreach ($rows as $row) {
			if (isFoodMenuUsable($row, $shopId, $today) === false) {
				continue;
			}
			$menus[] = [
				'id' => 'menu-' . str_pad((string)(int)$row['id'], 3, '0', STR_PAD_LEFT),
				'name' => (string)$row['menu_name'],
				'price' => (int)$row['price'],
				'taxIncluded' => true,
				'image' => (string)$row['image_path'],
				'description' => (string)$row['description'],
				'enabled' => true,
			];
		}
		$menuSelectionType = is_array($settings) ? (int)$settings['menu_selection_type'] : 0;
		$writeData = [
			'shopId' => sprintf('%03d', $shopId),
			'allowSeatOnly' => $menuSelectionType !== 2,
			'updatedAt' => buildReservationJsonUpdatedAt(),
			'menus' => $menus,
		];
		return writeReservationJsonFile($shopId, 'menus.json', $writeData);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * [予約JSON] 月キーを月初日に変換
 *  不正な年月や存在しない日付を出力ファイル名に使用しない。
 */
function reservationAvailabilityMonthStart($monthKey)
{
	if (!is_string($monthKey) || preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $monthKey) !== 1) {
		return false;
	}
	$start = DateTimeImmutable::createFromFormat('!Y-m-d', $monthKey . '-01', new DateTimeZone('Asia/Tokyo'));
	return $start instanceof DateTimeImmutable && (int)$start->format('Y') > 0 && $start->format('Y-m') === $monthKey
		? $start : false;
}

/**
 * [予約JSON] 月別空席判定に使える通常席の有無を確認
 *  DB取得失敗は席0件と区別してnullを返す。
 */
function hasReservationAvailabilityNormalSeats($shopId)
{
	$seats = getSeatsForReservationOccupancy($shopId);
	if ($seats === false) {
		return null;
	}
	foreach ($seats as $seat) {
		if ((int)($seat['is_temp_move'] ?? 0) === 0 && (int)($seat['is_active'] ?? 0) === 1) {
			return true;
		}
	}
	return false;
}

/**
 * [予約JSON] 1日分の人数別空席状態を生成
 *  override・定休日を優先し、通常日は仮想占有stateで3件まで積む。
 */
function buildReservationAvailabilityDayData($shopId, $date)
{
	if (isReservationDateString($date) === false) {
		return false;
	}
	$state = buildReservationOccupancyStateFromDb($shopId, $date);
	if (is_array($state) === false) {
		return false;
	}
	$override = $state['calendar_override_status_type'] ?? null;
	if ($override !== null && in_array($override, [1, 2, 3], true) === false) {
		return false;
	}
	if ($override === 1 || $override === 2) {
		$status = $override === 1 ? 'full' : 'holiday';
		return [
			'date' => $date,
			'guests' => ['1' => $status, '2' => $status, '3' => $status, '4' => $status],
			'reason' => $override === 1 ? '受付停止' : '店休日',
		];
	}
	$weekday = (int)(new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo')))->format('w');
	if ($override !== 3 && in_array($weekday, normalizeRegularHolidays($state['regular_holidays']), true)) {
		return [
			'date' => $date,
			'guests' => ['1' => 'holiday', '2' => 'holiday', '3' => 'holiday', '4' => 'holiday'],
			'reason' => '定休日',
		];
	}
	$guests = [];
	for ($partySize = 1; $partySize <= 4; $partySize++) {
		$virtualState = $state;
		$count = 0;
		while ($count < 3) {
			$assignment = checkAndAssignSeat($shopId, $date, $partySize, 'simulate', $virtualState);
			if (!is_array($assignment) || !is_bool($assignment['assignable'] ?? null)) {
				return false;
			}
			if ($assignment['assignable'] === false) {
				if (($assignment['reason'] ?? null) !== 'full') {
					return false;
				}
				break;
			}
			if (empty($assignment['assigned_seat_ids'])) {
				return false;
			}
			$virtualState = applyAssignmentPlanToOccupancyState($virtualState, $assignment, $partySize);
			if (isValidReservationOccupancyState($shopId, $date, $virtualState) === false) {
				return false;
			}
			$count++;
		}
		$guests[(string)$partySize] = $count === 0 ? 'full' : ($count < 3 ? 'limited' : 'open');
	}
	return ['date' => $date, 'guests' => $guests];
}

/**
 * [予約JSON] 1ヶ月分の空席データを構築
 *  日別判定に失敗した場合は月全体の書き出しを中止する。
 */
function buildReservationAvailabilityMonthData($shopId, $monthKey)
{
	$firstDay = reservationAvailabilityMonthStart($monthKey);
	if ($firstDay === false) {
		return false;
	}
	$days = [];
	$end = $firstDay->modify('first day of next month');
	for ($day = $firstDay; $day < $end; $day = $day->modify('+1 day')) {
		$dayData = buildReservationAvailabilityDayData($shopId, $day->format('Y-m-d'));
		if ($dayData === false) {
			return false;
		}
		$days[] = $dayData;
	}
	return [
		'shopId' => sprintf('%03d', $shopId),
		'year' => (int)$firstDay->format('Y'),
		'month' => (int)$firstDay->format('n'),
		'updatedAt' => buildReservationJsonUpdatedAt(),
		'days' => $days,
	];
}

/**
 * [予約JSON] 既存月別JSONの差し替え可能性を確認
 *  不完全な月は日次差分を載せず、全月を再生成する。
 */
function isReservationAvailabilityMonthData($data, $shopId, $monthKey)
{
	$firstDay = reservationAvailabilityMonthStart($monthKey);
	if ($firstDay === false || !is_array($data) ||
		($data['shopId'] ?? null) !== sprintf('%03d', $shopId) ||
		($data['year'] ?? null) !== (int)$firstDay->format('Y') ||
		($data['month'] ?? null) !== (int)$firstDay->format('n') ||
		!is_array($data['days'] ?? null) || !array_is_list($data['days']) ||
		count($data['days']) !== (int)$firstDay->format('t')) {
		return false;
	}
	foreach ($data['days'] as $index => $day) {
		if (!is_int($index) || !is_array($day) ||
			($day['date'] ?? null) !== $firstDay->modify('+' . $index . ' days')->format('Y-m-d') ||
			!is_array($day['guests'] ?? null) || count($day['guests']) !== 4) {
			return false;
		}
		for ($partySize = 1; $partySize <= 4; $partySize++) {
			if (!in_array($day['guests'][(string)$partySize] ?? null, ['open', 'limited', 'full', 'holiday'], true)) {
				return false;
			}
		}
		if (isset($day['reason']) && !in_array($day['reason'], ['定休日', '店休日', '受付停止'], true)) {
			return false;
		}
	}
	return true;
}

/**
 * [予約JSON] 月別空席JSONを全件生成
 *  固定lockファイルを保持し、席0件の場合は古い月別JSONを除去する。
 */
function generateReservationAvailabilityMonthJson($shopId, $monthKey)
{
	$shopId = (int)$shopId;
	if ($shopId < 1 || reservationAvailabilityMonthStart($monthKey) === false) {
		return false;
	}
	$fileName = $monthKey . '.json';
	$lock = lockReservationJsonFile($shopId, $fileName);
	if ($lock === false) {
		return false;
	}
	try {
		$hasSeats = hasReservationAvailabilityNormalSeats($shopId);
		if ($hasSeats === false) {
			$filePath = buildReservationJsonDir($shopId) . '/' . $fileName;
			return is_file($filePath) === false || @unlink($filePath);
		}
		if ($hasSeats !== true) {
			return false;
		}
		$data = buildReservationAvailabilityMonthData($shopId, $monthKey);
		return $data !== false && writeReservationJsonFile($shopId, $fileName, $data);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * [予約JSON] 保持範囲外になった月別JSONを削除
 *  月別書き出しと同じ固定ロックを取得してから対象ファイルのみ削除する。
 */
function deleteReservationAvailabilityMonthJson($shopId, $monthKey)
{
	$shopId = (int)$shopId;
	if ($shopId < 1 || reservationAvailabilityMonthStart($monthKey) === false) {
		return false;
	}
	$fileName = $monthKey . '.json';
	$lock = lockReservationJsonFile($shopId, $fileName);
	if ($lock === false) {
		return false;
	}
	try {
		$filePath = buildReservationJsonDir($shopId) . '/' . $fileName;
		return is_file($filePath) === false || @unlink($filePath);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * [予約JSON] 生成済み月別空席JSONの月キーを列挙
 *  未生成ディレクトリは空配列、読取失敗はfalseを返す。
 */
function listReservationAvailabilityMonthKeys($shopId)
{
	$dir = buildReservationJsonDir($shopId);
	if ($dir === false) {
		return false;
	}
	if (file_exists($dir) === false) {
		return [];
	}
	if (is_dir($dir) === false || ($names = @scandir($dir)) === false) {
		return false;
	}
	$monthKeys = [];
	foreach ($names as $name) {
		if (preg_match('/\A([0-9]{4}-(?:0[1-9]|1[0-2]))\.json\z/D', $name, $matches) === 1 &&
			reservationAvailabilityMonthStart($matches[1]) !== false &&
			is_file($dir . '/' . $name)) {
			$monthKeys[] = $matches[1];
		}
	}
	sort($monthKeys, SORT_STRING);
	return $monthKeys;
}

/**
 * [予約JSON] 月別空席JSONの同期を一度再試行
 *  失敗した月も後続の月の処理を妨げない。
 */
function retryReservationAvailabilityMonthJson($shopId, $monthKey)
{
	for ($attempt = 0; $attempt < 2; $attempt++) {
		try {
			if (generateReservationAvailabilityMonthJson($shopId, $monthKey) === true) {
				return true;
			}
		} catch (Throwable $e) {
			// JSON同期失敗はコミット済み保存の成否を変えない。
		}
	}
	return false;
}

/**
 * [予約JSON] 日次・月次の再生成失敗をキューへ登録
 *  DB helperや接続の例外をコミット済み応答へ波及させない。
 */
function queueReservationAvailabilityJsonFailure($shopId, $jobType, $targetDate)
{
	try {
		return enqueueReservationJsonRegeneration($shopId, $jobType, $targetDate) === true;
	} catch (Throwable $e) {
		return false;
	}
}

/**
 * [予約JSON] 生成済み月と受付有効化時の12か月を同期
 *  席・定休日変更は生成済み全月と受付可能な12か月、有効化時は12か月を対象とする。
 */
function syncFrontendReservationAvailabilityMonthsJson(&$response, $shopId, $wasEnabled, $refreshExisting)
{
	$monthKeys = $refreshExisting ? listReservationAvailabilityMonthKeys($shopId) : [];
	$failedMonths = [];
	$queueFailedMonths = [];
	$scanFailed = $monthKeys === false;
	$failed = $scanFailed;
	if ($scanFailed) {
		$monthKeys = [];
	}
	try {
		$nowEnabled = isReservationEnabledForShop($shopId) === true;
	} catch (Throwable $e) {
		$nowEnabled = false;
		$failed = true;
	}
	$includeRolling = ($wasEnabled !== true && $nowEnabled === true) ||
		($refreshExisting === true && ($wasEnabled === true || $nowEnabled === true));
	if ($includeRolling) {
		$firstMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('first day of this month');
		for ($offset = 0; $offset < 12; $offset++) {
			$monthKeys[] = $firstMonth->modify('+' . $offset . ' months')->format('Y-m');
		}
	}
	$monthKeys = array_values(array_unique($monthKeys));
	sort($monthKeys, SORT_STRING);
	foreach ($monthKeys as $monthKey) {
		if (retryReservationAvailabilityMonthJson($shopId, $monthKey) !== true) {
			$failed = true;
			$failedMonths[] = $monthKey;
			if (queueReservationAvailabilityJsonFailure($shopId, 'month', $monthKey . '-01') === false) {
				$queueFailedMonths[] = $monthKey;
			}
		}
	}
	if ($failed === false) {
		return true;
	}
	appendFrontendJsonWarningMessage($response);
	try {
		logFrontendJsonError('reservation_availability_month_json_export_failed', $shopId, null, [
			'scan_failed' => $scanFailed,
			'failed_months' => $failedMonths,
			'queue_failed_months' => $queueFailedMonths,
		]);
	} catch (Throwable $e) {
		// ログ失敗も保存済み応答には波及させない。
	}
	return false;
}

/**
 * [予約JSON] 月別JSONの対象日だけを再生成
 *  月別ファイルが未生成・不正な場合は全月を構築して置換する。
 *  保持範囲外で既に削除済みの月は再作成しない。
 */
function regenerateReservationAvailabilityDayJson($shopId, $date)
{
	$shopId = (int)$shopId;
	if ($shopId < 1 || isReservationDateString($date) === false) {
		return false;
	}
	$monthKey = substr($date, 0, 7);
	if (reservationAvailabilityMonthStart($monthKey) === false) {
		return false;
	}
	$fileName = $monthKey . '.json';
	$lock = lockReservationJsonFile($shopId, $fileName);
	if ($lock === false) {
		return false;
	}
	try {
		$filePath = buildReservationJsonDir($shopId) . '/' . $fileName;
		$currentMonthKey = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
		if ($monthKey < $currentMonthKey && is_file($filePath) === false) {
			return true;
		}
		$hasSeats = hasReservationAvailabilityNormalSeats($shopId);
		if ($hasSeats === false) {
			return is_file($filePath) === false || @unlink($filePath);
		}
		if ($hasSeats !== true) {
			return false;
		}
		if (is_file($filePath)) {
			$json = @file_get_contents($filePath);
			if ($json === false) {
				return false;
			}
			$data = json_decode($json, true);
		} else {
			$data = null;
		}
		if (isReservationAvailabilityMonthData($data, $shopId, $monthKey) === false) {
			$data = buildReservationAvailabilityMonthData($shopId, $monthKey);
			if ($data === false) {
				return false;
			}
		} else {
			$dayData = buildReservationAvailabilityDayData($shopId, $date);
			if ($dayData === false) {
				return false;
			}
			$data['days'][(int)substr($date, 8, 2) - 1] = $dayData;
			$data['updatedAt'] = buildReservationJsonUpdatedAt();
		}
		return writeReservationJsonFile($shopId, $fileName, $data);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

/**
 * [予約JSON] 日別空席JSONの同期を一度再試行
 *  保存済みDBの再読込で失敗した場合に限りもう一度試す。
 */
function retryReservationAvailabilityDayJson($shopId, $date)
{
	for ($attempt = 0; $attempt < 2; $attempt++) {
		try {
			if (regenerateReservationAvailabilityDayJson($shopId, $date) === true) {
				return true;
			}
		} catch (Throwable $e) {
			// JSON同期失敗はコミット済み予約の成否を変えない。
		}
	}
	return false;
}

/**
 * [予約JSON] 管理画面の保存応答を維持して日別空席JSONを同期
 *  warning statusを扱わないAjaxのため、保存成功のまま警告を返す。
 */
function syncFrontendReservationAvailabilityDayJson(&$response, $shopId, $date)
{
	if (retryReservationAvailabilityDayJson($shopId, $date) === true) {
		return true;
	}
	$queued = queueReservationAvailabilityJsonFailure($shopId, 'day', $date);
	if (is_array($response)) {
		$response['jsonSyncFailed'] = true;
		$response['msg'] .= ' フロント表示用JSONの更新に失敗しました。';
	}
	try {
		logFrontendJsonError('reservation_availability_day_json_export_failed', $shopId, null, [
			'date' => $date,
			'queued' => $queued,
		]);
	} catch (Throwable $e) {
		// ログ失敗も保存済み応答には波及させない。
	}
	return false;
}

/**
 * [予約JSON] basic.json同期書き出し
 *  失敗時は既存フロントJSON同様にwarning応答とログだけを付与する。
 */
function syncFrontendReservationBasicJson(&$makeTag, $shopId)
{
	try {
		$okBasic = generateReservationBasicJson($shopId);
	} catch (Throwable $e) {
		$okBasic = false;
	}
	if ($okBasic !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		try {
			logFrontendJsonError('reservation_basic_json_export_failed', $shopId, null, [
				'basic_json' => $okBasic,
			]);
		} catch (Throwable $e) {
			// ログ失敗も保存済み応答には波及させない。
		}
		return false;
	}
	return true;
}

/**
 * [予約JSON] menus.json同期書き出し
 *  失敗時は既存フロントJSON同様にwarning応答とログだけを付与する。
 */
function syncFrontendReservationMenusJson(&$makeTag, $shopId)
{
	try {
		$okMenus = generateReservationMenusJson($shopId);
	} catch (Throwable $e) {
		$okMenus = false;
	}
	if ($okMenus !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		try {
			logFrontendJsonError('reservation_menus_json_export_failed', $shopId, null, [
				'menus_json' => $okMenus,
			]);
		} catch (Throwable $e) {
			// ログ失敗も保存済み応答には波及させない。
		}
		return false;
	}
	return true;
}

/**
 * [予約JSON] basic.json・menus.json同期書き出し
 *  予約基本設定保存時など、両方のcontractが影響を受ける操作から呼ぶ。
 */
function syncFrontendReservationBaseJson(&$makeTag, $shopId)
{
	try {
		$okBasic = generateReservationBasicJson($shopId);
	} catch (Throwable $e) {
		$okBasic = false;
	}
	try {
		$okMenus = generateReservationMenusJson($shopId);
	} catch (Throwable $e) {
		$okMenus = false;
	}
	if ($okBasic !== true || $okMenus !== true) {
		appendFrontendJsonWarningMessage($makeTag);
		try {
			logFrontendJsonError('reservation_base_json_export_failed', $shopId, null, [
				'basic_json' => $okBasic,
				'menus_json' => $okMenus,
			]);
		} catch (Throwable $e) {
			// ログ失敗も保存済み応答には波及させない。
		}
		return false;
	}
	return true;
}
