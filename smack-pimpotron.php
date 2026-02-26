<?php
/**
 * SNAPSMACK - Pimpotron slideshow manager.
 * Orchestrates the creation and configuration of slideshows for the frontend engine.
 * Manages master glitch/shift settings and individual slide configurations (up to 10 max).
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// -------------------------------------------------------------------------
// PULL FONT INVENTORY
// -------------------------------------------------------------------------
$inventory = include 'core/manifest-inventory.php';
$fonts     = $inventory['fonts'] ?? [];

// -------------------------------------------------------------------------
// PULL ALL SLIDESHOWS
// -------------------------------------------------------------------------
$slideshows = $pdo->query("
    SELECT s.*,
           COUNT(sl.id) as slide_count
    FROM   snap_pimpotron_slideshows s
    LEFT JOIN snap_pimpotron_slides sl ON sl.slideshow_id = s.id
    GROUP BY s.id
    ORDER BY s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// ACTIVE SLIDESHOW (from ?sid= param)
// -------------------------------------------------------------------------
$active_sid       = isset($_GET['sid']) ? (int)$_GET['sid'] : ($slideshows[0]['id'] ?? null);
$active_slideshow = null;
$slides           = [];

if ($active_sid) {
    $stmt = $pdo->prepare("SELECT * FROM snap_pimpotron_slideshows WHERE id = ?");
    $stmt->execute([$active_sid]);
    $active_slideshow = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM snap_pimpotron_slides WHERE slideshow_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$active_sid]);
    $slides = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------------------
// PULL IMAGES FOR PICKER (most recent 60)
// -------------------------------------------------------------------------
$images = $pdo->query("
    SELECT id, img_file, img_title
    FROM   snap_images
    ORDER BY id DESC
    LIMIT 60
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// POST: CREATE SLIDESHOW
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_slideshow') {
    $name = trim($_POST['name'] ?? 'New Slideshow');
    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name));

    // Ensure unique slug
    $existing = $pdo->prepare("SELECT COUNT(*) FROM snap_pimpotron_slideshows WHERE slug = ?");
    $existing->execute([$slug]);
    if ($existing->fetchColumn() > 0) {
        $slug .= '-' . time();
    }

    $stmt = $pdo->prepare("
        INSERT INTO snap_pimpotron_slideshows
            (name, slug, default_speed_ms, glitch_frequency, glitch_intensity,
             stage_shift_enabled, stage_shift_max_px, stage_scale_max, slideshow_font)
        VALUES (?, ?, 5000, 'occasional', 'normal', 0, 8, 1.015, 'Stalinist One')
    ");
    $stmt->execute([$name, $slug]);
    $new_id = $pdo->lastInsertId();

    header("Location: smack-pimpotron.php?sid={$new_id}&msg=CREATED");
    exit;
}

// -------------------------------------------------------------------------
// POST: SAVE SLIDESHOW SETTINGS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_slideshow' && $active_sid) {
    $stmt = $pdo->prepare("
        UPDATE snap_pimpotron_slideshows SET
            name                 = ?,
            default_speed_ms     = ?,
            glitch_frequency     = ?,
            glitch_intensity     = ?,
            stage_shift_enabled  = ?,
            stage_shift_max_px   = ?,
            stage_scale_max      = ?,
            slideshow_font       = ?
        WHERE id = ?
    ");
    $stmt->execute([
        trim($_POST['name']                ?? 'Untitled'),
        (int)($_POST['default_speed_ms']   ?? 5000),
        $_POST['glitch_frequency']         ?? 'occasional',
        $_POST['glitch_intensity']         ?? 'normal',
        isset($_POST['stage_shift_enabled']) ? 1 : 0,
        (int)($_POST['stage_shift_max_px'] ?? 8),
        (float)($_POST['stage_scale_max']  ?? 1.015),
        $_POST['slideshow_font']           ?? 'Stalinist One',
        $active_sid
    ]);

    header("Location: smack-pimpotron.php?sid={$active_sid}&msg=SAVED");
    exit;
}

// -------------------------------------------------------------------------
// POST: DELETE SLIDESHOW
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_slideshow' && $active_sid) {
    $pdo->prepare("DELETE FROM snap_pimpotron_slides WHERE slideshow_id = ?")->execute([$active_sid]);
    $pdo->prepare("DELETE FROM snap_pimpotron_slideshows WHERE id = ?")->execute([$active_sid]);
    header("Location: smack-pimpotron.php?msg=DELETED");
    exit;
}

// -------------------------------------------------------------------------
// POST: SAVE SLIDE (add or update)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_slide' && $active_sid) {

    // Enforce 10 slide max on new slides
    $slide_id = (int)($_POST['slide_id'] ?? 0);
    if (!$slide_id) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM snap_pimpotron_slides WHERE slideshow_id = ?");
        $count->execute([$active_sid]);
        if ($count->fetchColumn() >= 10) {
            header("Location: smack-pimpotron.php?sid={$active_sid}&err=MAX_SLIDES");
            exit;
        }
    }

    $data = [
        'slideshow_id'          => $active_sid,
        'slide_type'            => $_POST['slide_type']            ?? 'image',
        'snap_image_id'         => !empty($_POST['snap_image_id'])  ? (int)$_POST['snap_image_id'] : null,
        'external_image_url'    => trim($_POST['external_image_url'] ?? '') ?: null,
        'video_url'             => trim($_POST['video_url']          ?? '') ?: null,
        'video_autoplay'        => isset($_POST['video_autoplay'])   ? 1 : 0,
        'video_loop'            => isset($_POST['video_loop'])       ? 1 : 0,
        'video_muted'           => isset($_POST['video_muted'])      ? 1 : 0,
        'bg_color_hex'          => $_POST['bg_color_hex']            ?? '#000000',
        'rain_speed'            => !empty($_POST['rain_speed'])       ? (int)$_POST['rain_speed']    : null,
        'rain_density'          => !empty($_POST['rain_density'])     ? (int)$_POST['rain_density']  : null,
        'rain_color_hex'        => !empty($_POST['rain_color_hex'])   ? $_POST['rain_color_hex']     : null,
        'image_glitch_enabled'  => isset($_POST['image_glitch_enabled']) ? 1 : 0,
        'overlay_text'          => trim($_POST['overlay_text']        ?? '') ?: null,
        'text_animation_type'   => $_POST['text_animation_type']      ?? 'staccato',
        'word_delay_ms'         => (int)($_POST['word_delay_ms']      ?? 200),
        'font_color_hex'        => $_POST['font_color_hex']           ?? '#FFFFFF',
        'pos_x_pct'             => (int)($_POST['pos_x_pct']          ?? 50),
        'pos_y_pct'             => (int)($_POST['pos_y_pct']          ?? 50),
        'display_duration_ms'   => !empty($_POST['display_duration_ms']) ? (int)$_POST['display_duration_ms'] : null,
        'glitch_frequency'      => !empty($_POST['glitch_frequency'])    ? $_POST['glitch_frequency']         : null,
        'glitch_intensity'      => !empty($_POST['glitch_intensity'])    ? $_POST['glitch_intensity']         : null,
        'stage_shift_enabled'   => isset($_POST['stage_shift_enabled']) ? 1 : 0,
        'sort_order'            => (int)($_POST['sort_order']            ?? 0),
    ];

    if ($slide_id) {
        $stmt = $pdo->prepare("
            UPDATE snap_pimpotron_slides SET
                slide_type = :slide_type, snap_image_id = :snap_image_id,
                external_image_url = :external_image_url, video_url = :video_url,
                video_autoplay = :video_autoplay, video_loop = :video_loop, video_muted = :video_muted,
                bg_color_hex = :bg_color_hex, rain_speed = :rain_speed, rain_density = :rain_density,
                rain_color_hex = :rain_color_hex, image_glitch_enabled = :image_glitch_enabled,
                overlay_text = :overlay_text, text_animation_type = :text_animation_type,
                word_delay_ms = :word_delay_ms, font_color_hex = :font_color_hex,
                pos_x_pct = :pos_x_pct, pos_y_pct = :pos_y_pct,
                display_duration_ms = :display_duration_ms, glitch_frequency = :glitch_frequency,
                glitch_intensity = :glitch_intensity, stage_shift_enabled = :stage_shift_enabled,
                sort_order = :sort_order
            WHERE id = :id AND slideshow_id = :slideshow_id
        ");
        $data['id'] = $slide_id;
        $stmt->execute($data);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO snap_pimpotron_slides
                (slideshow_id, slide_type, snap_image_id, external_image_url, video_url,
                 video_autoplay, video_loop, video_muted, bg_color_hex, rain_speed, rain_density,
                 rain_color_hex, image_glitch_enabled, overlay_text, text_animation_type,
                 word_delay_ms, font_color_hex, pos_x_pct, pos_y_pct, display_duration_ms,
                 glitch_frequency, glitch_intensity, stage_shift_enabled, sort_order)
            VALUES
                (:slideshow_id, :slide_type, :snap_image_id, :external_image_url, :video_url,
                 :video_autoplay, :video_loop, :video_muted, :bg_color_hex, :rain_speed, :rain_density,
                 :rain_color_hex, :image_glitch_enabled, :overlay_text, :text_animation_type,
                 :word_delay_ms, :font_color_hex, :pos_x_pct, :pos_y_pct, :display_duration_ms,
                 :glitch_frequency, :glitch_intensity, :stage_shift_enabled, :sort_order)
        ");
        $stmt->execute($data);
    }

    header("Location: smack-pimpotron.php?sid={$active_sid}&msg=SLIDE_SAVED");
    exit;
}

// -------------------------------------------------------------------------
// POST: DELETE SLIDE
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_slide') {
    $slide_id = (int)($_POST['slide_id'] ?? 0);
    if ($slide_id && $active_sid) {
        $pdo->prepare("DELETE FROM snap_pimpotron_slides WHERE id = ? AND slideshow_id = ?")->execute([$slide_id, $active_sid]);
    }
    header("Location: smack-pimpotron.php?sid={$active_sid}&msg=SLIDE_DELETED");
    exit;
}

// -------------------------------------------------------------------------
// POST: REORDER SLIDES (called via JS fetch)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reorder_slides') {
    $order = json_decode($_POST['order'] ?? '[]', true);
    foreach ($order as $position => $slide_id) {
        $pdo->prepare("UPDATE snap_pimpotron_slides SET sort_order = ? WHERE id = ? AND slideshow_id = ?")
            ->execute([$position, (int)$slide_id, $active_sid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// -------------------------------------------------------------------------
// ACTIVE SLIDE FOR EDITING (from ?edit_slide=)
// -------------------------------------------------------------------------
$editing_slide = null;
if (isset($_GET['edit_slide']) && $active_sid) {
    $stmt = $pdo->prepare("SELECT * FROM snap_pimpotron_slides WHERE id = ? AND slideshow_id = ?");
    $stmt->execute([(int)$_GET['edit_slide'], $active_sid]);
    $editing_slide = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title = "Pimpotron";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>
<div class="main">
    <h2>PIMPOTRON</h2>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] === 'MAX_SLIDES'): ?>
        <div class="alert alert-error">> MAXIMUM 10 SLIDES PER SLIDESHOW. DELETE ONE FIRST.</div>
    <?php endif; ?>

    <div class="box">
        <h3>SLIDESHOWS</h3>
        <div class="dash-grid">
            <div class="lens-input-wrapper">
                <label>ACTIVE SLIDESHOW</label>
                <select onchange="window.location='smack-pimpotron.php?sid='+this.value">
                    <?php foreach ($slideshows as $ss): ?>
                        <option value="<?php echo $ss['id']; ?>" <?php echo ($ss['id'] == $active_sid) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(strtoupper($ss['name'])); ?>
                            (<?php echo $ss['slide_count']; ?>/10 SLIDES)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lens-input-wrapper">
                <label>CREATE NEW SLIDESHOW</label>
                <div style="display:flex;gap:10px;align-items:stretch;">
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="action" value="create_slideshow">
                        <input type="text" name="name" placeholder="SLIDESHOW NAME" style="flex:1;min-width:0;margin:0;">
                        <button type="submit" class="btn-smack" style="margin:0;width:auto;flex-shrink:0;">CREATE</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($active_slideshow): ?>

    <form method="POST">
        <input type="hidden" name="action" value="save_slideshow">
        <div class="box">
            <h3>MASTER SETTINGS<?php echo !empty($active_slideshow['name']) ? ' — ' . htmlspecialchars(strtoupper($active_slideshow['name'])) : ''; ?></h3>

            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>SLIDESHOW NAME</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($active_slideshow['name']); ?>">
                </div>
                <div class="lens-input-wrapper">
                    <label>DEFAULT SLIDE DURATION (MS)</label>
                    <input type="number" name="default_speed_ms" value="<?php echo $active_slideshow['default_speed_ms']; ?>" min="1000" max="30000" step="500">
                </div>
                <div class="lens-input-wrapper">
                    <label>SLIDESHOW FONT (ALL SLIDES)</label>
                    <select name="slideshow_font">
                        <?php foreach ($fonts as $fval => $flabel): ?>
                            <option value="<?php echo htmlspecialchars($fval); ?>"
                                <?php echo ($active_slideshow['slideshow_font'] === $fval) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($flabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="dim" style="font-size:0.75em;margin-top:4px;">ONE FONT. LOCKED. NO EXCEPTIONS.</p>
                </div>
            </div>

            <h3 style="margin-top:20px;">MASTER GLITCH DEFAULTS</h3>
            <p class="dim" style="font-size:0.8em;margin-bottom:12px;">Per-slide overrides will trump these. Leave slide fields blank to inherit.</p>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>DEFAULT GLITCH FREQUENCY</label>
                    <select name="glitch_frequency">
                        <?php foreach (['every_slide'=>'Every Slide','occasional'=>'Occasional (50%)','rare'=>'Rare (20%)','random'=>'Random (Chaos)'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($active_slideshow['glitch_frequency']===$v)?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>DEFAULT GLITCH INTENSITY</label>
                    <select name="glitch_intensity">
                        <?php foreach (['subtle'=>'Subtle','normal'=>'Normal','violent'=>'Violent'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($active_slideshow['glitch_intensity']===$v)?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>STAGE SHIFT (MASTER)</label>
                    <select name="stage_shift_enabled">
                        <option value="1" <?php echo $active_slideshow['stage_shift_enabled'] ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo !$active_slideshow['stage_shift_enabled'] ? 'selected' : ''; ?>>DISABLED</option>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>MAX SHIFT DISTANCE (PX)</label>
                    <input type="number" name="stage_shift_max_px" value="<?php echo $active_slideshow['stage_shift_max_px']; ?>" min="1" max="40">
                </div>
                <div class="lens-input-wrapper">
                    <label>MAX SCALE FACTOR</label>
                    <input type="number" name="stage_scale_max" value="<?php echo $active_slideshow['stage_scale_max']; ?>" min="1.001" max="1.05" step="0.001">
                </div>
            </div>

            <div class="action-grid-dual">
                <button type="submit" class="master-update-btn">SAVE MASTER SETTINGS</button>
                <button type="submit" form="delete-slideshow-form" class="btn-smack btn-backup" onclick="return confirm('DELETE THIS SLIDESHOW AND ALL ITS SLIDES?')">DELETE SLIDESHOW</button>
            </div>
        </div>
    </form>
    <form method="POST" id="delete-slideshow-form">
        <input type="hidden" name="action" value="delete_slideshow">
    </form>

    <div class="box">
        <h3>SLIDES (<?php echo count($slides); ?>/10)</h3>

        <?php if (empty($slides)): ?>
            <p class="dim">NO SLIDES YET. ADD ONE BELOW.</p>
        <?php else: ?>
        <table class="smack-table" id="slide-sort-table">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>TYPE</th>
                    <th>CONTENT</th>
                    <th>OVERLAY</th>
                    <th>DURATION</th>
                    <th>GLITCH</th>
                    <th>ACTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slides as $i => $sl): ?>
                <tr data-slide-id="<?php echo $sl['id']; ?>" style="cursor:grab;">
                    <td class="drag-handle">&#8597; <?php echo $i + 1; ?></td>
                    <td><?php echo strtoupper($sl['slide_type']); ?></td>
                    <td>
                        <?php if ($sl['slide_type'] === 'image'): ?>
                            <?php if ($sl['snap_image_id']): ?>
                                IMG #<?php echo $sl['snap_image_id']; ?>
                            <?php elseif ($sl['external_image_url']): ?>
                                <span class="dim" title="<?php echo htmlspecialchars($sl['external_image_url']); ?>">EXTERNAL URL</span>
                            <?php endif; ?>
                        <?php elseif ($sl['slide_type'] === 'video'): ?>
                            <span class="dim" title="<?php echo htmlspecialchars($sl['video_url']); ?>">VIDEO</span>
                        <?php elseif ($sl['slide_type'] === 'matrix'): ?>
                            <span style="color:<?php echo $sl['rain_color_hex'] ?? '#00FF00'; ?>;">&#9608;</span> RAIN
                        <?php else: ?>
                            <span style="background:<?php echo $sl['bg_color_hex']; ?>;width:14px;height:14px;display:inline-block;border:1px solid #444;"></span> TEXT
                        <?php endif; ?>
                    </td>
                    <td><?php echo $sl['overlay_text'] ? htmlspecialchars(mb_strimwidth($sl['overlay_text'], 0, 20, '…')) : '<span class="dim">—</span>'; ?></td>
                    <td><?php echo $sl['display_duration_ms'] ? $sl['display_duration_ms'].'ms' : '<span class="dim">DEFAULT</span>'; ?></td>
                    <td>
                        <?php echo $sl['glitch_intensity'] ? strtoupper($sl['glitch_intensity']) : '<span class="dim">INHERIT</span>'; ?>
                        /
                        <?php echo $sl['glitch_frequency'] ? strtoupper($sl['glitch_frequency']) : '<span class="dim">INHERIT</span>'; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="smack-pimpotron.php?sid=<?php echo $active_sid; ?>&edit_slide=<?php echo $sl['id']; ?>" class="action-edit">EDIT</a>
                        <form method="POST" style="display:inline;margin-left:6px;" onsubmit="return confirm('DELETE THIS SLIDE?');">
                            <input type="hidden" name="action" value="delete_slide">
                            <input type="hidden" name="slide_id" value="<?php echo $sl['id']; ?>">
                            <button type="submit" class="action-delete">DEL</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="box" id="slide-editor">
        <h3><?php echo $editing_slide ? 'EDIT SLIDE #'.$editing_slide['id'] : 'ADD NEW SLIDE'; ?></h3>

        <form method="POST" id="slide-form">
            <input type="hidden" name="action" value="save_slide">
            <input type="hidden" name="slide_id" value="<?php echo $editing_slide['id'] ?? 0; ?>">

            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>SLIDE TYPE</label>
                    <select name="slide_type" id="slide-type-select" onchange="pimpShowFields(this.value)">
                        <?php foreach (['image'=>'Image','text'=>'Text Only','video'=>'Video','matrix'=>'Matrix Rain'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo (($editing_slide['slide_type']??'image')===$v)?'selected':''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>SORT ORDER</label>
                    <input type="number" name="sort_order" value="<?php echo $editing_slide['sort_order'] ?? count($slides); ?>" min="0" max="9">
                </div>

                <div class="lens-input-wrapper">
                    <label>DURATION OVERRIDE (MS, BLANK = INHERIT)</label>
                    <input type="number" name="display_duration_ms" value="<?php echo $editing_slide['display_duration_ms'] ?? ''; ?>" min="500" max="60000" step="500" placeholder="<?php echo $active_slideshow['default_speed_ms']; ?>">
                </div>

            </div>

            <div class="pimp-field-group" id="fields-image">
                <h4>IMAGE SOURCE</h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>SNAPSMACK IMAGE</label>
                        <select name="snap_image_id">
                            <option value="">— NONE / USE EXTERNAL URL —</option>
                            <?php foreach ($images as $img): ?>
                                <option value="<?php echo $img['id']; ?>"
                                    <?php echo (($editing_slide['snap_image_id']??null)==$img['id'])?'selected':''; ?>>
                                    #<?php echo $img['id']; ?> — <?php echo htmlspecialchars(mb_strimwidth($img['img_title']??'Untitled',0,40,'…')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>OR EXTERNAL IMAGE URL</label>
                        <input type="text" name="external_image_url" value="<?php echo htmlspecialchars($editing_slide['external_image_url']??''); ?>" placeholder="https://...">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>IMAGE GLITCH ON TRANSITION</label>
                        <select name="image_glitch_enabled">
                            <option value="1" <?php echo (($editing_slide['image_glitch_enabled']??1)==1)?'selected':''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($editing_slide['image_glitch_enabled']??1)==0)?'selected':''; ?>>DISABLED</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="pimp-field-group" id="fields-video">
                <h4>VIDEO SOURCE</h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>VIDEO URL</label>
                        <input type="text" name="video_url" value="<?php echo htmlspecialchars($editing_slide['video_url']??''); ?>" placeholder="https://...">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>AUTOPLAY</label>
                        <select name="video_autoplay">
                            <option value="1" <?php echo (($editing_slide['video_autoplay']??1)==1)?'selected':''; ?>>YES</option>
                            <option value="0" <?php echo (($editing_slide['video_autoplay']??1)==0)?'selected':''; ?>>NO</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>LOOP</label>
                        <select name="video_loop">
                            <option value="1" <?php echo (($editing_slide['video_loop']??1)==1)?'selected':''; ?>>YES</option>
                            <option value="0" <?php echo (($editing_slide['video_loop']??1)==0)?'selected':''; ?>>NO</option>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>MUTED</label>
                        <select name="video_muted">
                            <option value="1" <?php echo (($editing_slide['video_muted']??1)==1)?'selected':''; ?>>YES</option>
                            <option value="0" <?php echo (($editing_slide['video_muted']??1)==0)?'selected':''; ?>>NO</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="pimp-field-group" id="fields-matrix">
                <h4>MATRIX RAIN CONFIG</h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>RAIN COLOUR</label>
                        <div class="color-picker-container">
                            <input type="color" name="rain_color_hex" value="<?php echo $editing_slide['rain_color_hex']??'#00FF00'; ?>">
                            <span class="hex-display"><?php echo strtoupper($editing_slide['rain_color_hex']??'#00FF00'); ?></span>
                        </div>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BACKGROUND COLOUR</label>
                        <div class="color-picker-container">
                            <input type="color" name="bg_color_hex" value="<?php echo $editing_slide['bg_color_hex']??'#000000'; ?>">
                            <span class="hex-display"><?php echo strtoupper($editing_slide['bg_color_hex']??'#000000'); ?></span>
                        </div>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>RAIN SPEED (MS/TICK, LOWER = FASTER)</label>
                        <input type="number" name="rain_speed" value="<?php echo $editing_slide['rain_speed']??150; ?>" min="16" max="500">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>RAIN DENSITY (1-100)</label>
                        <input type="number" name="rain_density" value="<?php echo $editing_slide['rain_density']??20; ?>" min="1" max="100">
                    </div>
                </div>
            </div>

            <div class="pimp-field-group" id="fields-text">
                <h4>TEXT SLIDE BACKGROUND</h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>BACKGROUND COLOUR</label>
                        <div class="color-picker-container">
                            <input type="color" name="bg_color_hex" value="<?php echo $editing_slide['bg_color_hex']??'#000000'; ?>">
                            <span class="hex-display"><?php echo strtoupper($editing_slide['bg_color_hex']??'#000000'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box" style="margin-top:20px;">
                <h4>OVERLAY TEXT</h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper" style="grid-column:1/-1;">
                        <label>TEXT (BLANK = NO OVERLAY)</label>
                        <input type="text" name="overlay_text" value="<?php echo htmlspecialchars($editing_slide['overlay_text']??''); ?>" placeholder="LEAVE BLANK FOR NO TEXT">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>ANIMATION TYPE</label>
                        <select name="text_animation_type">
                            <?php foreach (['staccato'=>'Staccato (Word by Word)','glitch'=>'Glitch Reveal','static'=>'Static (Instant)'] as $v=>$l): ?>
                                <option value="<?php echo $v; ?>" <?php echo (($editing_slide['text_animation_type']??'staccato')===$v)?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WORD DELAY (MS)</label>
                        <input type="number" name="word_delay_ms" value="<?php echo $editing_slide['word_delay_ms']??200; ?>" min="50" max="2000" step="50">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>TEXT COLOUR</label>
                        <div class="color-picker-container">
                            <input type="color" name="font_color_hex" value="<?php echo $editing_slide['font_color_hex']??'#FFFFFF'; ?>">
                            <span class="hex-display"><?php echo strtoupper($editing_slide['font_color_hex']??'#FFFFFF'); ?></span>
                        </div>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>POSITION X (%)</label>
                        <input type="number" name="pos_x_pct" value="<?php echo $editing_slide['pos_x_pct']??50; ?>" min="0" max="100">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>POSITION Y (%)</label>
                        <input type="number" name="pos_y_pct" value="<?php echo $editing_slide['pos_y_pct']??50; ?>" min="0" max="100">
                    </div>
                </div>
            </div>

            <div class="box" style="margin-top:20px;">
                <h4>GLITCH OVERRIDES <span class="dim" style="font-size:0.75em;">(BLANK = INHERIT FROM SLIDESHOW)</span></h4>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>FREQUENCY OVERRIDE</label>
                        <select name="glitch_frequency">
                            <option value="">— INHERIT —</option>
                            <?php foreach (['every_slide'=>'Every Slide','occasional'=>'Occasional','rare'=>'Rare','random'=>'Random'] as $v=>$l): ?>
                                <option value="<?php echo $v; ?>" <?php echo (($editing_slide['glitch_frequency']??null)===$v)?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>INTENSITY OVERRIDE</label>
                        <select name="glitch_intensity">
                            <option value="">— INHERIT —</option>
                            <?php foreach (['subtle'=>'Subtle','normal'=>'Normal','violent'=>'Violent'] as $v=>$l): ?>
                                <option value="<?php echo $v; ?>" <?php echo (($editing_slide['glitch_intensity']??null)===$v)?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-action-row">
                <button type="submit" class="master-update-btn">
                    <?php echo $editing_slide ? 'UPDATE SLIDE' : 'ADD SLIDE'; ?>
                </button>
                <?php if ($editing_slide): ?>
                    <a href="smack-pimpotron.php?sid=<?php echo $active_sid; ?>" class="dim" style="margin-left:15px;">CANCEL</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php endif; ?>
</div>

<script>
// Show/hide field groups based on slide type
function pimpShowFields(type) {
    ['image','video','matrix','text'].forEach(t => {
        const el = document.getElementById('fields-' + t);
        if (el) el.style.display = (t === type) ? 'block' : 'none';
    });
}
// Init on load
pimpShowFields(document.getElementById('slide-type-select')?.value ?? 'image');

// Drag-to-reorder
(function() {
    const tbody = document.querySelector('#slide-sort-table tbody');
    if (!tbody) return;
    let dragging = null;
    tbody.querySelectorAll('tr').forEach(row => {
        row.draggable = true;
        row.addEventListener('dragstart', () => { dragging = row; row.style.opacity = '0.4'; });
        row.addEventListener('dragend',   () => { dragging = null; row.style.opacity = '1'; saveOrder(); });
        row.addEventListener('dragover',  e => {
            e.preventDefault();
            const after = getDragAfterElement(tbody, e.clientY);
            if (after == null) tbody.appendChild(dragging);
            else tbody.insertBefore(dragging, after);
        });
    });
    function getDragAfterElement(container, y) {
        const rows = [...container.querySelectorAll('tr:not([style*="opacity: 0.4"])')];
        return rows.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    function saveOrder() {
        const order = [...tbody.querySelectorAll('tr')].map(r => r.dataset.slideId);
        fetch('smack-pimpotron.php?sid=<?php echo $active_sid; ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reorder_slides&order=' + encodeURIComponent(JSON.stringify(order))
        });
    }
})();
</script>

<?php include 'core/admin-footer.php'; ?>