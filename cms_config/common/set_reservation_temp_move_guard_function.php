<?php
/*
 * [飲食店予約 仮移動ガード]
 */

/**
 * 仮移動中に許可するrequestか判定
 *  予約カレンダー表示・read・解消endpoint・logoutだけを例外とする
 */
function reservationTempMoveGuardRequestIsAllowed($scriptName, $post)
{
	if (in_array($scriptName, ['logout.php', 'client04_04.php', 'proc_client04_04.php', 'proc_reservation_temp_move.php'], true)) {
		return true;
	}
	return $scriptName === 'proc_client04_04_override.php'
		&& is_array($post)
		&& ($post['action'] ?? null) === 'readActions';
}

/**
 * 共通bootstrapでguard state取得が必要か判定
 *  logout・read・仮移動endpointはDB照会前に除外する
 */
function reservationTempMoveGuardRequestNeedsState($scriptName, $post)
{
	if (in_array($scriptName, ['logout.php', 'proc_client04_04.php', 'proc_reservation_temp_move.php'], true)) {
		return false;
	}
	if ($scriptName === 'proc_client04_04_override.php' && is_array($post) && ($post['action'] ?? null) === 'readActions') {
		return false;
	}
	return true;
}

/**
 * 予約カレンダーの絶対URL pathを生成
 *  呼出元の階層に依存せずdocument rootから96-clientの配置を解決する
 */
function reservationTempMoveGuardCalendarUrl()
{
	$clientPagePath = realpath(rtrim((string)DOCUMENT_ROOT_PATH, '/\\') . '/96-client/client04_04.php');
	$webRootPath = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
	if (is_string($clientPagePath) && is_string($webRootPath)) {
		$normalizedClientPath = str_replace('\\', '/', $clientPagePath);
		$normalizedWebRootPath = rtrim(str_replace('\\', '/', $webRootPath), '/');
		if ($normalizedWebRootPath !== '' && str_starts_with($normalizedClientPath, $normalizedWebRootPath . '/')) {
			return '/' . ltrim(substr($normalizedClientPath, strlen($normalizedWebRootPath)), '/');
		}
	}

	$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
	$clientMarkerPosition = strpos($scriptName, '/96-client/');
	if ($clientMarkerPosition !== false) {
		return substr($scriptName, 0, $clientMarkerPosition) . '/96-client/client04_04.php';
	}

	$resolvedProjectPath = realpath((string)DOCUMENT_ROOT_PATH);
	$projectDirectory = is_string($resolvedProjectPath) ? basename($resolvedProjectPath) : '';
	return '/' . ($projectDirectory !== '' ? $projectDirectory . '/' : '') . '96-client/client04_04.php';
}

/**
 * 仮移動中の管理画面requestを選択的に停止
 *  pageは予約カレンダーへ誘導しendpointは副作用前にJSON errorを返す
 */
function applyReservationTempMoveRequestGuard($shopId, $scriptName, $post = [])
{
	$guardState = getReservationTempMoveGuardState($shopId);
	if ($guardState === false) {
		$guardState = ['active' => true, 'has_temp_move' => false, 'has_invalid_reference' => true];
	}
	$GLOBALS['reservationTempMoveGuardActive'] = ($guardState['active'] ?? false) === true;
	$GLOBALS['reservationTempMoveGuardState'] = $guardState;
	if (($guardState['active'] ?? false) !== true || reservationTempMoveGuardRequestIsAllowed($scriptName, $post) === true) {
		return true;
	}

	if (preg_match('/\A(?!proc_).+\.php\z/D', $scriptName) === 1) {
		header('Location: ' . reservationTempMoveGuardCalendarUrl() . '?seatMoveGuard=1');
		exit;
	}

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode([
		'tag' => '',
		'status' => 'error',
		'title' => '席移動',
		'msg' => '席の移動中です。席を確定させてからページを移動して下さい',
		'refreshRequired' => true,
	], JSON_UNESCAPED_UNICODE);
	exit;
}
