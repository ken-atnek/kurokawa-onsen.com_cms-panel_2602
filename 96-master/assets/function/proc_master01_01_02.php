<?php
/*
 * [96-master/assets/function/proc_master01_01_02.php]
 *  - 管理画面 -
 *  店舗紹介情報登録／編集 処理
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
#店舗紹介情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shop_details.php';
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
#紹介文章
$form01 = isset($_POST['form01']) ? $_POST['form01'] : null;
#紹介文章（英語）
$form01_01 = isset($_POST['form01_01']) ? $_POST['form01_01'] : null;
#地図URL
$form02 = isset($_POST['form02']) ? $_POST['form02'] : null;
#地図URL（リンク用）
$form03 = isset($_POST['form03']) ? $_POST['form03'] : null;
#メイン画像
$mainImagePath = isset($_POST['main_image_path']) ? $_POST['main_image_path'] : null;
#画像1
$image1Path = isset($_POST['image_path_1']) ? $_POST['image_path_1'] : null;
$image1Title = isset($_POST['image_title_1']) ? $_POST['image_title_1'] : null;
#画像2
$image2Path = isset($_POST['image_path_2']) ? $_POST['image_path_2'] : null;
$image2Title = isset($_POST['image_title_2']) ? $_POST['image_title_2'] : null;
#画像3
$image3Path = isset($_POST['image_path_3']) ? $_POST['image_path_3'] : null;
$image3Title = isset($_POST['image_title_3']) ? $_POST['image_title_3'] : null;
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#***** タグ生成開始 *****#
switch ($action) {
  #***** 画像選択モーダル生成 *****#
  case 'changeFolder':
  case 'selectFileModal': {
      if ($shopId !== null) {
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
      #必須項目チェック
      $requiredFields = [
        'form01' => '紹介文章',
        'form01_01' => '紹介文章（英語）',
        'form02' => '地図URL',
        'form03' => '地図URL（リンク用）',
      ];
      foreach ($requiredFields as $fieldName => $fieldLabel) {
        $value = isset($_POST[$fieldName]) ? trim($_POST[$fieldName]) : '';
        if ($value === '') {
          $validationErrors[] = $fieldLabel . 'は必須です。';
        }
      }
      #地図URL形式チェック（空でない場合のみ）
      if (!empty($form02) && !filter_var($form02, FILTER_VALIDATE_URL)) {
        $validationErrors[] = '地図URLの形式が正しくありません。';
      }
      if (!empty($form03) && !filter_var($form03, FILTER_VALIDATE_URL)) {
        $validationErrors[] = '地図URL（リンク用）の形式が正しくありません。';
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
            'pageName' => 'proc_master01_01_02',
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
          #DB登録情報準備
          $mapURLRaw = (!empty($form02)) ? trim($form02) : '';
          $mapLinkURLRaw = (!empty($form03)) ? trim($form03) : '';
          #URL用：スペース等（半角/全角含むホワイトスペース）を全て削除
          $mapURL = preg_replace('/[\s　]+/u', '', $mapURLRaw);
          $mapLinkURL = preg_replace('/[\s　]+/u', '', $mapLinkURLRaw);
          switch ($method) {
            #***** 新規登録 *****#
            case 'new': {
                if (!is_numeric($shopId) || (int)$shopId <= 0) {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_01_02',
                    'reason' => '店舗ID未指定（新規）',
                  ];
                  makeLog($data);
                  $dbCompleteFlg = false;
                  break;
                }
                $shopId = (int)$shopId;
                #登録用配列：初期化
                $dbFiledData = array();
                #登録情報セット
                $dbFiledData['shop_id'] = array(':shop_id', $shopId, 1);
                $dbFiledData['intro_body'] = array(':intro_body', $form01, 1);
                $dbFiledData['intro_body_en'] = array(':intro_body_en', $form01_01, 1);
                $dbFiledData['main_image_path'] = array(':main_image_path', $mainImagePath, 0);
                $dbFiledData['image_path_1'] = array(':image_path_1', $image1Path, 0);
                $dbFiledData['image_title_1'] = array(':image_title_1', $image1Title, 0);
                $dbFiledData['image_path_2'] = array(':image_path_2', $image2Path, 0);
                $dbFiledData['image_title_2'] = array(':image_title_2', $image2Title, 0);
                $dbFiledData['image_path_3'] = array(':image_path_3', $image3Path, 0);
                $dbFiledData['image_title_3'] = array(':image_title_3', $image3Title, 0);
                $dbFiledData['map_url'] = array(':map_url', $mapURL, 0);
                $dbFiledData['map_link_url'] = array(':map_link_url', $mapLinkURL, 0);
                $dbFiledData['created_at'] = array(':created_at', date("Y-m-d H:i:s"), 0);
                #更新用キー：初期化
                $dbFiledValue = array();
                #処理モード：[1].新規追加｜[2].更新｜[3].削除
                $processFlg = 1;
                #実行モード：[1].トランザクション｜[2].即実行
                $exeFlg = 2;
                #DB更新
                $dbSuccessFlg = SQL_Process($DB_CONNECT, "shops_details", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
                #基本情報の更新が成功したらJSONデータ書き出し処理に進む
                if ($dbSuccessFlg != 1) {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_01_02',
                    'reason' => '新規店舗紹介登録失敗',
                  ];
                  makeLog($data);
                  $dbCompleteFlg = false;
                  break;
                }
              }
              break;
            #***** 編集 *****#
            case 'edit': {
                if (!is_numeric($shopId) || (int)$shopId <= 0) {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_01_02',
                    'reason' => '店舗ID未指定（編集）',
                  ];
                  makeLog($data);
                  $dbCompleteFlg = false;
                  break;
                }
                $shopId = (int)$shopId;
                #登録用配列：初期化
                $dbFiledData = array();
                #登録情報セット
                $dbFiledData['intro_body'] = array(':intro_body', $form01, 1);
                $dbFiledData['intro_body_en'] = array(':intro_body_en', $form01_01, 1);
                $dbFiledData['main_image_path'] = array(':main_image_path', $mainImagePath, 0);
                $dbFiledData['image_path_1'] = array(':image_path_1', $image1Path, 0);
                $dbFiledData['image_title_1'] = array(':image_title_1', $image1Title, 0);
                $dbFiledData['image_path_2'] = array(':image_path_2', $image2Path, 0);
                $dbFiledData['image_title_2'] = array(':image_title_2', $image2Title, 0);
                $dbFiledData['image_path_3'] = array(':image_path_3', $image3Path, 0);
                $dbFiledData['image_title_3'] = array(':image_title_3', $image3Title, 0);
                $dbFiledData['map_url'] = array(':map_url', $mapURL, 0);
                $dbFiledData['map_link_url'] = array(':map_link_url', $mapLinkURL, 0);
                $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
                #更新用キー：初期化
                $dbFiledValue = array();
                $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
                #処理モード：[1].新規追加｜[2].更新｜[3].削除
                $processFlg = 2;
                #実行モード：[1].トランザクション｜[2].即実行
                $exeFlg = 2;
                #DB更新
                $dbSuccessFlg = SQL_Process($DB_CONNECT, "shops_details", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
                #基本情報の更新が成功したらログイン情報の登録処理に進む
                if ($dbSuccessFlg != 1) {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_01_02',
                    'reason' => '店舗紹介情報更新失敗',
                  ];
                  makeLog($data);
                  $dbCompleteFlg = false;
                  break;
                }
              }
              break;
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
                  $makeTag['title'] = '新規店舗登録';
                  $makeTag['msg'] = '登録が完了しました。';
                }
                break;
              #***** 編集 *****#
              case 'edit': {
                  $makeTag['title'] = '店舗情報編集';
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
          'pageName' => 'proc_master01_02',
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
