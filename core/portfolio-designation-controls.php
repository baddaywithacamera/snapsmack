<?php
/**
 * SNAPSMACK - PORTFOLIO DESIGNATION CONTROLS (post editor fieldset)
 *
 * SS-1185. The six portfolio fields have existed in snap_posts since the
 * routing work landed -- portfolio_post, the three exclude_* flags, and the
 * two primary_* ids -- and core/portfolio-route.php reads them, the public
 * surfaces honour them, and core/smackpress-api.php writes them.
 *
 * NOTHING IN THE ADMIN COULD SET THEM. Measured on dev 39a23e8a: zero
 * occurrences of any of the six in smack-post-gram.php, smack-post-long.php
 * or smack-post-solo.php. The only writer was the API, so the owner could not
 * mark one of his own galleries as a portfolio from his own admin -- he would
 * have had to drive the API by hand, six times.
 *
 * NAMED BY FUNCTION, NOT BY SKIN OR BY PAGE, and shared rather than pasted
 * into each editor, because the fault this repairs is one surface having a
 * control another surface does not.
 *
 * THE TWO EXCLUSIONS GOVERN DIFFERENT SURFACES AND THE LABELS SAY SO.
 * Muse caught the trap: the blog listing is longformLanding(), which filters
 * on exclude_from_archive (core/public-repository.php:538), while
 * exclude_from_feed governs RSS and the public profile. That split is
 * deliberate and the column comments document it. But Sean calls the blog
 * listing "the feed" -- his words were "Portfolio currently appears in the
 * blog feed" -- so a control labelled KEEP OUT OF THE FEED is exactly the one
 * he would tick, and it would not do what he wanted. The labels now name the
 * surface each flag actually governs. Behaviour is unchanged; the words are
 * the fix.
 *
 * NO STATIC style= ANYWHERE HERE. It uses the admin classes the geometry
 * stylesheet already defines (.option-group, .toggle-row, .toggle-label,
 * .toggle-desc, .lens-input-wrapper), so nothing in assets/css has to change
 * and this does not touch the appearance lane.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!function_exists('snap_portfolio_control_fields')) {

/** The six columns this fieldset owns, in the order they are written. */
function snap_portfolio_control_fields(): array {
    return ['portfolio_post', 'exclude_from_archive', 'exclude_from_search',
            'exclude_from_feed', 'primary_album_id', 'primary_category_id'];
}

/**
 * Read the fieldset out of a submitted form.
 *
 * ABSENT IS NOT THE SAME AS OFF. An editor that does not render this fieldset
 * must not silently clear a designation the API or another editor set, so the
 * caller passes the row it is updating and anything the form did not carry
 * keeps its stored value. That is the same contract core/smackpress-api.php
 * gives older clients, and for the same reason.
 *
 * @param array $post    normally $_POST
 * @param array $stored  the row as it is now; [] when creating
 * @return array column => value, ready for a prepared statement
 */
function snap_portfolio_control_read(array $post, array $stored = []): array {
    // A rendered fieldset always posts this marker, so its absence means the
    // form had no fieldset rather than every box being unticked.
    $present = array_key_exists('portfolio_controls_present', $post);
    $out = [];
    foreach (['portfolio_post', 'exclude_from_archive', 'exclude_from_search',
              'exclude_from_feed'] as $flag) {
        $out[$flag] = $present
            ? (!empty($post[$flag]) ? 1 : 0)
            : (int)($stored[$flag] ?? 0);
    }
    foreach (['primary_album_id', 'primary_category_id'] as $id) {
        if (!$present) {
            $out[$id] = isset($stored[$id]) && $stored[$id] !== null
                ? (int)$stored[$id] : null;
            continue;
        }
        $raw = trim((string)($post[$id] ?? ''));
        $out[$id] = ($raw === '' || (int)$raw <= 0) ? null : (int)$raw;
    }
    return $out;
}

/**
 * Print the fieldset. $row is the post being edited, or [] when creating.
 *
 * $albums and $categories are [['id'=>int,'name'=>string], ...]. An empty list
 * is fine and renders the "not designated" choice alone -- a site with no
 * albums must still be able to tick the flags.
 */
function snap_portfolio_controls_render(array $row, array $albums = [],
                                        array $categories = []): void {
    $on = static fn(string $k): string =>
        !empty($row[$k]) ? ' checked' : '';
    $sel = static function ($current, $id): string {
        return ((int)$current === (int)$id) ? ' selected' : '';
    };
    ?>
    <div class="option-group">
      <input type="hidden" name="portfolio_controls_present" value="1">
      <div class="toggle-row">
        <div>
          <div class="toggle-label">PORTFOLIO PAGE</div>
          <div class="toggle-desc">A standing page rather than a dated entry.
            This marks it only &mdash; on its own it hides nothing.</div>
        </div>
        <input type="checkbox" name="portfolio_post" value="1"<?php echo $on('portfolio_post'); ?>>
      </div>
      <div class="toggle-row">
        <div>
          <div class="toggle-label">KEEP OUT OF THE BLOG LISTING AND ARCHIVE</div>
          <div class="toggle-desc"><strong>This is the one that takes it off the
            front page.</strong> It also removes its photographs from the photo
            archive and the taxonomy counts. The post&rsquo;s own address always
            still works.</div>
        </div>
        <input type="checkbox" name="exclude_from_archive" value="1"<?php echo $on('exclude_from_archive'); ?>>
      </div>
      <div class="toggle-row">
        <div>
          <div class="toggle-label">KEEP OUT OF SEARCH</div>
          <div class="toggle-desc">Both halves of site search.</div>
        </div>
        <input type="checkbox" name="exclude_from_search" value="1"<?php echo $on('exclude_from_search'); ?>>
      </div>
      <div class="toggle-row">
        <div>
          <div class="toggle-label">KEEP OUT OF THE RSS FEED</div>
          <div class="toggle-desc"><strong>RSS and the public profile listing
            only &mdash; not the blog page.</strong> To take a portfolio off the
            front page, use the setting above. This is also NOT the fediverse
            switch: delivery is governed by the fediverse setting, and two
            switches for one surface would drift.</div>
        </div>
        <input type="checkbox" name="exclude_from_feed" value="1"<?php echo $on('exclude_from_feed'); ?>>
      </div>

      <div class="lens-input-wrapper">
        <label>CANONICAL ALBUM</label>
        <select name="primary_album_id" class="full-width-select">
          <option value="">NOT DESIGNATED &mdash; keep the flat address</option>
          <?php foreach ($albums as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>"<?php
              echo $sel($row['primary_album_id'] ?? 0, $a['id']); ?>><?php
              echo htmlspecialchars((string)$a['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="lens-input-wrapper">
        <label>CANONICAL CATEGORY</label>
        <select name="primary_category_id" class="full-width-select">
          <option value="">NOT DESIGNATED</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>"<?php
              echo $sel($row['primary_category_id'] ?? 0, $c['id']); ?>><?php
              echo htmlspecialchars((string)$c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="toggle-desc">A photograph can sit in several albums, so the
        address has to come from one you pick. The system never guesses: an
        automatic choice would move a live address by itself the moment a
        membership changed.</div>
    </div>
    <?php
}

}
// ===== SNAPSMACK EOF =====
