<?php
/*
 * [96-client/assets/function/proc_client03_05_01.php]
 *  - 【加盟店】管理画面 -
 *  分類登録／編集 処理
 *
 * [初版]
 *  2026.4.23
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/../../cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_contents.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ EC-CUBE API 共通クライアント ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/api/eccube/eccube_api.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/client/start_processing.php';
#***** ★ DBテーブル読み書きファイル：インクルード ★ *****#
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';
#店舗情報（EC関連）
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops_ec.php';

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
    $makeTag['msg'] = 'セッションが切れました。<br>ページを再読み込みしてください。';
    echo json_encode($makeTag);
    exit;
  }
}

#新規／編集
$method = isset($_POST['method']) ? $_POST['method'] : null;
#確認／修正／登録
$action = isset($_POST['action']) ? $_POST['action'] : null;
#店舗ID
$shopId = $_SESSION['client_login']['shop_id'] ?? null;
#規格ID（編集時のみ）
$specificationId = isset($_POST['specificationId']) ? $_POST['specificationId'] : null;
#分類ID（編集時のみ）
$classifyId = isset($_POST['classifyId']) ? $_POST['classifyId'] : null;
#表示状態（切替時のみ）
$isActive = isset($_POST['isActive']) ? $_POST['isActive'] : null;
#並び順更新用（JSON配列）
$orderedIdsJson = isset($_POST['orderedIds']) ? (string)$_POST['orderedIds'] : null;
if ($shopId === null || ctype_digit((string)$shopId) === false || (int)$shopId <= 0) {
  header('Content-Type: application/json; charset=UTF-8');
  $makeTag['status'] = 'error';
  $makeTag['title'] = 'セッションエラー';
  $makeTag['msg'] = '店舗情報が取得できませんでした。<br>再ログインしてください。';
  echo json_encode($makeTag);
  exit;
}
$shopId = (int)$shopId;

#=========#
# 規格一覧
#---------#
$itemSpecificationsList = getShopItemSpecifications($shopId);

#規格詳細（指定時）
$itemSpecificationDetails = null;
if ($specificationId !== null && $specificationId !== '' && is_numeric($specificationId) === true && (int)$specificationId > 0) {
  $specificationId = (int)$specificationId;
  $itemSpecificationDetails = getShopItemSpecificationDetails($shopId, $specificationId);
}

#=========#
# 分類一覧
#---------#
$itemClassifyList = null;
if ($itemSpecificationDetails !== null) {
  $itemClassifyList = getShopItemClassify($shopId, $specificationId);
}

#---------#
#新規分類名
$newClassifyName = isset($_POST['newClassifyName']) ? trim($_POST['newClassifyName']) : null;
#編集分類名
$editClassifyName = isset($_POST['editClassifyName']) ? trim($_POST['editClassifyName']) : null;
#新規分類管理名
$newClassifyAdminName = isset($_POST['newClassifyAdminName']) ? trim($_POST['newClassifyAdminName']) : null;
#編集分類管理名
$editClassifyAdminName = isset($_POST['editClassifyAdminName']) ? trim($_POST['editClassifyAdminName']) : null;
#ソート順
$sortOrder = isset($_POST['sortOrder']) ? $_POST['sortOrder'] : null;
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

#保存用管理名（省略時は空）
$saveNewClassifyAdminName = ($newClassifyAdminName === null || $newClassifyAdminName === '') ? '' : $newClassifyAdminName;
$saveEditClassifyAdminName = ($editClassifyAdminName === null || $editClassifyAdminName === '') ? '' : $editClassifyAdminName;

/*
 * [EC-CUBE GraphQL 文字列エスケープ]
 */
function buildEccubeGraphqlString($value)
{
  $encoded = json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) {
    throw new Exception('EC-CUBE連携用の文字列変換に失敗しました。');
  }
  return $encoded;
}
/*
 * [EC-CUBE連携warning応答設定]
 */
function appendEccubeWarningMessage(&$makeTag)
{
  $makeTag['status'] = 'warning';
  if (strpos((string)$makeTag['msg'], 'EC-CUBE連携に失敗しました。') === false) {
    $makeTag['msg'] .= '<br>EC-CUBE連携に失敗しました。';
  }
}

