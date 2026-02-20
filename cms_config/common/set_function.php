<?php
/*
 * 型宣言を厳密にする
 */

declare(strict_types=1);

/*
 * [共通処理関数定義]
 */
#-------------------------------------
# アクティブメニュー判定
#-------------------------------------
#require(呼び出し元)のファイル名を取得
$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
$requireFilePath = $trace[1]['file'] ?? $trace[0]['file'] ?? __FILE__;
#ファイルパスから動的に変数生成
$fileParts = pathinfo($requireFilePath);
$fileName = $fileParts['filename'];
#$fileNameから先頭11文字を取得
$shortFileName = substr($fileName, 0, 11);
#メニュー側で参照される *_active 変数は未定義Warningを避けるため初期化する
#命名規則：master01_02.php / client01_02.php のような形式を前提に、該当ディレクトリを自動スキャン
$prefix = substr($shortFileName, 0, 6); // 'master' or 'client'
$scanDir = null;
if ($prefix === 'master') {
	$scanDir = rtrim((string)DOCUMENT_ROOT_PATH, '/\\') . '/96-master';
} elseif ($prefix === 'client') {
	$scanDir = rtrim((string)DOCUMENT_ROOT_PATH, '/\\') . '/96-client';
}
if ($scanDir !== null) {
	$phpFiles = glob($scanDir . '/*.php') ?: [];
	foreach ($phpFiles as $phpFilePath) {
		$baseName = pathinfo($phpFilePath, PATHINFO_FILENAME);
		$varName = $baseName . '_active';
		if (!isset(${$varName})) {
			${$varName} = '';
		}
	}
}
${$shortFileName . '_active'} = ' class="is-active"';


/*
 * [ページャー処理関数定義]
 */
function makePagerBoxTag(int $pageNumber, int $totalPages, int $maxPager = 8, string $onClickFunction = 'movePage'): string
{
	#トータルページ数・現在ページ数・最大表示ページ数・onclick関数名のバリデーション
	if ($totalPages < 1) {
		$totalPages = 1;
	}
	#ページ番号補正
	if ($pageNumber < 1) {
		$pageNumber = 1;
	} elseif ($pageNumber > $totalPages) {
		$pageNumber = $totalPages;
	}
	#最大表示ページ数補正
	if ($maxPager < 1) {
		$maxPager = 1;
	}
	#onclick関数名バリデーション（英数字・アンダースコアのみ、先頭は数字不可）
	if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $onClickFunction)) {
		$onClickFunction = 'movePage';
	}
	#表示開始ページ・終了ページ計算
	$startPage = 1;
	$endPage = $totalPages;
	if ($totalPages > $maxPager) {
		$startPage = $pageNumber;
		$maxStart = $totalPages - ($maxPager - 1);
		if ($startPage > $maxStart) {
			$startPage = $maxStart;
		}
		$endPage = $startPage + ($maxPager - 1);
	}
	if ($startPage < 1) {
		$startPage = 1;
	}
	if ($endPage > $totalPages) {
		$endPage = $totalPages;
	}
	$counterReset = $startPage - 1;
	#タグ生成開始
	$resultHtml = '';
	$resultHtml .= "          <div class=\"box-pager\">\n";
	$resultHtml .= "            <div class=\"box_number\">\n";
	#「＜」ボタン
	if ($pageNumber > 1) {
		$prevPage = $pageNumber - 1;
		if ($prevPage < 1) {
			$prevPage = 1;
		}
		$resultHtml .= '              <button type="button" class="btn_prev" onclick="' . $onClickFunction . '(' . (int)$prevPage . ')"></button>' . "\n";
	}
	#ページ番号
	$resultHtml .= '              <nav style="counter-reset:number ' . (int)$counterReset . '">' . "\n";
	for ($p = $startPage; $p <= $endPage; $p++) {
		$isActive = ($p === $pageNumber) ? 'is-active' : '';
		$resultHtml .= '                <a href="#" class="' . $isActive . '" onclick="' . $onClickFunction . '(' . (int)$p . ');return false;"></a>' . "\n";
	}
	$resultHtml .= "              </nav>\n";
	#「＞」ボタン
	if ($pageNumber < $totalPages) {
		$nextPage = $pageNumber + 1;
		if ($nextPage > $totalPages) {
			$nextPage = $totalPages;
		}
		$resultHtml .= '              <button type="button" class="btn_next" onclick="' . $onClickFunction . '(' . (int)$nextPage . ')"></button>' . "\n";
	}
	$resultHtml .= "            </div>\n";
	$resultHtml .= "            <div class=\"box_input\">\n";
	$sanitizeAndCall = 'var m=this.value.match(/\\d+/);'
		. 'if(!m){this.value="";return;}'
		. 'var p=parseInt(m[0],10);'
		. 'if(isNaN(p)){this.value="";return;}'
		. 'if(p<1)p=1;'
		. 'if(p>' . (int)$totalPages . ')p=' . (int)$totalPages . ';'
		. 'this.value="";'
		. $onClickFunction . '(p);';
	$onchange = $sanitizeAndCall;
	$onkeydown = 'if(event && (event.key==="Enter" || event.keyCode===13)){' . $sanitizeAndCall . 'return false;}';
	$resultHtml .= '              <input type="text" inputmode="numeric" pattern="\\d*" placeholder="' . (int)$pageNumber . '/' . (int)$totalPages . '" onchange="' . htmlspecialchars($onchange, ENT_QUOTES, 'UTF-8') . '" onkeydown="' . htmlspecialchars($onkeydown, ENT_QUOTES, 'UTF-8') . '">' . "\n";
	$resultHtml .= "            </div>\n";
	$resultHtml .= "          </div>\n";
	#応答
	return $resultHtml;
}

