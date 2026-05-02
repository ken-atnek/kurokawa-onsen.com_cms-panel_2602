<?php
/*
 * [フロントJSON] warning応答設定
 */
function appendFrontendJsonWarningMessage(&$makeTag)
{
	if (!is_array($makeTag)) {
		return;
	}
	$makeTag['status'] = 'warning';
	if (strpos((string)$makeTag['msg'], 'フロント表示用JSONの更新に失敗しました。') === false) {
		$makeTag['msg'] .= '<br>フロント表示用JSONの更新に失敗しました。';
	}
}

/*
 * [フロントJSON] エラーログ出力
 */
function logFrontendJsonError($context, $shopId = null, $productId = null, $extra = [])
{
	if (function_exists('makeLog') === false) {
		return;
	}
	makeLog([
		'pageName' => 'frontend_json_error',
		'context' => $context,
		'shopId' => $shopId,
		'productId' => $productId,
		'extra' => $extra,
	]);
}
