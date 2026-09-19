<?php
/*
 * [予約JSON] 初回導入時の月別空席JSON一括生成
 * 手動CLI専用。--planで対象確認後、--executeで当月から12か月を再生成する。
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}

$mode = null;
$shopIdFilter = null;
foreach (array_slice($argv, 1) as $argument) {
	if (($argument === '--plan' || $argument === '--execute') && $mode === null) {
		$mode = $argument;
	} elseif (preg_match('/\A--shop-id=([1-9][0-9]*)\z/D', $argument, $matches) === 1 && $shopIdFilter === null) {
		$shopIdFilter = (int)$matches[1];
		if ($shopIdFilter < 1 || (string)$shopIdFilter !== $matches[1]) {
			$mode = null;
			break;
		}
	} else {
		$mode = null;
		break;
	}
}
if ($mode === null) {
	fwrite(STDERR, "Usage: php backfill_reservation_availability_json.php --plan|--execute [--shop-id=N]\n");
	exit(2);
}

$lock = null;
if ($mode === '--execute') {
	$lock = @fopen(sys_get_temp_dir() . '/kurokawa_reservation_json_backfill.lock', 'c');
	if ($lock === false || @flock($lock, LOCK_EX | LOCK_NB) !== true) {
		fwrite(STDERR, "Backfill is already running or lock is unavailable.\n");
		exit(1);
	}
}

$exitCode = 0;
$summary = ['mode' => $mode, 'candidate_shops' => 0, 'eligible_shops' => 0, 'skipped_shops' => 0, 'failed_shops' => 0, 'generated_months' => 0, 'failed_months' => 0, 'queue_failures' => 0];
try {
	require_once dirname(__DIR__, 2) . '/common/define.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

	$shopIds = getReservationJsonBackfillShopIds();
	if ($shopIds === false) {
		throw new RuntimeException('shop_list_failed');
	}
	if ($shopIdFilter !== null) {
		$shopIds = in_array($shopIdFilter, $shopIds, true) ? [$shopIdFilter] : [];
		if ($shopIds === []) {
			throw new RuntimeException('shop_not_candidate');
		}
	}
	$summary['candidate_shops'] = count($shopIds);
	$firstMonth = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('first day of this month');
	$months = [];
	for ($offset = 0; $offset < 12; $offset++) {
		$months[] = $firstMonth->modify('+' . $offset . ' months')->format('Y-m');
	}
	foreach ($shopIds as $shopId) {
		$shop = getReservationShopForOccupancy($shopId);
		$settings = getShopReservationSettings($shopId);
		$hasSeats = hasReservationAvailabilityNormalSeats($shopId);
		if (!is_array($shop) || $settings === false || $hasSeats === null) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Preflight failed: shop_id=' . $shopId . PHP_EOL);
			continue;
		}
		if (is_array($settings) && (int)$settings['menu_selection_type'] === 2 && getActiveFoodMenuCountForReservation($shopId) === false) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Menu preflight failed: shop_id=' . $shopId . PHP_EOL);
			continue;
		}
		if (isReservationEnabledForShop($shopId) !== true) {
			$summary['skipped_shops']++;
			continue;
		}
		$summary['eligible_shops']++;
		if ($mode === '--plan') {
			echo 'shop_id=' . $shopId . ' basic=basic.json menus=menus.json months=' . $months[0] . '..' . $months[11] . PHP_EOL;
			continue;
		}
		if (generateReservationBasicJson($shopId) !== true || generateReservationMenusJson($shopId) !== true) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Base JSON failed: shop_id=' . $shopId . PHP_EOL);
			continue;
		}
		foreach ($months as $monthKey) {
			if (retryReservationAvailabilityMonthJson($shopId, $monthKey) === true) {
				$summary['generated_months']++;
				continue;
			}
			$summary['failed_months']++;
			$exitCode = 1;
			if (queueReservationAvailabilityJsonFailure($shopId, 'month', $monthKey . '-01') !== true) {
				$summary['queue_failures']++;
			}
			fwrite(STDERR, 'Generation failed: shop_id=' . $shopId . ' month=' . $monthKey . PHP_EOL);
		}
	}
	$summary['months'] = [$months[0], $months[11]];
} catch (Throwable $e) {
	$exitCode = 1;
	fwrite(STDERR, 'Backfill failed: ' . get_class($e) . PHP_EOL);
}

if ($lock !== null) {
	flock($lock, LOCK_UN);
	fclose($lock);
}
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exitCode);
