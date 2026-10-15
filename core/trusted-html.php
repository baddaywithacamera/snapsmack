<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Opaque HTML value. Strict templates can render it but cannot construct it. */
final class SnapTrustedHtml
{
    private function __construct(private string $html) {}
    private function __clone(): void {}
    public function __serialize(): array { throw new LogicException('Trusted HTML cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new LogicException('Trusted HTML cannot be reconstructed.'); }
    public function __wakeup(): void { throw new LogicException('Trusted HTML cannot be reconstructed.'); }
    public static function __set_state(array $properties): self { throw new LogicException('Trusted HTML cannot be exported.'); }
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
        // Native, script-free disclosure controls used by CMS-authored FAQs.
        'details' => ['class'], 'summary' => ['class'],
        // nav, and class on a. THE BOARD's prompt index is a <nav> of linked
        // poster cards built by pc_board_rounds_embed_html(). Reached through
        // the dedicated board page it rendered; reached through a static page
        // and the shortcode parser it came here, nav was not on this list,
        // and an unknown tag is replaced by its own text -- so the whole
        // index collapsed into one unbroken line of words. The cards then
        // needed class on the anchor as well, or they survive with no styling
        // at all. Neither is a behaviour: nav is a plain container, and class
        // is CSS, which div, span, section, figure and figcaption are already
        // trusted with. href and src stay URL-validated below exactly as before.
        'nav' => ['class', 'aria-label'],
        'a' => ['href', 'title', 'class'],
        // This attribute is emitted by the CMS shortcode parser, then consumed
        // by the shared SMACKTALK lightbox engine. It remains URL-validated
        // below just like src and href.
        // class is presentation-only and is required by CMS components such
        // as the Board: its square crop lives on img.thumb. Stripping it made
        // portrait source files expand the entire card despite the square
        // contract in the registered Board stylesheet.
        'img' => ['src', 'alt', 'title', 'class', 'width', 'height', 'loading', 'data-lightbox-src'],
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
            if (in_array($name, ['href', 'src', 'data-lightbox-src'], true)) {
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
// ===== SNAPSMACK EOF =====
