<?php
/**
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
 * Strict sanitizer for administrator-uploaded SVG branding.
 */

function snapsmack_sanitize_branding_svg(string $path, ?string &$error = null): ?string {
    $error = null;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '' || strlen($raw) > 2 * 1024 * 1024) {
        $error = 'The SVG is empty, unreadable, or larger than 2 MB.';
        return null;
    }
    if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $raw)) {
        $error = 'SVG document types, entities, and external stylesheets are not allowed.';
        return null;
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || !$dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
        $error = 'The file is not a valid SVG document.';
        return null;
    }

    $allowed_elements = array_flip([
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'title', 'desc',
    ]);
    $allowed_attributes = array_flip([
        'xmlns', 'viewbox', 'width', 'height', 'preserveaspectratio', 'role',
        'aria-label', 'focusable', 'id', 'transform', 'opacity', 'fill',
        'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap',
        'stroke-linejoin', 'stroke-opacity', 'd', 'x', 'y', 'x1', 'x2', 'y1',
        'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'offset', 'stop-color',
        'stop-opacity', 'gradientunits', 'gradienttransform', 'clip-path', 'mask',
        'font-family', 'font-size', 'font-weight', 'text-anchor',
    ]);

    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!isset($allowed_elements[strtolower($element->localName)])) {
            $error = 'The SVG contains an unsupported or unsafe element: ' . $element->nodeName . '.';
            return null;
        }
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue);
            if (str_starts_with($name, 'on') || !isset($allowed_attributes[$name])) {
                $error = 'The SVG contains an unsupported or unsafe attribute: ' . $attribute->nodeName . '.';
                return null;
            }
            if (preg_match('/(?:javascript|data|https?|file):/i', $value)) {
                $error = 'External or executable SVG references are not allowed.';
                return null;
            }
            if (stripos($value, 'url(') !== false && !preg_match('/^url\(#[A-Za-z_][A-Za-z0-9_.:-]*\)$/', $value)) {
                $error = 'Only internal SVG fragment references are allowed.';
                return null;
            }
        }
    }

    return $dom->saveXML($dom->documentElement) ?: null;
}

// ===== SNAPSMACK EOF =====
