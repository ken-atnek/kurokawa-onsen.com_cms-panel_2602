<?php
/*
 * [予約JSON] 月替わりの12か月維持
 * 月初の定期実行用。--planで対象確認後、--executeで新しい末尾月を生成する。
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
	fwrite(STDERR, "Usage: php roll_reservation_availability_json.php --plan|--execute [--shop-id=N]\n");
	exit(2);
}

$lock = null;
if ($mode === '--execute') {
	$lock = @fopen(sys_get_temp_dir() . '/kurokawa_reservation_json_backfill.lock', 'c');
	if ($lock === false || @flock($lock, LOCK_EX | LOCK_NB) !== true) {
		fwrite(STDERR, "Another reservation JSON batch is running or lock is unavailable.\n");
		exit(1);
	}
}

$exitCode = 0;
$summary = ['mode' => $mode, 'candidate_shops' => 0, 'eligible_shops' => 0, 'skipped_shops' => 0, 'failed_shops' => 0, 'generated_months' => 0, 'failed_months' => 0, 'deleted_months' => 0, 'delete_failures' => 0, 'queue_failures' => 0];
try {
	require_once dirname(__DIR__, 2) . '/common/define.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

	$shopIds = getReservationJsonMaintenanceShopIds();
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
	$newMonthKey = $firstMonth->modify('+11 months')->format('Y-m');
	$oldMonthKey = $firstMonth->modify('-1 month')->format('Y-m');
	$summary['new_month'] = $newMonthKey;
	$summary['old_month'] = $oldMonthKey;
	foreach ($shopIds as $shopId) {
		$shop = getReservationShopForOccupancy($shopId);
		$settings = getShopReservationSettings($shopId);
		$settingsData = is_array($settings) ? normalizeShopReservationSettingsData($settings) : false;
		if (!is_array($shop) || $settingsData === false) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Preflight failed: shop_id=' . $shopId . PHP_EOL);
			continue;
		}
		$disabled = ($shop['shop_type'] ?? null) !== 'food' ||
			(int)($shop['is_active'] ?? 0) !== 1 ||
			(int)($shop['is_public'] ?? 0) !== 1 ||
			(int)$settingsData['reservation_enabled'] !== 1;
		if (!$disabled) {
			$hasSeats = hasReservationAvailabilityNormalSeats($shopId);
			$menuCount = (int)$settingsData['menu_selection_type'] === 2 ? getActiveFoodMenuCountForReservation($shopId) : 1;
			if ($hasSeats === null || $menuCount === false) {
				$summary['failed_shops']++;
				$exitCode = 1;
				fwrite(STDERR, 'Availability preflight failed: shop_id=' . $shopId . PHP_EOL);
				continue;
			}
			$disabled = $hasSeats === false || $menuCount === 0;
		}
		if (!$disabled && isReservationEnabledForShop($shopId) !== true) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Eligibility preflight failed: shop_id=' . $shopId . PHP_EOL);
			continue;
		}
		$oldFilePath = buildReservationJsonDir($shopId) . '/' . $oldMonthKey . '.json';
		$hadOldFile = is_file($oldFilePath);
		if ($disabled) {
			$summary['skipped_shops']++;
			if ($mode === '--plan') {
				echo 'shop_id=' . $shopId . ' generate=none delete_out_of_range=' . ($hadOldFile ? $oldMonthKey : 'none') . PHP_EOL;
				continue;
			}
			if ($hadOldFile && deleteReservationAvailabilityMonthJson($shopId, $oldMonthKey) !== true) {
				$summary['delete_failures']++;
				$exitCode = 1;
				fwrite(STDERR, 'Deletion failed: shop_id=' . $shopId . ' month=' . $oldMonthKey . PHP_EOL);
			} elseif ($hadOldFile) {
				$summary['deleted_months']++;
			}
			continue;
		}
		$summary['eligible_shops']++;
		if ($mode === '--plan') {
			echo 'shop_id=' . $shopId . ' generate=' . $newMonthKey . ' delete_after_success=' . $oldMonthKey . PHP_EOL;
			continue;
		}
		$generated = retryReservationAvailabilityMonthJson($shopId, $newMonthKey);
		$newFilePath = buildReservationJsonDir($shopId) . '/' . $newMonthKey . '.json';
		if ($generated !== true || !is_file($newFilePath)) {
			$summary['failed_months']++;
			$exitCode = 1;
			if (queueReservationAvailabilityJsonFailure($shopId, 'month', $newMonthKey . '-01') !== true) {
				$summary['queue_failures']++;
			}
			fwrite(STDERR, 'Generation failed: shop_id=' . $shopId . ' month=' . $newMonthKey . PHP_EOL);
			continue;
		}
		$summary['generated_months']++;
		if (deleteReservationAvailabilityMonthJson($shopId, $oldMonthKey) !== true) {
			$summary['delete_failures']++;
			$exitCode = 1;
			fwrite(STDERR, 'Deletion failed: shop_id=' . $shopId . ' month=' . $oldMonthKey . PHP_EOL);
			continue;
		}
		if ($hadOldFile) {
			$summary['deleted_months']++;
		}
	}
} catch (Throwable $e) {
	$exitCode = 1;
	fwrite(STDERR, 'Month roll failed: ' . get_class($e) . PHP_EOL);
}

if ($lock !== null) {
	flock($lock, LOCK_UN);
	fclose($lock);
}
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exitCode);
