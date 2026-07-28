<?php
/**
 * SNAPSMACK - HEURISTIC shared profile, memory centre, and navigation
 *
 * The public page supplies configuration as data attributes. The shared
 * HEURISTIC engine owns all animation; the skin contains no inline JavaScript.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$show_profile = ($settings['he_profile_header'] ?? '1') === '1';
$show_tagline = ($settings['he_show_tagline'] ?? '1') === '1';

try {
    $_he_count = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
    );
    $_he_count->execute([date('Y-m-d H:i:s')]);
    $post_count = (int) $_he_count->fetchColumn();
} catch (PDOException $e) {
    $post_count = 0;
}

$avatar_path     = $settings['skin_avatar'] ?? '';
$avatar_exists   = $avatar_path && file_exists(dirname(__DIR__, 2) . '/' . $avatar_path);
$avatar_initials = strtoupper(substr($settings['site_name'] ?? 'H', 0, 1));
$avatar_url      = $avatar_exists ? BASE_URL . htmlspecialchars($avatar_path) : '';
$tagline         = trim($settings['site_tagline'] ?? '');
$bio             = trim($settings['site_description'] ?? '');

$_he_mode       = $settings['he_system_mode'] ?? 'live';
$_he_first      = max(8, min(90, (int) ($settings['he_first_delay'] ?? 20)));
$_he_hold       = max(4, min(20, (int) ($settings['he_hold_seconds'] ?? 8)));
$_he_rest       = max(8, min(90, (int) ($settings['he_rest_seconds'] ?? 16)));
$_he_pulses     = max(0, min(3, (int) ($settings['he_pulse_count'] ?? 3)));
$_he_memory     = ($settings['he_memory_activity'] ?? '1') === '1';
$_he_infomatic  = ($settings['he_infomatics'] ?? '1') === '1';
$_he_fallback   = ($settings['he_classic_fallback'] ?? '1') === '1';
$_he_red        = trim($settings['he_memory_red'] ?? '#d7193f');
$_he_calm       = trim($settings['he_calm_color'] ?? '#2a7891');
$_he_fault      = trim($settings['he_fault_color'] ?? '#c7193f');
?>
<style id="he-vars">:root{--he-memory-red:<?php echo htmlspecialchars($_he_red); ?>;--he-calm:<?php echo htmlspecialchars($_he_calm); ?>;--he-fault:<?php echo htmlspecialchars($_he_fault); ?>;}</style>

<div class="he-memory-centre" aria-hidden="true"
     data-heuristic-memory
     data-mode="<?php echo htmlspecialchars($_he_mode); ?>"
     data-first-delay="<?php echo $_he_first; ?>"
     data-hold="<?php echo $_he_hold; ?>"
     data-rest="<?php echo $_he_rest; ?>"
     data-pulses="<?php echo $_he_pulses; ?>"
     data-memory="<?php echo $_he_memory ? '1' : '0'; ?>"
     data-infomatics="<?php echo $_he_infomatic ? '1' : '0'; ?>"
     data-classic-fallback="<?php echo $_he_fallback ? '1' : '0'; ?>"></div>

<div class="he-panel" aria-hidden="true"></div>

<?php if ($show_profile): ?>
<section class="he-profile">
    <div class="he-profile-avatar<?php echo $avatar_exists ? ' he-profile-avatar--zoom' : ''; ?>"
         <?php if ($avatar_exists): ?>role="button" tabindex="0"
         aria-label="View profile photo"
         data-he-lightbox="<?php echo $avatar_url; ?>"<?php endif; ?>>
        <?php if ($avatar_exists): ?>
            <img src="<?php echo $avatar_url; ?>" alt="Profile avatar">
        <?php else: ?>
            <span class="he-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
    </div>
    <div class="he-profile-info">
        <div class="he-profile-nameline">
            <h1 class="he-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>
            <?php if ($show_tagline && $tagline): ?>
                <span class="he-profile-tagline-sep">/</span>
                <p class="he-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
            <?php endif; ?>
        </div>
        <div class="he-profile-stats">
            <span class="he-profile-stat-num"><?php echo number_format($post_count); ?></span>
            <span class="he-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($bio): ?>
            <p class="he-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<nav class="he-sticky-nav" aria-label="Site navigation">
    <div class="he-sticky-nav-inner">
        <?php if ($avatar_exists): ?>
            <img class="he-sticky-avatar" src="<?php echo $avatar_url; ?>"
                 alt="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>" aria-hidden="true">
        <?php else: ?>
            <span class="he-sticky-avatar-initials" aria-hidden="true"><?php echo htmlspecialchars($avatar_initials); ?></span>
        <?php endif; ?>
        <ul class="he-sticky-nav-links">
            <?php include dirname(__DIR__, 2) . '/core/gram-nav-links.php'; ?>
        </ul>
    </div>
</nav>
<?php // ===== SNAPSMACK EOF =====