#***** タグ生成開始 *****#
switch ($action) {
  #***** 編集用formタグ生成 *****#
  case 'makeEditForm': {
      #編集以外は未対応（別工程）
      if ($method !== 'edit') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #=====================#
      # 分類詳細 （編集時のみ）
      #---------------------#
      if ($classifyId === null || $classifyId === '' || is_numeric($classifyId) === false || (int)$classifyId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類IDが不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $classifyId = (int)$classifyId;
      $itemClassifyDetails = getShopItemClassifyDetails($shopId, $classifyId);
      if ($itemClassifyDetails === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '指定された分類情報が見つかりませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $itemClassifyID = htmlspecialchars($itemClassifyDetails['classify_id'], ENT_QUOTES, 'UTF-8');
      $itemSpecificationID = htmlspecialchars($itemClassifyDetails['specification_id'], ENT_QUOTES, 'UTF-8');
      $itemClassifyName = htmlspecialchars($itemClassifyDetails['name'], ENT_QUOTES, 'UTF-8');
      $itemClassifyAdminName = htmlspecialchars($itemClassifyDetails['backend_name'], ENT_QUOTES, 'UTF-8');
      #編集モードで応答
      $makeTag['status'] = 'editForm';
      #タグ生成
      $makeTag['tag'] .= <<<HTML
                <form name="editForm" class="inputForm">
                  <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                  <input type="hidden" name="method" value="edit">
                  <input type="hidden" name="specificationId" value="{$itemSpecificationID}">
                  <input type="hidden" name="classifyId" value="{$itemClassifyID}">
                  <div>
                    <span>分類名</span>
                    <input type="text" name="editClassifyName" class="required-item" value="{$itemClassifyName}" required maxlength="255">
                  </div>
                  <div>
                    <span>管理名</span>
                    <input type="text" name="editClassifyAdminName" value="{$itemClassifyAdminName}" maxlength="255">
                  </div>
                  <button type="button" class="btn-submit" onclick="sendInput()">決定</button>
                  <button type="button" class="btn-cancel" onclick="cancelEdit()">キャンセル</button>
                </form>

HTML;
    }
    break;
  #***** 登録 *****#
  case 'sendInput': {
      #----------------------------
      # 新規追加／編集
      #----------------------------
      if ($method !== 'new' && $method !== 'edit') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #----------------------------
      # サーバーサイドバリデーション
      #----------------------------
      $validationErrors = [];
      if ($method === 'new') {
        if ($specificationId === null || $specificationId === '' || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
          $validationErrors[] = '規格IDが不正です。';
        }
        if ($newClassifyName === null || $newClassifyName === '') {
          $validationErrors[] = '分類名は必須です。';
        } else {
          if (mb_strlen($newClassifyName, 'UTF-8') > 255) {
            $validationErrors[] = '分類名は255文字以内で入力してください。';
          }
        }
        if ($newClassifyAdminName !== null && $newClassifyAdminName !== '' && mb_strlen($newClassifyAdminName, 'UTF-8') > 255) {
          $validationErrors[] = '管理名は255文字以内で入力してください。';
        }
      } else if ($method === 'edit') {
        if ($classifyId === null || $classifyId === '' || is_numeric($classifyId) === false || (int)$classifyId <= 0) {
          $validationErrors[] = '分類IDが不正です。';
        }
        if ($editClassifyName === null || $editClassifyName === '') {
          $validationErrors[] = '分類名は必須です。';
        } else {
          if (mb_strlen($editClassifyName, 'UTF-8') > 255) {
            $validationErrors[] = '分類名は255文字以内で入力してください。';
          }
        }
        if ($editClassifyAdminName !== null && $editClassifyAdminName !== '' && mb_strlen($editClassifyAdminName, 'UTF-8') > 255) {
          $validationErrors[] = '管理名は255文字以内で入力してください。';
        }
      }
      if (!empty($validationErrors)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = implode("\n", $validationErrors);
        echo json_encode($makeTag);
        exit;
      }
      #分類存在チェック（編集時）
      if ($method === 'edit') {
        $classifyId = (int)$classifyId;
        $itemClassifyDetails = getShopItemClassifyDetails($shopId, $classifyId);
        if ($itemClassifyDetails === null) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = '分類情報';
          $makeTag['msg'] = '指定された分類情報が見つかりませんでした。<br>ページを再読み込みしてください。';
          echo json_encode($makeTag);
          exit;
        }
        $specificationId = (int)$itemClassifyDetails['specification_id'];
      } else {
        $specificationId = (int)$specificationId;
        $itemSpecificationDetails = getShopItemSpecificationDetails($shopId, $specificationId);
        if ($itemSpecificationDetails === null) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = '規格情報';
          $makeTag['msg'] = '指定された規格情報が見つかりませんでした。<br>ページを再読み込みしてください。';
          echo json_encode($makeTag);
          exit;
        }
      }
      #同名分類（同一shop/同一parent）重複チェック（rootのみ）
      if ($method === 'new') {
        $isDuplicate = isShopItemClassifyNameExistsRoot($shopId, $specificationId, $newClassifyName);
      } else {
        $isDuplicate = isShopItemClassifyNameExistsRootExcludeClassifyId($shopId, $specificationId, $editClassifyName, (int)$classifyId);
      }
      if ($isDuplicate === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '分類情報の確認に失敗しました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($isDuplicate === true) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '同名の分類が既に存在します。<br>分類名を変更して再度登録してください。';
        echo json_encode($makeTag);
        exit;
      }
      #更新開始
      try {
        $savedClassifyId = null;
        #トランザクション開始
        # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
        $result = DB_Transaction(1);
        if ($result == false) {
          #エラーログ出力
          $data = [
            'pageName' => 'proc_client03_05_01',
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
          if ($method === 'new') {
            #sort_order 採番（rootのみ）
            $nextSortOrder = getNextShopItemClassifySortOrderRoot($shopId, $specificationId);
            if ($nextSortOrder === null || is_numeric($nextSortOrder) === false || (int)$nextSortOrder <= 0) {
              $nextSortOrder = 1;
            }
            $nextSortOrder = (int)$nextSortOrder;
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット
            $dbFiledData['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledData['class_name_id'] = array(':class_name_id', $specificationId, 1);
            $dbFiledData['name'] = array(':name', $newClassifyName, 0);
            $dbFiledData['backend_name'] = array(':backend_name', $saveNewClassifyAdminName, 0);
            $dbFiledData['sort_order'] = array(':sort_order', $nextSortOrder, 1);
            $dbFiledData['is_active'] = array(':is_active', 1, 1);
            $dbFiledData['created_at'] = array(':created_at', date("Y-m-d H:i:s"), 0);
            $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
            #更新用キー：初期化
            $dbFiledValue = array();
            #処理モード：[1].新規追加｜[2].更新｜[3].削除
            $processFlg = 1;
            #実行モード：[1].トランザクション｜[2].即実行
            $exeFlg = 2;
            #DB更新
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
            if ($dbSuccessFlg == 1) {
              $savedClassifyId = (int)$DB_CONNECT->lastInsertId();
            }
          } else {
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット：編集（分類名のみ）
            $dbFiledData['name'] = array(':name', $editClassifyName, 0);
            $dbFiledData['backend_name'] = array(':backend_name', $saveEditClassifyAdminName, 0);
            $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
            #更新用キー：初期化
            $dbFiledValue = array();
            $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledValue['class_category_id'] = array(':class_category_id', (int)$classifyId, 1);
            #実行モード：[1].トランザクション｜[2].即実行
            $processFlg = 2;
            #DB更新
            $exeFlg = 2;
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
            if ($dbSuccessFlg == 1) {
              $savedClassifyId = (int)$classifyId;
            }
          }
          if ($dbSuccessFlg != 1) {
            #エラーログ出力
            $data = [
              'pageName' => 'proc_client03_05_01',
              'reason' => ($method === 'new') ? '新規分類登録失敗' : '分類更新失敗',
              'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
            ];
            makeLog($data);
            $dbCompleteFlg = false;
          }
          #全ての処理成功
          if ($dbCompleteFlg == true) {
            #DBコミット
            # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
            DB_Transaction(2);
            #応答用タグセット
            $makeTag['status'] = 'success';
            if ($method === 'new') {
              $makeTag['title'] = '新規分類登録';
              $makeTag['msg'] = '登録が完了しました。';
            } else {
              $makeTag['title'] = '分類情報編集';
              $makeTag['msg'] = '更新が完了しました。';
            }
            if ($savedClassifyId !== null && $savedClassifyId > 0) {
              $savedClassifyDetails = getShopItemClassifyDetails($shopId, $savedClassifyId);
              if ($savedClassifyDetails === null) {
                appendEccubeWarningMessage($makeTag);
              } else {
                try {
                  if ($method === 'new') {
                    $parentSpecificationDetails = getShopItemSpecificationDetails($shopId, (int)$savedClassifyDetails['specification_id']);
                    if ($parentSpecificationDetails !== null) {
                      $eccubeClassNameId = isset($parentSpecificationDetails['eccube_class_name_id']) ? (int)$parentSpecificationDetails['eccube_class_name_id'] : 0;
                      if ($eccubeClassNameId > 0) {
                        $classifyNameGraphql = buildEccubeGraphqlString($savedClassifyDetails['name']);
                        $backendNameArg = '';
                        if (trim((string)$savedClassifyDetails['backend_name']) !== '') {
                          $adminNameGraphql = buildEccubeGraphqlString($savedClassifyDetails['backend_name']);
                          $backendNameArg = ", backend_name: {$adminNameGraphql}";
                        }
                        $mutation = <<<GRAPHQL
mutation CreateClassCategoryMutation {
  CreateClassCategoryMutation(class_name_id: {$eccubeClassNameId}, name: {$classifyNameGraphql}{$backendNameArg}) {
    id
  }
}
GRAPHQL;
                        $eccubeResponse = eccube_api_call($mutation);
                        $eccubeClassCategoryId = isset($eccubeResponse['CreateClassCategoryMutation']['id']) ? (int)$eccubeResponse['CreateClassCategoryMutation']['id'] : 0;
                        if ($eccubeClassCategoryId <= 0) {
                          appendEccubeWarningMessage($makeTag);
                        } else {
                          $stmt = $DB_CONNECT->prepare('UPDATE shop_class_categories SET eccube_class_category_id = :eccube_class_category_id, updated_at = :updated_at WHERE shop_id = :shop_id AND class_category_id = :class_category_id');
                          $stmt->bindValue(':eccube_class_category_id', $eccubeClassCategoryId, PDO::PARAM_INT);
                          $stmt->bindValue(':updated_at', date("Y-m-d H:i:s"));
                          $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
                          $stmt->bindValue(':class_category_id', $savedClassifyId, PDO::PARAM_INT);
                          if ($stmt->execute() === false) {
                            appendEccubeWarningMessage($makeTag);
                          }
                        }
                      }
                    } else {
                      appendEccubeWarningMessage($makeTag);
                    }
                  } else {
                    $eccubeClassCategoryId = isset($savedClassifyDetails['eccube_class_category_id']) ? (int)$savedClassifyDetails['eccube_class_category_id'] : 0;
                    if ($eccubeClassCategoryId > 0) {
                      $classifyNameGraphql = buildEccubeGraphqlString($savedClassifyDetails['name']);
                      $backendNameArg = '';
                      if (trim((string)$savedClassifyDetails['backend_name']) !== '') {
                        $adminNameGraphql = buildEccubeGraphqlString($savedClassifyDetails['backend_name']);
                        $backendNameArg = ", backend_name: {$adminNameGraphql}";
                      }
                      $mutation = <<<GRAPHQL
mutation UpdateClassCategoryMutation {
  UpdateClassCategoryMutation(id: {$eccubeClassCategoryId}, name: {$classifyNameGraphql}{$backendNameArg}) {
    id
  }
}
GRAPHQL;
                      eccube_api_call($mutation);
                    }
                  }
                } catch (Exception $e) {
                  appendEccubeWarningMessage($makeTag);
                }
              }
            } else {
              appendEccubeWarningMessage($makeTag);
            }
          } else {
            #失敗時はROLLBACK
            DB_Transaction(3);
            if ($makeTag['status'] === '') {
              $makeTag['status'] = 'error';
              $makeTag['title'] = '登録エラー';
              $makeTag['msg'] = '登録処理に失敗しました。<br>ページを再読み込みしてください。';
            }
          }
        }
      } catch (Exception $e) {
        #ROLLBACK
        DB_Transaction(3);
        #エラーログ出力
        $data = [
          'pageName' => 'proc_client03_05_01',
          'reason' => '新規分類登録例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '登録処理に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** 表示／非表示切替 *****#
  case 'updatePublicStatus': {
      if ($method !== 'togglePublic') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($classifyId === null || $classifyId === '' || is_numeric($classifyId) === false || (int)$classifyId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類IDが不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($isActive === null || ($isActive !== '0' && $isActive !== '1' && $isActive !== 0 && $isActive !== 1)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '表示状態が不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $classifyId = (int)$classifyId;
      $isActive = (int)$isActive;
      $itemClassifyDetails = getShopItemClassifyDetails($shopId, $classifyId);
      if ($itemClassifyDetails === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '指定された分類情報が見つかりませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      try {
        $result = DB_Transaction(1);
        if ($result == false) {
          $data = [
            'pageName' => 'proc_client03_05_01',
            'reason' => 'トランザクション開始失敗（分類表示切替）',
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '更新エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          header('Content-Type: application/json; charset=UTF-8');
          echo json_encode($makeTag);
          exit;
        }
        $dbFiledData = array();
        $dbFiledData['is_active'] = array(':is_active', $isActive, 1);
        $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
        $dbFiledValue = array();
        $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
        $dbFiledValue['class_category_id'] = array(':class_category_id', $classifyId, 1);
        $processFlg = 2;
        $exeFlg = 2;
        $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
        if ($dbSuccessFlg == 1) {
          DB_Transaction(2);
          $makeTag['status'] = 'success';
          $makeTag['title'] = '分類表示設定';
          $makeTag['msg'] = ($isActive === 1) ? '表示に更新しました。' : '非表示に更新しました。';
        } else {
          DB_Transaction(3);
          $data = [
            'pageName' => 'proc_client03_05_01',
            'reason' => '分類表示切替失敗',
            'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '更新エラー';
          $makeTag['msg'] = '表示設定の更新に失敗しました。<br>ページを再読み込みしてください。';
        }
      } catch (Exception $e) {
        DB_Transaction(3);
        $data = [
          'pageName' => 'proc_client03_05_01',
          'reason' => '分類表示切替例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '更新エラー';
        $makeTag['msg'] = '表示設定の更新に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** 削除 *****#
  case 'deleteClassify': {
      #削除以外は未対応
      if ($method !== 'delete') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #分類IDチェック
      if ($classifyId === null || $classifyId === '' || is_numeric($classifyId) === false || (int)$classifyId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類IDが不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $classifyId = (int)$classifyId;
      #分類存在チェック
      $itemClassifyDetails = getShopItemClassifyDetails($shopId, $classifyId);
      if ($itemClassifyDetails === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '指定された分類情報が見つかりませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $specificationId = (int)$itemClassifyDetails['specification_id'];
      $eccubeClassCategoryIdBeforeDelete = isset($itemClassifyDetails['eccube_class_category_id']) ? (int)$itemClassifyDetails['eccube_class_category_id'] : 0;
      #商品紐付きチェック
      $hasChildCategories = hasShopClassCategoriesByClassifyId($shopId, $classifyId);
      if ($hasChildCategories === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類情報の確認に失敗しました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($hasChildCategories === true) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類削除';
        $makeTag['msg'] = '商品に紐付いている分類は削除できません。';
        echo json_encode($makeTag);
        exit;
      }
      #削除開始
      try {
        #トランザクション開始
        # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
        $result = DB_Transaction(1);
        if ($result == false) {
          $data = [
            'pageName' => 'proc_client03_05_01',
            'reason' => 'トランザクション開始失敗（分類削除）',
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '削除エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          header('Content-Type: application/json; charset=UTF-8');
          echo json_encode($makeTag);
          exit;
        }
        $dbCompleteFlg = true;
        #登録用配列：初期化（削除では未使用）
        $dbFiledData = array();
        #更新用キー：初期化
        $dbFiledValue = array();
        $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
        $dbFiledValue['class_category_id'] = array(':class_category_id', $classifyId, 1);
        #処理モード：[1].新規追加｜[2].更新｜[3].削除
        $processFlg = 3;
        #実行モード：[1].トランザクション｜[2].即実行
        $exeFlg = 2;
        $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
        if ($dbSuccessFlg != 1) {
          $data = [
            'pageName' => 'proc_client03_05_01',
            'reason' => '分類削除失敗',
            'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
          ];
          makeLog($data);
          $dbCompleteFlg = false;
        }
        #削除後 sort_order の欠番解消
        if ($dbCompleteFlg == true) {
          $remainCategories = getShopItemClassify($shopId, $specificationId);
          if (!empty($remainCategories)) {
            $sortNo = 1;
            $now = date("Y-m-d H:i:s");
            foreach ($remainCategories as $remainClassify) {
              if (!isset($remainClassify['classify_id']) || is_numeric($remainClassify['classify_id']) === false) {
                continue;
              }
              $remainClassifyId = (int)$remainClassify['classify_id'];
              $currentSortOrder = isset($remainClassify['sort_order']) && is_numeric($remainClassify['sort_order']) ? (int)$remainClassify['sort_order'] : null;
              if ($currentSortOrder === $sortNo) {
                $sortNo++;
                continue;
              }
              #登録用配列：初期化
              $dbFiledData = array();
              $dbFiledData['sort_order'] = array(':sort_order', $sortNo, 1);
              $dbFiledData['updated_at'] = array(':updated_at', $now, 0);
              #更新用キー：初期化
              $dbFiledValue = array();
              $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
              $dbFiledValue['class_category_id'] = array(':class_category_id', $remainClassifyId, 1);
              #処理モード：[1].新規追加｜[2].更新｜[3].削除
              $processFlg = 2;
              #実行モード：[1].トランザクション｜[2].即実行
              $exeFlg = 2;
              $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
              if ($dbSuccessFlg != 1) {
                $data = [
                  'pageName' => 'proc_client03_05_01',
                  'reason' => '分類削除後 sort_order 詰め直し失敗',
                  'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
                ];
                makeLog($data);
                $dbCompleteFlg = false;
                break;
              }
              $sortNo++;
            }
          }
        }
        if ($dbCompleteFlg == true) {
          DB_Transaction(2);
          $makeTag['status'] = 'success';
          $makeTag['title'] = '分類削除';
          $makeTag['msg'] = '削除が完了しました。';
          if ($eccubeClassCategoryIdBeforeDelete > 0) {
            try {
              $mutation = <<<GRAPHQL
mutation DeleteClassCategoryMutation {
  DeleteClassCategoryMutation(id: {$eccubeClassCategoryIdBeforeDelete}) {
    success
    error
  }
}
GRAPHQL;
              eccube_api_call($mutation);
            } catch (Exception $e) {
              appendEccubeWarningMessage($makeTag);
            }
          }
        } else {
          DB_Transaction(3);
          if ($makeTag['status'] === '') {
            $makeTag['status'] = 'error';
            $makeTag['title'] = '削除エラー';
            $makeTag['msg'] = '削除処理に失敗しました。<br>ページを再読み込みしてください。';
          }
        }
      } catch (Exception $e) {
        DB_Transaction(3);
        $data = [
          'pageName' => 'proc_client03_05_01',
          'reason' => '分類削除例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '削除エラー';
        $makeTag['msg'] = '削除処理に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** 並び順更新 *****#
  case 'updateSortOrder': {
      #並び替え以外は未対応
      if ($method !== 'sort') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($orderedIdsJson === null || trim($orderedIdsJson) === '') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '並び順情報が取得できませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $orderedIds = json_decode($orderedIdsJson, true);
      if (!is_array($orderedIds)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '並び順情報が不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $normalizedIds = [];
      foreach ($orderedIds as $v) {
        if (is_numeric($v) === false) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = '入力エラー';
          $makeTag['msg'] = '並び順情報が不正です。<br>ページを再読み込みしてください。';
          echo json_encode($makeTag);
          exit;
        }
        $id = (int)$v;
        if ($id <= 0) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = '入力エラー';
          $makeTag['msg'] = '並び順情報が不正です。<br>ページを再読み込みしてください。';
          echo json_encode($makeTag);
          exit;
        }
        $normalizedIds[] = $id;
      }
      #重複チェック
      if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '並び順情報が不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($specificationId === null || $specificationId === '' || is_numeric($specificationId) === false || (int)$specificationId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '規格情報';
        $makeTag['msg'] = '規格情報が取得できませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $specificationId = (int)$specificationId;
      #分類一覧取得（自店舗）
      $currentCategories = getShopItemClassify($shopId, $specificationId);
      if (empty($currentCategories)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類情報が取得できませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $currentIds = [];
      foreach ($currentCategories as $c) {
        if (!isset($c['classify_id']) || is_numeric($c['classify_id']) === false) continue;
        $currentIds[] = (int)$c['classify_id'];
      }
      #一覧件数と集合一致（安全側）
      sort($currentIds);
      $sortedNormalized = $normalizedIds;
      sort($sortedNormalized);
      if (count($currentIds) !== count($normalizedIds) || $currentIds !== $sortedNormalized) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '分類情報';
        $makeTag['msg'] = '分類一覧が更新されました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      try {
        $result = DB_Transaction(1);
        if ($result == false) {
          $data = [
            'pageName' => 'proc_client03_05_01',
            'reason' => 'トランザクション開始失敗（並び順更新）',
          ];
          makeLog($data);
          $makeTag['status'] = 'error';
          $makeTag['title'] = '更新エラー';
          $makeTag['msg'] = 'トランザクション開始に失敗しました。';
          header('Content-Type: application/json; charset=UTF-8');
          echo json_encode($makeTag);
          exit;
        }
        $dbCompleteFlg = true;
        $now = date("Y-m-d H:i:s");
        $sortNo = 1;
        foreach ($normalizedIds as $id) {
          #登録用配列：初期化
          $dbFiledData = array();
          $dbFiledData['sort_order'] = array(':sort_order', $sortNo, 1);
          $dbFiledData['updated_at'] = array(':updated_at', $now, 0);
          #更新用キー：初期化
          $dbFiledValue = array();
          $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
          $dbFiledValue['class_category_id'] = array(':class_category_id', (int)$id, 1);
          #処理モード：[1].新規追加｜[2].更新｜[3].削除
          $processFlg = 2;
          #実行モード：[1].トランザクション｜[2].即実行
          $exeFlg = 2;
          $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_class_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
          if ($dbSuccessFlg != 1) {
            $data = [
              'pageName' => 'proc_client03_05_01',
              'reason' => '分類並び順更新失敗',
              'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
            ];
            makeLog($data);
            $dbCompleteFlg = false;
            break;
          }
          $sortNo++;
        }
        if ($dbCompleteFlg == true) {
          DB_Transaction(2);
          $makeTag['status'] = 'success';
          $makeTag['title'] = '分類並び順変更';
          $makeTag['msg'] = '更新が完了しました。';
        } else {
          DB_Transaction(3);
          if ($makeTag['status'] === '') {
            $makeTag['status'] = 'error';
            $makeTag['title'] = '更新エラー';
            $makeTag['msg'] = '更新処理に失敗しました。<br>ページを再読み込みしてください。';
          }
        }
      } catch (Exception $e) {
        DB_Transaction(3);
        $data = [
          'pageName' => 'proc_client03_05_01',
          'reason' => '分類並び順更新例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '更新エラー';
        $makeTag['msg'] = '更新処理に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** デフォルト *****#
  default: {
      header('Content-Type: application/json; charset=UTF-8');
      $makeTag['status'] = 'error';
      $makeTag['title'] = '不正なリクエスト';
      $makeTag['msg'] = '不正な操作です。<br>ページを再読み込みしてください。';
    }
    break;
}
#-------------------------------------------#
#json 応答
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($makeTag);
#-------------------------------------------#
#===========================================#
