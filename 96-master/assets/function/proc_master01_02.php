<?php
/*
 * [96-master/assets/function/proc_master01_02.php]
 *  - 管理画面 -
 *  店舗登録／編集 処理
 *
 * [初版]
 *  2026.2.16
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
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';

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
#公開設定
$form01 = isset($_POST['form01']) ? $_POST['form01'] : null;
#店舗種別
$form02 = isset($_POST['form02']) ? $_POST['form02'] : null;
#店舗名
$form03 = isset($_POST['form03']) ? $_POST['form03'] : null;
#店舗名（ふりがな）
$form03_01 = isset($_POST['form03_01']) ? $_POST['form03_01'] : null;
#店舗名(英語)
$form03_02 = isset($_POST['form03_02']) ? $_POST['form03_02'] : null;
#住所
#郵便番号
$form04 = isset($_POST['form04']) ? $_POST['form04'] : null;
#住所1
$form04_01 = isset($_POST['form04_01']) ? $_POST['form04_01'] : null;
#住所2
$form04_02 = isset($_POST['form04_02']) ? $_POST['form04_02'] : null;
#住所3
$form04_03 = isset($_POST['form04_03']) ? $_POST['form04_03'] : null;
#電話番号
$form05 = isset($_POST['form05']) ? $_POST['form05'] : null;
#FAX番号
$form05_01 = isset($_POST['form05_01']) ? $_POST['form05_01'] : null;
#メールアドレス
$form06 = isset($_POST['form06']) ? $_POST['form06'] : null;
#メールアドレス公開設定
$form06_01 = isset($_POST['form06_01']) ? $_POST['form06_01'] : null;
#オフィシャルサイト
$form07 = isset($_POST['form07']) ? $_POST['form07'] : null;
#営業時間
#ランチ営業開始
$form08_01_open_hour = isset($_POST['form08_01_open_hour']) ? $_POST['form08_01_open_hour'] : null;
$form08_01_open_minute = isset($_POST['form08_01_open_minute']) ? $_POST['form08_01_open_minute'] : null;
#ランチ営業終了
$form08_01_close_hour = isset($_POST['form08_01_close_hour']) ? $_POST['form08_01_close_hour'] : null;
$form08_01_close_minute = isset($_POST['form08_01_close_minute']) ? $_POST['form08_01_close_minute'] : null;
#ランチ備考
$form08_01_note = isset($_POST['form08_01_note']) ? $_POST['form08_01_note'] : null;
#ディナー営業開始
$form08_02_open_hour = isset($_POST['form08_02_open_hour']) ? $_POST['form08_02_open_hour'] : null;
$form08_02_open_minute = isset($_POST['form08_02_open_minute']) ? $_POST['form08_02_open_minute'] : null;
#ディナー営業終了
$form08_02_close_hour = isset($_POST['form08_02_close_hour']) ? $_POST['form08_02_close_hour'] : null;
$form08_02_close_minute = isset($_POST['form08_02_close_minute']) ? $_POST['form08_02_close_minute'] : null;
#ディナー備考
$form08_02_note = isset($_POST['form08_02_note']) ? $_POST['form08_02_note'] : null;
#店休日
#表示用
$form09 = isset($_POST['form09']) ? $_POST['form09'] : null;
#システム用
$form10 = isset($_POST['form10']) ? $_POST['form10'] : array();
#-------------#
#ログイン情報
#ID
$form11_01 = isset($_POST['form11_01']) ? $_POST['form11_01'] : null;
#パスワード
$form11_02 = isset($_POST['form11_02']) ? $_POST['form11_02'] : null;
#-------------#

#***** タグ生成開始 *****#
switch ($action) {
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
      #0/1系バリデーション
      if ($form01 !== null && $form01 !== '0' && $form01 !== '1') {
        $validationErrors[] = '公開設定の値が不正です。';
      }
      #if ($form06_01 !== null && $form06_01 !== '0' && $form06_01 !== '1') {
      #  $validationErrors[] = 'メールアドレス公開設定の値が不正です。';
      #}
      #店舗種別バリデーション
      $allowedShopTypes = ['food', 'souvenir', 'other'];
      if (!empty($form02) && in_array((string)$form02, $allowedShopTypes, true) === false) {
        $validationErrors[] = '店舗種別の値が不正です。';
      }
      #必須項目チェック
      $requiredFields = [
        'form01' => '公開設定',
        'form02' => '店舗種別',
        'form03' => '店舗名',
        'form03_01' => '店舗名（ふりがな）',
        'form03_02' => '店舗名（英語）',
        'form04' => '郵便番号',
        #'form05' => '電話番号',
        #'form06_01' => 'メールアドレスの公開設定',
        #'form09' => '店休日（表示用）',
        'form11_01' => 'ログインID',
        'form11_02' => 'パスワード',
      ];
      foreach ($requiredFields as $fieldName => $fieldLabel) {
        $value = isset($_POST[$fieldName]) ? trim($_POST[$fieldName]) : '';
        if ($value === '') {
          $validationErrors[] = $fieldLabel . 'は必須です。';
        }
      }
      #メールアドレス形式チェック
      if (!empty($form06) && !filter_var($form06, FILTER_VALIDATE_EMAIL)) {
        $validationErrors[] = 'メールアドレスの形式が正しくありません。';
      }
      #オフィシャルサイトURL形式チェック（空でない場合のみ）
      if (!empty($form07) && !filter_var($form07, FILTER_VALIDATE_URL)) {
        $validationErrors[] = 'オフィシャルサイトURLの形式が正しくありません。';
      }
      #店休日（システム用）の正規化（0-6の数値配列にする）
      $normalizedClosedWeekdays = [];
      if (is_array($form10)) {
        foreach ($form10 as $weekday) {
          if (is_numeric($weekday) === false) {
            continue;
          }
          $weekdayInt = (int)$weekday;
          if ($weekdayInt < 0 || $weekdayInt > 6) {
            continue;
          }
          $normalizedClosedWeekdays[] = $weekdayInt;
        }
      }
      $normalizedClosedWeekdays = array_values(array_unique($normalizedClosedWeekdays));
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
            'pageName' => 'proc_master01_02',
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
          #郵便番号フォーマット
          $postalCode = formatPostalCode($form04);
          #電話番号フォーマット
          if (!empty($form05)) {
            $phone = str_replace(['-', '−', '―', 'ー', '‐'], '', $form05);
            $phone = formatPhoneNumber($phone, false);
          } else {
            $phone = null;
          }
          #FAX番号フォーマット
          if (!empty($form05_01)) {
            $fax = str_replace(['-', '−', '―', 'ー', '‐'], '', $form05_01);
            $fax = formatPhoneNumber($fax, false);
          } else {
            $fax = null;
          }
          #DB登録情報準備
          $lunchOpenTime = (!empty($form08_01_open_hour) && !empty($form08_01_open_minute)) ? sprintf('%02d:%02d:00', $form08_01_open_hour, $form08_01_open_minute) : null;
          $lunchCloseTime = (!empty($form08_01_close_hour) && !empty($form08_01_close_minute)) ? sprintf('%02d:%02d:00', $form08_01_close_hour, $form08_01_close_minute) : null;
          $dinnerOpenTime = (!empty($form08_02_open_hour) && !empty($form08_02_open_minute)) ? sprintf('%02d:%02d:00', $form08_02_open_hour, $form08_02_open_minute) : null;
          $dinnerCloseTime = (!empty($form08_02_close_hour) && !empty($form08_02_close_minute)) ? sprintf('%02d:%02d:00', $form08_02_close_hour, $form08_02_close_minute) : null;
          #店休日（システム用：曜日番号配列JSON）
          $closedWeekdays = json_encode($normalizedClosedWeekdays, JSON_UNESCAPED_UNICODE);
          switch ($method) {
            #***** 新規登録 *****#
            case 'new': {
                #登録用配列：初期化
                $dbFiledData = array();
                #登録情報セット
                $dbFiledData['is_public'] = array(':is_public', $form01, 1);
                $dbFiledData['shop_type'] = array(':shop_type', $form02, 0);
                $dbFiledData['shop_name'] = array(':shop_name', $form03, 0);
                $dbFiledData['shop_name_kana'] = array(':shop_name_kana', $form03_01, 0);
                $dbFiledData['shop_name_en'] = array(':shop_name_en', $form03_02, 0);
                $dbFiledData['postal_code'] = array(':postal_code', $postalCode['zipCode'], 0);
                $dbFiledData['address1'] = array(':address1', $form04_01, 0);
                $dbFiledData['address2'] = array(':address2', $form04_02, 0);
                $dbFiledData['address3'] = array(':address3', $form04_03, 0);
                $dbFiledData['tel'] = array(':tel', $phone, 0);
                $dbFiledData['fax'] = array(':fax', $fax, 0);
                $dbFiledData['email'] = array(':email', $form06, 0);
                $dbFiledData['is_email_public'] = array(':is_email_public', $form06_01, 0);
                $dbFiledData['website_url'] = array(':website_url', $form07, 0);
                $dbFiledData['lunch_open_time'] = array(':lunch_open_time', $lunchOpenTime, 1);
                $dbFiledData['lunch_close_time'] = array(':lunch_close_time', $lunchCloseTime, 1);
                $dbFiledData['lunch_note'] = array(':lunch_note', $form08_01_note, 1);
                $dbFiledData['dinner_open_time'] = array(':dinner_open_time', $dinnerOpenTime, 1);
                $dbFiledData['dinner_close_time'] = array(':dinner_close_time', $dinnerCloseTime, 1);
                $dbFiledData['dinner_note'] = array(':dinner_note', $form08_02_note, 1);
                $dbFiledData['regular_holiday_display'] = array(':regular_holiday_display', $form09, 0);
                $dbFiledData['closed_weekdays'] = array(':closed_weekdays', $closedWeekdays, 0);
                $dbFiledData['created_at'] = array(':created_at', date("Y-m-d H:i:s"), 0);
                #更新用キー：初期化
                $dbFiledValue = array();
                #処理モード：[1].新規追加｜[2].更新｜[3].削除
                $processFlg = 1;
                #実行モード：[1].トランザクション｜[2].即実行
                $exeFlg = 2;
                #DB更新
                $dbSuccessFlg = SQL_Process($DB_CONNECT, "shops", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
                #基本情報の更新が成功したらログイン情報の登録処理に進む
                if ($dbSuccessFlg == 1) {
                  #追加した店舗IDを取得（0/不正は致命扱い）
                  $newShopIdRaw = $DB_CONNECT->lastInsertId();
                  if (!is_numeric($newShopIdRaw) || (int)$newShopIdRaw <= 0) {
                    $data = [
                      'pageName' => 'proc_master01_02',
                      'reason' => '店舗ID採番失敗（lastInsertId不正）: ' . (string)$newShopIdRaw,
                    ];
                    makeLog($data);
                    $dbCompleteFlg = false;
                    break;
                  }
                  $newShopId = (int)$newShopIdRaw;
                  #パスワードはハッシュ化して保存
                  $hashedPassword = password_hash($form11_02, PASSWORD_BCRYPT);
                  #登録用配列：初期化
                  $dbAccountFiledData = array();
                  #登録情報セット
                  $dbAccountFiledData['account_type'] = array(':account_type', 'shop', 0);
                  $dbAccountFiledData['login_id'] = array(':login_id', $form11_01, 0);
                  #既存仕様：平文passwordも保持
                  $dbAccountFiledData['password'] = array(':password', $form11_02, 0);
                  $dbAccountFiledData['password_hash'] = array(':password_hash', $hashedPassword, 0);
                  $dbAccountFiledData['password_changed_at'] = array(':password_changed_at', date("Y-m-d H:i:s"), 0);
                  $dbAccountFiledData['shop_id'] = array(':shop_id', $newShopId, 1);
                  $dbAccountFiledData['created_at'] = array(':created_at', date("Y-m-d H:i:s"), 0);
                  #更新用キー：初期化
                  $dbAccountFiledValue = array();
                  #処理モード：[1].新規追加｜[2].更新｜[3].削除
                  $accountProcessFlg = 1;
                  #実行モード：[1].トランザクション｜[2].即実行
                  $accountExeFlg = 2;
                  #DB更新
                  $dbAccountSuccessFlg = SQL_Process($DB_CONNECT, "accounts", $dbAccountFiledData, $dbAccountFiledValue, $accountProcessFlg, $accountExeFlg);
                  if ($dbAccountSuccessFlg != 1) {
                    #エラーログ出力
                    $data = [
                      'pageName' => 'proc_master01_02',
                      'reason' => '新規店舗ログイン情報登録失敗',
                    ];
                    makeLog($data);
                    $dbCompleteFlg = false;
                    break;
                  }
                } else {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_02',
                    'reason' => '新規店舗登録失敗',
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
                    'pageName' => 'proc_master01_02',
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
                $dbFiledData['is_public'] = array(':is_public', $form01, 1);
                $dbFiledData['shop_type'] = array(':shop_type', $form02, 0);
                $dbFiledData['shop_name'] = array(':shop_name', $form03, 0);
                $dbFiledData['shop_name_kana'] = array(':shop_name_kana', $form03_01, 0);
                $dbFiledData['shop_name_en'] = array(':shop_name_en', $form03_02, 0);
                $dbFiledData['postal_code'] = array(':postal_code', $postalCode['zipCode'], 0);
                $dbFiledData['address1'] = array(':address1', $form04_01, 0);
                $dbFiledData['address2'] = array(':address2', $form04_02, 0);
                $dbFiledData['address3'] = array(':address3', $form04_03, 0);
                $dbFiledData['tel'] = array(':tel', $phone, 0);
                $dbFiledData['fax'] = array(':fax', $fax, 0);
                $dbFiledData['email'] = array(':email', $form06, 0);
                $dbFiledData['is_email_public'] = array(':is_email_public', $form06_01, 0);
                $dbFiledData['website_url'] = array(':website_url', $form07, 0);
                $dbFiledData['lunch_open_time'] = array(':lunch_open_time', $lunchOpenTime, 1);
                $dbFiledData['lunch_close_time'] = array(':lunch_close_time', $lunchCloseTime, 1);
                $dbFiledData['lunch_note'] = array(':lunch_note', $form08_01_note, 1);
                $dbFiledData['dinner_open_time'] = array(':dinner_open_time', $dinnerOpenTime, 1);
                $dbFiledData['dinner_close_time'] = array(':dinner_close_time', $dinnerCloseTime, 1);
                $dbFiledData['dinner_note'] = array(':dinner_note', $form08_02_note, 1);
                $dbFiledData['regular_holiday_display'] = array(':regular_holiday_display', $form09, 0);
                $dbFiledData['closed_weekdays'] = array(':closed_weekdays', $closedWeekdays, 0);
                $dbFiledData['updated_at'] = array(':updated_at', date("Y-m-d H:i:s"), 0);
                #更新用キー：初期化
                $dbFiledValue = array();
                $dbFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
                #処理モード：[1].新規追加｜[2].更新｜[3].削除
                $processFlg = 2;
                #実行モード：[1].トランザクション｜[2].即実行
                $exeFlg = 2;
                #DB更新
                $dbSuccessFlg = SQL_Process($DB_CONNECT, "shops", $dbFiledData, $dbFiledValue, $processFlg, $exeFlg);
                #基本情報の更新が成功したらログイン情報の登録処理に進む
                if ($dbSuccessFlg == 1) {
                  #パスワードが変更されている場合のみ更新処理を行う
                  #既存のアカウント情報を取得（login_id 変更判定用）
                  $accountData = accounts_FindById(null, $shopId);
                  if (!is_array($accountData)) {
                    $data = [
                      'pageName' => 'proc_master01_02',
                      'reason' => '店舗アカウント情報取得失敗（編集）',
                    ];
                    makeLog($data);
                    $dbCompleteFlg = false;
                    break;
                  }
                  $dbAccountFiledData = array();
                  #ログインIDが変更されている場合のみ更新
                  if (isset($accountData['login_id']) && (string)$accountData['login_id'] !== (string)$form11_01) {
                    $dbAccountFiledData['login_id'] = array(':login_id', $form11_01, 0);
                  }
                  #パスワードが変更されている場合のみ更新（既存仕様：平文も保持）
                  if (isset($accountData['password']) && (string)$accountData['password'] !== (string)$form11_02) {
                    $hashedPassword = password_hash($form11_02, PASSWORD_BCRYPT);
                    $dbAccountFiledData['password'] = array(':password', $form11_02, 0);
                    $dbAccountFiledData['password_hash'] = array(':password_hash', $hashedPassword, 0);
                    $dbAccountFiledData['password_changed_at'] = array(':password_changed_at', date("Y-m-d H:i:s"), 0);
                  }
                  if (count($dbAccountFiledData) > 0) {
                    #更新用キー：初期化
                    $dbAccountFiledValue = array();
                    $dbAccountFiledValue['shop_id'] = array(':shop_id', $shopId, 1);
                    #処理モード：[1].新規追加｜[2].更新｜[3].削除
                    $accountProcessFlg = 2;
                    #実行モード：[1].トランザクション｜[2].即実行
                    $accountExeFlg = 2;
                    #DB更新
                    $dbAccountSuccessFlg = SQL_Process($DB_CONNECT, "accounts", $dbAccountFiledData, $dbAccountFiledValue, $accountProcessFlg, $accountExeFlg);
                    if ($dbAccountSuccessFlg != 1) {
                      #エラーログ出力
                      $data = [
                        'pageName' => 'proc_master01_02',
                        'reason' => '店舗ログイン情報更新失敗',
                      ];
                      makeLog($data);
                      $dbCompleteFlg = false;
                      break;
                    }
                  }
                } else {
                  #エラーログ出力
                  $data = [
                    'pageName' => 'proc_master01_02',
                    'reason' => '店舗更新情報登録失敗',
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
