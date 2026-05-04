<?php
/**
 * SNAPSMACK - Migration 035: Big Wheel / Pimpmobile UI Modes
 *
 * Seeds the settings keys that control the admin interface complexity level.
 * New installs start in Big Wheel (simplified) mode. Admins are offered
 * Pimpmobile (full) mode every 100 posts, backing off to every 200 posts
 * after 3 declines.
 */

function migration_035(PDO $pdo): void {

    $seeds = [
        // 'bigwheel' = simplified mode (default). 'pimpmobile' = full admin.
        ['ui_mode',                    'bigwheel'],

        // How many times the admin has clicked "Not yet" on the offer card.
        ['pimpmobile_offer_declines',  '0'],

        // Post count at the time of the last offer (0 = never offered).
        ['pimpmobile_last_offer_at',   '0'],

        // '1' = admin chose "Leave me alone" — never show offer again.
        ['pimpmobile_never_show',      '0'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO snap_settings (setting_key, setting_val)
        VALUES (?, ?)
    ");
    foreach ($seeds as [$key, $val]) {
        $stmt->execute([$key, $val]);
    }
}
// EOF
