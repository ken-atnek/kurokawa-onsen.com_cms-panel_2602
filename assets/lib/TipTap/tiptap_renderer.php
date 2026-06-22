<?php

declare(strict_types=1);

/**
 * Tiptap(ProseMirror) JSON -> HTML renderer (allowlist)
 * このプロジェクト（StarterKit + Image + Color/TextStyle + imageGallery）に合わせた「完全対応版」。
 *
 * - text は必ず htmlspecialchars でエスケープ（XSS防止）
 * - href/src/color は簡易バリデーション（許可リスト）
 * - 未対応ノードは子要素だけ描画（壊れにくく）
 *
 * 想定JSON:
 * 1) ProseMirror doc JSON（editor.getJSON()）: { type:"doc", content:[...] }
 * 2) 本プロジェクトの article.json: { id,title,visibility,..., content: <doc>, ... }
 */

/**
 * 記事データから TipTap(ProseMirror) doc を取り出してHTMLへ変換する
 *
 * 想定入力:
 * - { editor: <doc-json> } を含む配列（本プロジェクトの保存形式）
 *
 * @param array $article
 * @return string HTML（不正/未対応なら空文字）
 */
function tt_render_article(array $article): string
{
  $doc = $article['editor'] ?? null;
  if (!is_array($doc)) return '';
  return tt_render_doc($doc);
}
/**
 * ProseMirror doc JSON（type=doc）をHTMLへ変換する
 *
 * @param array $doc
 * @return string HTML（type不一致なら空文字）
 */
function tt_render_doc(array $doc): string
{
  if (($doc['type'] ?? '') !== 'doc') return '';
  return tt_render_children($doc['content'] ?? []);
}
/**
 * 子ノード配列（content）を順に描画して連結する
 * - 未対応ノードは tt_render_node() 側で「子だけ描画」する方針
 *
 * @param mixed $content
 * @return string HTML
 */
function tt_render_children($content): string
{
  if (!is_array($content)) return '';
  $out = '';
  foreach ($content as $node) {
    if (is_array($node)) $out .= tt_render_node($node);
  }
  return $out;
}
/**
 * 1ノードをHTMLへ変換する（allowlist方式）
 *
 * セキュリティ方針:
 * - text は必ず htmlspecialchars でエスケープ
 * - href/src/color は簡易バリデーション（許可リスト）
 * - 未対応ノード/markは「無視」または「子だけ描画」して壊れにくく
 *
 * @param array $node ProseMirror node
 * @return string HTML
 */
function tt_render_node(array $node): string
{
  $type = (string)($node['type'] ?? '');
  $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
  $content = $node['content'] ?? [];
  $text = (string)($node['text'] ?? '');
  switch ($type) {
    case 'paragraph':
      return tt_wrap_block('p', tt_render_children($content), $attrs);
    case 'heading':
      $level = (int)($attrs['level'] ?? 2);
      if ($level < 1 || $level > 6) $level = 2;
      return tt_wrap_block("h{$level}", tt_render_children($content), $attrs);
    case 'text':
      $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
      $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];
      return tt_apply_marks($html, $marks);
    case 'hardBreak':
      return '<br>';
    case 'bulletList':
      return tt_wrap_block('ul', tt_render_children($content), $attrs);
    case 'orderedList':
      $start = (int)($attrs['start'] ?? 1);
      $extra = [];
      if ($start > 1) $extra['start'] = (string)$start;
      return tt_wrap_block('ol', tt_render_children($content), $extra);
    case 'listItem':
      #listItem の中は paragraph が入る想定だが、子をそのまま描画
      return '<li>' . tt_render_children($content) . '</li>';
    case 'blockquote':
      return '<blockquote>' . tt_render_children($content) . '</blockquote>';
    case 'horizontalRule':
      return '<hr>';
    case 'codeBlock':
      #StarterKit の codeBlock
      $code = tt_render_children($content);
      #codeBlock 内で <p> 等にならないよう、子の text はそのまま＋<br>等が来たら許容
      return '<pre><code>' . $code . '</code></pre>';
    case 'image':
      return tt_render_image($attrs);
    case 'imageGallery':
      return tt_render_image_gallery($content);
    default:
      #未対応は子要素だけ描画（安全）
      return tt_render_children($content);
  }
}
/**
 * ブロック要素に textAlign など（将来拡張用）を安全に反映
 */
