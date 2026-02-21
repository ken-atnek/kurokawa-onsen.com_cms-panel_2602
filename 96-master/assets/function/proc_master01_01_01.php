<?php
/*
 * [96-master/assets/function/proc_master01_01_01.php]
 *  - 管理画面 -
 *  店舗：アルバム管理
 *
 * [初版]
 *  2026.2.17
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#フォルダ情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_folders.php';
#写真情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_photos.php';

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
	'tag' => '',
	'rec' => '',
	'status' => '',
	'ymd' => '',
	'msg' => '',
	'title' => '',
);

#onclick等で安全にJS文字列を埋め込むためのフラグ
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#=============#
# POSTチェック
#-------------#
#セッションキー（画面インスタンス識別）
$noUpDateKey = isset($_POST['noUpDateKey']) ? (string)$_POST['noUpDateKey'] : '';
$currentNoUpDateKey = isset($_SESSION['sKey']) ? (string)$_SESSION['sKey'] : '';
if ($noUpDateKey === '' || isset($_SESSION[$noUpDateKey]) === false) {
	if ($currentNoUpDateKey !== '' && isset($_SESSION[$currentNoUpDateKey])) {
		$noUpDateKey = $currentNoUpDateKey;
	} else {
		header('Content-Type: application/json; charset=UTF-8');
		$makeTag['status'] = 'error';
		$makeTag['title'] = 'セッションエラー';
		$makeTag['msg'] = 'セッションが切れました。ページを再読み込みしてください。';
		echo json_encode($makeTag);
		exit;
	}
}

#=============#
# POSTチェック
#-------------#
#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : 'new';
#フォルダ追加／写真登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#editPhotoDetail は編集画面のため、method 未送信でも edit 扱いにする
if ((string)$action === 'editPhotoDetail') {
	$method = 'edit';
}
#shopId が不要な（アップロード/ドラフト破棄など）アクション
$uploadOnlyActions = [
	'discardUploadDraft',
	'preUploadImage',
	'replaceUploadImage',
	'deleteUploadImage',
];
#店舗ID
$shopId = isset($_POST['shopId']) ? $_POST['shopId'] : null;
#店舗IDが必要なアクションでは店舗情報取得（upload-only は shopId なしでも処理する）
if (!in_array((string)$action, $uploadOnlyActions, true)) {
	if ($shopId !== null) {
		#店舗情報
		$shopData = getShops_FindById($shopId);
		if ($shopData === null) {
			#店舗情報が存在しない場合
			header('Content-Type: application/json; charset=UTF-8');
			$makeTag['status'] = 'error';
			$makeTag['title'] = '店舗情報エラー';
			$makeTag['msg'] = '店舗情報が見つかりませんでした。ページを再読み込みしてください。';
			echo json_encode($makeTag);
			exit;
		} else {
			#店舗情報が存在する場合はフォルダと写真情報も取得
			$folderData = getFolderList($shopId);
			$photoData = getPhotoList($shopId);
		}
	} else {
		#店舗IDがない場合
		header('Content-Type: application/json; charset=UTF-8');
		$makeTag['status'] = 'error';
		$makeTag['title'] = '店舗情報エラー';
		$makeTag['msg'] = '店舗IDが取得できませんでした。ページを再読み込みしてください。';
		echo json_encode($makeTag);
		exit;
	}
}
#フォルダID
$folderId = isset($_POST['folderId']) ? $_POST['folderId'] : null;
#画像アップロード先（領域名→セッションキーへ変換）
$uploadAreas = [];
if (isset($_POST['up_image_area'])) {
	$uploadAreas = $_POST['up_image_area'];
	if (!is_array($uploadAreas)) {
		$uploadAreas = array($uploadAreas);
	}
}
#フォーム側が upload_image_area のみ渡してくる場合のフォールバック
$uploadImageArea = isset($_POST['upload_image_area']) ? (string)$_POST['upload_image_area'] : '';
if (empty($uploadAreas) && $uploadImageArea !== '') {
	$uploadAreas = [$uploadImageArea];
}
#画面インスタンスキー配下でセッションキーを一意化
$imageUploadSessionKeys = [];
foreach ($uploadAreas as $a) {
	if (!is_string($a) || $a === '') {
		continue;
	}
	$imageUploadSessionKeys[] = $noUpDateKey . '__upload__' . $a;
}
$targetImageUploadSessionKey = $imageUploadSessionKeys[0] ?? '';

#***** モード毎処理 *****#
$errCheck = 0;
#削除写真保存フォルダキー
$deletePhotoFolderKey = 0;

/**
 * tmpファイル掃除（存在すれば削除）
 */
function cleanupFiles(array $paths): void
{
	foreach ($paths as $p) {
		if (!is_string($p) || $p === '') {
			continue;
		}
		if (file_exists($p) && is_file($p)) {
			@unlink($p);
		}
	}
}
/**
 * shops_folders: 削除済み(is_active=0)の同名フォルダが残っていると UNIQUE により再利用できないため、
 * 同名の削除済みレコードを退避名へリネームしてユニーク枠を解放する。
 */
function archiveDeletedFoldersWithSameName($shopId, string $folderName, $parentFolderId = null): void
{
	global $DB_CONNECT;
	if ($shopId === null || !is_string($folderName) || $folderName === '') {
		return;
	}
	try {
		if ($parentFolderId === null || $parentFolderId === '') {
			$sql = 'SELECT folder_id, folder_name FROM shops_folders WHERE shop_id = :shop_id AND parent_folder_id IS NULL AND folder_name = :folder_name AND is_active = 0';
			$stmt = $DB_CONNECT->prepare($sql);
			$stmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
			$stmt->bindValue(':folder_name', $folderName);
		} else {
			$sql = 'SELECT folder_id, folder_name FROM shops_folders WHERE shop_id = :shop_id AND parent_folder_id = :parent_folder_id AND folder_name = :folder_name AND is_active = 0';
			$stmt = $DB_CONNECT->prepare($sql);
			$stmt->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
			$stmt->bindValue(':parent_folder_id', (int)$parentFolderId, PDO::PARAM_INT);
			$stmt->bindValue(':folder_name', $folderName);
		}
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$stmt->closeCursor();
		if (empty($rows)) {
			return;
		}
		foreach ($rows as $r) {
			$fid = (string)($r['folder_id'] ?? '');
			$orig = (string)($r['folder_name'] ?? $folderName);
			if ($fid === '') {
				continue;
			}
			$suffix = '__archived__' . $fid . '__' . date('YmdHis');
			$newName = $orig . $suffix;
			if (function_exists('mb_strlen') && function_exists('mb_substr')) {
				$max = 255 - mb_strlen($suffix, 'UTF-8');
				if ($max < 0) {
					$max = 0;
				}
				if (mb_strlen($orig, 'UTF-8') > $max) {
					$newName = mb_substr($orig, 0, $max, 'UTF-8') . $suffix;
				}
			}
			$u = $DB_CONNECT->prepare('UPDATE shops_folders SET folder_name = :folder_name, updated_at = :updated_at WHERE folder_id = :folder_id');
			$u->bindValue(':folder_name', $newName);
			$u->bindValue(':updated_at', date('Y-m-d H:i:s'));
			$u->bindValue(':folder_id', (int)$fid, PDO::PARAM_INT);
			$u->execute();
			$u->closeCursor();
		}
	} catch (PDOException $e) {
		return;
	}
}
/**
 * 公開パス（/db/... や db/...）からフロント公開ディレクトリ配下の絶対パスへ変換
 */
function resolveFrontendAbsolutePath(string $filePath): string
{
	if (!is_string($filePath) || $filePath === '') {
		return '';
	}
	$p = str_replace('\\', '/', $filePath);
	#念のためURL形式が混ざっていてもパス部分だけ使う
	$parsed = parse_url($p);
	if (is_array($parsed) && isset($parsed['path']) && is_string($parsed['path'])) {
		$p = $parsed['path'];
	}
	$p = ltrim($p, '/');
	$root = defined('DEFINE_FRONTEND_DIR_PATH') ? (string)DEFINE_FRONTEND_DIR_PATH : '';
	$root = str_replace('\\', '/', $root);
	if ($root === '') {
		return '';
	}
	return rtrim($root, '/') . '/' . $p;
}