/*
 * [メール送信処理関数定義]
 */
function sendMail_Common($toEmail, $toName, $mailTitle, $mailBody, $fromEmail, $fromName, $sendAddressList)
{
	#デバッグ時は実送信しない（既存運用互換）
	if (defined('DEFINE_DEBUGFLG') && (int)DEFINE_DEBUGFLG === 1) {
		return true;
	}
	#文字コード設定（mb_send_mail の内部変換に合わせる）
	#  - 本文は ISO-2022-JP で送信されるため、ヘッダの charset も揃える
	@mb_language('Japanese');
	@mb_internal_encoding('UTF-8');
	#送信先リストの正規化
	if (!is_array($sendAddressList)) {
		$sendAddressList = [];
	}
	#改行コード設定
	$eol = "\r\n";
	#ヘッダー情報設定
	$encodedFromName = mb_encode_mimeheader((string)$fromName, 'ISO-2022-JP', 'B', $eol);
	$headers = '';
	$headers .= 'From: ' . $encodedFromName . ' <' . $fromEmail . '>' . $eol;
	$headers .= 'Reply-To: ' . $encodedFromName . ' <' . $fromEmail . '>' . $eol;
	$headers .= 'MIME-Version: 1.0' . $eol;
	$headers .= 'Content-Type: text/plain; charset=ISO-2022-JP' . $eol;
	$headers .= 'Content-Transfer-Encoding: 7bit' . $eol;
	#メール送信
	$result = @mb_send_mail((string)$toEmail, (string)$mailTitle, (string)$mailBody, $headers, "-f" . (string)$fromEmail);
	#複数アドレス送信（主送信が成功している場合のみ、失敗しても主結果は維持）
	if ($result) {
		foreach ($sendAddressList as $sendAddress) {
			$sendAddress = is_string($sendAddress) ? trim($sendAddress) : '';
			if ($sendAddress === '') {
				continue;
			}
			@mb_send_mail($sendAddress, (string)$mailTitle, (string)$mailBody, $headers, "-f" . (string)$fromEmail);
		}
	}
	#応答
	return $result;
}



/**
 * db/ を同期する
 * db/shops/product(s) を除外して db/ を同期（--delete あり）
 */
