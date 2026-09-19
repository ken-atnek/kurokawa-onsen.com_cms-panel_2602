<?php
/*
 * [予約JSON] 日次・月次の再生成キューDB helper
 */

/**
 * キュー対象の形式を検証
 *  monthジョブは対象月の1日で表す。
 */
function isReservationJsonQueueTarget($shopId, $jobType, $targetDate)
{
	if (filter_var($shopId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false ||
		!in_array($jobType, ['day', 'month'], true) || !is_string($targetDate) ||
		preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $targetDate) !== 1) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate, new DateTimeZone('Asia/Tokyo'));
	return $date instanceof DateTimeImmutable && (int)$date->format('Y') > 0 && $date->format('Y-m-d') === $targetDate &&
		($jobType !== 'month' || $date->format('d') === '01');
}

/**
 * 日次・月次の再生成ジョブを登録
 *  同じジョブがあれば失敗回数と失敗日時を更新する。
 */
function enqueueReservationJsonRegeneration($shopId, $jobType, $targetDate)
{
	global $DB_CONNECT;
	if (isReservationJsonQueueTarget($shopId, $jobType, $targetDate) === false || !is_object($DB_CONNECT)) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare("
			INSERT INTO json_regeneration_queue (shop_id, job_type, target_date)
			VALUES (:shop_id, :job_type, :target_date)
			ON DUPLICATE KEY UPDATE
				retry_count = retry_count + 1,
				failed_at = CURRENT_TIMESTAMP
		");
		if ($stmt === false) {
			return false;
		}
		$stmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
		$stmt->bindValue(':job_type', $jobType, PDO::PARAM_STR);
		$stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
		$ok = $stmt->execute();
		$stmt->closeCursor();
		return $ok === true;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 再試行可能なジョブを失敗日時順に取得
 *  取得後のファイル生成中はDB行をロックしない。
 */
function getPendingReservationJsonRegenerationJobs($limit = 10, $maxRetries = 5)
{
	global $DB_CONNECT;
	if (!is_int($limit) || $limit < 1 || $limit > 100 || !is_int($maxRetries) || $maxRetries < 1 || !is_object($DB_CONNECT)) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare("
			SELECT id, shop_id, job_type, target_date, failed_at, retry_count
			FROM json_regeneration_queue
			WHERE retry_count < :max_retries
			ORDER BY failed_at ASC, id ASC
			LIMIT :limit
		");
		if ($stmt === false) {
			return false;
		}
		$stmt->bindValue(':max_retries', $maxRetries, PDO::PARAM_INT);
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		if ($stmt->execute() !== true) {
			return false;
		}
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		if (!is_array($rows)) {
			return false;
		}
		$jobs = [];
		foreach ($rows as $row) {
			$id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			$shopId = filter_var($row['shop_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			$retryCount = filter_var($row['retry_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
			if ($id === false || $shopId === false || $retryCount === false ||
				isReservationJsonQueueTarget($shopId, $row['job_type'] ?? null, $row['target_date'] ?? null) === false ||
				!is_string($row['failed_at'] ?? null) || $row['failed_at'] === '') {
				return false;
			}
			$jobs[] = [
				'id' => $id,
				'shop_id' => $shopId,
				'job_type' => $row['job_type'],
				'target_date' => $row['target_date'],
				'failed_at' => $row['failed_at'],
				'retry_count' => $retryCount,
			];
		}
		return $jobs;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 取得時から更新されていない成功ジョブだけを削除
 *  0件は別リクエストによる再登録・更新を表す。
 */
function deleteCompletedReservationJsonRegenerationJob($job)
{
	global $DB_CONNECT;
	if (!is_array($job) || !is_object($DB_CONNECT)) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare("
			DELETE FROM json_regeneration_queue
			WHERE id = :id AND retry_count = :retry_count AND failed_at = :failed_at
		");
		if ($stmt === false) {
			return false;
		}
		$stmt->bindValue(':id', $job['id'], PDO::PARAM_INT);
		$stmt->bindValue(':retry_count', $job['retry_count'], PDO::PARAM_INT);
		$stmt->bindValue(':failed_at', $job['failed_at'], PDO::PARAM_STR);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true ? $count : false;
	} catch (PDOException $e) {
		return false;
	}
}

/**
 * 取得時から更新されていない失敗ジョブの再試行回数を増加
 *  0件は別リクエストによる再登録・更新を表す。
 */
function incrementFailedReservationJsonRegenerationJob($job)
{
	global $DB_CONNECT;
	if (!is_array($job) || !is_object($DB_CONNECT)) {
		return false;
	}
	try {
		$stmt = $DB_CONNECT->prepare("
			UPDATE json_regeneration_queue
			SET retry_count = retry_count + 1, failed_at = CURRENT_TIMESTAMP
			WHERE id = :id AND retry_count = :retry_count AND failed_at = :failed_at
		");
		if ($stmt === false) {
			return false;
		}
		$stmt->bindValue(':id', $job['id'], PDO::PARAM_INT);
		$stmt->bindValue(':retry_count', $job['retry_count'], PDO::PARAM_INT);
		$stmt->bindValue(':failed_at', $job['failed_at'], PDO::PARAM_STR);
		$ok = $stmt->execute();
		$count = $stmt->rowCount();
		$stmt->closeCursor();
		return $ok === true ? $count : false;
	} catch (PDOException $e) {
		return false;
	}
}
