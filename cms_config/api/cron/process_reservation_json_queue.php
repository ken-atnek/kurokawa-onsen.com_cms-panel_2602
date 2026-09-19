<?php
/*
 * [予約JSON] 日次・月次再生成キューのCLI処理
 *  5分ごとのcronから実行する。DDL適用前には起動しない。
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}

$lockPath = sys_get_temp_dir() . '/kurokawa_reservation_json_queue.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false) {
	error_log('[reservation-json-queue] lock_unavailable');
	exit(1);
}
if (@flock($lock, LOCK_EX | LOCK_NB) !== true) {
	fclose($lock);
	exit(0);
}
$batchLock = @fopen(sys_get_temp_dir() . '/kurokawa_reservation_json_backfill.lock', 'c');
if ($batchLock === false) {
	flock($lock, LOCK_UN);
	fclose($lock);
	error_log('[reservation-json-queue] batch_lock_unavailable');
	exit(1);
}
if (@flock($batchLock, LOCK_EX | LOCK_NB) !== true) {
	flock($lock, LOCK_UN);
	fclose($lock);
	fclose($batchLock);
	exit(0);
}

$summary = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'stale' => 0, 'expired' => 0];
$exitCode = 0;
try {
	require_once dirname(__DIR__, 2) . '/common/define.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
	require_once DOCUMENT_ROOT_PATH . '/cms_config/common/workJson/makeReservationJson.php';

	$jobs = getPendingReservationJsonRegenerationJobs(10, 5);
	if ($jobs === false) {
		throw new RuntimeException('queue_read_failed');
	}
	$currentMonthKey = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
	foreach ($jobs as $job) {
		$summary['processed']++;
		$expired = false;
		try {
			$jobMonthKey = substr($job['target_date'], 0, 7);
			if ($jobMonthKey < $currentMonthKey) {
				$existingMonths = listReservationAvailabilityMonthKeys($job['shop_id']);
				if ($existingMonths === false) {
					$generated = false;
				} elseif (!in_array($jobMonthKey, $existingMonths, true)) {
					$generated = true;
					$expired = true;
				} else {
					$generated = $job['job_type'] === 'month'
						? generateReservationAvailabilityMonthJson($job['shop_id'], $jobMonthKey)
						: regenerateReservationAvailabilityDayJson($job['shop_id'], $job['target_date']);
				}
			} else {
				$generated = $job['job_type'] === 'month'
					? generateReservationAvailabilityMonthJson($job['shop_id'], $jobMonthKey)
					: regenerateReservationAvailabilityDayJson($job['shop_id'], $job['target_date']);
			}
		} catch (Throwable $e) {
			$generated = false;
		}
		$changed = $generated === true
			? deleteCompletedReservationJsonRegenerationJob($job)
			: incrementFailedReservationJsonRegenerationJob($job);
		if ($changed === false) {
			$exitCode = 1;
			error_log('[reservation-json-queue] queue_update_failed id=' . $job['id']);
		} elseif ($changed === 0) {
			$summary['stale']++;
		} elseif ($expired) {
			$summary['expired']++;
		} elseif ($generated === true) {
			$summary['completed']++;
		} else {
			$summary['failed']++;
			error_log('[reservation-json-queue] regeneration_failed id=' . $job['id'] . ' type=' . $job['job_type']);
		}
	}
} catch (Throwable $e) {
	$exitCode = 1;
	error_log('[reservation-json-queue] processor_failed exception=' . get_class($e));
}

flock($lock, LOCK_UN);
fclose($lock);
flock($batchLock, LOCK_UN);
fclose($batchLock);
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exitCode);
