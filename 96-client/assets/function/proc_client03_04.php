<?php
/*
 * [96-client/assets/function/proc_client03_04.php]
 *  - 【加盟店】管理画面 -
 *  カテゴリ登録／編集 処理
 *
 * [初版]
 *  2026.4.22
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
#カテゴリID（編集時のみ）
$categoryId = isset($_POST['categoryId']) ? $_POST['categoryId'] : null;
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

#============#
# カテゴリ一覧
#------------#
$itemCategoryList = getShopItemCategories($shopId);

#-------------#
#新規カテゴリ名
$newCategoryName = isset($_POST['newCategoryName']) ? trim($_POST['newCategoryName']) : null;
#編集カテゴリ名
$editCategoryName = isset($_POST['editCategoryName']) ? trim($_POST['editCategoryName']) : null;
#ソート順
$sortOrder = isset($_POST['sortOrder']) ? $_POST['sortOrder'] : null;
#-------------#
#inline JS用エスケープ宣言
$jsonHex = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

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
      #=========================#
      # カテゴリ詳細 （編集時のみ）
      #-------------------------#
      if ($categoryId === null || $categoryId === '' || is_numeric($categoryId) === false || (int)$categoryId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = 'カテゴリIDが不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $categoryId = (int)$categoryId;
      $itemCategoryDetails = getShopItemCategoryDetails($shopId, $categoryId);
      if ($itemCategoryDetails === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = '指定されたカテゴリ情報が見つかりませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $itemCategoryID = htmlspecialchars($itemCategoryDetails['category_id'], ENT_QUOTES, 'UTF-8');
      $itemCategoryName = htmlspecialchars($itemCategoryDetails['name'], ENT_QUOTES, 'UTF-8');
      #編集モードで応答
      $makeTag['status'] = 'editForm';
      #タグ生成
      $makeTag['tag'] .= <<<HTML
                <form name="editForm" class="inputForm">
                  <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
                  <input type="hidden" name="method" value="edit">
                  <input type="hidden" name="categoryId" value="{$itemCategoryID}">
                  <input type="text" name="editCategoryName" class="required-item" value="{$itemCategoryName}" required maxlength="255">
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
        if ($newCategoryName === null || $newCategoryName === '') {
          $validationErrors[] = 'カテゴリ名は必須です。';
        } else {
          if (mb_strlen($newCategoryName, 'UTF-8') > 255) {
            $validationErrors[] = 'カテゴリ名は255文字以内で入力してください。';
          }
        }
      } else if ($method === 'edit') {
        if ($categoryId === null || $categoryId === '' || is_numeric($categoryId) === false || (int)$categoryId <= 0) {
          $validationErrors[] = 'カテゴリIDが不正です。';
        }
        if ($editCategoryName === null || $editCategoryName === '') {
          $validationErrors[] = 'カテゴリ名は必須です。';
        } else {
          if (mb_strlen($editCategoryName, 'UTF-8') > 255) {
            $validationErrors[] = 'カテゴリ名は255文字以内で入力してください。';
          }
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
      #カテゴリ存在チェック（編集時）
      if ($method === 'edit') {
        $categoryId = (int)$categoryId;
        $itemCategoryDetails = getShopItemCategoryDetails($shopId, $categoryId);
        if ($itemCategoryDetails === null) {
          header('Content-Type: application/json; charset=UTF-8');
          $makeTag['status'] = 'error';
          $makeTag['title'] = 'カテゴリ情報';
          $makeTag['msg'] = '指定されたカテゴリ情報が見つかりませんでした。<br>ページを再読み込みしてください。';
          echo json_encode($makeTag);
          exit;
        }
      }
      #同名カテゴリ（同一shop/同一parent）重複チェック（rootのみ）
      if ($method === 'new') {
        $isDuplicate = isShopItemCategoryNameExistsRoot($shopId, $newCategoryName);
      } else {
        $isDuplicate = isShopItemCategoryNameExistsRootExcludeCategoryId($shopId, $editCategoryName, (int)$categoryId);
      }
      if ($isDuplicate === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = 'カテゴリ情報の確認に失敗しました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($isDuplicate === true) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '入力エラー';
        $makeTag['msg'] = '同名のカテゴリが既に存在します。<br>カテゴリ名を変更して再度登録してください。';
        echo json_encode($makeTag);
        exit;
      }
      #更新開始
      try {
        $savedCategoryId = null;
        #トランザクション開始
        # 1 = BEGIN／ 2 = COMMIT／ 3 = ROLLBACK
        $result = DB_Transaction(1);
        if ($result == false) {
          #エラーログ出力
          $data = [
            'pageName' => 'proc_client03_04',
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
            $nextSortOrder = getNextShopItemCategorySortOrderRoot($shopId);
            if ($nextSortOrder === null || is_numeric($nextSortOrder) === false || (int)$nextSortOrder <= 0) {
              $nextSortOrder = 1;
            }
            $nextSortOrder = (int)$nextSortOrder;
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット
            $dbFiledData['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledData['eccube_category_id'] = array(':eccube_category_id', null, 2);
            $dbFiledData['parent_id'] = array(':parent_id', null, 2);
            $dbFiledData['name'] = array(':name', $newCategoryName, 0);
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
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
            if ($dbSuccessFlg == 1) {
              $savedCategoryId = (int)$DB_CONNECT->lastInsertId();
            }
          } else {
            #登録用配列：初期化
            $dbFiledData = array();
            #登録情報セット：編集（カテゴリ名のみ）
            $dbFiledData['name'] = array(':name', $editCategoryName, 0);
            $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
            #更新用キー：初期化
            $dbFiledValue = array();
            $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
            $dbFiledValue['category_id'] = array(':category_id', (int)$categoryId, 1);
            #実行モード：[1].トランザクション｜[2].即実行
            $processFlg = 2;
            #DB更新
            $exeFlg = 2;
            $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
            if ($dbSuccessFlg == 1) {
              $savedCategoryId = (int)$categoryId;
            }
          }
          if ($dbSuccessFlg != 1) {
            #エラーログ出力
            $data = [
              'pageName' => 'proc_client03_04',
              'reason' => ($method === 'new') ? '新規カテゴリ登録失敗' : 'カテゴリ更新失敗',
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
              $makeTag['title'] = '新規カテゴリ登録';
              $makeTag['msg'] = '登録が完了しました。';
            } else {
              $makeTag['title'] = 'カテゴリ情報編集';
              $makeTag['msg'] = '更新が完了しました。';
            }
            if ($savedCategoryId !== null && $savedCategoryId > 0) {
              $savedCategoryDetails = getShopItemCategoryDetails($shopId, $savedCategoryId);
              if ($savedCategoryDetails === null) {
                appendEccubeWarningMessage($makeTag);
              } else {
                try {
                  if ($method === 'new') {
                    $categoryNameGraphql = buildEccubeGraphqlString($savedCategoryDetails['name']);
                    $mutation = <<<GRAPHQL
mutation CreateCategoryMutation {
  CreateCategoryMutation(name: {$categoryNameGraphql}) {
    id
  }
}
GRAPHQL;
                    $eccubeResponse = eccube_api_call($mutation);
                    $eccubeCategoryId = isset($eccubeResponse['CreateCategoryMutation']['id']) ? (int)$eccubeResponse['CreateCategoryMutation']['id'] : 0;
                    if ($eccubeCategoryId <= 0) {
                      appendEccubeWarningMessage($makeTag);
                    } else {
                      $stmt = $DB_CONNECT->prepare('UPDATE shop_categories SET eccube_category_id = :eccube_category_id, updated_at = :updated_at WHERE shop_id = :shop_id AND category_id = :category_id');
                      $stmt->bindValue(':eccube_category_id', $eccubeCategoryId, PDO::PARAM_INT);
                      $stmt->bindValue(':updated_at', date("Y-m-d H:i:s"));
                      $stmt->bindValue(':shop_id', $shopId, PDO::PARAM_INT);
                      $stmt->bindValue(':category_id', $savedCategoryId, PDO::PARAM_INT);
                      if ($stmt->execute() === false) {
                        appendEccubeWarningMessage($makeTag);
                      }
                    }
                  } else {
                    $eccubeCategoryId = isset($savedCategoryDetails['eccube_category_id']) ? (int)$savedCategoryDetails['eccube_category_id'] : 0;
                    if ($eccubeCategoryId > 0) {
                      $categoryNameGraphql = buildEccubeGraphqlString($savedCategoryDetails['name']);
                      $mutation = <<<GRAPHQL
mutation UpdateCategoryMutation {
  UpdateCategoryMutation(id: {$eccubeCategoryId}, name: {$categoryNameGraphql}) {
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
          'pageName' => 'proc_client03_04',
          'reason' => '新規カテゴリ登録例外',
          'errorMessage' => $e->getMessage(),
        ];
        makeLog($data);
        $makeTag['status'] = 'error';
        $makeTag['title'] = '登録エラー';
        $makeTag['msg'] = '登録処理に失敗しました。<br>ページを再読み込みしてください。';
      }
    }
    break;
  #***** 削除 *****#
  case 'deleteCategory': {
      #削除以外は未対応
      if ($method !== 'delete') {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = '未対応';
        $makeTag['msg'] = 'この処理は未対応です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      #カテゴリIDチェック
      if ($categoryId === null || $categoryId === '' || is_numeric($categoryId) === false || (int)$categoryId <= 0) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = 'カテゴリIDが不正です。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $categoryId = (int)$categoryId;
      #カテゴリ存在チェック
      $itemCategoryDetails = getShopItemCategoryDetails($shopId, $categoryId);
      if ($itemCategoryDetails === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = '指定されたカテゴリ情報が見つかりませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $eccubeCategoryIdBeforeDelete = isset($itemCategoryDetails['eccube_category_id']) ? (int)$itemCategoryDetails['eccube_category_id'] : 0;
      #商品紐付きチェック
      $hasLinkedProducts = hasShopProductsByCategoryId($shopId, $categoryId);
      if ($hasLinkedProducts === null) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = 'カテゴリ情報の確認に失敗しました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      if ($hasLinkedProducts === true) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ削除';
        $makeTag['msg'] = '商品に紐付いているカテゴリは削除できません。';
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
            'pageName' => 'proc_client03_04',
            'reason' => 'トランザクション開始失敗（カテゴリ削除）',
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
        $dbFiledValue['category_id'] = array(':category_id', $categoryId, 1);
        #処理モード：[1].新規追加｜[2].更新｜[3].削除
        $processFlg = 3;
        #実行モード：[1].トランザクション｜[2].即実行
        $exeFlg = 2;
        $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
        if ($dbSuccessFlg != 1) {
          $data = [
            'pageName' => 'proc_client03_04',
            'reason' => 'カテゴリ削除失敗',
            'dbError' => $GLOBALS['DB_LAST_ERROR'] ?? null,
          ];
          makeLog($data);
          $dbCompleteFlg = false;
        }
        #削除後 sort_order の欠番解消
        if ($dbCompleteFlg == true) {
          $remainCategories = getShopItemCategories($shopId);
          if (!empty($remainCategories)) {
            $sortNo = 1;
            $now = date("Y-m-d H:i:s");
            foreach ($remainCategories as $remainCategory) {
              if (!isset($remainCategory['category_id']) || is_numeric($remainCategory['category_id']) === false) {
                continue;
              }
              $remainCategoryId = (int)$remainCategory['category_id'];
              $currentSortOrder = isset($remainCategory['sort_order']) && is_numeric($remainCategory['sort_order']) ? (int)$remainCategory['sort_order'] : null;
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
              $dbFiledValue['category_id'] = array(':category_id', $remainCategoryId, 1);
              #処理モード：[1].新規追加｜[2].更新｜[3].削除
              $processFlg = 2;
              #実行モード：[1].トランザクション｜[2].即実行
              $exeFlg = 2;
              $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
              if ($dbSuccessFlg != 1) {
                $data = [
                  'pageName' => 'proc_client03_04',
                  'reason' => 'カテゴリ削除後 sort_order 詰め直し失敗',
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
          $makeTag['title'] = 'カテゴリ削除';
          $makeTag['msg'] = '削除が完了しました。';
          if ($eccubeCategoryIdBeforeDelete > 0) {
            try {
              $mutation = <<<GRAPHQL
mutation DeleteCategoryMutation {
  DeleteCategoryMutation(id: {$eccubeCategoryIdBeforeDelete}) {
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
          'pageName' => 'proc_client03_04',
          'reason' => 'カテゴリ削除例外',
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
      #カテゴリ一覧取得（自店舗）
      $currentCategories = getShopItemCategories($shopId);
      if (empty($currentCategories)) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = 'カテゴリ情報が取得できませんでした。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      $currentIds = [];
      foreach ($currentCategories as $c) {
        if (!isset($c['category_id']) || is_numeric($c['category_id']) === false) continue;
        $currentIds[] = (int)$c['category_id'];
      }
      #一覧件数と集合一致（安全側）
      sort($currentIds);
      $sortedNormalized = $normalizedIds;
      sort($sortedNormalized);
      if (count($currentIds) !== count($normalizedIds) || $currentIds !== $sortedNormalized) {
        header('Content-Type: application/json; charset=UTF-8');
        $makeTag['status'] = 'error';
        $makeTag['title'] = 'カテゴリ情報';
        $makeTag['msg'] = 'カテゴリ一覧が更新されました。<br>ページを再読み込みしてください。';
        echo json_encode($makeTag);
        exit;
      }
      try {
        $result = DB_Transaction(1);
        if ($result == false) {
          $data = [
            'pageName' => 'proc_client03_04',
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
          $dbFiledValue['category_id'] = array(':category_id', (int)$id, 1);
          #処理モード：[1].新規追加｜[2].更新｜[3].削除
          $processFlg = 2;
          #実行モード：[1].トランザクション｜[2].即実行
          $exeFlg = 2;
          $dbSuccessFlg = SQL_Process($DB_CONNECT, "shop_categories", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
          if ($dbSuccessFlg != 1) {
            $data = [
              'pageName' => 'proc_client03_04',
              'reason' => 'カテゴリ並び順更新失敗',
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
          $makeTag['title'] = 'カテゴリ並び順変更';
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
          'pageName' => 'proc_client03_04',
          'reason' => 'カテゴリ並び順更新例外',
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
