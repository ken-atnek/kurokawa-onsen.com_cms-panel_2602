<?php
/*
 * [96-master/index.php]
 *  - 管理画面 -
 *  ログインページ
 *
 * [初版]
 *  2026.2.14
 */

#***** 定数定義ファイル：インクルード *****#
require_once dirname(__DIR__) . '/cms_config/common/define.php';
#***** 定数・関数宣言ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/common/set_function.php';
#***** DB設定ファイル：インクルード *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/database/set_db.php';
#***** ★ 処理開始：セッション宣言ファイルインクルード ★ *****#
require_once DOCUMENT_ROOT_PATH . '/cms_config/master/start_processing.php';

#PW再設定後のメッセージ表示
#idNote = 'kyoukai';
#$pwNote = 9696;
#echo password_hash("9696", PASSWORD_BCRYPT) . PHP_EOL;
#exit();

#=============#
# POSTチェック
#-------------#
#ログインエラー
$loginErr = isset($_REQUEST['loginERR']) ? $_REQUEST['loginERR'] : null;

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
    <link rel="stylesheet" href="../assets/css/log-in.css">
  </head>

  <body>
    <main class="contents-log-in cp-master">
      <article class="block-login">
        <div class="item-logo">
          <span>黒川温泉</span>
          <span>観光協会</span>
        </div>
        <!-- ログイン画面 -->
        <form name="loginForm" action="./check.php" method="post" class="box-log-in">
          <span class="title">ログインID</span>
          <input type="text" name="userId" inputmode="email">
          <span class="title">パスワード</span>
          <input type="password" name="userPassword" inputmode="email">

HTML;
#ログインエラーが発生しているときだけ警告メッセージを差し込む
if ((isset($_SESSION['login_err']) && $_SESSION['login_err'] != "") && $loginErr == 1) {
  print <<<HTML
          <div class="text-caution" style="display: block;">{$_SESSION['login_err']}</div>

HTML;
}
print <<<HTML
          <button type="submit">ログイン</button>
        </form>
      </article>
    </main>
    <script>
      //IDとPWの両方が入力されているときだけログインボタンを有効化する
      const loginForm = document.forms['loginForm'];
      const userIdInput = loginForm.elements['userId'];
      const userPasswordInput = loginForm.elements['userPassword'];
      const submitButton = loginForm.querySelector('button[type="submit"]');
      function toggleSubmitButton() {
        const isUserIdFilled = userIdInput.value.trim() !== '';
        const isUserPasswordFilled = userPasswordInput.value.trim() !== '';
        submitButton.disabled = !(isUserIdFilled && isUserPasswordFilled);
      }
      userIdInput.addEventListener('input', toggleSubmitButton);
      userPasswordInput.addEventListener('input', toggleSubmitButton);
      //初期状態はログインボタンを無効化する
      submitButton.disabled = true;
      toggleSubmitButton();
      //Enterキーでの送信を有効化する
      loginForm.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' && !submitButton.disabled) {
          event.preventDefault();
          submitButton.click();
        }
      });
      //IDとPWにフォーカスがあればエラーメッセージ削除
      function clearErrorMessage() {
        const errorMessageElement = document.querySelector('.text-caution');
        if (errorMessageElement) {
          errorMessageElement.style.display = 'none';
        }
      }
      userIdInput.addEventListener('input', clearErrorMessage);
      userPasswordInput.addEventListener('input', clearErrorMessage);
    </script>
  </body>
</html>

HTML;
