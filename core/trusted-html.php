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

/**
 * The CMS-rendered community block for the photograph on screen, or null.
 *
 * core/community-component.php needs a database handle, the settings and the
 * image row. A strict template runs in a closure holding only $view, so the
 * block is rendered on the CMS side of the boundary and left here for the
 * comments component to pick up. snapsmack_build_skin_view() is the only
 * caller that sets it, once per request, from the validated response.
 *
 * It lives beside the trusted-HTML type because that is all it can carry: a
 * skin cannot construct that type, so nothing a skin forged can arrive here.
 */
function snap_community_block(?SnapTrustedHtml $block = null, bool $set = false): ?SnapTrustedHtml
{
    static $current = null;
    if ($set) $current = $block;
    return $current;
}

/** Sanitize CMS-rendered rich text into the only trusted HTML type. */
function snapsmack_trusted_html(string $html): SnapTrustedHtml
{
    if (!class_exists('DOMDocument')) {
        return SnapTrustedHtml::__snapsmackCmsOnly(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $allowed = [
        // Paragraph classes are presentation metadata. Static CMS pages use
        // them for bounded, skin-owned layouts (for example PhotoFri's
        // pfd-wordmark, pfd-tagline, pfd-lede and pfd-label). Stripping the
        // class leaves the content safe but collapses every distinct block
        // into the same generic paragraph presentation.
        // Countdown attributes are inert schedule data consumed by the shared
        // countdown engine. Keeping them here restores CMS-authored counters;
        // event handlers and style remain outside the allowlist.
        'p' => ['class', 'data-until', 'data-every', 'data-roll-caption', 'data-done'],
        'br' => [], 'strong' => [], 'em' => [], 'b' => ['data-cd'], 'i' => [], 'u' => [], 's' => [],
        // List classes are presentation metadata too. PhotoFri's HOW IT WORKS
        // page carries `pfd-steps` on its ordered list; stripping that class
        // discarded the skin's numbered-step treatment while the FAQ kept its
        // component classes, making two sibling pages visibly inconsistent.
        // `class` is inert and style/event attributes remain forbidden.
        'blockquote' => [], 'ul' => ['class'], 'ol' => ['class'], 'li' => ['class'], 'code' => [], 'pre' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        // Mosaic geometry is CMS-generated inert data consumed by the shared
        // compositor. Stripping these attributes silently turns every saved
        // three-photo composition back into the engine's generic fallback.
        'div' => ['class', 'id', 'data-mosaic', 'data-gap', 'data-emphasis', 'data-template'],
        'span' => ['class', 'data-cd', 'data-cd-caption', 'data-cd-clock', 'aria-hidden'],
        'section' => ['class', 'id', 'aria-label'],
        // The photoblogs.fyi directory shortcode emits a searchable semantic
        // table. These elements carry presentation metadata and ordinary form
        // values only; scripts, event handlers and inline style remain banned.
        'header' => ['class'],
        'form' => ['class', 'method', 'action'],
        'input' => ['class', 'type', 'name', 'value', 'placeholder', 'aria-label'],
        'button' => ['class', 'type'],
        'table' => ['class'], 'thead' => ['class'], 'tbody' => ['class'],
        'tr' => ['class'], 'th' => ['class', 'scope'], 'td' => ['class'],
        'small' => ['class'],
        'time' => ['class', 'datetime', 'data-cd-date'],
        'figure' => ['class'], 'figcaption' => ['class'],
        // Frames are accepted only for the CMS-generated YouTube player. The
        // exact privacy-enhanced origin and inert video id are checked below.
        'iframe' => ['src', 'title', 'class', 'loading', 'allow', 'allowfullscreen'],
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
        if ($tag === 'iframe') {
            $src = trim((string)$node->getAttribute('src'));
            if (!preg_match('~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}$~D', $src)) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('allowfullscreen', '');
        }
        if ($tag === 'form') {
            $method = strtolower(trim((string)$node->getAttribute('method')));
            if (!in_array($method, ['get', 'post'], true)) $node->setAttribute('method', 'get');
            $action = trim((string)$node->getAttribute('action'));
            if ($action === '' || !str_starts_with($action, '/') || str_starts_with($action, '//')) {
                $node->setAttribute('action', '/');
            }
        }
        if ($tag === 'input') {
            $type = strtolower(trim((string)$node->getAttribute('type')));
            if (!in_array($type, ['search', 'hidden'], true)) $node->setAttribute('type', 'search');
        }
        if ($tag === 'button') {
            $type = strtolower(trim((string)$node->getAttribute('type')));
            if (!in_array($type, ['submit', 'button'], true)) $node->setAttribute('type', 'submit');
        }
        if ($tag === 'a' && $node->hasAttribute('href')) $node->setAttribute('rel', 'noopener noreferrer');
        if ($tag === 'img') $node->setAttribute('loading', 'lazy');
    }
    $output = '';
    foreach ($root->childNodes as $child) $output .= $document->saveHTML($child);
    return SnapTrustedHtml::__snapsmackCmsOnly($output);
}

/**
 * Render an ordinary photo description as safe, paragraph-preserving HTML.
 *
 * The image table contains two generations of content: older rows are plain
 * text separated by blank lines, while newer/edited rows may contain <p> and
 * other CMS HTML.  Treating both as already-rendered HTML collapses the blank
 * lines, and mixed rows can leave their opening paragraph as a bare text node.
 */
function snapsmack_photo_description_html(string $content): SnapTrustedHtml
{
    $content = trim(str_replace(["\r\n", "\r"], "\n", $content));
    if ($content === '') return snapsmack_trusted_html('');

    $hasBlockMarkup = (bool)preg_match(
        '~<(?:p|div|section|blockquote|ul|ol|pre|h[1-6]|figure|table|details)\b~i',
        $content
    );

    if (!$hasBlockMarkup) {
        $paragraphs = preg_split('/\n[\t ]*\n+/', $content) ?: [$content];
        $content = implode('', array_map(
            static fn(string $paragraph): string => '<p>' . preg_replace('/\n+/', '<br>', trim($paragraph)) . '</p>',
            array_filter($paragraphs, static fn(string $paragraph): bool => trim($paragraph) !== '')
        ));
    } elseif (class_exists('DOMDocument')) {
        // Imported posts can alternate CMS blocks with bare prose, especially
        // around an inline photograph: </div>Bare paragraph.<p>Next...</p>.
        // Wrap every meaningful top-level inline run, not only the opening
        // run. Inline emphasis and links move into the restored paragraph.
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="snap-paragraph-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('snap-paragraph-root');
        if ($root) {
            $blockTags = array_flip(['p','div','section','blockquote','ul','ol','pre','h1','h2','h3','h4','h5','h6','figure','table','details','header','form','nav']);
            $run = [];
            $flush = static function (?DOMNode $before = null) use (&$run, $document, $root): void {
                if (!$run) return;
                $meaningful = false;
                foreach ($run as $node) {
                    if (trim((string)$node->textContent) !== '' || ($node instanceof DOMElement && strtolower($node->tagName) === 'img')) {
                        $meaningful = true;
                        break;
                    }
                }
                if ($meaningful) {
                    $paragraph = $document->createElement('p');
                    $root->insertBefore($paragraph, $run[0]);
                    foreach ($run as $node) $paragraph->appendChild($node);
                }
                $run = [];
            };
            foreach (iterator_to_array($root->childNodes) as $child) {
                if ($child instanceof DOMElement && isset($blockTags[strtolower($child->tagName)])) {
                    $flush($child);
                    continue;
                }
                $run[] = $child;
            }
            $flush();
            $content = '';
            foreach ($root->childNodes as $child) $content .= $document->saveHTML($child);
        }
    } else {
        // Conservative fallback for PHP builds without DOM.
        $content = preg_replace_callback(
            '~(^|</(?:div|section|figure|table|details)>)([^<].*?)(?=<(?:p|div|section|blockquote|ul|ol|pre|h[1-6]|figure|table|details)\b|$)~is',
            static fn(array $match): string => $match[1] . (trim($match[2]) === ''
                ? $match[2]
                : '<p>' . preg_replace('/\n+/', '<br>', trim($match[2])) . '</p>'),
            $content
        ) ?? $content;
    }

    return snapsmack_trusted_html($content);
}
// ===== SNAPSMACK EOF =====
