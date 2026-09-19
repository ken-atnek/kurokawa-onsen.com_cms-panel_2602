<?php
/*
 * [Food Menu temporary image lifecycle]
 */

const FOOD_MENU_TEMP_TTL_SECONDS = 7 * 24 * 60 * 60;

/**
 * SESSION draftが指すFood Menu専用temporary fileを検証する
 *  tmp_upload直下のserver生成file以外は返さない
 */
function foodMenuTempDraftPath($draft, $root = null)
{
	$dir = realpath($root ?? __DIR__ . '/../../tmp_upload');
	if (!is_array($draft) || !is_string($draft['path'] ?? null) ||
		!is_string($draft['name'] ?? null) || !is_string($draft['extension'] ?? null) ||
		!is_string($dir) || basename($draft['name']) !== $draft['name'] ||
		preg_match('/\Afood-menu-[0-9]{14}-[0-9a-f]{32}\.(?:jpg|jpeg|png|webp)\z/D', $draft['name']) !== 1 ||
		strtolower(pathinfo($draft['name'], PATHINFO_EXTENSION)) !== $draft['extension']) {
		return false;
	}
	$expected = $dir . DIRECTORY_SEPARATOR . $draft['name'];
	$actual = realpath($draft['path']);
	return $actual !== false && $actual === realpath($expected) && dirname($actual) === $dir &&
		is_file($expected) && !is_link($expected) ? $expected : false;
}

/**
 * SESSION所有draftの物理fileを先に破棄する
 *  file削除失敗時もmetadataは従来どおり失効させ、期限付きGCへ委ねる
 */
function foodMenuRemoveSessionDraft($key, $root = null)
{
	$draft = $_SESSION['food_menu_image_drafts'][$key] ?? null;
	$path = foodMenuTempDraftPath($draft, $root);
	if ($path !== false) {
		@unlink($path);
	}
	unset($_SESSION['food_menu_image_drafts'][$key]);
}

/**
 * 別client画面への遷移前にFood Menuの旧draftを回収する
 *  後続のpage-instance cleanupがSESSION情報を消す前に呼ぶ
 */
function foodMenuCleanupDraftsOnPageEntry($root = null)
{
	$drafts = $_SESSION['food_menu_image_drafts'] ?? null;
	if (is_array($drafts)) {
		foreach (array_keys($drafts) as $key) {
			foodMenuRemoveSessionDraft($key, $root);
		}
	}
	unset($_SESSION['food_menu_image_drafts']);
}

/**
 * 期限超過したFood Menu専用temporary fileだけを回収する
 *  現在のSESSIONに保持されたdraftは期限を超えても削除しない
 */
function foodMenuSweepExpiredTempImages($root = null, $now = null)
{
	$dir = realpath($root ?? __DIR__ . '/../../tmp_upload');
	if (!is_string($dir) || !is_dir($dir)) {
		return false;
	}
	$now = $now ?? time();
	$protected = [];
	foreach (is_array($_SESSION['food_menu_image_drafts'] ?? null) ? $_SESSION['food_menu_image_drafts'] : [] as $draft) {
		$path = foodMenuTempDraftPath($draft, $dir);
		if ($path !== false) {
			$protected[$path] = true;
		}
	}
	try {
		foreach (new DirectoryIterator($dir) as $entry) {
			if ($entry->isDot() || $entry->isLink() || !$entry->isFile() ||
				preg_match('/\Afood-menu-[0-9]{14}-[0-9a-f]{32}\.(?:jpg|jpeg|png|webp)\z/D', $entry->getFilename()) !== 1) {
				continue;
			}
			$path = $entry->getPathname();
			$resolved = realpath($path);
			if ($resolved === false || $resolved !== $path || dirname($resolved) !== $dir ||
				isset($protected[$path]) || $entry->getMTime() >= $now - FOOD_MENU_TEMP_TTL_SECONDS) {
				continue;
			}
			@unlink($path);
		}
	} catch (Throwable $e) {
		return false;
	}
	return true;
}
