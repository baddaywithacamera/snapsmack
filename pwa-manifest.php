<?php
/**
 * SNAPSMACK - SMACK THAT APP UP web app manifest
 * SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
 */

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'id'          => './',
    'name'        => 'SMACK THAT APP UP',
    'short_name'  => 'SNAPSMACK',
    'description' => "SnapSmack's phone-and-tablet posting interface.",
    'start_url'   => './?source=pwa',
    'scope'       => './',
    'display'     => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0b0c0d',
    'theme_color'      => '#0b0c0d',
    'categories'       => ['photo', 'social', 'productivity'],
    'icons' => [
        ['src' => 'assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => [[
        'name'      => 'Create a post',
        'short_name'=> 'Create',
        'url'       => 'app',
        'icons'     => [['src' => 'assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']],
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ===== SNAPSMACK EOF =====
