<?php
/*
 * [96-master/assets/function/proc_master01_01_03.php]
 *  - 管理画面 -
 *  おすすめ商品登録／編集 処理
 *
 * [初版]
 *  2026.2.18
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
#おすすめ商品情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_items.php';
#フォルダ情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_folders.php';
#写真情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_photos.php';

#================#
# 応答用タグ初期化
#----------------#
$makeTag = array(
  'tag' => '',
  'status' => '',
  'title' => '',
  'msg' => '',
);

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

#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : null;
#確認／修正／登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#店舗ID
$shopId = isset($_POST['shopId']) ? $_POST['shopId'] : null;
#-------------#
for ($slot = 1; $slot <= $recommendedItemMax; $slot++) {
  #商品タイトル
  ${"title" . $slot} = isset($_POST['item']['recommended'][$slot]['title']) ? $_POST['item']['recommended'][$slot]['title'] : null;
  #価格
  ${"price" . $slot} = isset($_POST['item']['recommended'][$slot]['price']) ? $_POST['item']['recommended'][$slot]['price'] : null;
  #商品説明
  ${"description" . $slot} = isset($_POST['item']['recommended'][$slot]['description']) ? $_POST['item']['recommended'][$slot]['description'] : null;
  #画像パス
  ${"imagePath" . $slot} = isset($_POST["image_path{$slot}"]) ? $_POST["image_path{$slot}"] : null;
}
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#***** タグ生成開始 *****#
switch ($action) {
  #***** 画像選択モーダル生成 *****#
  case 'changeFolder':
  case 'selectFileModal': {
      if ($shopId !== null && is_numeric($shopId) && (int)$shopId > 0) {
        $shopId = (int)$shopId;
        #店舗情報が存在する場合はフォルダと写真情報も取得
        $folderList = getFolderList($shopId);
        $photoList = getPhotoList($shopId);
      } else {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '店舗情報エラー';
        $makeTag['msg'] = '店舗情報が取得できませんでした。ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #=============#
      # POSTチェック
      #-------------#
      $target = isset($_POST['target']) ? $_POST['target'] : null;
      $selectType = isset($_POST['selectType']) ? $_POST['selectType'] : null;
      $postedFolderId = isset($_POST['folderId']) ? (string)$_POST['folderId'] : '';
      #JS用（フォルダ切替後も選択対象を保持）
      $jsTarget = json_encode($target, $jsonHex);
      $jsSelectType = json_encode($selectType, $jsonHex);
      #-------------#
      #タグ生成
      #表示可能リストあればループで差し込む
      #最初のフォルダ情報を保存する変数
      $firstFolderId = '';
      $firstFolderName = '';
      $firstFolderIdRaw = '';
      $firstFolderNameRaw = '';
      $selectedFolderIdRaw = '';
      $selectedFolderNameRaw = '';
      $folderNameById = array();
      if (!empty($folderList)) {
        $makeTag['tag'] .= <<<HTML
    <article class="modal-select-image is-active" id="modalSelectBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>画像セレクト</p>
          <button type="button" onclick="closeModal()" class="btn-top-close"></button>
        </div>
        <div class="inner-select-image">
          <div class="block_select-image">
            <section class="inner_left">
              <h4>フォルダ名</h4>
              <nav>

HTML;
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
          #選択中フォルダ（POSTのfolderId優先、無ければ先頭）
          if ($selectedFolderIdRaw === '') {
            if ($postedFolderId !== '' && $postedFolderId === $folderIdRaw) {
              $selectedFolderIdRaw = $folderIdRaw;
              $selectedFolderNameRaw = $folderNameRaw;
            }
          }
          #JSエスケープ
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
          $activeFolderId = ($selectedFolderIdRaw !== '') ? $selectedFolderIdRaw : $firstFolderIdRaw;
          if ($activeFolderId === $folderIdRaw) {
            $navFolderActive = 'class = "is-active"';
          } else {
            $navFolderActive = '';
          }
          $makeTag['tag'] .= <<<HTML
                <button type="button" {$navFolderActive} onclick='changeFolder(this,{$jsAction},{$jsShopId},{$jsFolderId},{$jsFolderName},{$jsNoUpDateKey},{$jsSelectType},{$jsTarget})'>
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

HTML;
      #表示可能リストあればループで差し込む
      if (!empty($folderList)) {
        if ($selectedFolderIdRaw === '') {
          $selectedFolderIdRaw = $firstFolderIdRaw;
          $selectedFolderNameRaw = $firstFolderNameRaw;
        }
        $jsShopId = json_encode((string)$shopId, $jsonHex);
        $jsSelectedFolderId = json_encode((string)$selectedFolderIdRaw, $jsonHex);
        $jsSelectedFolderName = json_encode((string)$selectedFolderNameRaw, $jsonHex);
        $jsNoUpDateKey = json_encode((string)$noUpDateKey, $jsonHex);
        $jsActionSelect = json_encode('selectFile', $jsonHex);
        $makeTag['tag'] .= <<<HTML
              <ul>

HTML;
        #表示可能リストあればループで差し込む
        if (!empty($photoList)) {
          foreach ($photoList as $photo) {
            if ($selectedFolderIdRaw !== '' && (string)($photo['folder_id'] ?? '') !== (string)$selectedFolderIdRaw) {
              continue;
            }
            $photoTitle = htmlspecialchars($photo['title'], ENT_QUOTES, 'UTF-8');
            $photoFilePathRaw = (string)($photo['file_path'] ?? '');
            $photoFolderIdRaw = (string)($photo['folder_id'] ?? '');
            $photoFolderNameRaw = (string)($folderNameById[$photoFolderIdRaw] ?? $firstFolderNameRaw);
            #画像サムネイル
            $previewPath = DOMAIN_NAME_PREVIEW . $photoFilePathRaw;
            $previewPathEsc = htmlspecialchars($previewPath, ENT_QUOTES, 'UTF-8');
            $jsPhotoFilePath = json_encode($photoFilePathRaw, $jsonHex);
            $jsPhotoPreviewPath = json_encode((string)($previewPath ?? ''), $jsonHex);
            $jsPhotoTitle = json_encode((string)($photo['title'] ?? ''), $jsonHex);
            $jsActionDeletePhoto = json_encode('deletePhoto', $jsonHex);
            $makeTag['tag'] .= <<<HTML
                <li>
                  <form>
                    <div class="item_image">
                      <picture>
                        <source srcset="{$previewPathEsc}">
                        <img src="{$previewPathEsc}" alt="{$photoTitle}">
                      </picture>
                    </div>
                    <div class="title">{$photoTitle}</div>
                    <button type="button" class="btn-confirm" onclick='selectFile(this,{$jsActionSelect},{$jsSelectType},{$jsTarget},{$jsShopId},{$jsPhotoFilePath},{$jsPhotoPreviewPath},{$jsPhotoTitle},{$jsNoUpDateKey})'>選択</button>
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
    }
    break;

  #***** 登録 *****#
  case 'sendInput': {
      #----------------------------
      # サーバーサイドバリデーション
      #----------------------------
      $validationErrors = [];
      #methodチェック
      if ($method !== 'new' && $method !== 'edit') {
        $validationErrors[] = '処理方法が不正です。';
      }
      #必須項目チェック（最低1枠目のみ）
      $requiredTitle = isset($_POST['item']['recommended'][1]['title']) ? trim((string)$_POST['item']['recommended'][1]['title']) : '';
      $requiredDescription = isset($_POST['item']['recommended'][1]['description']) ? trim((string)$_POST['item']['recommended'][1]['description']) : '';
      $requiredImagePath = isset($_POST['image_path1']) ? trim((string)$_POST['image_path1']) : '';
      if ($requiredTitle === '') {
        $validationErrors[] = '商品タイトルは必須です。';
      }
      if ($requiredDescription === '') {
        $validationErrors[] = '商品説明は必須です。';
      }
      if ($requiredImagePath === '') {
        $validationErrors[] = 'メイン写真は必須です。';
      }
      #バリデーションエラーがあれば処理中断
      if (!empty($validationErrors)) {
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = implode("\n", $validationErrors);
        header('Content-Type: application/json');
        echo json_encode($makeTag);
        exit;
      }
      #更新開始
      try {
        #トランザクション開始
        # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
        $result = DB_Transaction(1);
        if ($result == false) {
          #エラーログ出力
          $data = [
            'pageName' => 'proc_master01_01_03',
            'reason' => 'トランザクション開始失敗',
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '登録エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          header('Content-Type: application/json');
          echo json_encode($makeTag);
          exit;
        } else {
          #DB登録結果フラグ：初期化
          $dbCompleteFlg = true;
          if (!is_numeric($shopId) || (int)$shopId <= 0) {
            #エラーログ出力
            $data = [
              'pageName' => 'proc_master01_01_03',
              'reason' => '店舗ID未指定',
            ];
            makeLog($data);
            $dbCompleteFlg = false;
          } else {
            $shopId = (int)$shopId;

            #---------------------------------
            # recommended は固定枠なので、一旦削除→全枠INSERT
            #（新規/編集、途中欠損データの救済も兼ねる）
            #---------------------------------
            $delWhere = array();
            $delWhere['shop_id'] = array(':shop_id', $shopId, 1);
            $delWhere['item_type'] = array(':item_type', 'recommended', 0);
            $delFlg = SQL_Process($DB_CONNECT, 'shop_items', array(), $delWhere, 3, 2);
            if ($delFlg != 1) {
              $data = [
                'pageName' => 'proc_master01_01_03',
                'reason' => 'おすすめ商品削除失敗',
                'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
              ];
              makeLog($data);
              $dbCompleteFlg = false;
            } else {
              for ($slot = 1; $slot <= $recommendedItemMax; $slot++) {
                $titleRaw = isset(${"title" . $slot}) ? trim((string)${"title" . $slot}) : '';
                $descRaw = isset(${"description" . $slot}) ? trim((string)${"description" . $slot}) : '';
                $imageRaw = isset(${"imagePath" . $slot}) ? trim((string)${"imagePath" . $slot}) : '';
                $priceRaw = isset(${"price" . $slot}) ? trim((string)${"price" . $slot}) : '';

                $titleVal = ($titleRaw === '') ? null : $titleRaw;
                $descVal = ($descRaw === '') ? null : $descRaw;
                $imageVal = ($imageRaw === '') ? null : $imageRaw;
                $priceVal = ($priceRaw === '') ? null : (int)$priceRaw;

                $ins = array();
                $ins['shop_id'] = array(':shop_id', $shopId, 1);
                $ins['item_type'] = array(':item_type', 'recommended', 0);
                $ins['slot'] = array(':slot', $slot, 1);
                $ins['title'] = array(':title', $titleVal, ($titleVal === null ? 2 : 0));
                $ins['description'] = array(':description', $descVal, ($descVal === null ? 2 : 0));
                $ins['price_yen'] = array(':price_yen', $priceVal, ($priceVal === null ? 2 : 1));
                $ins['image_path'] = array(':image_path', $imageVal, ($imageVal === null ? 2 : 0));
                $ins['is_active'] = array(':is_active', 1, 1);

                $insFlg = SQL_Process($DB_CONNECT, 'shop_items', $ins, array(), 1, 2);
                if ($insFlg != 1) {
                  $data = [
                    'pageName' => 'proc_master01_01_03',
                    'reason' => 'おすすめ商品INSERT失敗',
                    'slot' => $slot,
                    'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
                  ];
                  makeLog($data);
                  $dbCompleteFlg = false;
                  break;
                }
              }
            }
          }
          #全ての処理成功
          if ($dbCompleteFlg == true) {
            #DBコミット
            # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
            DB_Transaction(2);
            #応答用タグセット
            $makeTag['status'] = 'success';
            switch ($method) {
              #***** 新規登録 *****#
              case 'new': {
                  $makeTag['title'] = '新規おすすめ商品登録';
                  $makeTag['msg'] = '登録が完了しました。';
                }
                break;
              #***** 編集 *****#
              case 'edit': {
                  $makeTag['title'] = 'おすすめ商品情報編集';
                  $makeTag['msg'] = '更新が完了しました。';
                }
                break;
            }
            #----------------------------
            # DB更新完了のJSONファイル作成
            #----------------------------
            $cmd = '/usr/bin/php8.3 ' . DEFINE_JSON_FUNCTION_MASTER . '/workJson/makeShops.php ' . $shopId . ' 2>&1 &';
            exec($cmd, $output, $return_var);
          } else {
            #失敗時はROLLBACK
            DB_Transaction(3);
            if ($makeTag['status'] === '') {
              $makeTag['status'] = 'error';
              $makeTag['title'] = '登録エラー';
              $makeTag['msg'] = '登録処理に失敗しました。';
            }
          }
        }
      } catch (Exception $e) {
        #ROLLBACK
        DB_Transaction(3);
        #エラーログ出力
        $data = [
          'pageName' => 'proc_master01_01_03',
          'reason' => 'トランザクション開始失敗',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '登録処理に失敗しました。';
      }
    }
    break;
}
#-------------------------------------------#
#json 応答
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
