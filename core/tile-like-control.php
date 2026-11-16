<?php
/**
 * SNAPSMACK - TILE LIKE CONTROL (a heart on one photograph, in place)
 *
 * SS-1323. Sean's spec: an outline heart in the bottom-left corner of each
 * conveyor image, appearing on hover, clickable right there WITHOUT opening
 * the solo page, so a visitor can like several in a row. No count on the
 * conveyor.
 *
 * WHY THIS EXISTS SEPARATELY FROM core/community-dock.php. The dock is ONE
 * fixed-position button for the page you are on. This is one heart per
 * PHOTOGRAPH, inside the tile. Different surface, same storage: both post to
 * process-like.php and both write snap_likes, so a like given on a conveyor
 * and a like given on a solo page are the same like, counted once by
 * snap_likes' own UNIQUE KEY. No new public write path, which is what the
 * ticket required before anything could be built.
 *
 * NAMED BY FUNCTION, not by skin. GLIDE asked for it; nothing here knows
 * about GLIDE, and any skin can place it.
 *
 * READ THIS BEFORE YOU CALL IT: snap_likes.post_id HOLDS AN IMAGE ID.
 * The column is named post_id and it is not a post id. process-like.php
 * validates the value against snap_images (line 56), and
 * core/community-dock.php:43 sends (int)$img['id']. I nearly shipped this
 * taking a post id, which would have 404ed on every tile or, worse, liked
 * whichever photograph happened to share that number. The parameters here
 * are named image_id so the next person does not have to find that out the
 * same way.
 *
 * NO STATIC style= ANYWHERE. Presentation is assets/css/ss-tile-like.css.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!function_exists('snap_tile_like_enabled')) {

/**
 * Likes must be on for the site, and this asks the question the same way
 * every other reader of these two settings asks it.
 *
 * `!== '0'`, NOT `=== '1'`. Both settings default ON, so they are only off
 * when they say so: a row holding an empty string -- which an admin save can
 * leave -- must not silently turn a feature off for a site that never asked.
 * tests/default-on-switch-regression.php enforces that repo-wide.
 *
 * Muse found this control and process-like.php disagreeing before the branch
 * landed: hearts drawn on a site the endpoint would 403. My first fix made
 * the control strict to match the endpoint, and the default-on gate refused
 * it -- correctly, because the ENDPOINT was the one breaking the rule. It
 * read `!== '1'` while the dock at core/community-component.php:61 reads
 * `!== '0'`, so the dock's like button has been on screen and 403ing on any
 * site with an empty setting. That is fixed at process-like.php:40-41 in this
 * same commit. Both ends now read the same way, and the way they read is the
 * one the gate requires.
 *
 * It also now checks community_enabled, which it never did: likes cannot work
 * with the community switched off, and the endpoint has always said so.
 */
function snap_tile_like_enabled(array $settings): bool {
    return (string)($settings['community_enabled'] ?? '1') !== '0'
        && (string)($settings['community_likes_enabled'] ?? '1') !== '0';
}

/**
 * Which of $post_ids the current visitor has already liked.
 *
 * ONE QUERY FOR THE WHOLE CONVEYOR. A heart per tile asked per tile would be
 * one query per photograph on a wall of forty, which is how a landing page
 * becomes slow. Guests are matched on the same hashed identity
 * process-like.php writes -- no cookie, no session, no PII.
 */
function snap_tile_like_state(PDO $pdo, array $image_ids, ?int $user_id,
                              ?string $guest_hash): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $image_ids))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($user_id) {
        $sql = "SELECT post_id FROM snap_likes WHERE post_id IN ($in) AND user_id = ?";
        $params = array_merge($ids, [$user_id]);
    } elseif ($guest_hash !== null && $guest_hash !== '') {
        $sql = "SELECT post_id FROM snap_likes WHERE post_id IN ($in) AND guest_hash = ?";
        $params = array_merge($ids, [$guest_hash]);
    } else {
        return [];
    }
    try {
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        // A listing must render even if the likes table is missing on an old
        // install. Nothing shows as liked, and the heart still works.
        return [];
    }
}

/**
 * The assets this control needs, for a caller to register.
 *
 * THE COMPONENT DECLARES ITS OWN DEPENDENCIES. A skin that places the heart
 * should not also have to remember which engine draws it -- that is how a
 * control ends up rendered with no behaviour, which is the SS-1315 shape.
 * Hand the result to snapsmack_merge_registered_assets().
 *
 * assets/js/ss-engine-tile-like.js  -- the click, and the suppression of
 *     everything else the click would otherwise do
 * assets/css/ss-tile-like.css       -- placement, hover, the touch rule
 */
function snap_tile_like_assets(): array {
    return [
        'scripts' => ['asset:public:ss-engine-tile-like'],
        'styles'  => ['asset:public:ss-tile-like'],
    ];
}

/**
 * The heart for ONE photograph, as markup a skin drops inside its tile.
 *
 * A <button>, so Enter and Space work and focus lands on it without anything
 * being added. NO COUNT: Sean's spec says the conveyor shows none, and a
 * number beside a heart on every tile is also the thing that makes a wall
 * look like a scoreboard instead of photographs.
 */
function snap_tile_like_html(int $image_id, bool $liked): string {
    if ($image_id <= 0) return '';
    $label = $liked ? 'Unlike this photograph' : 'Like this photograph';
    return '<button type="button" class="ss-tile-like' . ($liked ? ' is-liked' : '')
        . '" data-tile-like="' . $image_id . '"'
        . ' data-liked="' . ($liked ? '1' : '0') . '"'
        . ' aria-pressed="' . ($liked ? 'true' : 'false') . '"'
        . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"'
        . ' title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
        . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
        . '<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 '
        . '3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 '
        . '6.86-8.55 11.54L12 21.35z"/></svg>'
        . '</button>';
}

}
// ===== SNAPSMACK EOF =====
