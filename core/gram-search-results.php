<?php
/**
 * SNAPSMACK - Shared Search Results Partial
 *
 * Renders the search bar, matched-tag chips, result count, and the results
 * grid for any skin's search.php. Skin-agnostic: styled with CSS variables
 * (with literal fallbacks) so it adopts each skin's palette/spacing without
 * skin-specific markup. The host skin's search.php supplies the surrounding
 * chrome (skin-meta/header/profile/footer); this only renders the inner
 * search UI.
 *
 * Expects in scope:
 *   $pdo       PDO          — database handle
 *   $settings  array        — site settings (for search_placeholder)
 *   $search_q  string       — the raw query (caller reads $_GET['q'])
 * Optional:
 *   $search_limit int       — max results (default 60)
 *
 * Styling + behaviour ship in assets/css|js/ss-engine-gram-search.* via the
 * smack-gram-search manifest handle.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once dirname(__DIR__) . '/core/search-engine.php';

$gsr_q       = isset($search_q) ? trim((string)$search_q) : trim((string)($_GET['q'] ?? ''));
$gsr_q_safe  = htmlspecialchars($gsr_q);
$gsr_limit   = isset($search_limit) ? (int)$search_limit : 60;
$gsr_ph      = htmlspecialchars($settings['search_placeholder'] ?? 'Search or #tag…');

$gsr = ($gsr_q !== '') ? snapsmack_search($pdo, $gsr_q, $gsr_limit)
                       : ['results' => [], 'tags' => [], 'count' => 0];
?>
<section class="gram-search" aria-label="Search">

    <form class="gram-search-bar" method="GET" action="<?php echo htmlspecialchars(BASE_URL); ?>" role="search">
        <svg class="gram-search-bar-icon" width="18" height="18" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="search" name="q" value="<?php echo $gsr_q_safe; ?>"
               class="gram-search-bar-input" placeholder="<?php echo $gsr_ph; ?>"
               autocomplete="off" autofocus aria-label="Search photos or tags">
        <?php if ($gsr_q !== ''): ?>
            <a href="<?php echo htmlspecialchars(BASE_URL); ?>?q=" class="gram-search-bar-clear" aria-label="Clear search">&times;</a>
        <?php endif; ?>
    </form>

    <?php if ($gsr_q !== ''): ?>

        <?php if (!empty($gsr['tags'])): ?>
        <div class="gram-search-tags">
            <?php foreach ($gsr['tags'] as $mt): ?>
                <a class="gram-search-tag" href="<?php echo htmlspecialchars(BASE_URL); ?>?tag=<?php echo rawurlencode($mt['slug']); ?>">
                    <span class="gram-search-tag-hash">#</span><?php echo htmlspecialchars($mt['slug']); ?>
                    <span class="gram-search-tag-count"><?php echo number_format((int)$mt['use_count']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="gram-search-meta">
            <?php if ($gsr['count'] > 0): ?>
                <?php echo number_format($gsr['count']); ?> result<?php echo $gsr['count'] !== 1 ? 's' : ''; ?> for <strong><?php echo $gsr_q_safe; ?></strong>
            <?php else: ?>
                No results for <strong><?php echo $gsr_q_safe; ?></strong>
            <?php endif; ?>
        </div>

        <?php if (!empty($gsr['results'])): ?>
        <main class="gram-search-grid" aria-label="Search results">
            <?php foreach ($gsr['results'] as $r):
                $thumb = $r['img_thumb_square'] ?: ($r['img_thumb_aspect'] ?: $r['img_file']);
                $url   = BASE_URL . '?s=' . urlencode($r['img_slug'] ?? '');
                $alt   = htmlspecialchars($r['img_title'] ?? '');
            ?>
            <a class="gram-search-cell" href="<?php echo $url; ?>" title="<?php echo $alt; ?>" aria-label="<?php echo $alt; ?>">
                <?php if (!empty($thumb)): ?>
                    <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo $alt; ?>" loading="lazy">
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </main>
        <?php endif; ?>

    <?php else: ?>
        <div class="gram-search-empty">
            <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:.3;">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <p>Search titles, descriptions, #hashtags, or colours</p>
        </div>
    <?php endif; ?>

</section>
<?php // ===== SNAPSMACK EOF =====
