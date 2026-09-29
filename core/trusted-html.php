<?php
declare(strict_types=1);

/** Opaque HTML value. Strict templates can render it but cannot construct it. */
final class SnapTrustedHtml
{
    private function __construct(private string $html) {}
    public static function __snapsmackCmsOnly(string $sanitized): self { return new self($sanitized); }
    public function __toString(): string { return $this->html; }
}

/** Sanitize CMS-rendered rich text into the only trusted HTML type. */
function snapsmack_trusted_html(string $html): SnapTrustedHtml
{
    if (!class_exists('DOMDocument')) {
        return SnapTrustedHtml::__snapsmackCmsOnly(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $allowed = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [],
        'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [], 'code' => [], 'pre' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'div' => ['class', 'id', 'data-mosaic'], 'span' => ['class'], 'section' => ['class', 'id'],
        'figure' => ['class'], 'figcaption' => ['class'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
    ];
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><div id="snap-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $document->getElementById('snap-root');
    if (!$root) return SnapTrustedHtml::__snapsmackCmsOnly('');

    $nodes = [];
    foreach ($root->getElementsByTagName('*') as $node) $nodes[] = $node;
    foreach (array_reverse($nodes) as $node) {
        $tag = strtolower($node->nodeName);
        if (!isset($allowed[$tag])) {
            $text = $document->createTextNode((string)$node->textContent);
            $node->parentNode?->replaceChild($text, $node);
            continue;
        }
        $keep = array_flip($allowed[$tag]);
        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!isset($keep[$name])) {
                $node->removeAttributeNode($attribute);
                continue;
            }
            if (in_array($name, ['href', 'src'], true)) {
                $value = trim(html_entity_decode($attribute->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
                if (str_starts_with($value, '//') || ($scheme !== '' && !in_array($scheme, ['http', 'https'], true))) {
                    $node->removeAttribute($name);
                }
            }
        }
        if ($tag === 'a' && $node->hasAttribute('href')) $node->setAttribute('rel', 'noopener noreferrer');
        if ($tag === 'img') $node->setAttribute('loading', 'lazy');
    }
    $output = '';
    foreach ($root->childNodes as $child) $output .= $document->saveHTML($child);
    return SnapTrustedHtml::__snapsmackCmsOnly($output);
}
