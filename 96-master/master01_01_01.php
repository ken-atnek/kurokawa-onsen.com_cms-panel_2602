<?php
/*
 * [96-master/master01_01_01.php]
 *  - 管理画面 -
 *  店舗：アルバム管理
 *
 * [初版]
 *  2026.2.14
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
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
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'mKey01-01-01_';
#このページのユニークなセッションキーを生成
$noUpDateKey = $pagePrefix . bin2hex(random_bytes(8));
$_SESSION['sKey'] = $noUpDateKey;
#不要なセッション削除
foreach ($_SESSION as $key => $val) {
  if ($key !== 'sKey' && $key !== 'master_login' && $key !== $noUpDateKey) {
    unset($_SESSION[$key]);
  }
}
#セッション本体の初期化
$_SESSION[$noUpDateKey] = array();
#アカウントキー
$_SESSION[$noUpDateKey]['masterKey'] = $_SESSION['master_login']['account_id'];
#データ取得エラー
if ($_SESSION[$noUpDateKey]['masterKey'] < 1) {
  header("Location: ./logout.php");
  exit;
}

#=============#
# POSTチェック
#-------------#
#店舗ID
$shopId = isset($_GET['shopId']) ? $_GET['shopId'] : null;
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  if ($shopData === null) {
    #店舗情報が存在しない場合は一覧にリダイレクト
    header("Location: ./master01_01.php");
    exit;
  } else {
    #店舗情報が存在する場合はフォルダと写真情報も取得
    $folderList = getFolderList($shopId);
    $photoList = getPhotoList($shopId);
  }
} else {
  #店舗IDがない場合は一覧にリダイレクト
  header("Location: ./master01_01.php");
  exit;
}
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
#-------------#
#XSS対策：エスケープ処理
$escShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">
  <head>
    <meta charset="UTF-8">
    <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data: kurokawa-onsen.com; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
    <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
    <link rel="stylesheet" href="../assets/css/master01-01-01.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-01-01-01">
      <section class="container-left-menu menu-color01">
        <div class="title">店舗管理</div>
        <nav>
          <a href="./master01_01.php"><span>店舗一覧</span></a>
          <a href="./master01_02.php?method=new" class="is-active"><span>店舗登録</span></a>
        </nav>
      </section>
      <div class="main-contents menu-color01">
        <div class="block_inner">
          <h2>写真フォルダ</h2>
          <article class="block_01" id="block01">
            <h3>{$escShopName}</h3>
            <dl>
              <div>
                <dt>フォルダ</dt>
                <dd>
                  <form name="addFolder" class="box_add-folder">
                    <h4>新規フォルダ名</h4>
                    <input type="text" name="addFolderName">
                    <input type="hidden" name="method" value="new">
                    <input type="hidden" name="action" value="addFolder">
                    <input type="hidden" name="shopId" value="{$shopId}">
                    <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                    <button type="button" class="btn_submit" onclick="addFolders(this, 'addFolder')">登録</button>
                  </form>
                </dd>
              </div>
              <div>
                <dt>写真追加</dt>
                <dd>
                  <form name="addPhoto" method="post" class="box_add-photo" enctype="multipart/form-data">
                    <div class="wrap_01">
                      <h4>格納フォルダ</h4>
                      <div class="select-folder-name" data-selectbox>
                        <button type="button" class="selectbox__head" aria-expanded="false">
                          <input type="hidden" name="select-folder" value="" data-selectbox-hidden>
                          <span class="selectbox__value" data-selectbox-value>選択してください</span>
                        </button>
                        <div class="list-wrapper">
                          <ul class="selectbox__panel">

HTML;
#表示可能リストあればループで差し込む
if (!empty($folderList)) {
  foreach ($folderList as $folder) {
    $folderId = htmlspecialchars($folder['folder_id'], ENT_QUOTES, 'UTF-8');
    $folderName = htmlspecialchars($folder['folder_name'], ENT_QUOTES, 'UTF-8');
    print <<<HTML
                            <li>
                              <input type="radio" name="selectFolder" value="{$folderId}" id="folder{$folderId}">
                              <label for="folder{$folderId}">{$folderName}</label>
                            </li>

HTML;
  }
}
print <<<HTML
                          </ul>
                        </div>
                      </div>
                    </div>
                    <div class="wrap_02" id="js-dragDrop-photoImage">
                      <input type="file" name="images_tmp" id="js-fileElem-photoImage" accept="image/*" style="display: none">
                      <input type="hidden" name="upload_image_mode" value="only" id="js-uploadImageMode-photoImage">
                      <input type="hidden" name="upload_image_area" value="photo_image" id="js-uploadImageArea-photoImage">
                      <input type="hidden" name="up_image_area[]" value="photo_image">
                      <input type="hidden" name="send_php" value="proc_master01_01_01.php">
                      <button type="button" class="btn_select" id="js-fileSelect-photoImage">写真を選択</button>
                      <ul id="fileList">
                        <li>追加する写真、画像を選択して下さい。</li>
                      </ul>
                    </div>
                    <div class="wrap_03">
                      <h4>画像タイトル</h4>
                      <input type="text" name="photoName">
                    </div>
                    <div class="wrap_04">
                      <ul>
                        <li>
                          ※縦横サイズがオーバーしている場合は、適正サイズで自動でリサイズされます。
                        </li>
                        <li>※ファイル形式はJPEG形式のみです。</li>
                        <li>※ファイル容量は5MB以内です。</li>
                      </ul>
                      <input type="hidden" name="method" value="new">
                      <input type="hidden" name="action" value="checkPhoto">
                      <input type="hidden" name="shopId" value="{$shopId}">
                      <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                      <button type="button" class="btn_submit" onclick="checkSubmit()">確認</button>
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
$folderNameById = array();
if (!empty($folderList)) {
  foreach ($folderList as $folder) {
    $folderIdEsc = htmlspecialchars($folder['folder_id'], ENT_QUOTES, 'UTF-8');
    $folderNameEsc = htmlspecialchars($folder['folder_name'], ENT_QUOTES, 'UTF-8');
    $folderIdRaw = (string)($folder['folder_id'] ?? '');
    $folderNameRaw = (string)($folder['folder_name'] ?? '');
    $folderNameById[$folderIdRaw] = $folderNameRaw;
    #最初のフォルダ情報を保存
    if ($firstFolderName === '') {
      $firstFolderId = $folderIdEsc;
      $firstFolderName = $folderNameEsc;
      $firstFolderIdRaw = $folderIdRaw;
      $firstFolderNameRaw = $folderNameRaw;
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
    if ($firstFolderIdRaw === $folderIdRaw) {
      $navFolderActive = 'class = "is-active"';
    } else {
      $navFolderActive = '';
    }
    print <<<HTML
                <button type="button" {$navFolderActive} onclick='changeFolder(this,{$jsAction},{$jsShopId},{$jsFolderId},{$jsFolderName},{$jsNoUpDateKey})'>
                  <h5>{$folderNameEsc}</h5>
                  <span>{$photoCount}</span>
                </button>

HTML;
  }
}
print <<<HTML
              </nav>
            </section>
            <section class="inner_right">

HTML;
#表示可能リストあればループで差し込む
if (!empty($folderList)) {
  $jsShopId = json_encode((string)$shopId, $jsonHex);
  $jsSelectedFolderId = json_encode((string)$firstFolderIdRaw, $jsonHex);
  $jsSelectedFolderName = json_encode((string)$firstFolderNameRaw, $jsonHex);
  $jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
  $jsActionSetEdit = json_encode('setEditFolderName', $jsonHex);
  $jsActionDeleteFolder = json_encode('deleteFolder', $jsonHex);
  print <<<HTML
              <form class="box_head">
                <h4>{$firstFolderName}</h4>
                <a href="javascript:void(0);" class="item_edit" onclick='setEditFolderName(this,{$jsActionSetEdit},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
                <a href="javascript:void(0);" class="item_delate" onclick='folderDeleteCheck(this,{$jsActionDeleteFolder},{$jsShopId},{$jsSelectedFolderId},{$jsSelectedFolderName},{$jsNoUpDateKey});'></a>
              </form>
              <ul>

HTML;
  #表示可能リストあればループで差し込む
  if (!empty($photoList)) {
    foreach ($photoList as $photo) {
      if ($firstFolderIdRaw !== '' && (string)($photo['folder_id'] ?? '') !== (string)$firstFolderIdRaw) {
        continue;
      }
      $photoId = htmlspecialchars($photo['photo_id'], ENT_QUOTES, 'UTF-8');
      $photoTitle = htmlspecialchars($photo['title'], ENT_QUOTES, 'UTF-8');
      $photoFilePath = htmlspecialchars($photo['file_path'], ENT_QUOTES, 'UTF-8');
      $photoFolderId = htmlspecialchars($photo['folder_id'], ENT_QUOTES, 'UTF-8');
      $photoFolderIdRaw = (string)($photo['folder_id'] ?? '');
      $photoFolderNameRaw = (string)($folderNameById[$photoFolderIdRaw] ?? $firstFolderNameRaw);
      #画像サムネイル
      $previewPath = DOMAIN_NAME_PREVIEW . $photoFilePath;
      $jsActionEditPhoto = json_encode('editPhotoDetail', $jsonHex);
      $jsTypeE = json_encode('e', $jsonHex);
      $jsPhotoFolderId = json_encode($photoFolderIdRaw, $jsonHex);
      $jsPhotoFolderName = json_encode($photoFolderNameRaw, $jsonHex);
      $jsPhotoId = json_encode((string)($photo['photo_id'] ?? ''), $jsonHex);
      $jsPhotoTitle = json_encode((string)($photo['title'] ?? ''), $jsonHex);
      $jsActionDeletePhoto = json_encode('deletePhoto', $jsonHex);
      print <<<HTML
                <li>
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
  print <<<HTML
              </ul>

HTML;
} else {
  print <<<HTML
              <form class="box_head">
                <h4>写真が登録されていません</h4>
              </form>

HTML;
}
print <<<HTML
            </section>
          </article>
          <a href="#" class="link_page-back_bottom">戻る</a>
          <a href="#body" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!-- NOTE is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>写真フォルダ</p>
          <button type="button" onclick="closeModal()" class="btn-top-close"></button>
        </div>
        <div class="box-details">
          <p></p>
          <div class="box-btn">
            <button type="button" class="btn-cancel" onclick="closeModal();">閉じる</button>
          </div>
        </div>
      </div>
    </article>
    <script src="../assets/js/common.js" defer></script>
    <script src="../assets/js/dropZone.js" defer></script>
    <script src="../assets/js/modal.js" defer></script>
    <script src="./assets/js/master01_01_01.js" defer></script>
  </body>
</html>

HTML;
