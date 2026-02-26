<?php
/**
 * SnapSmack - Pimpotron Payload API
 * Version: 1.1 - Schema v1.1 sync
 * -------------------------------------------------------------------------
 * - Serves the JSON manifest consumed by ss-engine-pimpotron.js
 * - Resolves slide-level glitch inheritance from parent slideshow
 * - Builds image URLs from snap_images.img_file (native) or external_image_url
 * - Enforces: active slides only, sort_order ASC, max 10
 * - ADDED: rain_speed, rain_density, rain_color_hex (matrix slides)
 * - ADDED: image_glitch_enabled (image slides)
 * - ADDED: slideshow_font in global block
 * -------------------------------------------------------------------------
 * Endpoint: /api/pimpotron-payload.php?slideshow_id=1
 *           /api/pimpotron-payload.php?slideshow_slug=default
 * -------------------------------------------------------------------------
 */

// Hard JSON output — no HTML ever leaves this file
header('Content-Type: application/json');
header('Cache-Control: no-store');

// Locate db.php — /api/ is one level below root; db.php lives in /core/ at root
require_once dirname(__DIR__) . '/core/db.php';

// Build BASE_URL from snap_settings (same pattern as rss.php)
$_settings_stm = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
$_settings     = $_settings_stm->fetchAll(PDO::FETCH_KEY_PAIR);
$BASE_URL      = rtrim($_settings['site_url'] ?? '', '/') . '/';

// --------------------------------------------------------------------------
// 1. RESOLVE WHICH SLIDESHOW WAS REQUESTED
// --------------------------------------------------------------------------

$slideshow_id   = isset($_GET['slideshow_id'])   ? (int)$_GET['slideshow_id']            : null;
$slideshow_slug = isset($_GET['slideshow_slug'])  ? trim($_GET['slideshow_slug'])          : null;

if (!$slideshow_id && !$slideshow_slug) {
    http_response_code(400);
    echo json_encode(['error' => 'PIMPOTRON_NO_TARGET', 'message' => 'Provide slideshow_id or slideshow_slug.']);
    exit;
}

// --------------------------------------------------------------------------
// 2. FETCH THE PARENT SLIDESHOW
// --------------------------------------------------------------------------

