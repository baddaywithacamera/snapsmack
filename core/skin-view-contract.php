<?php
declare(strict_types=1);

const SNAPSMACK_SKIN_VIEW_MODEL_VERSION = 1;

/** Convert a controller response into the only data a strict skin receives. */
function snapsmack_build_skin_view(array $response, array $presentation = []): array
{
    $allowedResponse = ['status', 'kind', 'mode', 'item', 'items', 'post', 'posts', 'tiles', 'photographs',
        'comments', 'navigation', 'results', 'query', 'slug', 'page', 'total_pages',
        'page_title', 'rendered_content', 'signature', 'previous', 'next', 'categories',
        'albums', 'author', 'colophon', 'photo_count', 'word_count', 'comments_enabled',
        'show_titles'];
    $allowedPresentation = ['site_name', 'tagline', 'site_url', 'base_url', 'language',
        'direction', 'brand_logo', 'owner_name', 'site_description', 'avatar_url', 'skin_slug',
        'skin_style_url', 'skin_custom_style', 'owner_custom_code', 'registered_assets'];
    return [
        'model' => 'snapsmack.public',
        'version' => SNAPSMACK_SKIN_VIEW_MODEL_VERSION,
        'response' => array_intersect_key($response, array_flip($allowedResponse)),
        'site' => array_intersect_key($presentation, array_flip($allowedPresentation)),
    ];
}

function snapsmack_validate_skin_view(array $view, ?string &$reason = null): bool
{
    $reason = null;
    if (($view['model'] ?? null) !== 'snapsmack.public'
        || ($view['version'] ?? null) !== SNAPSMACK_SKIN_VIEW_MODEL_VERSION
        || !is_array($view['response'] ?? null)
        || !is_array($view['site'] ?? null)) {
        $reason = 'unsupported or malformed view-model envelope';
        return false;
    }
    $walk = static function ($value) use (&$walk): bool {
        if (is_null($value) || is_scalar($value) || $value instanceof SnapTrustedHtml || $value instanceof SnapOwnerCode) return true;
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (!is_int($key) && !is_string($key)) return false;
            if (!$walk($child)) return false;
        }
        return true;
    };
    if (!$walk($view)) {
        $reason = 'view model contains an object, resource, or callable value';
        return false;
    }
    return true;
}

function snapsmack_safe_fallback_view(int $status = 500): array
{
    return snapsmack_build_skin_view(['status' => $status, 'kind' => 'safe_fallback'], []);
}

/**
 * Render a schema-v2 template in a deliberately tiny local scope. The policy
 * grammar rejects access to the internal path variable; only $view is usable.
 */
function snapsmack_render_strict_skin_template(string $skinDir, string $template, array $view): bool
{
    $reason = null;
    if (!snapsmack_validate_skin_view($view, $reason)) return false;
    if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $template)) return false;
    $root = realpath($skinDir);
    $candidate = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $template);
    if ($root === false || $candidate === false || is_link($candidate)
        || !str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
        return false;
    }
    (static function (string $__snapsmack_template, array $view): void {
        include $__snapsmack_template;
    })($candidate, $view);
    return true;
}
