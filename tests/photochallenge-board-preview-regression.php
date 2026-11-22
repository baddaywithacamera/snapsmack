<?php
/**
 * SS-1513: Board cards use remote preview derivatives and reserve geometry.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/fediverse.php';
require_once $root . '/core/photochallenge.php';

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$explicit = pc_board_media_view(
    ['https://remote.invalid/original/photo.jpg'],
    [[
        'url' => 'https://remote.invalid/original/photo.jpg',
        'preview_url' => 'https://remote.invalid/small/photo.jpg',
        'width' => 3200,
        'height' => 2000,
        'preview_width' => 640,
        'preview_height' => 400,
    ]]
);
$check($explicit['thumb'] === 'https://remote.invalid/small/photo.jpg', 'The board did not select the explicit preview.');
$check($explicit['width'] === 640 && $explicit['height'] === 400, 'The board lost preview dimensions.');
$check($explicit['thumb'] !== $explicit['original'], 'The board rendered the original as its card image.');
$markup = pc_board_preview_html([
    'thumb' => $explicit['thumb'],
    'thumb_width' => $explicit['width'],
    'thumb_height' => $explicit['height'],
], 'thumb', 'Photo by test@example');
$check(str_contains($markup, 'loading="lazy" decoding="async" width="640" height="400"'),
    'Rendered board markup does not reserve the prepared geometry.');
$check(str_contains($markup, 'src="https://remote.invalid/small/photo.jpg"'),
    'Rendered board markup does not use the prepared preview.');

$legacyMastodon = pc_board_media_view(
    ['https://files.example/media_attachments/files/1/original/photo.jpg'],
    [['width' => 3000, 'height' => 2000]]
);
$check($legacyMastodon['thumb'] === 'https://files.example/media_attachments/files/1/small/photo.jpg',
    'A legacy Mastodon row did not resolve its deterministic small derivative.');
$check($legacyMastodon['width'] === 3000 && $legacyMastodon['height'] === 2000,
    'The legacy derivative lost the original aspect ratio used to reserve space.');

$legacyThumb = pc_board_media_view(
    ['https://blog.example/img_uploads/2026/11/thumbs/photo.jpg'],
    [['width' => 600, 'height' => 400]]
);
$check($legacyThumb['thumb'] !== '', 'A URL already naming a thumbnail was discarded.');

$originalOnly = pc_board_media_view(
    ['https://cdn.example/full-size-photo.jpg'],
    [['width' => 6000, 'height' => 4000]]
);
$check($originalOnly['thumb'] === '', 'The board fell back to an unmarked full-size original.');

$missingGeometry = pc_board_media_view(
    ['https://cdn.example/preview/photo.jpg'],
    [['preview_url' => 'https://cdn.example/preview/photo.jpg']]
);
$check($missingGeometry['thumb'] === '', 'The board emitted an image without geometry.');

$object = pc_recovery_object_with_previews(
    ['attachment' => [['url' => 'https://remote.invalid/original/photo.jpg']]],
    ['attachments' => [[
        'preview_url' => 'https://remote.invalid/small/photo.jpg',
        'preview_width' => 640,
        'preview_height' => 400,
    ]]]
);
$check(($object['attachment'][0]['preview_url'] ?? '') === 'https://remote.invalid/small/photo.jpg',
    'Recovery discarded the REST preview before AP ingest.');
$check(($object['attachment'][0]['preview_width'] ?? 0) === 640
    && ($object['attachment'][0]['preview_height'] ?? 0) === 400,
    'Recovery discarded preview dimensions before AP ingest.');

$check(pc_preview_status_api_url(
    'https://mastodon.example/@person/123456',
    'https://mastodon.example/users/person/statuses/123456'
) === 'https://mastodon.example/api/v1/statuses/123456',
    'The bounded backfill did not resolve a canonical status API URL.');
$check(pc_preview_status_api_url('https://remote.invalid/posts/not-numeric') === '',
    'The bounded backfill guessed an API URL without a numeric status id.');
$check(pc_preview_status_api_url(
    'https://snapsmack.example/photo-slug',
    'https://snapsmack.example/?ap=note&post=42'
) === 'https://snapsmack.example/api/v1/statuses/42',
    'The bounded backfill did not resolve a SnapSmack Note id.');
$check(pc_preview_backfill_host_available('remote.invalid', ['remote.invalid' => 19], []),
    'The sequential host batch stopped before 20 attempts.');
$check(!pc_preview_backfill_host_available('remote.invalid', ['remote.invalid' => 20], []),
    'The sequential host batch exceeded 20 attempts.');
$check(!pc_preview_backfill_host_available('remote.invalid', ['remote.invalid' => 1], ['remote.invalid' => true]),
    'A stopped host was allowed another attempt in the same run.');
$check(sv_photochallenge_retry_after_seconds('120', 1000) === 120,
    'A numeric Retry-After value is not honoured.');
$check(sv_photochallenge_retry_after_seconds('Thu, 01 Jan 1970 00:20:00 GMT', 1000) === 200,
    'An HTTP-date Retry-After value is not honoured.');
$check(sv_photochallenge_retry_after_seconds('-5', 1000) === null
    && sv_photochallenge_retry_after_seconds('+5', 1000) === null
    && sv_photochallenge_retry_after_seconds('banana', 1000) === null,
    'Invalid Retry-After values must not create a persisted host backoff.');
$check(sv_photochallenge_retry_after_seconds('99999999', 1000) === 86400,
    'Retry-After must be clamped to one day.');

$photochallenge = (string)file_get_contents($root . '/core/photochallenge.php');
$check(!str_contains($photochallenge, "'thumb'        => (string)(\$media[0]"),
    'The board view model points directly at the legacy original again.');
$check(str_contains($photochallenge, 'pc_preview_backfill_host_available($preview_host')
    && str_contains($photochallenge, '$preview_stopped_hosts[$preview_host] = true')
    && str_contains($photochallenge, 'photochallenge_preview_backoff_json'),
    'The per-host 20-row cap or stop-on-backoff guard is missing.');

echo "Photo challenge board previews and geometry: PASS.\n";
// ===== SNAPSMACK EOF =====