try {
    if ($slideshow_id) {
        $stm = $pdo->prepare("SELECT * FROM snap_pimpotron_slideshows WHERE id = ? AND is_active = 1 LIMIT 1");
        $stm->execute([$slideshow_id]);
    } else {
        $stm = $pdo->prepare("SELECT * FROM snap_pimpotron_slideshows WHERE slideshow_slug = ? AND is_active = 1 LIMIT 1");
        $stm->execute([$slideshow_slug]);
    }

    $show = $stm->fetch();

    if (!$show) {
        http_response_code(404);
        echo json_encode(['error' => 'PIMPOTRON_NOT_FOUND', 'message' => 'Slideshow does not exist or is inactive.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'PIMPOTRON_DB_FAILURE', 'message' => 'Could not fetch slideshow.']);
    exit;
}

// --------------------------------------------------------------------------
// 3. FETCH SLIDES (JOIN snap_images for native media)
// --------------------------------------------------------------------------

try {
    $stm = $pdo->prepare("
        SELECT
            sl.id,
            sl.sort_order,
            sl.slide_type,
            sl.snap_image_id,
            sl.external_image_url,
            sl.bg_color_hex,
            sl.video_url,
            sl.video_autoplay,
            sl.video_loop,
            sl.video_muted,
            sl.overlay_text,
            sl.text_animation_type,
            sl.word_delay_ms,
            sl.font_color_hex,
            sl.pos_x_pct,
            sl.pos_y_pct,
            sl.display_duration_ms,
            sl.glitch_frequency,
            sl.glitch_intensity,
            sl.stage_shift_enabled,
            -- Matrix rain config
            sl.rain_speed,
            sl.rain_density,
            sl.rain_color_hex,
            -- Image glitch toggle
            sl.image_glitch_enabled,
            -- Native image path from snap_images (NULL if not a native image)
            si.img_file AS native_img_file
        FROM snap_pimpotron_slides sl
        LEFT JOIN snap_images si ON sl.snap_image_id = si.id
        WHERE sl.slideshow_id = ?
          AND sl.is_active = 1
        ORDER BY sl.sort_order ASC
        LIMIT 10
    ");
    $stm->execute([$show['id']]);
    $raw_slides = $stm->fetchAll();

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'PIMPOTRON_SLIDE_FAILURE', 'message' => 'Could not fetch slides.']);
    exit;
}

// --------------------------------------------------------------------------
// 4. BUILD THE PAYLOAD
// Resolve inheritance: slide-level NULLs fall back to slideshow defaults.
// Build final image_url from native path or external URL.
// --------------------------------------------------------------------------

$slides = [];

foreach ($raw_slides as $s) {

    // -- Glitch inheritance (COALESCE pattern in PHP) --
    $glitch_frequency    = $s['glitch_frequency']   ?? $show['glitch_frequency'];
    $glitch_intensity    = $s['glitch_intensity']    ?? $show['glitch_intensity'];
    $stage_shift_enabled = ($s['stage_shift_enabled'] !== null)
                            ? (bool)$s['stage_shift_enabled']
                            : (bool)$show['stage_shift_enabled'];

    // -- Resolve image URL --
    $image_url = null;
    if ($s['slide_type'] === 'image') {
        if (!empty($s['native_img_file'])) {
            // Native: build full URL from BASE_URL + img_file (strip leading slash)
            $image_url = $BASE_URL . ltrim($s['native_img_file'], '/');
        } elseif (!empty($s['external_image_url'])) {
            $image_url = $s['external_image_url'];
        }
    }

    // -- Resolve display duration --
    $duration_ms = $s['display_duration_ms'] ?? $show['default_speed_ms'];

    $slides[] = [
        'id'                  => (int)$s['id'],
        'sort_order'          => (int)$s['sort_order'],
        'slide_type'          => $s['slide_type'],

        // Content
        'image_url'           => $image_url,
        'image_glitch_enabled'=> (bool)($s['image_glitch_enabled'] ?? true),
        'bg_color_hex'        => $s['bg_color_hex'],
        'video_url'           => $s['video_url'],
        'video_autoplay'      => (bool)$s['video_autoplay'],
        'video_loop'          => (bool)$s['video_loop'],
        'video_muted'         => (bool)$s['video_muted'],

        // Matrix rain config (null = engine defaults)
        'rain_speed'          => $s['rain_speed']     !== null ? (int)$s['rain_speed']    : null,
        'rain_density'        => $s['rain_density']   !== null ? (int)$s['rain_density']  : null,
        'rain_color_hex'      => $s['rain_color_hex'] ?? null,

        // Text / HUD
        'overlay_text'        => $s['overlay_text'],
        'text_animation_type' => $s['text_animation_type'],
        'word_delay_ms'       => (int)$s['word_delay_ms'],
        'font_color_hex'      => $s['font_color_hex'],
        'pos_x_pct'           => (int)$s['pos_x_pct'],
        'pos_y_pct'           => (int)$s['pos_y_pct'],

        // Timing
        'display_duration_ms' => (int)$duration_ms,

        // Glitch config (resolved, never null in output)
        'glitch_frequency'    => $glitch_frequency,
        'glitch_intensity'    => $glitch_intensity,
        'stage_shift_enabled' => $stage_shift_enabled,
    ];
}

// --------------------------------------------------------------------------
// 5. ASSEMBLE FINAL MANIFEST AND FIRE
// --------------------------------------------------------------------------

$manifest = [
    'slideshow' => [
        'id'                 => (int)$show['id'],
        'name'               => $show['slideshow_name'],
        'slug'               => $show['slideshow_slug'],
    ],
    'global' => [
        'default_speed_ms'   => (int)$show['default_speed_ms'],
        'glitch_frequency'   => $show['glitch_frequency'],
        'glitch_intensity'   => $show['glitch_intensity'],
        'stage_shift_enabled'=> (bool)$show['stage_shift_enabled'],
        'stage_shift_max_px' => (int)$show['stage_shift_max_px'],
        'stage_scale_max'    => (float)$show['stage_scale_max'],
        'slideshow_font'     => $show['slideshow_font'] ?? 'Stalinist One',
    ],
    'slide_count' => count($slides),
    'slides'      => $slides,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