function mirrorDbSelectiveMasterByRsync(string $srcDbDir, string $destDbDir, string $masterOnlyFile): void
{
	$srcDbDir  = rtrim($srcDbDir, "/\\");
	$destDbDir = rtrim($destDbDir, "/\\");
	if ($srcDbDir === '' || $destDbDir === '' || $srcDbDir === $destDbDir) {
		return;
	}
	if (!is_dir($srcDbDir)) {
		return;
	}
	if (!is_dir($destDbDir)) {
		@mkdir($destDbDir, 0777, true);
	}
	#同時実行対策（src側でロック）
	$lockFp = @fopen($srcDbDir . '/.mirror_rsync.lock', 'c');
	if ($lockFp === false) {
		return;
	}
	if (!flock($lockFp, LOCK_EX)) {
		fclose($lockFp);
		return;
	}
	try {
		$rsync = defined('DEFINE_RSYNC_BIN') ? (string)DEFINE_RSYNC_BIN : 'rsync';
		# db/ 全体同期（--delete あり、shopのproductsだけ除外）
		# NOTE: 仕様表記ゆれ対策で product / products の両方を除外
		$cmd = escapeshellcmd($rsync)
			. ' -a --delete'
			. ' --exclude=' . escapeshellarg('.mirror_rsync.lock')
			. ' --exclude=' . escapeshellarg('shops/product/')
			. ' --exclude=' . escapeshellarg('shops/product/**')
			. ' --exclude=' . escapeshellarg('shops/products/')
			. ' --exclude=' . escapeshellarg('shops/products/**')
			. ' ' . escapeshellarg($srcDbDir . '/')
			. ' ' . escapeshellarg($destDbDir . '/')
			. ' 2>&1';
		$out = [];
		$code = 0;
		@exec($cmd, $out, $code);
		if ($code !== 0) {
			error_log('[json-mirror] rsync failed code=' . $code . ' cmd=' . $cmd . ' out=' . implode("\n", $out));
		}
	} finally {
		flock($lockFp, LOCK_UN);
		fclose($lockFp);
	}
}






/*
 * [データ処理関数定義]
 */
#-------------------------------------
# データ処理
#-------------------------------------
function convertData($data)
{
	#処理データが数値の場合はそのまま返す
	if (is_numeric($data)) {
		#大文字の場合は小文字に変換
		if (is_string($data)) {
			return strtolower($data);
		} else {
			return $data;
		}
	}
	#バックスラッシュ削除(magic_quotes_gpc=ONの場合使用する)
	#半角「'」→全角「’」
	$data = str_replace("'", "’", $data);
	#半角「"」→全角「”」
	$data = str_replace('"', "”", $data);
	#半角「<」→全角「＜」
	$data = str_replace("<", "＜", $data);
	#半角「>」→全角「＞」
	$data = str_replace(">", "＞", $data);
	#半角「&」→全角「＆」
	$data = str_replace("&", "＆", $data);
	#バックスラッシュ削除
	$data = addslashes($data);
	$data = stripslashes($data);
	#タグを無効にする
	$data = strip_tags($data);
	#$data = htmlspecialchars($data);
	$data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
	#特殊文字を削除する
	$data = trim($data);
	#半角スペース・全角スペースを削除した場合、値がNULLになったらこの処理を反映させる
	#半角スペースを削除
	$subdata = str_replace(" ", "", $data);
	#全角スペースを削除
	$subdata = str_replace("　", "", $data);
	if ($subdata == '') {
		#半角スペースを削除
		$data = str_replace(" ", "", $data);
		#全角スペースを削除
		$data = str_replace("　", "", $data);
	}
	return $data;
}

#-------------------------------------
# DB登録テキストフォーマット処理
#  ["テキスト", "", "テキスト", "", ...] 形式に変換する
#-------------------------------------
function formatTextareaForDB(string $input): array
{
	#末尾の改行だけで "" が増えるのを防ぎたい場合は rtrim する
	#（末尾改行も保持して "" を入れたいなら rtrim を外してください）
	$input = rtrim($input, "\r\n");
	if ($input === '') {
		return [];
	}
	#改行コードを統一せず、そのまま分割（Windows/Unix/Mac対応）
	$lines = preg_split("/\r\n|\r|\n/", $input);
	$result = [];
	$count = count($lines);
	for ($i = 0; $i < $count; $i++) {
		$result[] = $lines[$i];
		#行の間に "" を挟む
		#if ($i !== $count - 1) {
		#	$result[] = "";
		#}
	}
	return $result;
}

#-------------------------------------
# 数値調整：整数 or 少数
#-------------------------------------
function normalizeMetricValue($input)
{
	if ($input === null) return null;
	#PDOが文字列で返す場合もあるので数値化
	$number = (float)$input;
	#小数部が0なら int にする
	if (abs($number - round($number)) < 0.0000001) {
		return (int)round($number);
	}
	#応答
	return $number;
}

