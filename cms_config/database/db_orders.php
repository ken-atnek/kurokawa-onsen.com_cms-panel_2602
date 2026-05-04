<?php
/*
 * [webhook_logs] Webhookログ登録
 */
function insertWebhookLog($entity, $eccubeId, $action, $rawPayload)
{
	global $DB_CONNECT;
	if ($entity === null || trim((string)$entity) === '') {
		return false;
	}
	if ($eccubeId === null || is_numeric($eccubeId) === false || (int)$eccubeId < 1) {
		return false;
	}
	$allowedActions = ['created', 'updated', 'deleted'];
	if (in_array((string)$action, $allowedActions, true) === false) {
		return false;
	}
	try {
		$dbFiledData = [
			'entity' => [':entity', trim((string)$entity), 0],
			'eccube_id' => [':eccube_id', (int)$eccubeId, 1],
			'action' => [':action', (string)$action, 0],
			'raw_payload' => [':raw_payload', (string)$rawPayload, 0],
			'process_status' => [':process_status', 'pending', 0],
			'received_at' => [':received_at', date('Y-m-d H:i:s'), 0],
		];
		$result = SQL_Process($DB_CONNECT, 'webhook_logs', $dbFiledData, [], 1, 2);
		if ($result != 1) {
			return false;
		}
		return (int)$DB_CONNECT->lastInsertId();
	} catch (PDOException $e) {
		return false;
	}
}
/*
 * [webhook_logs] Webhookログステータス更新
 */
function updateWebhookLogStatus($logId, $status, $errorMessage = null)
{
	global $DB_CONNECT;
	if ($logId === null || is_numeric($logId) === false || (int)$logId < 1) {
		return false;
	}
	$allowedStatus = ['pending', 'processing', 'completed', 'failed', 'skipped'];
	if (in_array((string)$status, $allowedStatus, true) === false) {
		return false;
	}
	try {
		$dbFiledData = [
			'process_status' => [':process_status', (string)$status, 0],
		];
		if (in_array((string)$status, ['completed', 'failed', 'skipped'], true)) {
			$dbFiledData['processed_at'] = [':processed_at', date('Y-m-d H:i:s'), 0];
		}
		if ($errorMessage !== null) {
			$errorMessage = (string)$errorMessage;
			$dbFiledData['error_message'] = ($errorMessage === '')
				? [':error_message', null, 2]
				: [':error_message', $errorMessage, 0];
		}
		$dbFiledValue = [
			'log_id' => [':log_id', (int)$logId, 1],
		];
		return SQL_Process($DB_CONNECT, 'webhook_logs', $dbFiledData, $dbFiledValue, 2, 2) == 1;
	} catch (PDOException $e) {
		return false;
	}
}
