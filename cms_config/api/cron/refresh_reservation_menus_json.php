<?php
/*
 * [予約JSON] 掲載期間の日付変更に追随する日次更新
 * 日付変更後の定期実行用。--planで対象確認後、--executeで更新する。
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}

/**
 * 既存basic.jsonの日英受付状態を取得
 *  ファイル欠落・不正時はnullとして再生成対象にする
 */
function readReservationDailyBasicEligibility($shopId)
{
	$path = buildReservationJsonDir($shopId) . '/basic.json';
	if (!is_file($path)) {
		return null;
	}
	$json = @file_get_contents($path);
	$data = is_string($json) ? json_decode($json, true) : null;
	if (!is_array($data) || ($data['shopId'] ?? null) !== sprintf('%03d', $shopId) ||
		!is_bool($data['reservationEnabled'] ?? null) || !is_bool($data['reservationEnabled_en'] ?? null)) {
		return null;
	}
	return ['ja' => $data['reservationEnabled'], 'en' => $data['reservationEnabled_en']];
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
	fwrite(STDERR, "Usage: php refresh_reservation_menus_json.php --plan|--execute [--shop-id=N]\n");
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
$summary = ['mode' => $mode, 'candidate_shops' => 0, 'failed_shops' => 0, 'generated_menus' => 0, 'generated_basic' => 0, 'availability_failures' => 0];
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
	$summary['date'] = foodMenuTodayJst();
	foreach ($shopIds as $shopId) {
		try {
			$shop = getReservationShopForOccupancy($shopId);
			$settingsRaw = getShopReservationSettings($shopId);
			$settings = is_array($settingsRaw) ? normalizeShopReservationSettingsData($settingsRaw) : false;
			if (!is_array($shop) || $settings === false) {
				throw new RuntimeException('preflight_failed');
			}
			$menuRequired = (int)$settings['menu_selection_type'] === 2;
			if ($menuRequired) {
				$countJa = getActiveFoodMenuCountForReservation($shopId);
				$countEn = getActiveEnglishFoodMenuCountForReservation($shopId);
				if ($countJa === false || $countEn === false) {
					throw new RuntimeException('menu_preflight_failed');
				}
			}
			$hasSeats = hasReservationAvailabilityNormalSeats($shopId);
			if ($hasSeats === null) {
				throw new RuntimeException('seat_preflight_failed');
			}
			$oldFlags = readReservationDailyBasicEligibility($shopId);
			$newFlags = [
				'ja' => isReservationEnabledForShop($shopId, 'ja'),
				'en' => isReservationEnabledForShop($shopId, 'en'),
			];
			$needsBasic = $oldFlags === null || ($menuRequired && $oldFlags !== $newFlags);
			$wasEnabled = $oldFlags !== null && ($oldFlags['ja'] || $oldFlags['en']);
			if ($mode === '--plan') {
				echo 'shop_id=' . $shopId . ' menus=generate basic=' . ($needsBasic ? 'generate' : 'unchanged') .
					' availability=' . ($needsBasic && !$wasEnabled && ($newFlags['ja'] || $newFlags['en']) ? 'backfill' : 'unchanged') . PHP_EOL;
				continue;
			}
			if (generateReservationMenusJson($shopId) !== true) {
				throw new RuntimeException('menus_generation_failed');
			}
			$summary['generated_menus']++;
			if ($needsBasic) {
				if (generateReservationBasicJson($shopId) !== true) {
					throw new RuntimeException('basic_generation_failed');
				}
				$summary['generated_basic']++;
				if (!$wasEnabled && ($newFlags['ja'] || $newFlags['en'])) {
					$syncResult = [];
					if (syncFrontendReservationAvailabilityMonthsJson($syncResult, $shopId, false, false) !== true) {
						$summary['availability_failures']++;
						throw new RuntimeException('availability_generation_failed');
					}
				}
			}
		} catch (Throwable $e) {
			$summary['failed_shops']++;
			$exitCode = 1;
			fwrite(STDERR, 'Daily reservation JSON failed: shop_id=' . $shopId . ' reason=' . get_class($e) . PHP_EOL);
		}
	}
} catch (Throwable $e) {
	$exitCode = 1;
	fwrite(STDERR, 'Daily reservation JSON failed: ' . get_class($e) . PHP_EOL);
}

if ($lock !== null) {
	flock($lock, LOCK_UN);
	fclose($lock);
}
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exitCode);