function tt_wrap_block(string $tag, string $inner, array $attrs): string
{
  $style = '';
  #互換：TextAlign等が入ってきた場合に備える
  if (isset($attrs['textAlign']) && is_string($attrs['textAlign'])) {
    $v = strtolower(trim($attrs['textAlign']));
    if (in_array($v, ['left', 'right', 'center', 'justify'], true)) {
      $style = ' style="text-align:' . $v . '"';
    }
  }
  return "<{$tag}{$style}>{$inner}</{$tag}>";
}
/**
 * imageノード（attrs）をHTMLへ変換する
 * - src は tt_safe_img_src() で許可したものだけ出力
 * - alt/title は必ずエスケープ
 *
 * @param array $attrs image.attrs
 * @return string HTML（src不正なら空文字）
 */
function tt_render_image(array $attrs): string
{
  $src = tt_safe_img_src((string)($attrs['src'] ?? ''));
  if ($src === '') return '';
  $alt = htmlspecialchars((string)($attrs['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
  $title = htmlspecialchars((string)($attrs['title'] ?? ''), ENT_QUOTES, 'UTF-8');
  #エディタのCSSに合わせて image-node wrapper を付ける（削除ボタンは付けない）
  $t = $title !== '' ? ' title="' . $title . '"' : '';
  return '<span class="image-node"><img src="' . $src . '" alt="' . $alt . '"' . $t . ' loading="lazy"></span>';
}
/**
 * imageGalleryノードをHTMLへ変換する
 * - content 内の image ノードのみを抽出してレンダリング
 *
 * @param mixed $content imageGallery.content
 * @return string HTML
 */
function tt_render_image_gallery($content): string
{
  #content は image ノードの配列
  $items = [];
  if (is_array($content)) {
    foreach ($content as $n) {
      if (is_array($n) && ($n['type'] ?? '') === 'image') {
        $items[] = tt_render_image(is_array($n['attrs'] ?? null) ? $n['attrs'] : []);
      }
    }
  }
  $count = count($items);
  if ($count === 0) return '';
  $inner = implode('', $items);
  #エディタと同じ class / data-type / data-count を付与（CSS対応）
  return '<div class="image-gallery" data-type="image-gallery" data-count="' . $count . '"><div class="image-gallery__inner">' . $inner . '</div></div>';
}
/**
 * mark の適用（入れ子順を固定して、Tiptapの見た目に寄せる）
 */
function tt_apply_marks(string $html, array $marks): string
{
  if (!is_array($marks) || count($marks) === 0) return $html;
  #優先度（外側→内側）
  $rank = [
    'link' => 10,
    'textStyle' => 20, #color など span
    'color' => 20,     #互換（拡張によっては mark type が color になることがある）
    'bold' => 30,
    'italic' => 40,
    'underline' => 50, #将来用（現状未導入でも安全）
    'strike' => 60,
    'code' => 70,
    'highlight' => 80, #将来用
  ];
  #ソート（unknownは最後）
  usort($marks, function ($a, $b) use ($rank) {
    $ta = is_array($a) ? (string)($a['type'] ?? '') : '';
    $tb = is_array($b) ? (string)($b['type'] ?? '') : '';
    $ra = $rank[$ta] ?? 999;
    $rb = $rank[$tb] ?? 999;
    if ($ra === $rb) return 0;
    return ($ra < $rb) ? -1 : 1;
  });
  foreach ($marks as $m) {
    if (!is_array($m)) continue;
    $type = (string)($m['type'] ?? '');
    $attrs = is_array($m['attrs'] ?? null) ? $m['attrs'] : [];
    switch ($type) {
      case 'bold':
        $html = '<strong>' . $html . '</strong>';
        break;
      case 'italic':
        $html = '<em>' . $html . '</em>';
        break;
      case 'underline':
        $html = '<u>' . $html . '</u>';
        break;
      case 'strike':
        $html = '<s>' . $html . '</s>';
        break;
      case 'code':
        $html = '<code>' . $html . '</code>';
        break;
      case 'link':
        $href = tt_safe_href((string)($attrs['href'] ?? ''));
        if ($href !== '') {
          $target = '';
          if (($attrs['target'] ?? '') === '_blank') {
            $target = ' target="_blank" rel="noopener noreferrer"';
          }
          $html = '<a href="' . $href . '"' . $target . '>' . $html . '</a>';
        }
        break;
      case 'textStyle':
      case 'color':
        #Color extension: textStyle mark attrs.color が一般的
        $color = tt_safe_color((string)($attrs['color'] ?? ''));
        if ($color !== '') {
          $html = '<span style="color:' . $color . '">' . $html . '</span>';
        }
        break;
      case 'highlight':
        #互換（将来）: backgroundColor / color など
        $bg = isset($attrs['color']) ? (string)$attrs['color'] : '';
        $bg = tt_safe_color($bg);
        if ($bg !== '') {
          $html = '<mark style="background-color:' . $bg . '">' . $html . '</mark>';
        } else {
          $html = '<mark>' . $html . '</mark>';
        }
        break;
      default:
        #未対応markは無視（安全）
        break;
    }
  }
  return $html;
}
/**
 * 安全なカラー値に正規化する
 * - 現状は #RGB / #RRGGBB のみ許可
 *
 * @param string $color
 * @return string 許可される色ならそのまま、不可なら空文字
 */
function tt_safe_color(string $color): string
{
  $c = trim($color);
  if ($c === '') return '';
  #RGB / #RRGGBB のみ許可（必要なら rgb()/hsl() を追加）
  if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c)) return $c;
  return '';
}
/**
 * 安全なリンクURLに正規化する
 * - javascript: / data: を拒否
 * - / 相対 / http(s) / mailto / # のみ許可
 * - 返り値はHTML属性に安全な形へエスケープ済み
 *
 * @param string $href
 * @return string 許可されるURLならエスケープ済み文字列、不可なら空文字
 */
function tt_safe_href(string $href): string
{
  $h = trim($href);
  if ($h === '') return '';
  #javascript: / data: を排除
  if (preg_match('/^\s*(javascript|data):/i', $h)) return '';
  #相対 / 絶対 / mailto / # を許可
  if (preg_match('/^(\/|https?:\/\/|mailto:|#)/i', $h)) {
    return htmlspecialchars($h, ENT_QUOTES, 'UTF-8');
  }
  return '';
}
/**
 * 安全な画像srcに正規化する
 * - javascript: を拒否
 * - 許可する形式: / 相対 / http(s) / data:image / tmp_upload / cms-panel / demo-cms-panel / 2604 への相対
 * - 返り値はHTML属性に安全な形へエスケープ済み
 *
 * @param string $src
 * @return string 許可されるsrcならエスケープ済み文字列、不可なら空文字
 */
function tt_safe_img_src(string $src): string
{
  $s = trim($src);
  if ($s === '') return '';
  #javascript: を排除
  if (preg_match('/^\s*javascript:/i', $s)) return '';
  #/ 相対 / http(s) / data:image / ../../../tmp_upload / ../../../cms-panel / ../../../demo-cms-panel / ../../../../2604 を許可
  if (preg_match('/^(\/|https?:\/\/|(\.\.\/)+tmp_upload|(\.\.\/)+cms-panel|(\.\.\/)+demo-cms-panel|(\.\.\/)+2604)/i', $s)) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
  if (preg_match('/^data:image\//i', $s)) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
  return '';
}

/**
 * Tiptap(ProseMirror) doc JSON からプレーンテキスト抽出
 * - 改行は無視（hardBreak/段落境界も含めてスペースに寄せる）
 * - リストの「・」は付けない（listItemも通常の文章として連結）
 * - 画像は無視（image / imageGallery）
 * - 余分な空白は1つに正規化して trim
 */
function tt_render_article_plaintext(array $doc): string
{
  $out = '';
  $walk = function ($node) use (&$walk, &$out) {
    if (!is_array($node)) return;
    $type = (string)($node['type'] ?? '');
    $content = $node['content'] ?? null;
    #画像は完全に無視
    if ($type === 'image' || $type === 'imageGallery') {
      return;
    }
    #テキスト本体
    if ($type === 'text') {
      $out .= (string)($node['text'] ?? '');
      return;
    }
    #改行は無視したいが、単語の結合を避けるため空白に寄せる
    if ($type === 'hardBreak') {
      $out .= ' ';
      return;
    }
    #子を再帰
    if (is_array($content)) {
      foreach ($content as $c) $walk($c);
    }
    #ブロック境界も改行ではなく空白に寄せる（段落/見出し/リスト項目等）
    if (in_array($type, ['paragraph', 'heading', 'blockquote', 'codeBlock', 'listItem'], true)) {
      $out .= ' ';
    }
  };
  $walk($doc);
  #空白正規化（連続空白→1個、前後trim）
  $out = preg_replace('/\s+/u', ' ', $out) ?? $out;
  return trim($out);
}
