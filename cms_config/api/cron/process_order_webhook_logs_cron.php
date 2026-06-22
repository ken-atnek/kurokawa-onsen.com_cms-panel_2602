<?php
/*
 * [cms_config/api/cron/process_order_webhook_logs_cron.php]
 * EC-CUBE受注Webhook pending処理 cron ラッパー
 *
 * Xサーバー cron 設定例:
 * php /home/サーバーID/対象ドメイン/public_html/cms-panel_2602/cms_config/api/cron/process_order_webhook_logs_cron.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	echo 'Forbidden';
	exit;
}

$lockFile = sys_get_temp_dir() . '/kurokawa_order_webhook_processor.lock';
$logDir = dirname(__DIR__, 2) . '/logs';
$logFile = $logDir . '/order_webhook_cron.log';
$target = dirname(__DIR__) . '/eccube/process_order_webhook_logs.php';

if (is_dir($logDir) === false) {
	@mkdir($logDir, 0777, true);
}

/**
 * ログ出力
 * 認証情報や個人情報は出力しない前提で、処理要約のみを記録する。
 */
function writeOrderWebhookCronLog($logFile, $message)
{
	$line = '[' . date('Y-m-d H:i:s') . '] ' . (string)$message . PHP_EOL;
	@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
/**
 * 標準出力の要約
 * JSON形式なら status/total/completed/skipped/failed/details_count のみを抽出する。
 */
function summarizeOrderWebhookCronOutput($stdout)
{
	$stdout = trim((string)$stdout);
	if ($stdout === '') {
		return '';
	}
	$decoded = json_decode($stdout, true);
	if (is_array($decoded) === false) {
		$jsonStartPos = strpos($stdout, '{');
		if ($jsonStartPos !== false) {
			$jsonCandidate = substr($stdout, $jsonStartPos);
			$decoded = json_decode($jsonCandidate, true);
		}
	}
	if (is_array($decoded) === false) {
		return mb_substr($stdout, 0, 2000);
	}
	$summary = [
		'status' => $decoded['status'] ?? null,
		'total' => $decoded['total'] ?? null,
		'completed' => $decoded['completed'] ?? null,
		'skipped' => $decoded['skipped'] ?? null,
		'failed' => $decoded['failed'] ?? null,
		'details_count' => isset($decoded['details']) && is_array($decoded['details']) ? count($decoded['details']) : null,
	];
	return json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false) {
	writeOrderWebhookCronLog($logFile, 'lock file open failed: ' . $lockFile);
	exit(1);
}
if (@flock($lockFp, LOCK_EX | LOCK_NB) === false) {
	writeOrderWebhookCronLog($logFile, 'skipped due to lock');
	fclose($lockFp);
	exit(0);
}
$startedAt = microtime(true);
writeOrderWebhookCronLog($logFile, 'start target=' . $target);
try {
	$phpBin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
	$command = escapeshellarg($phpBin) . ' ' . escapeshellarg($target);
	$descriptors = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$process = @proc_open($command, $descriptors, $pipes);
	if (is_resource($process) === false) {
		writeOrderWebhookCronLog($logFile, 'proc_open failed');
		exit(1);
	}
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	$summary = summarizeOrderWebhookCronOutput($stdout);
	$stderrLog = trim((string)$stderr);
	if ($stderrLog !== '') {
		$stderrLog = mb_substr($stderrLog, 0, 2000);
	}
	writeOrderWebhookCronLog($logFile, 'exit_code=' . (int)$exitCode);
	writeOrderWebhookCronLog($logFile, 'stdout_summary=' . ($summary === '' ? '(empty)' : $summary));
	if ($stderrLog !== '') {
		writeOrderWebhookCronLog($logFile, 'stderr=' . $stderrLog);
	}
	$elapsed = round(microtime(true) - $startedAt, 3);
	writeOrderWebhookCronLog($logFile, 'end elapsed=' . $elapsed . 's');
	if ((int)$exitCode !== 0) {
		exit((int)$exitCode);
	}
	exit(0);
} catch (Throwable $e) {
	writeOrderWebhookCronLog($logFile, 'exception=' . $e->getMessage());
	exit(1);
} finally {
	@flock($lockFp, LOCK_UN);
	@fclose($lockFp);
}
