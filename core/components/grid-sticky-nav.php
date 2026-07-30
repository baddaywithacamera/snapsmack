<?php
/**
 * SNAPSMACK — Shared Grid Sticky Navigation
 *
 * Optional $grid_nav_config keys: prefix, observer_class, identity, links,
 * inline_social.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$grid_nav_config = isset($grid_nav_config) && is_array($grid_nav_config)
    ? $grid_nav_config : [];
$_gn_prefix = preg_replace('/[^a-z0-9_-]/i', '', $grid_nav_config['prefix'] ?? 'ss-grid');
$_gn_observer = preg_replace('/[^a-z0-9_-]/i', '', $grid_nav_config['observer_class'] ?? ($_gn_prefix . '-profile'));
$_gn_identity = (string)($grid_nav_config['identity'] ?? ($settings['photographer_name'] ?? ($settings['site_name'] ?? 'SnapSmack')));
$_gn_identity_url = (string)($grid_nav_config['identity_url'] ?? (defined('BASE_URL') ? BASE_URL : '/'));
$_gn_links = is_array($grid_nav_config['links'] ?? null) ? $grid_nav_config['links'] : [];
$_gn_always_visible = !empty($grid_nav_config['always_visible']);
$_gn_inline_then_sticky = !empty($grid_nav_config['inline_then_sticky']);
?>
<nav class="<?php echo htmlspecialchars($_gn_prefix); ?>-sticky-nav ss-grid-sticky-nav<?php
     echo $_gn_always_visible ? ' ss-grid-nav-always-visible' : '';
     echo $_gn_inline_then_sticky ? ' ss-grid-nav-inline-then-sticky' : '';
     ?>"
     aria-label="Site navigation"
     data-grid-nav-observer="<?php echo htmlspecialchars($_gn_observer); ?>">
    <div class="ss-grid-nav-identity">
        <a class="ss-grid-nav-name" href="<?php echo htmlspecialchars($_gn_identity_url); ?>">
            <?php echo htmlspecialchars($_gn_identity); ?>
        </a>
    </div>
    <div class="ss-grid-nav-links">
        <?php foreach ($_gn_links as $_gn_link):
            $_gn_url = (string)($_gn_link['url'] ?? '#');
            $_gn_label = (string)($_gn_link['label'] ?? 'Link');
            $_gn_icon = (string)($_gn_link['icon'] ?? 'circle');
        ?>
        <a class="ss-grid-nav-link" href="<?php echo htmlspecialchars($_gn_url); ?>"
           title="<?php echo htmlspecialchars($_gn_label); ?>">
            <?php if ($_gn_icon === 'home'): ?>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
            <?php elseif ($_gn_icon === 'archive'): ?>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v13H4zM3 4h18v3H3zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
            <?php elseif ($_gn_icon === 'people'): ?>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 20c0-4 2-6 6-6s6 2 6 6M16 5a3 3 0 0 1 0 6m1 3c2.7.3 4 2.3 4 6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
            <?php endif; ?>
            <span class="ss-grid-nav-label"><?php echo htmlspecialchars($_gn_label); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($grid_nav_config['inline_social'])): ?>
    <div class="ss-grid-nav-actions">
        <?php
        $social_dock_inline = true;
        include dirname(__DIR__) . '/social-dock.php';
        unset($social_dock_inline);
        ?>
    </div>
    <?php endif; ?>
</nav>
<?php // ===== SNAPSMACK EOF =====
