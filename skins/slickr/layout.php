<?php
/**
 * SNAPSMACK - Slickr Single Image View Flow
 * Spec v0.1 — Flickr visual idiom clone for archive migrations.
 *
 * Traditional open scroll layout flow. No interlocking sliding panels.
 * Integrates natively with core checked-out community scripts.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 * <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once dirname(__DIR__, 2) . '/core/layout-logic.php';

$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);

$show_desc = ($settings['single_show_description'] ?? '1') === '1';
$show_exif = ($settings['show_exif_panel'] ?? '1') === '1';
$show_geo  = ($settings['show_geo_link'] ?? '1') === '1';
$show_prov = ($settings['show_provenance_footer'] ?? '1') === '1';

// Technical property mapping dictionary
$exif_labels = [
    'Model' => 'Camera', 
    'lens' => 'Lens', 
    'FNumber' => 'Aperture',
    'ExposureTime' => 'Shutter', 
    'ISOSpeedRatings' => 'ISO',
    'FocalLength' => 'Focal Length', 
    'film' => 'Film'
];
?>

<div id="scroll-stage" class="sl-single-flow">

    <?php include('skin-header.php'); ?>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>

    <div id="rg-photobox" style="background-color: <?php echo htmlspecialchars($settings['solo_bg_color'] ?? '#1a1a1a'); ?>;">
        <div class="rg-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img class="rg-image post-image"
                 src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>">
            <?php echo $download_button; ?>
        </div>
    </div>

    <div class="sl-focus-container">
        
        <main class="sl-main-column">
            <h1 class="sl-photo-title"><?php echo htmlspecialchars($img['img_title']); ?></h1>
            
            <div class="sl-byline">
                Taken on <?php echo date('F j, Y', strtotime($img['img_date'])); ?>
            </div>

            <?php if ($show_desc && !empty($img['img_description'])): ?>
                <div class="sl-description-block">
                    <?php echo $snapsmack->parseContent($img['img_description']); ?>
                </div>
            <?php endif; ?>

            <?php if ($comments_active): ?>
                <div class="sl-comments-region">
                    <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
                </div>
            <?php endif; ?>
        </main>

        <aside class="sl-sidebar">
            
            <?php if (!empty($img_albums) && is_array($img_albums)): ?>
                <div class="sl-sidebar-block">
                    <h4>this photo belongs to</h4>
                    <ul class="sl-sidebar-links">
                        <?php foreach ($img_albums as $alb): ?>
                            <li><a href="<?php echo BASE_URL . 'albums/' . htmlspecialchars($alb['album_slug']); ?>"><?php echo htmlspecialchars($alb['album_name']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($img_tags) && is_array($img_tags)): ?>
                <div class="sl-sidebar-block">
                    <h4>tags</h4>
                    <div class="sl-tag-cloud">
                        <?php foreach ($img_tags as $t): ?>
                            <a href="<?php echo BASE_URL . 'tag/' . htmlspecialchars($t['tag_slug']); ?>" class="sl-sidebar-tag"><?php echo htmlspecialchars($t['tag_name']); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($show_exif && !empty($exif_data)): ?>
                <div class="sl-sidebar-block">
                    <h4>technical details</h4>
                    <table class="sl-metadata-table">
                        <tbody>
                            <?php foreach ($exif_labels as $key => $label): ?>
                                <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                    <tr>
                                        <td><?php echo $label; ?></td>
                                        <td><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($show_geo && !empty($exif_data['latitude']) && !empty($exif_data['longitude'])): ?>
                        <div class="sl-geo-routing">
                            <a href="https://www.openstreetmap.org/?mlat=<?php echo $exif_data['latitude']; ?>&mlon=<?php echo $exif_data['longitude']; ?>#map=16/<?php echo $exif_data['latitude']; ?>/<?php echo $exif_data['longitude']; ?>" target="_blank" rel="noopener">
                                View location on OpenStreetMap
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_prov && !empty($img['img_source_file']) && strpos($img['img_source_file'], 'flickr:') === 0): 
                $flickr_id = str_replace('flickr:', '', $img['img_source_file']);
            ?>
                <div class="sl-sidebar-block sl-provenance">
                    <p>Imported from Flickr. Original asset ID: <code><?php echo htmlspecialchars($flickr_id); ?></code></p>
                </div>
            <?php endif; ?>

        </aside>
    </div>

    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <?php include('skin-footer.php'); ?>
</div>
<?php // ===== SNAPSMACK EOF =====