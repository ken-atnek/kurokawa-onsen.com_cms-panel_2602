<?php
/*
 * [96-master/inc_header.php]
 *  - 管理画面 -
 *  ヘッダー
 *
 * [初版]
 *  2026.2.14
 */

$previewUrl = DOMAIN_NAME;

#***** タグ生成開始 *****#
print <<<HTML

<header class="cp-master">
  <div class="item-logo">
    <span>黒川温泉</span>
    <span>観光協会</span>
  </div>
  <div class="inner_head">
    <h1>黒川温泉観光協会（マスター）</h1>
    <nav>
      <a href="{$previewUrl}" target="_blank" rel="noopener">サイトの確認</a>
      <a href="./logout.php" class="logout">ログアウト</a>
    </nav>
  </div>
  <nav>
    <a href="./master01_01.php" class="menu-color-01"><span>店舗管理</span></a>
    <a href="#" class="menu-color-02"><span>サイト管理</span></a>
  </nav>
</header>

HTML;
