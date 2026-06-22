<?php
/*
 * [assets/lib/TipTap/html_sanitizer.php]
 *  TipTap getHTML() 出力のサニタイズ
 *
 * [初版]
 *  2026.5.16
 */

/*
 * [HTMLフリータイプ本文サニタイズ]
 *  対象  : shop_articles.body_html（article_type=2）
 *  許可タグ  : p / br / strong / em / s / h2 / h3 / h4 / h5 / ul / ol / li
 *              blockquote / img / a / span
 *  許可属性  : img[src, alt] / a[href, target, rel] / span[style（colorのみ）]
 *  img src制限: /db/images/shops/.../html_body/... のみ許可
 *  禁止スキーム: javascript: / data:
 *  target="_blank" 時は rel="noopener noreferrer" を補完
 */
function sanitizeArticleHtml($html)
{
  if ($html === null || trim((string)$html) === '') {
    return '';
  }
  $allowedTags = ['p', 'br', 'strong', 'em', 's', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'a', 'blockquote', 'img', 'span'];
  $allowedAttrsByTag = [
    'img'  => ['src', 'alt'],
    'a'    => ['href', 'target', 'rel'],
    'span' => ['style'],
  ];
  $forbiddenSchemes = ['javascript:', 'data:'];
  return _sanitizeArticleHtmlImageSrc(_sanitizeHtmlWalkDocument($html, $allowedTags, $allowedAttrsByTag, $forbiddenSchemes));
}
/*
 * HTMLフリー本文の画像srcを保存許可パスのみに制限
 */
function _sanitizeArticleHtmlImageSrc($html)
{
  if ($html === '') {
    return '';
  }
  return preg_replace_callback('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', function ($matches) {
    $src = html_entity_decode((string)$matches[2], ENT_QUOTES, 'UTF-8');
    if (strpos($src, '/db/images/shops/') === 0 && strpos($src, '/html_body/') !== false) {
      return $matches[0];
    }
    return '';
  }, (string)$html);
}
/*
 * [定型タイプ段落本文サニタイズ]
 *  対象  : shop_article_paragraphs.body_text（article_type=1、段落1〜3）
 *  許可タグ  : p / br / strong / em / s / h4 / h5 / ul / ol / li
 *              blockquote / pre / code / hr / img / a / span
 *  許可属性  : img[src, alt] / a[href, target, rel] / span[style（colorのみ）]
 *  禁止スキーム: javascript: / data: （img src / a href 共通）
 *  target="_blank" 時は rel="noopener noreferrer" を補完
 */
function sanitizeArticleParagraphHtml($html)
{
  if ($html === null || trim((string)$html) === '') {
    return '';
  }
  $allowedTags = [
    'p',
    'br',
    'strong',
    'em',
    's',
    'h4',
    'h5',
    'ul',
    'ol',
    'li',
    'blockquote',
    'pre',
    'code',
    'hr',
    'img',
    'a',
    'span'
  ];
  $allowedAttrsByTag = [
    'img'  => ['src', 'alt'],
    'a'    => ['href', 'target', 'rel'],
    'span' => ['style'],
  ];
  $forbiddenSchemes = ['javascript:', 'data:'];
  return _sanitizeHtmlWalkDocument($html, $allowedTags, $allowedAttrsByTag, $forbiddenSchemes);
}
/*
 * DOMDocumentをセットアップしてサニタイズ結果を返す内部関数
 */
function _sanitizeHtmlWalkDocument($html, array $allowedTags, array $allowedAttrsByTag, array $forbiddenSchemes)
{
  $doc = new DOMDocument('1.0', 'UTF-8');
  libxml_use_internal_errors(true);
  $doc->loadHTML(
    '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . (string)$html . '</body></html>'
  );
  libxml_clear_errors();
  $body = $doc->getElementsByTagName('body')->item(0);
  if ($body === null) {
    return '';
  }
  _sanitizeHtmlWalkNode($doc, $body, $allowedTags, $allowedAttrsByTag, $forbiddenSchemes);
  $result = '';
  foreach ($body->childNodes as $child) {
    $result .= $doc->saveHTML($child);
  }
  return $result;
}
/*
 * DOMノードを再帰的にサニタイズする内部関数
 */
function _sanitizeHtmlWalkNode(DOMDocument $doc, DOMNode $node, array $allowedTags, array $allowedAttrsByTag, array $forbiddenSchemes)
{
  $children = [];
  foreach ($node->childNodes as $child) {
    $children[] = $child;
  }
  foreach ($children as $child) {
    if ($child->nodeType === XML_TEXT_NODE) {
      continue;
    }
    if ($child->nodeType !== XML_ELEMENT_NODE) {
      $node->removeChild($child);
      continue;
    }
    $tagName = strtolower($child->nodeName);
    if (!in_array($tagName, $allowedTags, true)) {
      #不許可タグ: 子ノードを再帰してから親へ展開して自身を削除
      _sanitizeHtmlWalkNode($doc, $child, $allowedTags, $allowedAttrsByTag, $forbiddenSchemes);
      while ($child->firstChild) {
        $node->insertBefore($child->firstChild, $child);
      }
      $node->removeChild($child);
      continue;
    }
    #属性サニタイズ: on* 属性・許可属性以外をすべて除去
    $allowedAttrs = $allowedAttrsByTag[$tagName] ?? [];
    $removeAttrs = [];
    foreach ($child->attributes as $attr) {
      $attrName = strtolower($attr->nodeName);
      if (strpos($attrName, 'on') === 0 || !in_array($attrName, $allowedAttrs, true)) {
        $removeAttrs[] = $attr->nodeName;
      }
    }
    foreach ($removeAttrs as $attrName) {
      $child->removeAttribute($attrName);
    }
    #<a> 専用処理
    if ($tagName === 'a') {
      $href = $child->getAttribute('href');
      $hrefCheck = strtolower(preg_replace('/[\x00-\x20]+/', '', $href));
      $blocked = false;
      foreach ($forbiddenSchemes as $scheme) {
        if (strpos($hrefCheck, $scheme) === 0) {
          $blocked = true;
          break;
        }
      }
      if ($blocked) {
        $child->removeAttribute('href');
      }
      if ($child->getAttribute('target') === '_blank') {
        $child->setAttribute('rel', 'noopener noreferrer');
      } else {
        $child->removeAttribute('target');
        $child->removeAttribute('rel');
      }
    }
    #<img> 専用処理: src の禁止スキームチェック
    if ($tagName === 'img') {
      $src = $child->getAttribute('src');
      $srcCheck = strtolower(preg_replace('/[\x00-\x20]+/', '', $src));
      foreach ($forbiddenSchemes as $scheme) {
        if (strpos($srcCheck, $scheme) === 0) {
          $child->removeAttribute('src');
          break;
        }
      }
    }
    #<span> 専用処理: style は color: #xxx / rgb() のみ許可
    if ($tagName === 'span') {
      $style = $child->getAttribute('style');
      if ($style !== '') {
        if (preg_match('/\bcolor\s*:\s*(#[0-9a-fA-F]{3,6}|rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\))/i', $style, $m)) {
          $child->setAttribute('style', 'color:' . $m[1]);
        } else {
          $child->removeAttribute('style');
        }
      }
    }
    _sanitizeHtmlWalkNode($doc, $child, $allowedTags, $allowedAttrsByTag, $forbiddenSchemes);
  }
}