#-------------------------------------
# 郵便番号フォーマット：ハイフン有り
#-------------------------------------
function formatPostalCode($input)
{
	#ステータス初期化
	$result = array("status" => false, "err" => null, "zipCode" => null);
	#数字を半角に変換する
	$zipCode = mb_convert_kana($input, "n");
	#数字以外を削除する
	$zipCode = preg_replace("/[^0-9]/", "", $zipCode);
	#7桁の数字でなければエラー
	if (mb_strlen($zipCode) != 7) {
		#エラーメッセージセット
		$result["err"] = "郵便番号の桁数が正しくありません";
		#処理破棄
		return $result;
	}
	#郵便番号のフォーマット変換
	$zipCode01 = substr($zipCode, 0, 3);
	$zipCode02 = substr($zipCode, -4, 4);
	$result["zipCode"] = "${zipCode01}-${zipCode02}";
	#チェックOKセット
	$result["status"] = true;
	#処理終了
	return $result;
}

#-------------------------------------
# 都道府県・市・区・その他に分割
#-------------------------------------
function separateAddress(string $address)
{
	#都道府県
	$prefPattern = '(.{2,3}?[都道府県])';
	#市
	$cityPattern = '(.+?市)';
	#区（任意）
	$wardPattern = '(.*?区)?';
	#その他
	$otherPattern = '(.*)';
	$pattern = '@^' . $prefPattern . $cityPattern . $wardPattern . $otherPattern . '@u';
	#都道府県・市・区・その他に分割
	if (preg_match($pattern, $address, $matches) !== 1) {
		return [
			'state' => null,
			'city' => null,
			'other' => null
		];
	}
	#区が存在する場合はotherに区を入れる
	$other = '';
	if (!empty($matches[3])) {
		$other = $matches[3] . (isset($matches[4]) ? $matches[4] : '');
	} else {
		$other = isset($matches[4]) ? $matches[4] : '';
	}
	return [
		'state' => $matches[1],
		'city' => $matches[2],
		'other' => $other,
	];
}

