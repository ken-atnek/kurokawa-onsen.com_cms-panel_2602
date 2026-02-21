<?php
/*
 * [96-master/master01_02.php]
 *  - 管理画面 -
 *  店舗登録／編集
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
#アカウント情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_accounts.php';
#店舗情報
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/db_shops.php';

#================#
# SESSIONチェック
#----------------#
#セッションキー
$pagePrefix = 'mKey01-02_';
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
#新規／編集
$method = isset($_GET['method']) ? $_GET['method'] : null;
#モードチェック
if ($method === null || ($method !== 'new' && $method !== 'edit')) {
  #不正アクセス：トップページへリダイレクト
  header("Location: ./master01_01.php");
  exit;
}
#-------------#
#店舗ID（編集／削除時のみ）
$shopId = isset($_GET['shopId']) ? $_GET['shopId'] : null;
#店舗IDがあれば店舗情報取得
if ($shopId !== null) {
  #店舗情報
  $shopData = getShops_FindById($shopId);
  #アカウント情報
  $accountData = accounts_FindById(null, $shopId);
} else {
  #店舗情報
  $shopData = array(
    'shop_type' => 'food',
    'shop_name' => '',
    'shop_name_kana' => '',
    'shop_name_en' => '',
    'postal_code' => '869-2402',
    'address1' => '阿蘇郡南小国町大字満願寺黒川6603',
    'address2' => '',
    'address3' => '',
    'tel' => '',
    'fax' => '',
    'email' => '',
    'is_email_public' => 0,
    'website_url' => '',
    'lunch_open_time' => '',
    'lunch_close_time' => '',
    'lunch_note' => '',
    'dinner_open_time' => '',
    'dinner_close_time' => '',
    'dinner_note' => '',
    'regular_holiday_display' => '',
    'closed_weekdays' => array(),
    'sort_order' => '',
    'is_public' => 1
  );
  #アカウント情報
  $accountData = array(
    'login_id' => '',
    'password' => '',
    'password_hash' => ''
  );
}

#================#
# メニュータイトル
#----------------#
#メニュータイトル
$menuTitle = "店舗情報入力";
if ($method === 'new') {
  $menuTitle = "新規店舗情報入力";
} elseif ($method === 'edit') {
  if (!isset($shopData) || empty($shopData)) {
    #店舗データが無い場合は不正アクセス：トップページへリダイレクト
    header("Location: ./master01_01.php");
    exit;
  } else {
    $menuTitle = "店舗情報編集 - " . htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
  }
}

#***** タグ生成開始 *****#
print <<<HTML
<html lang="ja">
  <head>
    <meta charset="UTF-8">
    <title>黒川温泉観光協会｜コントロールパネル(管理)</title>
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';">
    <meta name="viewport" content="width=device-width,initial-scale=1,shrink-to-fit=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon/favicon.svg">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/images/favicon/apple-touch-icon.png">
    <link rel="shortcut icon" href="../assets/images/favicon/favicon.ico">
    <link rel="stylesheet" href="../assets/css/master01-02.css">
  </head>

  <body>

HTML;
@include './inc_header.php';
print <<<HTML
    <main class="inner-01-02">
      <section class="container-left-menu menu-color01">
        <div class="title">店舗管理</div>
        <nav>
          <a href="./master01_01.php" {$master01_01_active}><span>店舗一覧</span></a>
          <a href="./master01_02.php?method=new" {$master01_02_active}><span>店舗登録</span></a>
        </nav>
      </section>
      <div class="main-contents menu-color01">
        <div class="block_inner">
          <h2>{$menuTitle}</h2>
          <form name="inputForm" class="inputForm">
            <input type="hidden" name="noUpDateKey" value="{$noUpDateKey}">
            <input type="hidden" name="method" value="{$method}">
            <input type="hidden" name="action" value="checkInput">
            <input type="hidden" name="shopId" value="{$shopId}">
            <article>
              <h3>基本情報を入力ください</h3>
              <dl class="block_01">
                <div class="box_setting">
                  <dt class="required">公開設定</dt>
                  <dd>

HTML;
#checked判定
$checkedPrivate = ($shopData['is_public'] == 0) ? 'checked' : '';
$checkedPublic = ($shopData['is_public'] == 1) ? 'checked' : '';
print <<<HTML
                    <div>
                      <input type="radio" name="form01" value="0" id="form01_01" {$checkedPrivate} class="required-item" required>
                      <label for="form01_01">非公開</label>
                    </div>
                    <div>
                      <input type="radio" name="form01" value="1" id="form01_02" {$checkedPublic} class="required-item" required>
                      <label for="form01_02">公開</label>
                    </div>
                  </dd>
                </div>
                <div class="box_setting">
                  <dt class="required">店舗種別</dt>
                  <dd>

HTML;
#店舗種別
if (isset($shopCategoryList) && is_array($shopCategoryList)) {
  foreach ($shopCategoryList as $categoryKey => $categoryName) {
    $checked = ($shopData['shop_type'] === $categoryKey) ? 'checked' : '';
    print <<<HTML
                    <div>
                      <input type="radio" name="form02" value="{$categoryKey}" id="form02_{$categoryKey}" {$checked} class="required-item" required>
                      <label for="form02_{$categoryKey}">{$categoryName}</label>
                    </div>

HTML;
  }
}
#XSS対策：エスケープ処理
$escShopName = htmlspecialchars($shopData['shop_name'], ENT_QUOTES, 'UTF-8');
$escShopNameKana = htmlspecialchars($shopData['shop_name_kana'], ENT_QUOTES, 'UTF-8');
$escShopNameEn = htmlspecialchars($shopData['shop_name_en'], ENT_QUOTES, 'UTF-8');
$escPostalCode = htmlspecialchars($shopData['postal_code'], ENT_QUOTES, 'UTF-8');
$escAddress1 = htmlspecialchars($shopData['address1'], ENT_QUOTES, 'UTF-8');
$escAddress2 = htmlspecialchars($shopData['address2'], ENT_QUOTES, 'UTF-8');
$escAddress3 = htmlspecialchars($shopData['address3'], ENT_QUOTES, 'UTF-8');
$escTel = htmlspecialchars($shopData['tel'], ENT_QUOTES, 'UTF-8');
$escFax = htmlspecialchars($shopData['fax'], ENT_QUOTES, 'UTF-8');
$escEmail = htmlspecialchars($shopData['email'], ENT_QUOTES, 'UTF-8');
$escWebsiteUrl = htmlspecialchars($shopData['website_url'], ENT_QUOTES, 'UTF-8');
$escLunchNote = htmlspecialchars($shopData['lunch_note'], ENT_QUOTES, 'UTF-8');
$escDinnerNote = htmlspecialchars($shopData['dinner_note'], ENT_QUOTES, 'UTF-8');
$escRegularHolidayDisplay = htmlspecialchars($shopData['regular_holiday_display'], ENT_QUOTES, 'UTF-8');
$escLoginId = htmlspecialchars($accountData['login_id'], ENT_QUOTES, 'UTF-8');
$escPassword = htmlspecialchars($accountData['password'], ENT_QUOTES, 'UTF-8');
print <<<HTML
                  </dd>
                </div>
                <div style="margin-top: 1rem" class="box_name">
                  <dt class="required">店舗名</dt>
                  <dd>
                    <textarea name="form03" class="required-item" required>{$escShopName}</textarea>
                  </dd>
                </div>
                <div>
                  <dt class="required">店舗名<span>(ふりがな)</span></dt>
                  <dd>
                    <input type="text" name="form03_01" value="{$escShopNameKana}" class="required-item" required="">
                  </dd>
                </div>
                <div>
                  <dt class="required">店舗名(英語)<span>(半角英数)</span></dt>
                  <dd>
                    <input type="text" name="form03_02" value="{$escShopNameEn}" class="required-item" required>
                  </dd>
                </div>
                <div class="box_add">
                  <dt class="required">住所</dt>
                  <dd>
                    <div>郵便番号</div>
                    <input type="text" name="form04" class="add_01 required-item" value="{$escPostalCode}" required>
                    <div>熊本県</div>
                    <input type="text" name="form04_01" class="add_02" value="{$escAddress1}">
                    <input type="text" name="form04_02" class="add_03" value="{$escAddress2}">
                    <input type="text" name="form04_03" class="add_04" value="{$escAddress3}">
                  </dd>
                </div>
                <div class="box_tel">
                  <dt>電話番号</dt>
                  <dd>
                    <input type="text" name="form05" value="{$escTel}">
                  </dd>
                </div>
                <div class="box_fax">
                  <dt>FAX番号</dt>
                  <dd>
                    <input type="text" name="form05_01" value="{$escFax}">
                  </dd>
                </div>
                <div>
                  <dt>メールアドレス</dt>
                  <dd>
                    <input type="text" name="form06" value="{$escEmail}">
                  </dd>
                </div>
                <div class="box_setting">
                  <dt>メールアドレス<br>公開設定</dt>
                  <dd>

HTML;
#checked判定
$checkedEmailPrivate = ($shopData['is_email_public'] == 0) ? 'checked' : '';
$checkedEmailPublic = ($shopData['is_email_public'] == 1) ? 'checked' : '';
print <<<HTML
                    <div>
                      <input type="radio" name="form06_01" value="0" id="form06_private" {$checkedEmailPrivate}>
                      <label for="form06_private">非公開</label>
                    </div>
                    <div>
                      <input type="radio" name="form06_01" value="1" id="form06_public" {$checkedEmailPublic}>
                      <label for="form06_public">公開</label>
                    </div>
                  </dd>
                </div>
                <div>
                  <dt>オフィシャルサイト</dt>
                  <dd>
                    <input type="text" name="form07" value="{$escWebsiteUrl}">
                    <div></div>
                  </dd>
                </div>
                <div class="box_hour">
                  <dt class="required">営業時間</dt>
                  <dd>
                    <div class="wrap-hour">
                      <div class="inner-hour">
                        <h4>OPEN</h4>

HTML;
# ---- 営業時間（ランチ OPEN）初期値 ---- #
#編集モードで営業時間が選択されていたら
$open08_01TimeParts = array();
$form08_01_openHourValue = '';
$form08_01_openHourLabel = '--';
$form08_01_openMinuteValue = '';
$form08_01_openMinuteLabel = '--';
if ($method === 'edit' && isset($shopData['lunch_open_time']) && $shopData['lunch_open_time'] != '') {
  #営業時間を「時」と「分」に分割
  $open08_01TimeParts = explode(':', $shopData['lunch_open_time']);
  if (count($open08_01TimeParts) !== 0) {
    $form08_01_openHourValue = $open08_01TimeParts[0];
    $form08_01_openMinuteValue = $open08_01TimeParts[1];
    #表示用ラベルを取得
    if (isset($shopOpenHourList[$form08_01_openHourValue])) {
      $form08_01_openHourLabel = $form08_01_openHourValue ? $shopOpenHourList[$form08_01_openHourValue] : '--';
    }
    if (isset($shopMinuteList[$form08_01_openMinuteValue])) {
      $form08_01_openMinuteLabel = $form08_01_openMinuteValue ? $shopMinuteList[$form08_01_openMinuteValue] : '--';
    }
  }
}
print <<<HTML
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_01_open_hour" value="{$form08_01_openHourValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_01_openHourLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopOpenHourList) && is_array($shopOpenHourList) && count($shopOpenHourList) > 0) {
  foreach ($shopOpenHourList as $hourValue => $hourLabel) {
    $checked = (isset($open08_01TimeParts[0]) && (int)$open08_01TimeParts[0] === (int)$hourValue) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_01_open_hour" value="{$hourValue}" id="open01_hour{$hourValue}" {$checked}>
                                <label for="open01_hour{$hourValue}">{$hourLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>時</p>
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_01_open_minute" value="{$form08_01_openMinuteValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_01_openMinuteLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopMinuteList) && is_array($shopMinuteList) && count($shopMinuteList) > 0) {
  foreach ($shopMinuteList as $minuteValue => $minuteLabel) {
    $checked = (isset($open08_01TimeParts[1]) && (int)$open08_01TimeParts[1] === (int)$minuteLabel) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_01_open_minute" value="{$minuteLabel}" id="open01_minute{$minuteLabel}" {$checked}>
                                <label for="open01_minute{$minuteLabel}">{$minuteLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>分</p>
                      </div>
                      <div class="inner-hour">
                        <h4>CLOSE</h4>

HTML;
# ---- 営業時間（ランチ CLOSE）初期値 ---- #
#編集モードで営業時間が選択されていたら
$close08_01TimeParts = array();
$form08_01_closeHourValue = '';
$form08_01_closeHourLabel = '--';
$form08_01_closeMinuteValue = '';
$form08_01_closeMinuteLabel = '--';
if ($method === 'edit' && isset($shopData['lunch_close_time']) && $shopData['lunch_close_time'] != '') {
  #営業時間を「時」と「分」に分割
  $close08_01TimeParts = explode(':', $shopData['lunch_close_time']);
  if (count($close08_01TimeParts) !== 0) {
    $form08_01_closeHourValue = $close08_01TimeParts[0];
    $form08_01_closeMinuteValue = $close08_01TimeParts[1];
    #表示用ラベルを取得
    if (isset($shopCloseHourList[$form08_01_closeHourValue])) {
      $form08_01_closeHourLabel = $form08_01_closeHourValue ? $shopCloseHourList[$form08_01_closeHourValue] : '--';
    }
    if (isset($shopMinuteList[$form08_01_closeMinuteValue])) {
      $form08_01_closeMinuteLabel = $form08_01_closeMinuteValue ? $shopMinuteList[$form08_01_closeMinuteValue] : '--';
    }
  }
}
print <<<HTML
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_01_close_hour" value="{$form08_01_closeHourValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_01_closeHourLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopCloseHourList) && is_array($shopCloseHourList) && count($shopCloseHourList) > 0) {
  foreach ($shopCloseHourList as $hourValue => $hourLabel) {
    $checked = (isset($close08_01TimeParts[0]) && (int)$close08_01TimeParts[0] === (int)$hourValue) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_01_close_hour" value="{$hourValue}" id="close01_hour{$hourValue}" {$checked}>
                                <label for="close01_hour{$hourValue}">{$hourLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>時</p>
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_01_close_minute" value="{$form08_01_closeMinuteValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_01_closeMinuteLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopMinuteList) && is_array($shopMinuteList) && count($shopMinuteList) > 0) {
  foreach ($shopMinuteList as $minuteValue => $minuteLabel) {
    $checked = (isset($close08_01TimeParts[1]) && (int)$close08_01TimeParts[1] === (int)$minuteLabel) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_01_close_minute" value="{$minuteLabel}" id="close01_minute{$minuteLabel}" {$checked}>
                                <label for="close01_minute{$minuteLabel}">{$minuteLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>分</p>
                      </div>
                      <input type="text" name="form08_01_note" value="{$escLunchNote}" placeholder="備考（ラストオーダー等）">
                    </div>
                    <div class="wrap-hour">
                      <div class="inner-hour">
                        <h4>OPEN</h4>

HTML;
# ---- 営業時間（ディナー OPEN）初期値 ---- #
#編集モードで営業時間が選択されていたら
$open08_02TimeParts = array();
$form08_02_openHourValue = '';
$form08_02_openHourLabel = '--';
$form08_02_openMinuteValue = '';
$form08_02_openMinuteLabel = '--';
if ($method === 'edit' && isset($shopData['dinner_open_time']) && $shopData['dinner_open_time'] != '') {
  #営業時間を「時」と「分」に分割
  $open08_02TimeParts = explode(':', $shopData['dinner_open_time']);
  if (count($open08_02TimeParts) !== 0) {
    $form08_02_openHourValue = $open08_02TimeParts[0];
    $form08_02_openMinuteValue = $open08_02TimeParts[1];
    #表示用ラベルを取得
    if (isset($shopOpenHourList[$form08_02_openHourValue])) {
      $form08_02_openHourLabel = $form08_02_openHourValue ? $shopOpenHourList[$form08_02_openHourValue] : '--';
    }
    if (isset($shopMinuteList[$form08_02_openMinuteValue])) {
      $form08_02_openMinuteLabel = $form08_02_openMinuteValue ? $shopMinuteList[$form08_02_openMinuteValue] : '--';
    }
  }
}
print <<<HTML
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_02_open_hour" value="{$form08_02_openHourValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_02_openHourLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopOpenHourList) && is_array($shopOpenHourList) && count($shopOpenHourList) > 0) {
  foreach ($shopOpenHourList as $hourValue => $hourLabel) {
    $checked = (isset($open08_02TimeParts[0]) && (int)$open08_02TimeParts[0] === (int)$hourValue) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_02_open_hour" value="{$hourValue}" id="open02_hour{$hourValue}" {$checked}>
                                <label for="open02_hour{$hourValue}">{$hourLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>時</p>
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_02_open_minute" value="{$form08_02_openMinuteValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_02_openMinuteLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopMinuteList) && is_array($shopMinuteList) && count($shopMinuteList) > 0) {
  foreach ($shopMinuteList as $minuteValue => $minuteLabel) {
    $checked = (isset($open08_02TimeParts[1]) && (int)$open08_02TimeParts[1] === (int)$minuteLabel) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_02_open_minute" value="{$minuteLabel}" id="open02_minute{$minuteLabel}" {$checked}>
                                <label for="open02_minute{$minuteLabel}">{$minuteLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>分</p>
                      </div>
                      <div class="inner-hour">
                        <h4>CLOSE</h4>

HTML;
# ---- 営業時間（ディナー CLOSE）初期値 ---- #
#編集モードで営業時間が選択されていたら
$close08_02TimeParts = array();
$form08_02_closeHourValue = '';
$form08_02_closeHourLabel = '--';
$form08_02_closeMinuteValue = '';
$form08_02_closeMinuteLabel = '--';
if ($method === 'edit' && isset($shopData['dinner_close_time']) && $shopData['dinner_close_time'] != '') {
  #営業時間を「時」と「分」に分割
  $close08_02TimeParts = explode(':', $shopData['dinner_close_time']);
  if (count($close08_02TimeParts) !== 0) {
    $form08_02_closeHourValue = $close08_02TimeParts[0];
    $form08_02_closeMinuteValue = $close08_02TimeParts[1];
    #表示用ラベルを取得
    if (isset($shopCloseHourList[$form08_02_closeHourValue])) {
      $form08_02_closeHourLabel = $form08_02_closeHourValue ? $shopCloseHourList[$form08_02_closeHourValue] : '--';
    }
    if (isset($shopMinuteList[$form08_02_closeMinuteValue])) {
      $form08_02_closeMinuteLabel = $form08_02_closeMinuteValue ? $shopMinuteList[$form08_02_closeMinuteValue] : '--';
    }
  }
}
print <<<HTML
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_02_close_hour" value="{$form08_02_closeHourValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_02_closeHourLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopCloseHourList) && is_array($shopCloseHourList) && count($shopCloseHourList) > 0) {
  foreach ($shopCloseHourList as $hourValue => $hourLabel) {
    $checked = (isset($close08_02TimeParts[0]) && (int)$close08_02TimeParts[0] === (int)$hourValue) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_02_close_hour" value="{$hourValue}" id="close02_hour{$hourValue}" {$checked}>
                                <label for="close02_hour{$hourValue}">{$hourLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>時</p>
                        <div class="select-hour" data-selectbox>
                          <button type="button" class="selectbox__head" aria-expanded="false">
                            <input type="hidden" name="form08_02_close_minute" value="{$form08_02_closeMinuteValue}" data-selectbox-hidden>
                            <span class="selectbox__value" data-selectbox-value>{$form08_02_closeMinuteLabel}</span>
                          </button>
                          <div class="list-wrapper">
                            <ul class="selectbox__panel">

HTML;
#表示可能リストあればループ処理
if (isset($shopMinuteList) && is_array($shopMinuteList) && count($shopMinuteList) > 0) {
  foreach ($shopMinuteList as $minuteValue => $minuteLabel) {
    $checked = (isset($close08_02TimeParts[1]) && (int)$close08_02TimeParts[1] === (int)$minuteLabel) ? 'checked' : '';
    print <<<HTML
                              <li>
                                <input type="radio" name="form08_02_close_minute" value="{$minuteLabel}" id="close02_minute{$minuteLabel}" {$checked}>
                                <label for="close02_minute{$minuteLabel}">{$minuteLabel}</label>
                              </li>

HTML;
  }
}
print <<<HTML
                            </ul>
                          </div>
                        </div>
                        <p>分</p>
                      </div>
                      <input type="text" name="form08_02_note" value="{$escDinnerNote}" placeholder="備考（ラストオーダー等）">
                    </div>
                  </dd>
                </div>
                <div class="box_textarea">
                  <dt>店休日<span>(表示用)</span></dt>
                  <dd><textarea name="form09">{$escRegularHolidayDisplay}</textarea></dd>
                </div>
                <div class="box_close-week-day">
                  <dt>店休日<span>(システム用)</span></dt>
                  <dd>

HTML;
#表示可能リストあればループ処理
if (isset($shopCloseWeekList) && is_array($shopCloseWeekList) && count($shopCloseWeekList) > 0) {
  #closed_weekdays は「DB取得時: JSON文字列」「新規初期値: 配列」の両方があり得る
  $closedWeekdays = array();
  if (isset($shopData['closed_weekdays'])) {
    if (is_string($shopData['closed_weekdays'])) {
      $decoded = json_decode($shopData['closed_weekdays'], true);
      $closedWeekdays = is_array($decoded) ? $decoded : array();
    } elseif (is_array($shopData['closed_weekdays'])) {
      $closedWeekdays = $shopData['closed_weekdays'];
    }
  }
  foreach ($shopCloseWeekList as $weekKey => $weekLabel) {
    $checked = (isset($closedWeekdays) && in_array($weekKey, $closedWeekdays)) ? 'checked' : '';
    print <<<HTML
                    <div>
                      <input type="checkbox" name="form10[]" id="form10_{$weekKey}" value="{$weekKey}" {$checked}>
                      <label for="form10_{$weekKey}">{$weekLabel}</label>
                    </div>

HTML;
  }
}
print <<<HTML
                  </dd>
                </div>
              </dl>
            </article>
            <article>
              <h3>ログイン情報</h3>
              <dl class="block_02">
                <div class="box_login">
                  <dt class="required">ID</dt>
                  <dd><input type="text" name="form11_01" value="{$escLoginId}" class="required-item" required></dd>
                </div>
                <div class="box_login">
                  <dt class="required">パスワード</dt>
                  <dd>
                    <input type="password" name="form11_02" value="{$escPassword}" class="required-item" required id="passwordInput">

HTML;
#編集モードの時のみ表示
if ($method === 'new') {
  print <<<HTML
                    <a href="javascript:void(0);" onclick="togglePassword(this, 'passwordInput');">パスワードを確認する</a>

HTML;
} else {
  print <<<HTML
                    <a href="javascript:void(0);" onclick="togglePassword(this, 'passwordInput');">現在のパスワードを確認する</a>

HTML;
}
print <<<HTML
                  </dd>
                </div>
              </dl>
            </article>
            <div class="box-btn">
              <button type="button" class="btn-submit" onclick="sendInput();">登録</button>
            </div>
          </form>
          <a href="#" class="move_page-top"><i>↑</i>TOPへ</a>
        </div>
      </div>
    </main>
    <!-- NOTE 修正画面用 is-active付与でモーダル表示 -->
    <article class="modal-alert" id="modalBlock">
      <div class="inner-modal">
        <div class="box-title">
          <p>店舗基本情報</p>
          <button
            type="button"
            onclick="closeModal()"
            class="btn-top-close"
          ></button>
        </div>
        <div class="box-details">
          <p></p>
          <div class="box-btn">
            <button type="button" class="btn-cancel">キャンセル</button>
            <button type="button" class="btn-confirm">はい</button>
          </div>
        </div>
      </div>
    </article>
    <script src="../assets/js/common.js" defer></script>
    <script src="../assets/js/modal.js" defer></script>
    <script src="../assets/js/form.js" defer></script>
    <script src="./assets/js/master01_02.js" defer></script>
  </body>
</html>

HTML;
