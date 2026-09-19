<?php
/*
 * [Web新規予約メール共通処理]
 */

/**
 * 予約メール処理の内部異常を個人情報なしでserver logへ記録
 */
function logReservationMailSystemFailure($stage)
{
	$stage = is_string($stage) && preg_match('/\A[a-z0-9_]+\z/D', $stage) === 1 ? $stage : 'unknown';
	error_log('[reservation_mail] ' . $stage);
}

/**
 * 予約日を件名用・本文用の表示形式へ変換
 */
function buildReservationMailDateLabels($reservationDate)
{
	if (is_string($reservationDate) === false || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $reservationDate) !== 1) {
		return false;
	}
	$date = DateTimeImmutable::createFromFormat('!Y-m-d', $reservationDate, new DateTimeZone('Asia/Tokyo'));
	if ($date instanceof DateTimeImmutable === false || $date->format('Y-m-d') !== $reservationDate) {
		return false;
	}
	return [
		'subject' => $date->format('Y/m/d'),
		'body' => $date->format('Y年m月d日'),
	];
}

/**
 * メニューsnapshotを同一内容ごとに初出順で集約
 */
function buildReservationMailMenuSummary($partySize, $menuRows)
{
	if (is_int($partySize) === false || $partySize < 1 || is_array($menuRows) === false) {
		return false;
	}

	$guestNos = [];
	$groupIndexes = [];
	$groups = [];
	$total = 0;
	foreach ($menuRows as $row) {
		if (
			is_array($row) === false ||
			is_int($row['guest_no'] ?? null) === false ||
			$row['guest_no'] < 1 ||
			$row['guest_no'] > $partySize ||
			isset($guestNos[$row['guest_no']]) === true ||
			is_string($row['menu_name_snapshot'] ?? null) === false ||
			is_int($row['menu_price_snapshot'] ?? null) === false ||
			$row['menu_price_snapshot'] < 0 ||
			is_int($row['tax_included_snapshot'] ?? null) === false
		) {
			return false;
		}
		$guestNos[$row['guest_no']] = true;
		$key = json_encode([
			$row['menu_id'] ?? null,
			$row['menu_name_snapshot'],
			$row['menu_price_snapshot'],
			$row['tax_included_snapshot'],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (is_string($key) === false) {
			return false;
		}
		if (array_key_exists($key, $groupIndexes) === false) {
			$groupIndexes[$key] = count($groups);
			$groups[] = [
				'name' => $row['menu_name_snapshot'],
				'price' => $row['menu_price_snapshot'],
				'count' => 0,
			];
		}
		$groupIndex = $groupIndexes[$key];
		$groups[$groupIndex]['count']++;
		$total += $row['menu_price_snapshot'];
	}

	$selectedCount = count($guestNos);
	return [
		'is_seat_only' => $selectedCount === 0,
		'groups' => $groups,
		'unselected_count' => $partySize - $selectedCount,
		'total' => $total,
	];
}

/**
 * 食事メニュー・料金セクションの本文行を生成
 */
function buildReservationMailMenuLines($menuSummary)
{
	if (is_array($menuSummary) === false || ($menuSummary['is_seat_only'] ?? null) === true) {
		return [];
	}

	$lines = [
		'■ 食事メニュー',
		'',
	];
	foreach ($menuSummary['groups'] as $group) {
		$lines[] = '・' . $group['name'] . '　' . number_format($group['count']) . '名様';
	}
	if ($menuSummary['unselected_count'] > 0) {
		$lines[] = '・選択なし　' . number_format($menuSummary['unselected_count']) . '名様';
	}
	$lines[] = '';
	$lines[] = '■ ご選択メニュー料金';
	$lines[] = '';
	$lines[] = '※表示料金はサービス料・消費税込です。';
	$lines[] = '※下記は、ご予約時に選択された食事メニューの料金です。';
	$lines[] = '※追加注文等の料金は含まれておりません。';
	$lines[] = '';
	foreach ($menuSummary['groups'] as $group) {
		$lines[] = $group['name'];
		$lines[] = number_format($group['price']) . '円 × ' . number_format($group['count']) . '名 ＝ ' . number_format($group['price'] * $group['count']) . '円';
		$lines[] = '';
	}
	$lines[] = 'ご選択メニュー料金：' . number_format($menuSummary['total']) . '円';
	return $lines;
}

/**
 * NULLまたは空文字の備考を「なし」へ変換
 */
function buildReservationMailMemoText($value)
{
	return $value === null || $value === '' ? 'なし' : (string)$value;
}

/**
 * 保存済み予約情報から予約者向け・内部向けメールを生成
 */
function buildReservationCreatedMailMessages($context)
{
	if (
		is_array($context) === false ||
		is_array($context['reservation'] ?? null) === false ||
		is_array($context['shop'] ?? null) === false ||
		is_array($context['seat_rows'] ?? null) === false ||
		is_array($context['menu_rows'] ?? null) === false
	) {
		return false;
	}
	$reservation = $context['reservation'];
	$shop = $context['shop'];
	$dateLabels = buildReservationMailDateLabels($reservation['reservation_date'] ?? null);
	$menuSummary = buildReservationMailMenuSummary($reservation['party_size'] ?? null, $context['menu_rows']);
	if ($dateLabels === false || $menuSummary === false || empty($context['seat_rows']) === true) {
		return false;
	}

	$seatNames = [];
	foreach ($context['seat_rows'] as $seatRow) {
		if (is_array($seatRow) === false || is_string($seatRow['seat_name_snapshot'] ?? null) === false) {
			return false;
		}
		$seatNames[] = $seatRow['seat_name_snapshot'];
	}
	$menuLines = buildReservationMailMenuLines($menuSummary);
	$customerName = (string)$reservation['customer_name'];
	$customerNote = buildReservationMailMemoText($reservation['customer_note'] ?? null);
	$shopMemo = buildReservationMailMemoText($reservation['shop_memo'] ?? null);

	$customerLines = [
		$customerName . ' 様',
		'',
		'この度は「黒川温泉観光協会ホームページ」をご利用いただき、誠にありがとうございます。',
		'',
		'以下の内容でご予約が完了いたしました。',
		'ご予約内容をご確認くださいますようお願いいたします。',
		'',
		'',
		'■ ご予約内容',
		'',
		'予約番号：' . $reservation['id'],
		'ご予約日：' . $dateLabels['body'],
		'ご予約施設名：' . $shop['shop_name'],
		'',
		'代表者氏名：' . $customerName . ' 様',
		'電話番号：' . $reservation['customer_tel'],
		'メールアドレス：' . $reservation['customer_email'],
		'ご予約人数：' . number_format($reservation['party_size']) . '名',
	];
	if (empty($menuLines) === false) {
		$customerLines[] = '';
		$customerLines = array_merge($customerLines, $menuLines);
	}
	$customerLines = array_merge($customerLines, [
		'',
		'■ 備考',
		'',
		$customerNote,
		'',
		'',
		'────────────────────',
		'',
		'黒川温泉観光協会',
		'〒869-2402',
		'熊本県阿蘇郡南小国町満願寺黒川温泉6595-3 べっちん館',
		'TEL：0967-48-8130',
		'',
		'────────────────────',
	]);

	$internalLines = [
		'黒川温泉観光協会ホームページより、新しいご予約が入りました。',
		'',
		'',
		'■ 新規ご予約通知',
		'',
		$customerName . ' 様より',
		'以下の通り、ご予約を受付しました。',
		'',
		'',
		'■ ご予約内容',
		'',
		'予約番号：' . $reservation['id'],
		'ご予約日：' . $dateLabels['body'],
		'予約経路：Web',
		'ご予約施設名：' . $shop['shop_name'],
		'',
		'代表者氏名：' . $customerName . ' 様',
		'ふりがな：' . $reservation['customer_kana'],
		'電話番号：' . $reservation['customer_tel'],
		'メールアドレス：' . $reservation['customer_email'],
		'ご予約人数：' . number_format($reservation['party_size']) . '名',
		'',
		'割り当て席：' . implode(' / ', $seatNames),
	];
	if ($menuSummary['is_seat_only'] === true) {
		$internalLines[] = '※席のみ予約';
	} else {
		$internalLines[] = '';
		$internalLines = array_merge($internalLines, $menuLines);
	}
	$internalLines = array_merge($internalLines, [
		'',
		'■ お客様からの備考',
		'',
		$customerNote,
		'',
		'',
		'■ 店舗メモ',
		'',
		$shopMemo,
		'',
		'',
		'予約内容の詳細は、管理画面よりご確認ください。',
	]);

	return [
		'customer' => [
			'subject' => '【黒川温泉観光協会】ご予約確認メール（ご予約日：' . $dateLabels['subject'] . '）',
			'body' => implode("\n", $customerLines) . "\n",
		],
		'internal' => [
			'subject' => '【黒川温泉観光協会】新規予約のお知らせ（ご予約日：' . $dateLabels['subject'] . '）',
			'body' => implode("\n", $internalLines) . "\n",
		],
	];
}

/**
 * COMMIT済みの保存値・snapshotから予約メール用contextを取得
 */
function getReservationCreatedMailContext($shopId, $reservationId)
{
	foreach (['getReservationDetail', 'getReservationMailShop', 'getReservationDetailSeatRows', 'getReservationDetailMenuRows'] as $requiredFunction) {
		if (function_exists($requiredFunction) === false) {
			return false;
		}
	}
	$reservation = getReservationDetail($shopId, $reservationId);
	$shop = getReservationMailShop($shopId);
	$seatRows = getReservationDetailSeatRows($shopId, $reservationId);
	$menuRows = getReservationDetailMenuRows($shopId, $reservationId);
	if (
		is_array($reservation) === false ||
		is_array($shop) === false ||
		is_array($seatRows) === false ||
		is_array($menuRows) === false ||
		($reservation['reservation_route'] ?? null) !== 1
	) {
		return false;
	}
	return [
		'reservation' => $reservation,
		'shop' => $shop,
		'seat_rows' => $seatRows,
		'menu_rows' => $menuRows,
	];
}

/**
 * メールログを記録し、失敗時は個人情報なしでserver logへ残す
 */
function recordReservationMailResult($reservationId, $recipientType, $recipientAddress, $status, $lastError)
{
	if (
		function_exists('insertReservationMailLog') === false ||
		insertReservationMailLog($reservationId, $recipientType, $recipientAddress, $status, $lastError) !== true
	) {
		logReservationMailSystemFailure('log_insert_failed_type_' . (int)$recipientType);
		return false;
	}
	return true;
}

/**
 * メール準備失敗時に4宛先分の失敗ログを可能な範囲で記録
 */
function recordReservationMailPreparationFailure($reservationId, $lastError, $context = null)
{
	global $infoMaster, $sendAddressList;
	$associationAddress = is_string($infoMaster ?? null) ? $infoMaster : null;
	$serverAddress = is_array($sendAddressList ?? null) && is_string($sendAddressList[0] ?? null)
		? $sendAddressList[0]
		: null;
	$customerAddress = is_array($context) && is_string($context['reservation']['customer_email'] ?? null)
		? $context['reservation']['customer_email']
		: null;
	$shopAddress = is_array($context) && is_string($context['shop']['email'] ?? null)
		? $context['shop']['email']
		: null;
	$addresses = [1 => $customerAddress, 2 => $shopAddress, 3 => $associationAddress, 4 => $serverAddress];
	$result = true;
	foreach ($addresses as $recipientType => $recipientAddress) {
		$status = 2;
		$recipientLastError = $lastError;
		if ($recipientType === 2 && is_array($context) && ($recipientAddress === null || $recipientAddress === '')) {
			$status = 3;
			$recipientLastError = null;
		}
		if (recordReservationMailResult($reservationId, $recipientType, $recipientAddress, $status, $recipientLastError) !== true) {
			$result = false;
		}
	}
	return $result;
}

/**
 * Web新規予約メールを4宛先へ順番に同期送信し結果を個別記録
 */
function sendReservationCreatedNotificationMails($shopId, $reservationId)
{
	global $DEFINE_NO_REPLY, $DEFINE_MAIL_SENDER_NAME, $infoMaster, $sendAddressList;
	try {
		$context = getReservationCreatedMailContext($shopId, $reservationId);
	} catch (Throwable $e) {
		$context = false;
	}
	if ($context === false) {
		recordReservationMailPreparationFailure($reservationId, 'mail_context_read_failed');
		logReservationMailSystemFailure('context_read_failed');
		return false;
	}
	try {
		$messages = buildReservationCreatedMailMessages($context);
	} catch (Throwable $e) {
		$messages = false;
	}
	if ($messages === false) {
		recordReservationMailPreparationFailure($reservationId, 'mail_message_build_failed', $context);
		logReservationMailSystemFailure('message_build_failed');
		return false;
	}

	$reservation = $context['reservation'];
	$shop = $context['shop'];
	$associationAddress = is_string($infoMaster ?? null) ? $infoMaster : null;
	$serverAddress = is_array($sendAddressList ?? null) && is_string($sendAddressList[0] ?? null)
		? $sendAddressList[0]
		: null;
	$recipients = [
		[
			'type' => 1,
			'address' => $reservation['customer_email'],
			'name' => $reservation['customer_name'],
			'message' => $messages['customer'],
		],
		[
			'type' => 2,
			'address' => $shop['email'],
			'name' => $shop['shop_name'],
			'message' => $messages['internal'],
		],
		[
			'type' => 3,
			'address' => $associationAddress,
			'name' => '黒川温泉観光協会',
			'message' => $messages['internal'],
		],
		[
			'type' => 4,
			'address' => $serverAddress,
			'name' => 'サーバー管理者',
			'message' => $messages['customer'],
		],
	];
	$fromEmail = is_string($DEFINE_NO_REPLY ?? null) ? $DEFINE_NO_REPLY : '';
	$fromName = is_string($DEFINE_MAIL_SENDER_NAME ?? null) && $DEFINE_MAIL_SENDER_NAME !== ''
		? $DEFINE_MAIL_SENDER_NAME
		: '黒川温泉観光協会';
	$senderIsValid = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) !== false;
	$allLogsSucceeded = true;

	foreach ($recipients as $recipient) {
		$recipientAddress = is_string($recipient['address']) ? $recipient['address'] : null;
		$logAddress = $recipientAddress === '' ? null : $recipientAddress;
		$status = 2;
		$lastError = null;

		if ($recipientAddress === null || $recipientAddress === '') {
			if ($recipient['type'] === 2) {
				$status = 3;
			} else {
				$lastError = 'recipient_address_missing';
			}
		} elseif (filter_var($recipientAddress, FILTER_VALIDATE_EMAIL) === false) {
			$lastError = 'recipient_address_invalid';
		} elseif ($senderIsValid === false) {
			$lastError = 'sender_address_invalid';
		} elseif (function_exists('sendMail_Common') === false) {
			$lastError = 'mail_function_unavailable';
		} else {
			try {
				$mailResult = sendMail_Common(
					$recipientAddress,
					$recipient['name'],
					$recipient['message']['subject'],
					$recipient['message']['body'],
					$fromEmail,
					$fromName,
					[]
				);
				if ($mailResult === true) {
					$status = 1;
				} else {
					$lastError = 'mail_send_failed';
				}
			} catch (Throwable $e) {
				$lastError = 'mail_send_exception';
			}
		}

		if (recordReservationMailResult($reservationId, $recipient['type'], $logAddress, $status, $lastError) !== true) {
			$allLogsSucceeded = false;
		}
	}
	return $allLogsSucceeded;
}