#-------------------------------------
# 電話番号フォーマット：ハイフン有り
#-------------------------------------
function formatPhoneNumber($input, $strict = false)
{
	#電話番号の市外局番・市内局番・加入者番号のグループ定義
	$groups = array(
		5 => array(
			'01564' => 1,
			'01558' => 1,
			'01586' => 1,
			'01587' => 1,
			'01634' => 1,
			'01632' => 1,
			'01547' => 1,
			'05769' => 1,
			'04992' => 1,
			'04994' => 1,
			'01456' => 1,
			'01457' => 1,
			'01466' => 1,
			'01635' => 1,
			'09496' => 1,
			'08477' => 1,
			'08512' => 1,
			'08396' => 1,
			'08388' => 1,
			'08387' => 1,
			'08514' => 1,
			'07468' => 1,
			'01655' => 1,
			'01648' => 1,
			'01656' => 1,
			'01658' => 1,
			'05979' => 1,
			'04996' => 1,
			'01654' => 1,
			'01372' => 1,
			'01374' => 1,
			'09969' => 1,
			'09802' => 1,
			'09912' => 1,
			'09913' => 1,
			'01398' => 1,
			'01377' => 1,
			'01267' => 1,
			'04998' => 1,
			'01397' => 1,
			'01392' => 1,
		),
		4 => array(
			'0768' => 2,
			'0770' => 2,
			'0772' => 2,
			'0774' => 2,
			'0773' => 2,
			'0767' => 2,
			'0771' => 2,
			'0765' => 2,
			'0748' => 2,
			'0747' => 2,
			'0746' => 2,
			'0826' => 2,
			'0749' => 2,
			'0776' => 2,
			'0763' => 2,
			'0761' => 2,
			'0766' => 2,
			'0778' => 2,
			'0824' => 2,
			'0797' => 2,
			'0796' => 2,
			'0555' => 2,
			'0823' => 2,
			'0798' => 2,
			'0554' => 2,
			'0820' => 2,
			'0795' => 2,
			'0556' => 2,
			'0791' => 2,
			'0790' => 2,
			'0779' => 2,
			'0558' => 2,
			'0745' => 2,
			'0794' => 2,
			'0557' => 2,
			'0799' => 2,
			'0738' => 2,
			'0567' => 2,
			'0568' => 2,
			'0585' => 2,
			'0586' => 2,
			'0566' => 2,
			'0564' => 2,
			'0565' => 2,
			'0587' => 2,
			'0584' => 2,
			'0581' => 2,
			'0572' => 2,
			'0574' => 2,
			'0573' => 2,
			'0575' => 2,
			'0576' => 2,
			'0578' => 2,
			'0577' => 2,
			'0569' => 2,
			'0594' => 2,
			'0827' => 2,
			'0736' => 2,
			'0735' => 2,
			'0725' => 2,
			'0737' => 2,
			'0739' => 2,
			'0743' => 2,
			'0742' => 2,
			'0740' => 2,
			'0721' => 2,
			'0599' => 2,
			'0561' => 2,
			'0562' => 2,
			'0563' => 2,
			'0595' => 2,
			'0596' => 2,
			'0598' => 2,
			'0597' => 2,
			'0744' => 2,
			'0852' => 2,
			'0956' => 2,
			'0955' => 2,
			'0954' => 2,
			'0952' => 2,
			'0957' => 2,
			'0959' => 2,
			'0966' => 2,
			'0965' => 2,
			'0964' => 2,
			'0950' => 2,
			'0949' => 2,
			'0942' => 2,
			'0940' => 2,
			'0930' => 2,
			'0943' => 2,
			'0944' => 2,
			'0948' => 2,
			'0947' => 2,
			'0946' => 2,
			'0967' => 2,
			'0968' => 2,
			'0987' => 2,
			'0986' => 2,
			'0985' => 2,
			'0984' => 2,
			'0993' => 2,
			'0994' => 2,
			'0997' => 2,
			'0996' => 2,
			'0995' => 2,
			'0983' => 2,
			'0982' => 2,
			'0973' => 2,
			'0972' => 2,
			'0969' => 2,
			'0974' => 2,
			'0977' => 2,
			'0980' => 2,
			'0979' => 2,
			'0978' => 2,
			'0920' => 2,
			'0898' => 2,
			'0855' => 2,
			'0854' => 2,
			'0853' => 2,
			'0553' => 2,
			'0856' => 2,
			'0857' => 2,
			'0863' => 2,
			'0859' => 2,
			'0858' => 2,
			'0848' => 2,
			'0847' => 2,
			'0835' => 2,
			'0834' => 2,
			'0833' => 2,
			'0836' => 2,
			'0837' => 2,
			'0846' => 2,
			'0845' => 2,
			'0838' => 2,
			'0865' => 2,
			'0866' => 2,
			'0892' => 2,
			'0889' => 2,
			'0887' => 2,
			'0893' => 2,
			'0894' => 2,
			'0897' => 2,
			'0896' => 2,
			'0895' => 2,
			'0885' => 2,
			'0884' => 2,
			'0869' => 2,
			'0868' => 2,
			'0867' => 2,
			'0875' => 2,
			'0877' => 2,
			'0883' => 2,
			'0880' => 2,
			'0879' => 2,
			'0829' => 2,
			'0550' => 2,
			'0228' => 2,
			'0226' => 2,
			'0225' => 2,
			'0224' => 2,
			'0229' => 2,
			'0233' => 2,
			'0237' => 2,
			'0235' => 2,
			'0234' => 2,
			'0223' => 2,
			'0220' => 2,
			'0192' => 2,
			'0191' => 2,
			'0187' => 2,
			'0193' => 2,
			'0194' => 2,
			'0198' => 2,
			'0197' => 2,
			'0195' => 2,
			'0238' => 2,
			'0240' => 2,
			'0260' => 2,
			'0259' => 2,
			'0258' => 2,
			'0257' => 2,
			'0261' => 2,
			'0263' => 2,
			'0266' => 2,
			'0265' => 2,
			'0264' => 2,
			'0256' => 2,
			'0255' => 2,
			'0243' => 2,
			'0242' => 2,
			'0241' => 2,
			'0244' => 2,
			'0246' => 2,
			'0254' => 2,
			'0248' => 2,
			'0247' => 2,
			'0186' => 2,
			'0185' => 2,
			'0144' => 2,
			'0143' => 2,
			'0142' => 2,
			'0139' => 2,
			'0145' => 2,
			'0146' => 2,
			'0154' => 2,
			'0153' => 2,
			'0152' => 2,
			'0138' => 2,
			'0137' => 2,
			'0125' => 2,
			'0124' => 2,
			'0123' => 2,
			'0126' => 2,
			'0133' => 2,
			'0136' => 2,
			'0135' => 2,
			'0134' => 2,
			'0155' => 2,
			'0156' => 2,
			'0176' => 2,
			'0175' => 2,
			'0174' => 2,
			'0178' => 2,
			'0179' => 2,
			'0184' => 2,
			'0183' => 2,
			'0182' => 2,
			'0173' => 2,
			'0172' => 2,
			'0162' => 2,
			'0158' => 2,
			'0157' => 2,
			'0163' => 2,
			'0164' => 2,
			'0167' => 2,
			'0166' => 2,
			'0165' => 2,
			'0267' => 2,
			'0250' => 2,
			'0533' => 2,
			'0422' => 2,
			'0532' => 2,
			'0531' => 2,
			'0436' => 2,
			'0428' => 2,
			'0536' => 2,
			'0299' => 2,
			'0294' => 2,
			'0293' => 2,
			'0475' => 2,
			'0295' => 2,
			'0297' => 2,
			'0296' => 2,
			'0495' => 2,
			'0438' => 2,
			'0466' => 2,
			'0465' => 2,
			'0467' => 2,
			'0478' => 2,
			'0476' => 2,
			'0470' => 2,
			'0463' => 2,
			'0479' => 2,
			'0493' => 2,
			'0494' => 2,
			'0439' => 2,
			'0268' => 2,
			'0480' => 2,
			'0460' => 2,
			'0538' => 2,
			'0537' => 2,
			'0539' => 2,
			'0279' => 2,
			'0548' => 2,
			'0280' => 2,
			'0282' => 2,
			'0278' => 2,
			'0277' => 2,
			'0269' => 2,
			'0270' => 2,
			'0274' => 2,
			'0276' => 2,
			'0283' => 2,
			'0551' => 2,
			'0289' => 2,
			'0287' => 2,
			'0547' => 2,
			'0288' => 2,
			'0544' => 2,
			'0545' => 2,
			'0284' => 2,
			'0291' => 2,
			'0285' => 2,
			'0120' => 3,
			'0570' => 3,
			'0800' => 3,
			'0990' => 3,
		),
		3 => array(
			'099' => 3,
			'054' => 3,
			'058' => 3,
			'098' => 3,
			'095' => 3,
			'097' => 3,
			'052' => 3,
			'053' => 3,
			'011' => 3,
			'096' => 3,
			'049' => 3,
			'015' => 3,
			'048' => 3,
			'072' => 3,
			'084' => 3,
			'028' => 3,
			'024' => 3,
			'076' => 3,
			'023' => 3,
			'047' => 3,
			'029' => 3,
			'075' => 3,
			'025' => 3,
			'055' => 3,
			'026' => 3,
			'079' => 3,
			'082' => 3,
			'027' => 3,
			'078' => 3,
			'077' => 3,
			'083' => 3,
			'022' => 3,
			'086' => 3,
			'089' => 3,
			'045' => 3,
			'044' => 3,
			'092' => 3,
			'046' => 3,
			'017' => 3,
			'093' => 3,
			'059' => 3,
			'073' => 3,
			'019' => 3,
			'087' => 3,
			'042' => 3,
			'018' => 3,
			'043' => 3,
			'088' => 3,
			'050' => 4,
		),
		2 => array(
			'04' => 4,
			'03' => 4,
			'06' => 4,
		),
	);
	#携帯番号の厳密/緩やか判定
	$groups[3] +=
		$strict ? array(
			'020' => 3,
			'070' => 3,
			'080' => 3,
			'090' => 3,
		) : array(
			'020' => 4,
			'070' => 4,
			'080' => 4,
			'090' => 4,
		);
	#数字以外を除去
	$number = preg_replace('/[^\d]++/', '', $input);
	#グループごとに判定
	foreach ($groups as $len => $group) {
		$area = substr($number, 0, $len);
		if (isset($group[$area])) {
			$midLen = $group[$area];
			$first = $area;
			$second = substr($number, $len, $midLen);
			$third = substr($number, $len + $midLen);
			#3つ目が空なら元の値を返す
			if ($third === false || $third === '') {
				return $input;
			}
			return $first . '-' . $second . '-' . $third;
		}
	}
	#国際電話番号（例: 00123456789 → 001-23456789）
	if (preg_match('/\A(00(?:[013-8]|2\d|91[02-9])\d)(\d+)\z/', $number, $matches)) {
		return $matches[1] . '-' . $matches[2];
	}
	#該当しない場合は元の値を返す
	return $input;
}
