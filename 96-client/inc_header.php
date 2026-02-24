<?php
/*
 * [96-client/inc_header.php]
 *  - 【加盟店】管理画面 -
 *  ヘッダー
 *
 * [初版]
 *  2026.2.23
 */

$previewUrl = DOMAIN_NAME;

#***** タグ生成開始 *****#
print <<<HTML

<header class="cp-client">
  <div class="item-logo">
    <span>黒川温泉</span>
    <span>観光協会</span>
  </div>
  <div class="inner_head">
    <h1>{$headerShopName}</h1>
    <nav>
      <a href="{$previewUrl}" target="_blank" rel="noopener">サイトの確認</a>
      <a href="./logout.php" class="logout">ログアウト</a>
    </nav>
  </div>
  <nav>
    <a href="./client01_02.php" class="menu-color-01"><span>店舗管理</span></a>
    <a href="#" class="menu-color-02"><span>サイト管理</span></a>
    <a href="#" class="menu-color-03"><span>EC販売管理</span></a>
  </nav>
</header>

HTML;