#***** タグ生成開始 *****#
switch ($action) {
	#***** フォルダ追加 *****#
	case 'addFolder': {
			#=============#
			# POSTチェック
			#-------------#
			#新規追加フォルダ名
			$addFolderName = isset($_POST['addFolderName']) ? $_POST['addFolderName'] : null;
			#フォルダ名の入力があれば登録
			if ($addFolderName != "") {
				#削除済み同名が残っている場合は退避してユニーク枠を解放
				archiveDeletedFoldersWithSameName($shopId, (string)$addFolderName, null);
				#登録用配列：初期化
				$dbFiledData = array();
				#登録情報セット
				$dbFiledData['shop_id'] = array(':shop_id', $shopId, 1);
				$dbFiledData['folder_name'] = array(':folder_name', $addFolderName, 0);
				$dbFiledData['is_active'] = array(':is_active', 1, 1);
				$dbFiledData['created_at'] = array(':created_at', date("Y-m-d H:i:s"), 0);
				#更新用キー：初期化
				$dbFiledValue = array();
				#処理モード：[1].新規追加｜[2].更新｜[3].削除
				$processFlg = 1;
				#実行モード：[1].トランザクション｜[2].即実行
				$exeFlg = 2;
				#DB更新
				$dbSuccessFlg = SQL_Process($DB_CONNECT, "shops_folders", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
				if ($dbSuccessFlg == 1) {
					$makeTag['status'] = 'success';
					$makeTag['msg'] = '新規フォルダ「' . $addFolderName . '」を追加しました。';
					#追加したフォルダIDを保持（格納フォルダを選択済みにするため）
					$insertId = '';
					try {
						$insertId = (string)$DB_CONNECT->lastInsertId();
					} catch (Exception $e) {
						$insertId = '';
					}
					if ($insertId !== '' && $insertId !== '0') {
						$folderId = $insertId;
					}
				} else {
					$lastDbErr = $GLOBALS['DB_LAST_ERROR'] ?? null;
					if (is_array($lastDbErr)) {
						$sqlstate = (string)($lastDbErr['sqlstate'] ?? '');
						$msg = (string)($lastDbErr['message'] ?? '');
						if ($sqlstate === '23000' && strpos($msg, 'Duplicate entry') !== false) {
							$errCheck = 'duplicate-addFolder';
						} else {
							$errCheck = 'ng-addFolder';
						}
					} else {
						$errCheck = 'ng-addFolder';
					}
				}
			}
		}
		break;
	#***** フォルダ名変更 *****#
	case 'editFolderName': {
			#=============#
			# POSTチェック
			#-------------#
			#変更フォルダ名
			$editFolderName = isset($_POST['addFolderName']) ? $_POST['addFolderName'] : null;
			#フォルダ名の入力があれば登録
			if ($editFolderName != "") {
				#対象フォルダの親を取得し、同一階層の削除済み同名があれば退避
				$parentFolderId = null;
				try {
					$q = $DB_CONNECT->prepare('SELECT parent_folder_id FROM shops_folders WHERE folder_id = :folder_id AND shop_id = :shop_id');
					$q->bindValue(':folder_id', (int)$folderId, PDO::PARAM_INT);
					$q->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
					$q->execute();
					$r = $q->fetch(PDO::FETCH_ASSOC);
					$q->closeCursor();
					$parentFolderId = $r['parent_folder_id'] ?? null;
				} catch (PDOException $e) {
					$parentFolderId = null;
				}
				archiveDeletedFoldersWithSameName($shopId, (string)$editFolderName, $parentFolderId);
				#登録用配列：初期化
				$dbFiledData = array();
				#登録情報セット
				$dbFiledData['folder_name'] = array(':folder_name', $editFolderName, 0);
				$dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
				#処理モード：[1].新規追加｜[2].更新｜[3].削除
				$processFlg = 2;
				#実行モード：[1].トランザクション｜[2].即実行
				$exeFlg = 2;
				#更新用キー：初期化
				$dbFiledValue = array();
				$dbFiledValue['folder_id'] = array(':folder_id', $folderId, 1);
				$dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
				#DB更新
				$dbSuccessFlg = SQL_Process($DB_CONNECT, "shops_folders", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
				if ($dbSuccessFlg == 1) {
					$makeTag['status'] = 'success';
					$makeTag['msg'] = 'フォルダを「' . $editFolderName . '」に変更しました。';
					#フォルダ名変更完了：モード変更
					$action = 'editedFolderName';
				} else {
					$lastDbErr = $GLOBALS['DB_LAST_ERROR'] ?? null;
					if (is_array($lastDbErr)) {
						$sqlstate = (string)($lastDbErr['sqlstate'] ?? '');
						$msg = (string)($lastDbErr['message'] ?? '');
						if ($sqlstate === '23000' && strpos($msg, 'Duplicate entry') !== false) {
							$errCheck = 'duplicate-editFolder';
						} else {
							$errCheck = 'ng-editFolder';
						}
					} else {
						$errCheck = 'ng-editFolder';
					}
				}
			}
		}
		break;
	#***** フォルダ削除 *****#
	case 'deleteFolder': {
			#=============#
			# POSTチェック
			#-------------#
			#フォルダキー
			$folderId = isset($_POST['folderId']) ? $_POST['folderId'] : null;
			#削除するフォルダ名
			$folderName = isset($_POST['folderName']) ? $_POST['folderName'] : null;
			#フォルダ名の確認がとれれば削除
			if ($folderName != "") {
				if ($folderId === null || $folderId === '') {
					$errCheck = 'ng-deleteFolder';
					break;
				}
				#配下写真を取得
				$photos = [];
				try {
					$s = $DB_CONNECT->prepare('SELECT photo_id, file_path FROM shops_photos WHERE shop_id = :shop_id AND folder_id = :folder_id');
					$s->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
					$s->bindValue(':folder_id', (int)$folderId, PDO::PARAM_INT);
					$s->execute();
					$photos = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
					$s->closeCursor();
				} catch (PDOException $e) {
					$photos = [];
				}
				#DBから削除（写真→フォルダ）
				if (DB_Transaction(1) === false) {
					$errCheck = 'ng-deleteFolder';
					break;
				}
				try {
					$dp = $DB_CONNECT->prepare('DELETE FROM shops_photos WHERE shop_id = :shop_id AND folder_id = :folder_id');
					$dp->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
					$dp->bindValue(':folder_id', (int)$folderId, PDO::PARAM_INT);
					$dp->execute();
					$dp->closeCursor();
					$df = $DB_CONNECT->prepare('DELETE FROM shops_folders WHERE shop_id = :shop_id AND folder_id = :folder_id');
					$df->bindValue(':shop_id', (int)$shopId, PDO::PARAM_INT);
					$df->bindValue(':folder_id', (int)$folderId, PDO::PARAM_INT);
					$df->execute();
					$df->closeCursor();
					DB_Transaction(2);
				} catch (PDOException $e) {
					DB_Transaction(3);
					$errCheck = 'ng-deleteFolder';
					break;
				}
				#サーバー上の画像ファイルも削除（ベストエフォート）
				$failedFiles = [];
				foreach ($photos as $p) {
					$fp = (string)($p['file_path'] ?? '');
					if ($fp === '') {
						continue;
					}
					$abs = resolveFrontendAbsolutePath($fp);
					if ($abs === '') {
						continue;
					}
					if (file_exists($abs) && is_file($abs)) {
						if (@unlink($abs) === false) {
							$failedFiles[] = $fp;
						}
					}
				}
				$makeTag['status'] = 'success';
				$makeTag['msg'] = '「' . $folderName . '」を削除しました。';
				if (!empty($failedFiles)) {
					$makeTag['msg'] .= '<br>一部の画像ファイル削除に失敗しました。サーバー権限をご確認ください。';
				}
				$action = 'deletedFolder';
			}
		}
		break;
	#***** 写真削除 *****#
	case 'deletePhoto': {
			#=============#
			# POSTチェック
			#-------------#
			#フォルダ名
			$folderName = isset($_POST['folderName']) ? $_POST['folderName'] : null;
			#削除対象画像キー
			$deletePhotoKey = isset($_POST['deletePhotoKey']) ? $_POST['deletePhotoKey'] : null;
			#削除対象画像名
			$deletePhotoName = isset($_POST['deletePhotoName']) ? $_POST['deletePhotoName'] : null;
			#削除対象データ取得
			$deletePhotoList = getDeletePhoto($shopId, $deletePhotoKey);
			#削除対象データあれば削除
			if ($deletePhotoList !== null) {
				$deleteFileAbs = '';
				$deleteFilePublic = '';
				$fp = isset($deletePhotoList['file_path']) && is_string($deletePhotoList['file_path']) ? (string)$deletePhotoList['file_path'] : '';
				if ($fp !== '') {
					$deleteFilePublic = $fp;
					$deleteFileAbs = resolveFrontendAbsolutePath($fp);
				}
				#登録用配列：初期化
				$dbFiledData = array();
				#登録情報セット
				$dbFiledData['is_active'] = array(':is_active', 0, 1);
				$dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
				#更新用キー：初期化
				$dbFiledValue = array();
				#識別キー
				$dbFiledValue['photo_id'] = array(':photo_id', $deletePhotoKey, 1);
				#処理モード：[1].新規追加｜[2].更新｜[3].削除
				$processFlg = 2;
				#実行モード：[1].トランザクション｜[2].即実行
				$exeFlg = 2;
				$dbSuccessFlg = SQL_Process($DB_CONNECT, "shops_photos", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
				#フォルダ取得
				$deletePhotoFolderKey = $deletePhotoList["folder_id"];
				if ($dbSuccessFlg == 1) {
					#画像ファイル削除（ベストエフォート）
					$failedUnlink = false;
					if (is_string($deleteFileAbs) && $deleteFileAbs !== '' && file_exists($deleteFileAbs) && is_file($deleteFileAbs)) {
						#念のため、フロント配下以外は削除しない
						$root = defined('DEFINE_FRONTEND_DIR_PATH') ? str_replace('\\', '/', (string)DEFINE_FRONTEND_DIR_PATH) : '';
						$absNorm = str_replace('\\', '/', $deleteFileAbs);
						if ($root !== '' && strpos($absNorm, rtrim($root, '/') . '/') === 0) {
							if (@unlink($deleteFileAbs) === false) {
								$failedUnlink = true;
							}
						}
					}
					$makeTag['status'] = 'success';
					$makeTag['msg'] = '「' . $deletePhotoName . '」を削除しました。';
					if ($failedUnlink) {
						$makeTag['msg'] .= '<br>画像ファイルの削除に失敗しました。サーバー権限をご確認ください。';
						$data = [
							'pageName' => 'proc_master01_01_01',
							'reason' => 'deletePhoto unlink failed',
							'filePath' => (string)$deleteFilePublic,
							'absPath' => (string)$deleteFileAbs,
						];
						makeLog($data);
					}
					#フォルダ名変更完了：モード変更
					$action = 'deletedPhoto';
				} else {
					$errCheck = 'ng-deletePhoto';
				}
			}
		}
		break;
	#***** ページ離脱・リロード時：アップロードドラフト破棄（tmp_upload + session） *****#
	case 'discardUploadDraft': {
			#up_image_area[] で指定された領域を、画面インスタンスキー($noUpDateKey)配下のセッションから破棄
			$pathsToDelete = [];
			foreach ($imageUploadSessionKeys as $sKey) {
				if (!is_string($sKey) || $sKey === '') {
					continue;
				}
				if (isset($_SESSION[$sKey]) && is_array($_SESSION[$sKey])) {
					foreach ($_SESSION[$sKey] as $row) {
						if (!is_array($row)) {
							continue;
						}
						$tmp = $row['tmp_name'] ?? '';
						if (is_string($tmp) && $tmp !== '') {
							$pathsToDelete[] = $tmp;
						}
					}
				}
				unset($_SESSION[$sKey]);
			}
			cleanupFiles($pathsToDelete);
			$makeTag['status'] = 'success';
			$makeTag['title'] = 'discarded';
			$makeTag['msg'] = '';
			header('Content-Type: application/json');
			echo json_encode($makeTag);
			exit;
		}
		break;
	#***** 画像プレビューチェック（ドラッグ＆ドロップ/ファイル選択アップロード） *****#
	case 'preUploadImage': {
			#エリア名が未指定の場合はエラー応答
			if (empty($targetImageUploadSessionKey)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = 'アップロード失敗';
				$makeTag['msg'] = 'アップロードエリア名が指定されていません。';
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode($makeTag);
				exit;
			}
			$makeTag['file_url'] = '';
			$makeTag['file_name'] = '';
			$upImageMode = isset($_POST['up_image_mode']) ? (string)$_POST['up_image_mode'] : '';
			if (isset($_FILES['images_tmp']) && is_uploaded_file($_FILES['images_tmp']['tmp_name'])) {
				$file = $_FILES['images_tmp'];
				$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
				$allowed = ['jpg', 'jpeg', 'png', 'gif'];
				if (!in_array($ext, $allowed)) {
					$makeTag['status'] = 'error';
					$makeTag['msg'] = '許可されていないファイル形式です。';
				} else {
					#一時保存先
					$tmpDir = __DIR__ . '/../../../tmp_upload/';
					if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);
					$uniqueName = 'photo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
					$savePath = $tmpDir . $uniqueName;
					$previewUrl = '/tmp_upload/' . $uniqueName;
					if (move_uploaded_file($file['tmp_name'], $savePath)) {
						#セッションにファイル情報を保存
						# - onlyモードは「1枠」のため、成功時に既存を掃除して置換する
						if (!isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
							$_SESSION[$targetImageUploadSessionKey] = [];
						}
						if ($upImageMode === 'only') {
							foreach ($_SESSION[$targetImageUploadSessionKey] as $old) {
								if (!is_array($old)) {
									continue;
								}
								$oldTmp = $old['tmp_name'] ?? '';
								if (is_string($oldTmp) && $oldTmp !== '' && file_exists($oldTmp) && is_file($oldTmp)) {
									@unlink($oldTmp);
								}
							}
							$_SESSION[$targetImageUploadSessionKey] = [];
						}
						$_SESSION[$targetImageUploadSessionKey][] = [
							'tmp_name' => $savePath,
							'preview' => $previewUrl,
							'name' => $uniqueName,
							'original' => $file['name'],
							'type' => $file['type'],
							'size' => $file['size'],
							'uploaded_at' => time(),
						];
						$makeTag['status'] = 'success';
						$makeTag['file_url'] = $previewUrl;
						$makeTag['file_name'] = $uniqueName;
						#sourceタグ用MIMEタイプ設定
						switch ($ext) {
							case 'jpg':
							case 'jpeg':
								$mimeType = 'image/jpeg';
								break;
							case 'png':
								$mimeType = 'image/png';
								break;
							case 'gif':
								$mimeType = 'image/gif';
								break;
							default:
								$mimeType = '';
								break;
						}
						#プレビュー用タグ生成
						$makeTag['tag'] .= <<<HTML
                        <li>
                          <input type="hidden" name="draft_file_name" value="{$uniqueName}">
                          {$uniqueName}
                          <button type="button" class="btn_close" onclick="deleteFile(this);"></button>
                        </li>

HTML;
					} else {
						$makeTag['status'] = 'error';
						$makeTag['title'] = 'アップロード失敗';
						$makeTag['msg'] = 'ファイルの保存に失敗しました。';
					}
				}
			} else {
				$makeTag['status'] = 'error';
				$makeTag['title'] = 'アップロード失敗';
				$makeTag['msg'] = 'ファイルがアップロードされていません。';
			}
			header('Content-Type: application/json');
			echo json_encode($makeTag);
			exit;
		}
		break;
	#***** 画像入れ替え（プレビューからの入れ替え） *****#
	case 'replaceUploadImage': {
			if (empty($targetImageUploadSessionKey)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = 'アップロード失敗';
				$makeTag['msg'] = 'アップロードエリア名が指定されていません。';
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			$replaceIndex = isset($_POST['replace_index']) ? intval($_POST['replace_index']) : null;
			$makeTag['file_url'] = '';
			$makeTag['file_name'] = '';
			$file = isset($_FILES['images_tmp']) ? $_FILES['images_tmp'] : null;
			$ext = $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';
			$allowed = ['jpg', 'jpeg', 'png', 'gif'];
			if ($replaceIndex === null || !$file || !is_uploaded_file($file['tmp_name']) || !in_array($ext, $allowed, true)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = 'アップロード失敗';
				$makeTag['msg'] = '入れ替え画像が指定されていません。';
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			$tmpDir = __DIR__ . '/../../../tmp_upload/';
			if (!file_exists($tmpDir)) {
				@mkdir($tmpDir, 0777, true);
			}
			$uniqueName = 'photo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
			$savePath = $tmpDir . $uniqueName;
			$previewUrl = '/tmp_upload/' . $uniqueName;
			if (!move_uploaded_file($file['tmp_name'], $savePath)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = 'アップロード失敗';
				$makeTag['msg'] = 'ファイルの保存に失敗しました。';
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			#DB登録済み画像の入れ替えでも扱えるよう、必要ならセッションをDBから展開する
			if (!isset($_SESSION[$targetImageUploadSessionKey]) || !is_array($_SESSION[$targetImageUploadSessionKey])) {
				$_SESSION[$targetImageUploadSessionKey] = [];
			}
			if (!array_key_exists($replaceIndex, $_SESSION[$targetImageUploadSessionKey])) {
				$logoList = [];
				$_SESSION[$targetImageUploadSessionKey] = [];
				foreach ($logoList as $p) {
					if (!is_string($p) || $p === '') {
						$_SESSION[$targetImageUploadSessionKey][] = [];
						continue;
					}
					$_SESSION[$targetImageUploadSessionKey][] = [
						'tmp_name' => '',
						'preview' => DOMAIN_NAME_PREVIEW . $p,
						'name' => basename($p),
						'path' => $p,
						'is_db' => true,
					];
				}
			}
			#既存tmpがあれば削除
			$old = $_SESSION[$targetImageUploadSessionKey][$replaceIndex] ?? null;
			if (is_array($old)) {
				$oldTmp = $old['tmp_name'] ?? '';
				if (is_string($oldTmp) && $oldTmp !== '' && file_exists($oldTmp) && is_file($oldTmp)) {
					@unlink($oldTmp);
				}
			}
			$replaceFrom = (is_array($old) && isset($old['path']) && is_string($old['path'])) ? $old['path'] : '';
			$newRow = [
				'tmp_name' => $savePath,
				'preview' => $previewUrl,
				'name' => $uniqueName,
				'original' => $file['name'],
				'type' => $file['type'],
				'size' => $file['size'],
				'uploaded_at' => time(),
			];
			if (is_string($replaceFrom) && $replaceFrom !== '') {
				$newRow['replace_from_path'] = $replaceFrom;
			}
			$_SESSION[$targetImageUploadSessionKey][$replaceIndex] = $newRow;
			#応答
			$makeTag['status'] = 'success';
			$makeTag['file_url'] = $previewUrl;
			$makeTag['file_name'] = $uniqueName;
			#プレビュー用タグ生成（セッションの並びをそのまま反映）
			$makeTag['tag'] = '';
			foreach ($_SESSION[$targetImageUploadSessionKey] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$displayName = '';
				if (isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
					$displayName = $row['name'];
				} elseif (isset($row['path']) && is_string($row['path']) && $row['path'] !== '') {
					$displayName = basename($row['path']);
				}
				if ($displayName === '') {
					continue;
				}
				$src = '';
				if (isset($row['tmp_name']) && is_string($row['tmp_name']) && $row['tmp_name'] !== '') {
					$src = $row['preview'] ?? '';
				} elseif (isset($row['preview']) && is_string($row['preview']) && $row['preview'] !== '') {
					$src = $row['preview'];
				} elseif (isset($row['path']) && is_string($row['path']) && $row['path'] !== '') {
					$src = DOMAIN_NAME_PREVIEW . $row['path'];
				}
				if (!is_string($src) || $src === '') {
					continue;
				}
				$srcExt = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
				switch ($srcExt) {
					case 'jpg':
					case 'jpeg':
						$mimeType = 'image/jpeg';
						break;
					case 'png':
						$mimeType = 'image/png';
						break;
					case 'gif':
						$mimeType = 'image/gif';
						break;
					default:
						$mimeType = '';
						break;
				}
				$hiddenDraft = '';
				if (isset($row['tmp_name']) && is_string($row['tmp_name']) && $row['tmp_name'] !== '' && isset($row['name']) && is_string($row['name']) && $row['name'] !== '') {
					$hiddenName = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
					$hiddenDraft = '<input type="hidden" name="draft_file_name" value="' . $hiddenName . '">';
				}
				$makeTag['tag'] .= <<<HTML
                  <li>
                    {$hiddenDraft}
                    {$displayName}
                    <button type="button" class="btn_close" onclick="deleteFile(this);"></button>
                  </li>

HTML;
			}
			#応答
			header('Content-Type: application/json');
			echo json_encode($makeTag);
			exit;
		}
		break;
	#***** 画像削除（プレビュー or 本体からの削除） *****#
	case 'deleteUploadImage': {
			#エリア名が未指定の場合はエラー応答
			if (empty($targetImageUploadSessionKey)) {
				$makeTag['status'] = 'error';
				$makeTag['title'] = '削除失敗';
				$makeTag['msg'] = 'アップロードエリア名が指定されていません。';
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			$fileName = isset($_POST['file_name']) ? $_POST['file_name'] : '';
			if (!is_string($fileName) || $fileName === '') {
				$makeTag['status'] = 'error';
				$makeTag['title'] = '削除失敗';
				$makeTag['msg'] = '削除対象ファイルが指定されていません。';
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			#セッションがある場合：tmp/擬似DBリストから削除
			if (isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey])) {
				$wasMaterialized = false;
				foreach ($_SESSION[$targetImageUploadSessionKey] as $row) {
					if (!is_array($row)) continue;
					if ((isset($row['is_db']) && $row['is_db'] === true) || (isset($row['path']) && is_string($row['path']) && $row['path'] !== '')) {
						$wasMaterialized = true;
						break;
					}
				}
				$deleted = false;
				foreach ($_SESSION[$targetImageUploadSessionKey] as $idx => $info) {
					if (($info['name'] ?? '') === $fileName) {
						if (isset($info['tmp_name']) && is_string($info['tmp_name']) && $info['tmp_name'] !== '' && file_exists($info['tmp_name'])) {
							@unlink($info['tmp_name']);
						}
						array_splice($_SESSION[$targetImageUploadSessionKey], $idx, 1);
						$deleted = true;
						break;
					}
				}
				#削除応答
				if ($deleted) {
					$makeTag['status'] = 'success';
				} else {
					$makeTag['status'] = 'error';
					$makeTag['title'] = '削除失敗';
					$makeTag['msg'] = '削除対象が見つかりませんでした。';
				}
				#空になった場合：DB由来のmaterializedなら空を保持（保存時に全削除を反映）
				if (empty($_SESSION[$targetImageUploadSessionKey])) {
					if ($wasMaterialized) {
						$_SESSION[$targetImageUploadSessionKey] = [
							['is_db' => true],
						];
					} else {
						unset($_SESSION[$targetImageUploadSessionKey]);
					}
				}
				#応答
				header('Content-Type: application/json');
				echo json_encode($makeTag);
				exit;
			}
			#セッションが無い場合：DBの現状リストをセッションへ展開し、そこから除去（DB/本番は保存で確定）
			$logoList = [];
			$_SESSION[$targetImageUploadSessionKey] = [];
			foreach ($logoList as $p) {
				if (!is_string($p) || $p === '') {
					continue;
				}
				$_SESSION[$targetImageUploadSessionKey][] = [
					'tmp_name' => '',
					'preview' => DOMAIN_NAME_PREVIEW . $p,
					'name' => basename($p),
					'path' => $p,
					'is_db' => true,
				];
			}
			$deleted = false;
			foreach ($_SESSION[$targetImageUploadSessionKey] as $idx => $info) {
				if (($info['name'] ?? '') === $fileName) {
					array_splice($_SESSION[$targetImageUploadSessionKey], $idx, 1);
					$deleted = true;
					break;
				}
			}
			#削除応答
			if ($deleted) {
				$makeTag['status'] = 'success';
			} else {
				$makeTag['status'] = 'error';
				$makeTag['title'] = '削除失敗';
				$makeTag['msg'] = '削除対象が見つかりませんでした。';
			}
			#空になった場合：DB由来のmaterializedなら空を保持（保存時に全削除を反映）
			if (empty($_SESSION[$targetImageUploadSessionKey])) {
				$_SESSION[$targetImageUploadSessionKey] = [
					['is_db' => true],
				];
			}
			#応答
			header('Content-Type: application/json');
			echo json_encode($makeTag);
			exit;
		}
		break;
	#***** 確認画面からの選択写真削除 *****#
	case 'deleteFile_for_checkPage': {
			#アップロードドラフト（tmp_upload + session）を掃除
			$pathsToDelete = [];
			foreach ($imageUploadSessionKeys as $sKey) {
				if (!is_string($sKey) || $sKey === '') {
					continue;
				}
				if (!isset($_SESSION[$sKey]) || !is_array($_SESSION[$sKey])) {
					continue;
				}
				foreach ($_SESSION[$sKey] as $row) {
					if (!is_array($row)) {
						continue;
					}
					$tmp = $row['tmp_name'] ?? '';
					if (is_string($tmp) && $tmp !== '') {
						$pathsToDelete[] = $tmp;
					}
				}
				unset($_SESSION[$sKey]);
			}
			cleanupFiles($pathsToDelete);
			#セッションのアップロードフラグもリセット
			$_SESSION[$noUpDateKey]['upFlg'] = '';
			#$_FILESで保持しているデータを削除
			$_FILES['photo']['name'] = '';
			$_FILES['photo']['type'] = '';
			$_FILES['photo']['tmp_name'] = '';
			$_FILES['photo']['error'] = '';
			$_FILES['photo']['size'] = '';
		}
		break;
	#***** 写真登録 *****#
	case 'sendPhoto': {
			$pendingDeletePaths = [];
			$tmpPathsToCleanup = [];
			$pendingClearSessions = [];
			$tmpPathsFromClearedSessions = [];
			$genToken = function (int $bytes = 8): string {
				if (function_exists('random_bytes')) {
					return bin2hex(random_bytes($bytes));
				}
				if (function_exists('openssl_random_pseudo_bytes')) {
					$bin = openssl_random_pseudo_bytes($bytes);
					if ($bin !== false) {
						return bin2hex($bin);
					}
				}
				return substr(md5(uniqid('', true)), 0, $bytes * 2);
			};
			#格納フォルダ（radio: selectFolder）
			$postedSelectFolder = '';
			if (isset($_POST['selectFolder'])) {
				$postedSelectFolder = (string)$_POST['selectFolder'];
			} elseif (isset($_POST['select-folder'])) {
				$postedSelectFolder = (string)$_POST['select-folder'];
			}
			$selectedFolderId = null;
			if ($postedSelectFolder !== '' && ctype_digit($postedSelectFolder)) {
				$selectedFolderId = (int)$postedSelectFolder;
			}
			#タイトル（任意）
			$title = isset($_POST['photoName']) ? trim((string)$_POST['photoName']) : '';
			if ($title === '') {
				$title = null;
			}
			#アップロードドラフトから画像を取得（最新1件）
			$draft = null;
			if ($targetImageUploadSessionKey !== '' && isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey])) {
				$rows = $_SESSION[$targetImageUploadSessionKey];
				for ($i = count($rows) - 1; $i >= 0; $i--) {
					if (!is_array($rows[$i])) {
						continue;
					}
					$tmp = $rows[$i]['tmp_name'] ?? '';
					if (is_string($tmp) && $tmp !== '' && file_exists($tmp) && is_file($tmp)) {
						$draft = $rows[$i];
						break;
					}
				}
			}
			$photoId = null;
			if ($method === 'edit') {
				$photoIdRaw = isset($_POST['photoKey']) ? (string)$_POST['photoKey'] : '';
				if ($photoIdRaw === '' || !ctype_digit($photoIdRaw)) {
					$makeTag['status'] = 'error';
					$makeTag['msg'] = '更新対象の写真が特定できませんでした。';
					break;
				}
				$photoId = (int)$photoIdRaw;
			}
			#新規は画像必須
			if ($method === 'new' && $draft === null) {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = '追加する写真を選択してください。';
				break;
			}
			#画像メタ（差し替えが無い edit では不要）
			$imgTmpPath = '';
			$mimeType = null;
			$fileSize = null;
			$width = null;
			$height = null;
			$ext = '';
			if ($draft !== null) {
				$imgTmpPath = (string)($draft['tmp_name'] ?? '');
				if ($imgTmpPath === '' || !file_exists($imgTmpPath) || !is_file($imgTmpPath)) {
					$makeTag['status'] = 'error';
					$makeTag['msg'] = '画像ファイルが見つかりませんでした。';
					break;
				}
				$info = @getimagesize($imgTmpPath);
				if (!is_array($info)) {
					$makeTag['status'] = 'error';
					$makeTag['msg'] = '画像ファイルを読み取れませんでした。';
					break;
				}
				$width = isset($info[0]) ? (int)$info[0] : null;
				$height = isset($info[1]) ? (int)$info[1] : null;
				$mimeType = isset($info['mime']) && is_string($info['mime']) ? $info['mime'] : null;
				$fileSize = @filesize($imgTmpPath);
				if ($fileSize !== false) {
					$fileSize = (int)$fileSize;
				} else {
					$fileSize = null;
				}
				switch ((string)$mimeType) {
					case 'image/jpeg':
						$ext = 'jpg';
						break;
					case 'image/png':
						$ext = 'png';
						break;
					case 'image/gif':
						$ext = 'gif';
						break;
					case 'image/webp':
						$ext = 'webp';
						break;
					default:
						$ext = '';
						break;
				}
				if ($ext === '') {
					$makeTag['status'] = 'error';
					$makeTag['msg'] = '許可されていない画像形式です。';
					break;
				}
				$tmpPathsToCleanup[] = $imgTmpPath;
			}
			try {
				#トランザクション開始
				if (DB_Transaction(1) === false) {
					throw new Exception('DB transaction begin failed');
				}
				#画像保存先ディレクトリ名（shops/003/ のように3桁で統一）
				$shopDir = sprintf("%03d", (int)$shopId);
				if ($method === 'new') {
					#-----------------
					# [1] DB Insert（先に確定）
					#-----------------
					$pendingName = 'pending_' . $genToken(8) . '.' . $ext;
					$pendingPath = '/db/images/shops/' . (string)$shopDir . '/' . $pendingName;
					$dbFiledData = [];
					$dbFiledData['shop_id'] = [':shop_id', (int)$shopId, 1];
					if ($selectedFolderId === null) {
						$dbFiledData['folder_id'] = [':folder_id', null, 2];
					} else {
						$dbFiledData['folder_id'] = [':folder_id', (int)$selectedFolderId, 1];
					}
					$dbFiledData['file_path'] = [':file_path', (string)$pendingPath, 0];
					if ($title === null) {
						$dbFiledData['title'] = [':title', null, 2];
					} else {
						$dbFiledData['title'] = [':title', (string)$title, 0];
					}
					$dbFiledData['mime_type'] = [':mime_type', $mimeType, 0];
					$dbFiledData['file_size'] = [':file_size', $fileSize, $fileSize === null ? 2 : 1];
					$dbFiledData['width'] = [':width', $width, $width === null ? 2 : 1];
					$dbFiledData['height'] = [':height', $height, $height === null ? 2 : 1];
					$dbFiledData['sort_order'] = [':sort_order', 0, 1];
					$dbFiledData['is_active'] = [':is_active', 1, 1];
					$dbFiledData['created_at'] = [':created_at', date('Y-m-d H:i:s'), 0];
					$dbFiledValue = [];
					$dbSuccessFlg = SQL_Process($DB_CONNECT, 'shops_photos', $dbFiledData, $dbFiledValue, 1, 2);
					if ($dbSuccessFlg != 1) {
						throw new Exception('DB insert failed');
					}
					$photoId = (int)$DB_CONNECT->lastInsertId();
					#-----------------
					# [2] ファイル本登録（DB Insert成功後）
					#-----------------
					$destDir = rtrim((string)DEFINE_FILE_DIR_PATH, '/\\') . '/' . (string)$shopDir;
					if (!file_exists($destDir)) {
						@mkdir($destDir, 0777, true);
					}
					$finalName = 'photo_' . (string)$photoId . '_' . date('YmdHis') . '_' . $genToken(4) . '.' . $ext;
					$finalAbs = rtrim($destDir, '/\\') . '/' . $finalName;
					$finalPath = '/db/images/shops/' . (string)$shopDir . '/' . $finalName;
					if (!@rename($imgTmpPath, $finalAbs)) {
						throw new Exception('File move failed');
					}
					#rename成功したのでtmp掃除対象から除外
					$filtered = [];
					foreach ($tmpPathsToCleanup as $p) {
						if ($p !== $imgTmpPath) {
							$filtered[] = $p;
						}
					}
					$tmpPathsToCleanup = $filtered;
					#-----------------
					# [3] DB Update（file_path確定）
					#-----------------
					$upd = [];
					$upd['file_path'] = [':file_path', (string)$finalPath, 0];
					$where = [];
					$where['photo_id'] = [':photo_id', (int)$photoId, 1];
					$where['shop_id'] = [':shop_id', (int)$shopId, 1];
					$dbSuccessFlg = SQL_Process($DB_CONNECT, 'shops_photos', $upd, $where, 2, 2);
					if ($dbSuccessFlg != 1) {
						#DB更新失敗時は作成したファイルを消して整合性を保つ
						@unlink($finalAbs);
						throw new Exception('DB update failed');
					}
					DB_Transaction(2);
					$makeTag['status'] = 'success';
					$makeTag['msg'] = '写真を追加しました。';
					$action = 'addedPhoto';
				} elseif ($method === 'edit') {
					#対象取得
					$existing = getDeletePhoto($shopId, $photoId);
					if (!is_array($existing)) {
						throw new Exception('Photo not found');
					}
					#差し替え有無
					$hasReplace = ($draft !== null);
					if ($hasReplace) {
						$oldPath = is_string($existing['file_path'] ?? '') ? (string)$existing['file_path'] : '';
						if ($oldPath !== '') {
							$pendingDeletePaths[] = resolveFrontendAbsolutePath($oldPath);
						}
					}
					if ($hasReplace) {
						$pendingName = 'pending_edit_' . (string)$photoId . '_' . $genToken(6) . '.' . $ext;
						$pendingPath = '/db/images/shops/' . (string)$shopDir . '/' . $pendingName;
					}
					#-----------------
					# [1] DB Update（先に確定）
					#-----------------
					$upd = [];
					if ($selectedFolderId === null) {
						$upd['folder_id'] = [':folder_id', null, 2];
					} else {
						$upd['folder_id'] = [':folder_id', (int)$selectedFolderId, 1];
					}
					if ($title === null) {
						$upd['title'] = [':title', null, 2];
					} else {
						$upd['title'] = [':title', (string)$title, 0];
					}
					if ($hasReplace) {
						$upd['file_path'] = [':file_path', (string)$pendingPath, 0];
						$upd['mime_type'] = [':mime_type', $mimeType, 0];
						$upd['file_size'] = [':file_size', $fileSize, $fileSize === null ? 2 : 1];
						$upd['width'] = [':width', $width, $width === null ? 2 : 1];
						$upd['height'] = [':height', $height, $height === null ? 2 : 1];
					}
					$where = [];
					$where['photo_id'] = [':photo_id', (int)$photoId, 1];
					$where['shop_id'] = [':shop_id', (int)$shopId, 1];
					$dbSuccessFlg = SQL_Process($DB_CONNECT, 'shops_photos', $upd, $where, 2, 2);
					if ($dbSuccessFlg != 1) {
						throw new Exception('DB update failed');
					}
					#-----------------
					# [2] 差し替えがあればファイル本登録
					#-----------------
					if ($hasReplace) {
						$destDir = rtrim((string)DEFINE_FILE_DIR_PATH, '/\\') . '/' . (string)$shopDir;
						if (!file_exists($destDir)) {
							@mkdir($destDir, 0777, true);
						}
						$finalName = 'photo_' . (string)$photoId . '_' . date('YmdHis') . '_' . $genToken(4) . '.' . $ext;
						$finalAbs = rtrim($destDir, '/\\') . '/' . $finalName;
						$finalPath = '/db/images/shops/' . (string)$shopDir . '/' . $finalName;
						if (!@rename($imgTmpPath, $finalAbs)) {
							throw new Exception('File move failed');
						}
						$filtered = [];
						foreach ($tmpPathsToCleanup as $p) {
							if ($p !== $imgTmpPath) {
								$filtered[] = $p;
							}
						}
						$tmpPathsToCleanup = $filtered;
						#DBのfile_pathを最終パスへ
						$upd2 = [];
						$upd2['file_path'] = [':file_path', (string)$finalPath, 0];
						$dbSuccessFlg = SQL_Process($DB_CONNECT, 'shops_photos', $upd2, $where, 2, 2);
						if ($dbSuccessFlg != 1) {
							@unlink($finalAbs);
							throw new Exception('DB update failed');
						}
					}
					DB_Transaction(2);
					$makeTag['status'] = 'success';
					$makeTag['msg'] = '写真を更新しました。';
					$action = 'editedPhoto';
				} else {
					throw new Exception('Invalid method');
				}
				#編集完了後は新規登録モードへ戻す（次の登録で更新扱いにならないように）
				if (($makeTag['status'] ?? '') === 'success') {
					$method = 'new';
					$_POST['method'] = 'new';
					unset($_POST['photoKey']);
				}
				#成功時：ドラフトセッション掃除（tmpはrename済み or 明示cleanup）
				foreach ($imageUploadSessionKeys as $sKey) {
					if (is_string($sKey) && $sKey !== '') {
						$pendingClearSessions[] = $sKey;
					}
				}
			} catch (Exception $e) {
				DB_Transaction(3);
				$makeTag['status'] = 'error';
				$makeTag['msg'] = ($method === 'new') ? '写真の追加に失敗しました。' : '写真の更新に失敗しました。';
				$data = [
					'pageName' => 'proc_master01_01_01',
					'reason' => 'sendPhoto failed',
					'errorMessage' => $e->getMessage(),
				];
				makeLog($data);
			}
			#共通クリーンアップ
			# - 失敗時にtmpを消すと再試行できないため、成功時のみ tmp/セッションを掃除する
			if (($makeTag['status'] ?? '') === 'success') {
				if (!empty($pendingClearSessions)) {
					foreach ($pendingClearSessions as $sKey) {
						if (!is_string($sKey) || $sKey === '') {
							continue;
						}
						if (!isset($_SESSION[$sKey]) || !is_array($_SESSION[$sKey])) {
							continue;
						}
						foreach ($_SESSION[$sKey] as $row) {
							if (!is_array($row)) {
								continue;
							}
							$tmp = $row['tmp_name'] ?? '';
							if (!is_string($tmp) || $tmp === '') {
								continue;
							}
							$normalized = str_replace('\\', '/', $tmp);
							if (strpos($normalized, '/tmp_upload/') !== false) {
								$tmpPathsFromClearedSessions[] = $tmp;
							}
						}
					}
				}
				cleanupFiles(array_merge($tmpPathsToCleanup, $tmpPathsFromClearedSessions));
				if (!empty($pendingClearSessions)) {
					$pendingClearSessions = array_values(array_unique(array_filter($pendingClearSessions, 'is_string')));
					foreach ($pendingClearSessions as $sKey) {
						unset($_SESSION[$sKey]);
					}
				}
				#更新成功時のみ旧ファイルを削除（ベストエフォート）
				if (!empty($pendingDeletePaths)) {
					cleanupFiles($pendingDeletePaths);
				}
			}
		}
		break;
}
#-------------#
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
	#店舗情報
	$shopData = getShops_FindById($shopId);
	if ($shopData === null) {
		#店舗情報が存在しない場合
		if ($action !== null) {
			header('Content-Type: application/json; charset=UTF-8');
			$makeTag['status'] = 'error';
			$makeTag['title'] = '店舗情報エラー';
			$makeTag['msg'] = '店舗情報が見つかりませんでした。ページを再読み込みしてください。';
			echo json_encode($makeTag);
			exit;
		}
		#（直接アクセス等）一覧にリダイレクト
		header("Location: ./master01_01.php");
		exit;
	} else {
		#店舗情報が存在する場合はフォルダと写真情報も取得
		$folderList = getFolderList($shopId);
		$photoList = getPhotoList($shopId);
	}
} else {
	#店舗IDがない場合
	if ($action !== null) {
		header('Content-Type: application/json; charset=UTF-8');
		$makeTag['status'] = 'error';
		$makeTag['title'] = '店舗情報エラー';
		$makeTag['msg'] = '店舗IDが取得できませんでした。ページを再読み込みしてください。';
		echo json_encode($makeTag);
		exit;
	}
	#（直接アクセス等）一覧にリダイレクト
	header("Location: ./master01_01.php");
	exit;
}
#-------------#
#XSS対策：エスケープ処理
$escShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');

#***** タグ生成開始 *****#
switch ($action) {
	#-----------------#
	# フォルダ切り替え #
	#-----------------#
	case 'changeFolder': {
			#========================#
			# POSTチェック
			#------------------------#
			#選択フォルダキー
			$selectedFolderId = isset($_POST['folderId']) ? $_POST['folderId'] : null;
			#選択フォルダ名
			$selectedFolderName = isset($_POST['folderName']) ? (string)$_POST['folderName'] : '';
			$escSelectedFolderName = htmlspecialchars($selectedFolderName, ENT_QUOTES, 'UTF-8');
			#選択フォルダの写真枚数格納用
			$selectFolderPhotoNum = 0;
			$makeTag['tag'] .= <<<HTML
      <article class="block_02" id="block02">
        <section class="inner_left">
          <h4>フォルダ名</h4>
          <nav>

HTML;
			#表示可能リストあればループで差し込む
			if (!empty($folderList)) {
				foreach ($folderList as $folder) {
					$folderIdEsc = htmlspecialchars($folder['folder_id'], ENT_QUOTES, 'UTF-8');
					$folderNameEsc = htmlspecialchars($folder['folder_name'], ENT_QUOTES, 'UTF-8');
					$jsAction = json_encode('changeFolder', $jsonHex);
					$jsShopId = json_encode((string)$shopId, $jsonHex);
					$jsFolderId = json_encode((string)$folder['folder_id'], $jsonHex);
					$jsFolderName = json_encode((string)$folder['folder_name'], $jsonHex);
					$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
					#フォルダ毎の写真枚数
					$photoCount = 0;
					if (!empty($photoList)) {
						foreach ($photoList as $photo) {
							if ((string)$photo['folder_id'] === (string)$folder['folder_id']) {
								$photoCount++;
							}
						}
					}
					#active判定（選択フォルダをactiveに）
					$navFolderActive = '';
					if ($selectedFolderId !== null && (string)$selectedFolderId === (string)$folder['folder_id']) {
						$navFolderActive = 'class = "is-active"';
					}
					$makeTag['tag'] .= <<<HTML
            <button type="button" {$navFolderActive} onclick='changeFolder(this,{$jsAction},{$jsShopId},{$jsFolderId},{$jsFolderName},{$jsNoUpDateKey})'>
              <h5>{$folderNameEsc}</h5><span>{$photoCount}</span>
            </button>

HTML;
				}
			}
			$makeTag['tag'] .= <<<HTML
          </nav>
        </section>
        <section class="inner_right">

HTML;
			#表示可能リストあればループで差し込む
			if (!empty($folderList)) {
				$jsShopId = json_encode((string)$shopId, $jsonHex);
				$jsSelectedFolderId = json_encode((string)$selectedFolderId, $jsonHex);
				$jsSelectedFolderName = json_encode((string)$selectedFolderName, $jsonHex);
				$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
				$jsActionSetEdit = json_encode('setEditFolderName', $jsonHex);
				$jsActionDeleteFolder = json_encode('deleteFolder', $jsonHex);
				$jsActionEditPhoto = json_encode('editPhotoDetail', $jsonHex);
				$jsTypeE = json_encode('e', $jsonHex);
				$jsActionDeletePhoto = json_encode('deletePhoto', $jsonHex);
				$makeTag['tag'] .= <<<HTML
              <form class="box_head">
                <h4>{$escSelectedFolderName}</h4>
                <a href="javascript:void(0);" class="item_edit" onclick='setEditFolderName(this,{$jsActionSetEdit},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
                <a href="javascript:void(0);" class="item_delate" onclick='folderDeleteCheck(this,{$jsActionDeleteFolder},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
              </form>
              <ul>

HTML;
				#表示可能リストあればループで差し込む
				if (!empty($photoList)) {
					foreach ($photoList as $photo) {
						if ($selectedFolderId !== null && (string)$photo['folder_id'] !== (string)$selectedFolderId) {
							continue;
						}
						$photoId = htmlspecialchars($photo['photo_id'], ENT_QUOTES, 'UTF-8');
						$photoTitle = htmlspecialchars($photo['title'], ENT_QUOTES, 'UTF-8');
						$photoFilePath = htmlspecialchars($photo['file_path'], ENT_QUOTES, 'UTF-8');
						$photoFolderId = htmlspecialchars($photo['folder_id'], ENT_QUOTES, 'UTF-8');
						$previewPath = DOMAIN_NAME_PREVIEW . $photoFilePath;
						$jsPhotoFolderId = json_encode((string)$photo['folder_id'], $jsonHex);
						$jsPhotoId = json_encode((string)$photo['photo_id'], $jsonHex);
						$jsPhotoTitle = json_encode((string)$photo['title'], $jsonHex);
						$makeTag['tag'] .= <<<HTML
                <li>
                  <form>
                    <div class="item_image">
                      <picture>
                        <source srcset="{$previewPath}">
                        <img src="{$previewPath}" alt="{$photoTitle}">
                      </picture>
                    </div>
                    <div class="title">{$photoTitle}</div>
                    <a href="javascript:void(0);" class="item_edit" onclick='editPhotoDetail(this,{$jsActionEditPhoto},{$jsTypeE},{$jsShopId},{$jsPhotoFolderId},{$jsSelectedFolderName},{$jsPhotoId},{$jsPhotoTitle},{$jsNoUpDateKey})'></a>
                    <a href="javascript:void(0);" class="item_delate" onclick='photoDeleteCheck(this,{$jsActionDeletePhoto},{$jsShopId},{$jsSelectedFolderName},{$jsPhotoId},{$jsPhotoTitle},{$jsNoUpDateKey});'></a>
                  </form>
                </li>

HTML;
					}
				}
				$makeTag['tag'] .= <<<HTML
              </ul>

HTML;
			} else {
				$makeTag['tag'] .= <<<HTML
              <form class="box_head">
                <h4>写真が登録されていません</h4>
              </form>

HTML;
			}
			$makeTag['tag'] .= <<<HTML
            </section>
          </article>

HTML;
		}
		break;
	#-------------------#
	# [1] フォルダ追加   #
	# [2] フォルダ削除   #
	# [3] フォルダ名変更 #
	# [4] 写真情報変更   #
	#-------------------#
	default: {
			#登録失敗
			if ($errCheck === 'ng-addFolder') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = '新規フォルダの追加に失敗しました。<br>お手数ですが、最初からやり直してください。';
			} elseif ($errCheck === 'duplicate-addFolder') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = '同じフォルダ名が既に存在します。<br>別のフォルダ名で登録してください。';
			} elseif ($errCheck === 'ng-deletePhoto') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = '写真の削除に失敗しました。<br>お手数ですが、最初からやり直してください。';
			} elseif ($errCheck === 'ng-deleteFolder') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = 'フォルダの削除に失敗しました。<br>お手数ですが、最初からやり直してください。';
			} elseif ($errCheck === 'ng-editFolder') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = 'フォルダ名の変更に失敗しました。<br>お手数ですが、最初からやり直してください。';
			} elseif ($errCheck === 'duplicate-editFolder') {
				$makeTag['status'] = 'error';
				$makeTag['msg'] = '同じフォルダ名が既に存在します。<br>別のフォルダ名で変更してください。';
			} else {
				#========================#
				# POSTチェック
				#------------------------#
				#フォルダキー
				$folderId = isset($_POST['folderId']) ? $_POST['folderId'] : (isset($folderId) ? $folderId : null);
				#フォルダ名
				if ($action == 'setEditFolderName') {
					#旧フォルダ名
					$folderName = isset($_POST['oldName']) ? $_POST['oldName'] : null;
				} else {
					#フォルダ名
					$folderName = isset($_POST['folderName']) ? $_POST['folderName'] : null;
				}
				#写真キー
				$photoKey = isset($_POST['photoKey']) ? $_POST['photoKey'] : null;
				#画像ファイル名
				$photoName = isset($_POST['photoName']) ? $_POST['photoName'] : null;
				#checkPhoto（確認）では、file input が空でも upload draft があれば進める
				#ただしドラフトもアップロードも無い場合はエラー
				if ((string)$action === 'checkPhoto' && (string)$method === 'new') {
					$hasUploadedFile = false;
					if (isset($_FILES['images_tmp']) && is_array($_FILES['images_tmp'])) {
						$tmp = (string)($_FILES['images_tmp']['tmp_name'] ?? '');
						if ($tmp !== '' && is_uploaded_file($tmp)) {
							$hasUploadedFile = true;
						}
					}
					$hasDraft = false;
					if ($targetImageUploadSessionKey !== '' && isset($_SESSION[$targetImageUploadSessionKey]) && is_array($_SESSION[$targetImageUploadSessionKey])) {
						$rows = $_SESSION[$targetImageUploadSessionKey];
						for ($i = count($rows) - 1; $i >= 0; $i--) {
							if (!is_array($rows[$i])) {
								continue;
							}
							$tp = $rows[$i]['tmp_name'] ?? '';
							if (is_string($tp) && $tp !== '' && file_exists($tp) && is_file($tp)) {
								$hasDraft = true;
								break;
							}
						}
					}
					#セッションのドラフトが消えている場合でも、POSTされた tmp_upload 名から復元できるようにする
					if (!$hasUploadedFile && !$hasDraft && $targetImageUploadSessionKey !== '') {
						$postedDraftName = '';
						if (isset($_POST['draft_file_name'])) {
							if (is_array($_POST['draft_file_name'])) {
								$postedDraftName = (string)($_POST['draft_file_name'][count($_POST['draft_file_name']) - 1] ?? '');
							} else {
								$postedDraftName = (string)$_POST['draft_file_name'];
							}
						}
						$postedDraftName = trim($postedDraftName);
						if ($postedDraftName !== '' && preg_match('/\Aphoto_\d{14}_[0-9a-f]{8}\.(?:jpe?g|png|gif|webp)\z/i', $postedDraftName)) {
							$tmpDir = __DIR__ . '/../../../tmp_upload/';
							$abs = $tmpDir . $postedDraftName;
							if (file_exists($abs) && is_file($abs)) {
								$_SESSION[$targetImageUploadSessionKey] = [[
									'tmp_name' => $abs,
									'preview' => '/tmp_upload/' . $postedDraftName,
									'name' => $postedDraftName,
									'original' => $postedDraftName,
									'uploaded_at' => time(),
								]];
								$hasDraft = true;
							}
						}
					}
					if (!$hasUploadedFile && !$hasDraft) {
						$makeTag['status'] = 'error';
						$makeTag['msg'] = '追加する写真を選択してください。';
						break;
					}
				}
				#エディットモード判定
				$isEdit = '';
				if ($action == 'checkPhoto' || $action == 'editPhotoDetail') {
					$isEdit = 'class="is-mode-edit"';
				} else {
					$isEdit = '';
				}
				$makeTag['tag'] .= <<<HTML
          <article class="block_01" id="block01">
            <h3>{$escShopName}</h3>
            <dl>

HTML;
				if ($action != "checkPhoto") {
					$makeTag['tag'] .= <<<HTML
              <div>
                <dt>フォルダ</dt>
                <dd>

HTML;
					if ($action == 'setEditFolderName') {
						$makeTag['tag'] .= <<<HTML
                  <form name="addFolder" class="box_add-folder">
                    <h4>フォルダ名</h4>
                    <input type="text" name="addFolderName" value="{$folderName}">
                    <input type="hidden" name="method" value="{$method}">
                    <input type="hidden" name="action" value="editFolderName">
                    <input type="hidden" name="shopId" value="{$shopId}">
                    <input type="hidden" name="folderId" value="{$folderId}">
                    <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                    <button type="button" class="btn_submit" onclick="editFolderNames()">変更</button>
                  </form>

HTML;
					} elseif ($action == 'editPhotoDetail') {
						$makeTag['tag'] .= <<<HTML
                  <form name="addFolder" class="box_add-folder">
                    <h4>フォルダ名</h4>
                    <input type="text" name="addFolderName" value="{$folderName}" readonly>
                    <input type="hidden" name="method" value="{$method}">
                    <input type="hidden" name="action" value="editFolderName">
                    <input type="hidden" name="shopId" value="{$shopId}">
                    <input type="hidden" name="folderId" value="{$folderId}">
                    <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                    <!-- <button type="button" class="btn_submit" onclick="editFolderNames()">変更</button> -->
                  </form>

HTML;
					} else {
						$makeTag['tag'] .= <<<HTML
                  <form name="addFolder" class="box_add-folder">
                    <h4>新規フォルダ名</h4>
                    <input type="text" name="addFolderName">
                    <input type="hidden" name="method" value="{$method}">
                    <input type="hidden" name="action" value="addFolder">
                    <input type="hidden" name="shopId" value="{$shopId}">
                    <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                    <button type="button" class="btn_submit" onclick="addFolders(this,'addFolder');">登録</button>
                  </form>

HTML;
					}
					$makeTag['tag'] .= <<<HTML
                </dd>
              </div>

HTML;
				}
				#格納フォルダ（セレクトボックス）の初期選択を復元する
				$selectFolderHiddenValue = '';
				$selectFolderDisplayName = '選択してください';
				$selectedFolderIdForSelect = '';
				if (($action === 'addFolder' || $action === 'editedFolderName' || $action === 'editPhotoDetail') && isset($folderId) && $folderId !== null && (string)$folderId !== '') {
					$selectedFolderIdForSelect = (string)$folderId;
					$selectFolderHiddenValue = htmlspecialchars($selectedFolderIdForSelect, ENT_QUOTES, 'UTF-8');
					$defaultName = '';
					if ($action === 'addFolder') {
						$defaultName = (string)($addFolderName ?? '');
					} elseif ($action === 'editedFolderName') {
						$defaultName = (string)($editFolderName ?? '');
					} elseif ($action === 'editPhotoDetail') {
						$defaultName = (string)($folderName ?? '');
					}
					if ($defaultName === '') {
						$defaultName = (string)($folderName ?? '');
					}
					$selectFolderDisplayName = htmlspecialchars($defaultName, ENT_QUOTES, 'UTF-8');
				} else {
					#checkPhoto（プレビュー）等で、POSTされた選択値を復元
					$postedSelectFolder = '';
					if (isset($_POST['selectFolder'])) {
						$postedSelectFolder = (string)$_POST['selectFolder'];
					} elseif (isset($_POST['select-folder'])) {
						$postedSelectFolder = (string)$_POST['select-folder'];
					}
					if ($postedSelectFolder !== '') {
						$selectedFolderIdForSelect = $postedSelectFolder;
						$selectFolderHiddenValue = htmlspecialchars($selectedFolderIdForSelect, ENT_QUOTES, 'UTF-8');
						$foundName = '';
						if (!empty($folderList)) {
							foreach ($folderList as $f) {
								if ((string)($f['folder_id'] ?? '') === $selectedFolderIdForSelect) {
									$foundName = (string)($f['folder_name'] ?? '');
									break;
								}
							}
						}
						if ($foundName !== '') {
							$selectFolderDisplayName = htmlspecialchars($foundName, ENT_QUOTES, 'UTF-8');
						}
					}
				}
				$makeTag['tag'] .= <<<HTML
              <div {$isEdit}>
                <dt>写真追加</dt>
                <dd>
                  <form name="addPhoto" method="post" class="box_add-photo" enctype="multipart/form-data">
                    <div class="wrap_01">
                      <h4>格納フォルダ</h4>
                      <div class="select-folder-name" data-selectbox>
                        <button type="button" class="selectbox__head" aria-expanded="false">
                          <input type="hidden" name="select-folder" value="{$selectFolderHiddenValue}" data-selectbox-hidden>
                          <span class="selectbox__value" data-selectbox-value>{$selectFolderDisplayName}</span>
                        </button>
                        <div class="list-wrapper">
                          <ul class="selectbox__panel">

HTML;
				#表示可能リストあればループで差し込む
				if (!empty($folderList)) {
					foreach ($folderList as $folder) {
						$loopFolderIdRaw = (string)($folder['folder_id'] ?? '');
						$loopFolderId = htmlspecialchars($loopFolderIdRaw, ENT_QUOTES, 'UTF-8');
						$loopFolderName = htmlspecialchars($folder['folder_name'], ENT_QUOTES, 'UTF-8');
						$checked = ($selectedFolderIdForSelect !== '' && $loopFolderIdRaw === $selectedFolderIdForSelect) ? ' checked' : '';
						$makeTag['tag'] .= <<<HTML
                            <li>
                              <input type="radio" name="selectFolder" value="{$loopFolderId}" id="folder{$loopFolderId}"{$checked}>
                              <label for="folder{$loopFolderId}">{$loopFolderName}</label>
                            </li>

HTML;
					}
				}
				$makeTag['tag'] .= <<<HTML
                          </ul>
                        </div>
                      </div>
                    </div>
                    <div class="wrap_02" id="js-dragDrop-photoImage">

HTML;
				if ($action == 'checkPhoto' || $action == 'editPhotoDetail') {
					$imgPath = '';
					if ($action == 'checkPhoto') {
						#現行のアップロードドラフト（preUploadImage/replaceUploadImage）からプレビューを復元
						if (
							$targetImageUploadSessionKey !== ''
							&& isset($_SESSION[$targetImageUploadSessionKey])
							&& is_array($_SESSION[$targetImageUploadSessionKey])
							&& !empty($_SESSION[$targetImageUploadSessionKey])
						) {
							$last = end($_SESSION[$targetImageUploadSessionKey]);
							if (is_array($last)) {
								$preview = $last['preview'] ?? '';
								$path = $last['path'] ?? '';
								if (is_string($preview) && $preview !== '') {
									$imgPath = $preview;
								} elseif (is_string($path) && $path !== '') {
									$imgPath = rtrim(DOMAIN_NAME_PREVIEW, '/') . '/' . ltrim($path, '/');
								}
							}
						}
						#最終フォールバック：checkPhoto のPOSTで画像が来ていれば tmp_upload へ退避してプレビューを作る
						if ($imgPath === '' && isset($_FILES['images_tmp']) && is_array($_FILES['images_tmp']) && isset($_FILES['images_tmp']['tmp_name'])) {
							$tmpName = (string)($_FILES['images_tmp']['tmp_name'] ?? '');
							$origName = (string)($_FILES['images_tmp']['name'] ?? '');
							$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
							$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
							if ($tmpName !== '' && is_uploaded_file($tmpName) && in_array($ext, $allowed, true)) {
								$tmpDir = __DIR__ . '/../../../tmp_upload/';
								if (!file_exists($tmpDir)) {
									@mkdir($tmpDir, 0777, true);
								}
								$uniqueName = 'photo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
								$savePath = $tmpDir . $uniqueName;
								$previewUrl = '/tmp_upload/' . $uniqueName;
								if (@move_uploaded_file($tmpName, $savePath)) {
									if ($targetImageUploadSessionKey !== '') {
										$_SESSION[$targetImageUploadSessionKey] = [[
											'tmp_name' => $savePath,
											'preview' => $previewUrl,
											'name' => $uniqueName,
											'original' => $origName,
											'type' => (string)($_FILES['images_tmp']['type'] ?? ''),
											'size' => (int)($_FILES['images_tmp']['size'] ?? 0),
											'uploaded_at' => time(),
										]];
									}
									$imgPath = $previewUrl;
								}
							}
						}
					} elseif ($action == 'editPhotoDetail') {
						#既存写真のパスを復元（DBの file_path を利用）
						if ($photoKey !== null && !empty($photoList)) {
							foreach ($photoList as $p) {
								if ((string)($p['photo_id'] ?? '') !== (string)$photoKey) {
									continue;
								}
								$fp = (string)($p['file_path'] ?? '');
								if ($fp !== '') {
									$imgPath = rtrim(DOMAIN_NAME_PREVIEW, '/') . '/' . ltrim($fp, '/');
								}
								break;
							}
						}
					}
					$jsActionDeleteForCheck = json_encode('deleteFile_for_checkPage', $jsonHex);
					$jsMethod = json_encode((string)$method, $jsonHex);
					$jsShopId = json_encode((string)$shopId, $jsonHex);
					$jsFolderId = json_encode((string)$folderId, $jsonHex);
					$jsFolderName = json_encode((string)$folderName, $jsonHex);
					$jsPhotoName = json_encode((string)$photoName, $jsonHex);
					$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
					$imgPathEsc = htmlspecialchars((string)$imgPath, ENT_QUOTES, 'UTF-8');
					$makeTag['tag'] .= <<<HTML
                      <div class="check-details" id="preview-container">
                        <div class="image">
                          <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-photoImage">
                          <input type="hidden" name="upload_image_area" value="photo_image" id="js-uploadImageArea-photoImage">
                          <input type="hidden" name="up_image_area[]" value="photo_image">
                          <input type="hidden" name="send_php" value="proc_master01_01_01.php">
                          <input type="file" name="images_tmp" id="js-fileElem-photoImage" accept="image/*" style="display:none">
                          <picture>
                            <source srcset="{$imgPathEsc}" id="preview-source">
                            <img src="{$imgPathEsc}" alt="" id="preview-image">
                          </picture>
                        </div>
                        <div class="wrap_btn">
                          <div class="item_reload"><button type="button" id="js-fileSelect-photoImage"></button></div>
                          <div class="item_delate"><button type="button" onclick='deleteFile_for_checkPage(this,{$jsActionDeleteForCheck},{$jsMethod},{$jsShopId},{$jsFolderId},{$jsFolderName},{$jsPhotoName},{$jsNoUpDateKey});'></button></div>
                        </div>
                      </div>

HTML;
				} else {
					$makeTag['tag'] .= <<<HTML
                      <input type="file" name="images_tmp" id="js-fileElem-photoImage" accept="image/*" style="display:none">
                      <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-photoImage">
                      <input type="hidden" name="upload_image_area" value="photo_image" id="js-uploadImageArea-photoImage">
                      <input type="hidden" name="up_image_area[]" value="photo_image">
                      <input type="hidden" name="send_php" value="proc_master01_01_01.php">
                      <button type="button" class="btn_select" id="js-fileSelect-photoImage">写真を選択</button>
                      <ul id="fileList"><li>追加する写真、画像を選択して下さい。</li></ul>

HTML;
				}
				$makeTag['tag'] .= <<<HTML
                    </div>
                    <div class="wrap_03">
                      <h4>画像タイトル</h4>
                      <input type="text" name="photoName" value="{$photoName}">
                    </div>
                    <div class="wrap_04">
                      <ul>
                        <li>※縦横サイズがオーバーしている場合は、適正サイズで自動でリサイズされます。</li>
                        <li>※ファイル形式はJPEG形式のみです。</li>
                        <li>※ファイル容量は5MB以内です。</li>
                      </ul>

HTML;
				if ($action == 'checkPhoto' || $action == 'editPhotoDetail') {
					$makeTag['tag'] .= <<<HTML
                      <input type="hidden" name="method" value="{$method}">
                      <input type="hidden" name="action" value="sendPhoto">
                      <input type="hidden" name="shopId" value="{$shopId}">
                      <input type="hidden" name="photoKey" value="{$photoKey}">
                      <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                      <button type="button" class="btn_submit" onclick="sendSubmit();">登録</button>

HTML;
				} else {
					$makeTag['tag'] .= <<<HTML
                      <input type="hidden" name="method" value="{$method}">
                      <input type="hidden" name="action" value="checkPhoto">
                      <input type="hidden" name="shopId" value="{$shopId}">
                      <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                      <button type="button" class="btn_submit" onclick="checkSubmit();">確認</button>

HTML;
				}
				$makeTag['tag'] .= <<<HTML
                    </div>
                  </form>
                </dd>
              </div>
            </dl>
          </article>
          <article class="block_02" id="block02">
            <section class="inner_left">
              <h4>フォルダ名</h4>
              <nav>

HTML;
				#表示可能リストあればループで差し込む
				#最初のフォルダ情報を保存する変数
				$firstFolderId = '';
				$firstFolderName = '';
				$firstFolderIdRaw = '';
				$firstFolderNameRaw = '';
				$folderNameById = [];
				$activeFolderIdRaw = '';
				if (!empty($folderList)) {
					foreach ($folderList as $folder) {
						$folderIdEsc = htmlspecialchars($folder['folder_id'], ENT_QUOTES, 'UTF-8');
						$folderNameEsc = htmlspecialchars($folder['folder_name'], ENT_QUOTES, 'UTF-8');
						$folderIdRaw = (string)($folder['folder_id'] ?? '');
						$folderNameRaw = (string)($folder['folder_name'] ?? '');
						$folderNameById[$folderIdRaw] = $folderNameRaw;
						#最初のフォルダ情報を保存
						if ($firstFolderNameRaw === '') {
							$firstFolderId = $folderIdEsc;
							$firstFolderName = $folderNameEsc;
							$firstFolderIdRaw = $folderIdRaw;
							$firstFolderNameRaw = $folderNameRaw;
						}
						if ($activeFolderIdRaw === '') {
							$activeFolderIdRaw = $firstFolderIdRaw;
						}
						$jsAction = json_encode('changeFolder', $jsonHex);
						$jsShopId = json_encode((string)$shopId, $jsonHex);
						$jsFolderId = json_encode($folderIdRaw, $jsonHex);
						$jsFolderName = json_encode($folderNameRaw, $jsonHex);
						$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
						#フォルダ毎の写真枚数
						$photoCount = 0;
						if (!empty($photoList)) {
							foreach ($photoList as $photo) {
								if ((string)($photo['folder_id'] ?? '') === $folderIdRaw) {
									$photoCount++;
								}
							}
						}
						#active判定
						$navFolderActive =  '';
						#基本は先頭フォルダ、ただしフォーム側で選択済みならそのフォルダを優先
						$activeId = $activeFolderIdRaw;
						if (isset($selectedFolderIdForSelect) && is_string($selectedFolderIdForSelect) && $selectedFolderIdForSelect !== '') {
							$activeId = $selectedFolderIdForSelect;
						} elseif (($action === 'editPhotoDetail') && isset($folderId) && $folderId !== null && (string)$folderId !== '') {
							$activeId = (string)$folderId;
						}
						if ((string)$activeId === $folderIdRaw) {
							$navFolderActive = 'class = "is-active"';
						} else {
							$navFolderActive = '';
						}
						$makeTag['tag'] .= <<<HTML
                <button type="button" {$navFolderActive} onclick='changeFolder(this,{$jsAction},{$jsShopId},{$jsFolderId},{$jsFolderName},{$jsNoUpDateKey})'>
                  <h5>{$folderNameEsc}</h5>
                  <span>{$photoCount}</span>
                </button>

HTML;
					}
				}
				$makeTag['tag'] .= <<<HTML
              </nav>
            </section>
            <section class="inner_right">
              <form class="box_head">

HTML;
				if ($action == 'addFolder') {
					$selectedFolderIdRaw = (string)$folderId;
					$selectedFolderNameRaw = (string)$addFolderName;
					$selectedFolderNameEsc = htmlspecialchars((string)$addFolderName, ENT_QUOTES, 'UTF-8');
					$jsShopId = json_encode((string)$shopId, $jsonHex);
					$jsSelectedFolderId = json_encode($selectedFolderIdRaw, $jsonHex);
					$jsSelectedFolderName = json_encode($selectedFolderNameRaw, $jsonHex);
					$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
					$jsActionSetEdit = json_encode('setEditFolderName', $jsonHex);
					$jsActionDeleteFolder = json_encode('deleteFolder', $jsonHex);
					$makeTag['tag'] .= <<<HTML
                <h4>{$selectedFolderNameEsc}</h4>
                <a href="javascript:void(0);" class="item_edit" onclick='setEditFolderName(this,{$jsActionSetEdit},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
                <a href="javascript:void(0);" class="item_delate" onclick='folderDeleteCheck(this,{$jsActionDeleteFolder},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>

HTML;
				} else {
					$selectedFolderIdRaw = $firstFolderIdRaw;
					$selectedFolderNameRaw = $firstFolderNameRaw;
					$selectedFolderNameEsc = $firstFolderName;
					if (isset($selectedFolderIdForSelect) && is_string($selectedFolderIdForSelect) && $selectedFolderIdForSelect !== '') {
						$selectedFolderIdRaw = $selectedFolderIdForSelect;
						$selectedFolderNameRaw = (string)($folderNameById[$selectedFolderIdRaw] ?? $selectedFolderNameRaw);
						$selectedFolderNameEsc = htmlspecialchars($selectedFolderNameRaw, ENT_QUOTES, 'UTF-8');
					} elseif ($action === 'editPhotoDetail' && isset($folderId) && $folderId !== null && (string)$folderId !== '') {
						$selectedFolderIdRaw = (string)$folderId;
						$selectedFolderNameRaw = (string)($folderNameById[$selectedFolderIdRaw] ?? ($folderName ?? $selectedFolderNameRaw));
						$selectedFolderNameEsc = htmlspecialchars($selectedFolderNameRaw, ENT_QUOTES, 'UTF-8');
					}
					$jsShopId = json_encode((string)$shopId, $jsonHex);
					$jsSelectedFolderId = json_encode((string)$selectedFolderIdRaw, $jsonHex);
					$jsSelectedFolderName = json_encode((string)$selectedFolderNameRaw, $jsonHex);
					$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
					$jsActionSetEdit = json_encode('setEditFolderName', $jsonHex);
					$jsActionDeleteFolder = json_encode('deleteFolder', $jsonHex);
					$makeTag['tag'] .= <<<HTML
                <h4>{$selectedFolderNameEsc}</h4>
                <a href="javascript:void(0);" class="item_edit" onclick='setEditFolderName(this,{$jsActionSetEdit},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
                <a href="javascript:void(0);" class="item_delate" onclick='folderDeleteCheck(this,{$jsActionDeleteFolder},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>

HTML;
				}
				$makeTag['tag'] .= <<<HTML
              </form>
              <ul>

HTML;
				#表示可能リストあればループで差し込む
				if (!empty($photoList)) {
					foreach ($photoList as $photo) {
						if ($selectedFolderIdRaw !== '' && (string)($photo['folder_id'] ?? '') !== (string)$selectedFolderIdRaw) {
							continue;
						}
						$liActive = '';
						if ($action === 'editPhotoDetail' && isset($photoKey) && $photoKey !== null && (string)$photoKey !== '' && (string)($photo['photo_id'] ?? '') === (string)$photoKey) {
							$liActive = ' class="is-active"';
						}
						$photoId = htmlspecialchars($photo['photo_id'], ENT_QUOTES, 'UTF-8');
						$photoTitle = htmlspecialchars($photo['title'], ENT_QUOTES, 'UTF-8');
						$photoFilePath = htmlspecialchars($photo['file_path'], ENT_QUOTES, 'UTF-8');
						$photoFolderId = htmlspecialchars($photo['folder_id'], ENT_QUOTES, 'UTF-8');
						$photoFolderIdRaw = (string)($photo['folder_id'] ?? '');
						$photoFolderNameRaw = (string)($folderNameById[$photoFolderIdRaw] ?? $selectedFolderNameRaw);
						#画像サムネイル
						$previewPath = DOMAIN_NAME_PREVIEW . $photoFilePath;
						$jsActionEditPhoto = json_encode('editPhotoDetail', $jsonHex);
						$jsTypeE = json_encode('e', $jsonHex);
						$jsShopId = json_encode((string)$shopId, $jsonHex);
						$jsPhotoFolderId = json_encode($photoFolderIdRaw, $jsonHex);
						$jsPhotoFolderName = json_encode($photoFolderNameRaw, $jsonHex);
						$jsPhotoId = json_encode((string)($photo['photo_id'] ?? ''), $jsonHex);
						$jsPhotoTitle = json_encode((string)($photo['title'] ?? ''), $jsonHex);
						$jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
						$jsActionDeletePhoto = json_encode('deletePhoto', $jsonHex);
						$makeTag['tag'] .= <<<HTML
								<li {$liActive}>
                  <form>
                    <div class="item_image">
                      <picture>
                        <source srcset="{$previewPath}">
                        <img src="{$previewPath}" alt="{$photoTitle}">
                      </picture>
                    </div>
                    <div class="title">{$photoTitle}</div>
                    <a href="javascript:void(0);" class="item_edit" onclick='editPhotoDetail(this,{$jsActionEditPhoto},{$jsTypeE},{$jsShopId},{$jsPhotoFolderId},{$jsPhotoFolderName},{$jsPhotoId},{$jsPhotoTitle},{$jsNoUpDateKey})'></a>
                    <a href="javascript:void(0);" class="item_delate" onclick='photoDeleteCheck(this,{$jsActionDeletePhoto},{$jsShopId},{$jsPhotoFolderName},{$jsPhotoId},{$jsPhotoTitle},{$jsNoUpDateKey});'></a>
                  </form>
                </li>

HTML;
					}
				}
				$makeTag['tag'] .= <<<HTML
              </ul>
            </section>
          </article>

HTML;
			}
		}
		break;
}
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
