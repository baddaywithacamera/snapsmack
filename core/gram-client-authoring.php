<?php
/**
 * Shared GRAMOFSMACK authoring primitives for web-adjacent/native clients.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function snapsmack_gram_unique_post_slug(PDO $pdo, string $prefix): string {
    $base = trim($prefix, '-') . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $slug = $base; $n = 1; $q = $pdo->prepare('SELECT id FROM snap_posts WHERE slug=? LIMIT 1');
    do { $q->execute([$slug]); if (!$q->fetchColumn()) return $slug; $slug = $base . '-' . $n++; } while ($n < 1000);
    throw new RuntimeException('Could not allocate a unique post slug.');
}

/** One rolling budget shared by SYBU and Pixelix. Throws code 429 on overflow. */
function snapsmack_gram_authoring_budget(PDO $pdo, int $add): void {
    $add=max(0,$add);$cap=300;$now=time();$own=!$pdo->inTransaction();
    if($own)$pdo->beginTransaction();
    try {
        $seed=$pdo->prepare('INSERT IGNORE INTO snap_settings(setting_key,setting_val) VALUES(?,?)');
        $seed->execute(['gram_authoring_win_start',(string)$now]);$seed->execute(['gram_authoring_win_count','0']);
        $q=$pdo->query("SELECT setting_key,setting_val FROM snap_settings WHERE setting_key IN ('gram_authoring_win_start','gram_authoring_win_count') ORDER BY setting_key FOR UPDATE");
        $v=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$v[$r['setting_key']]=(int)$r['setting_val'];
        $start=$v['gram_authoring_win_start']??$now;$count=$v['gram_authoring_win_count']??0;
        if($now-$start>=3600){$start=$now;$count=0;}
        if($count+$add>$cap)throw new RuntimeException('Offline-posting rate limit reached (300 images/hour).',429);
        $write=$pdo->prepare('UPDATE snap_settings SET setting_val=? WHERE setting_key=?');
        $write->execute([(string)$start,'gram_authoring_win_start']);$write->execute([(string)($count+$add),'gram_authoring_win_count']);
        if($own)$pdo->commit();
    } catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/**
 * Ensure snap_posts carries the per-post federation + sensitivity columns that
 * snapsmack_gram_create_post() writes (fedi_enabled etc., added 0.7.367/0.7.393).
 * These are normally created by sv_ensure_tables when SMACKVERSE first runs, but a
 * site that has never run it drifts, and every gram/Pixelfed post then dies with
 * "Unknown column 'fedi_enabled' in 'INSERT INTO'".
 *
 * MUST be called BEFORE the caller opens its transaction: an ALTER implicit-commits
 * in MySQL/MariaDB, so running it inside a live transaction would sever it. Mirrors
 * the snap_posts block of core/smackverse.php sv_ensure_tables().
 */
function snapsmack_gram_ensure_post_columns(PDO $pdo): void {
    foreach ([
        "fedi_enabled tinyint(1) NOT NULL DEFAULT 1",
        "fedi_pushed_at datetime DEFAULT NULL",
        "fedi_published_at datetime DEFAULT NULL",
        "is_pinned tinyint(1) NOT NULL DEFAULT 0",
        "is_sensitive tinyint(1) NOT NULL DEFAULT 0",
        "content_warning varchar(255) DEFAULT NULL",
    ] as $_col) {
        try { $pdo->exec("ALTER TABLE snap_posts ADD COLUMN IF NOT EXISTS {$_col}"); }
        catch (Exception $e) { /* older MySQL without IF NOT EXISTS — ignore dup */ }
    }
}

/**
 * Create one GRAM post around already-created image rows.
 * $members: [['image_id'=>int,'style'=>optional pivot style], ...]
 * Caller owns the surrounding transaction. Call snapsmack_gram_ensure_post_columns()
 * BEFORE opening that transaction so a drifted snap_posts can't fail the INSERT.
 */
function snapsmack_gram_create_post(PDO $pdo, array $members, array $o=[]): int {
    if(!$members||count($members)>30)throw new InvalidArgumentException('A GRAM post needs between 1 and 30 images.');
    $type=(string)($o['post_type']??(count($members)>1?'carousel':'single'));
    if(!in_array($type,['single','carousel','panorama'],true))$type=count($members)>1?'carousel':'single';
    $date=(string)($o['created_at']??date('Y-m-d H:i:s'));
    $slug=(string)($o['slug']??snapsmack_gram_unique_post_slug($pdo,(string)($o['slug_prefix']??'gram')));
    $p=$pdo->prepare("INSERT INTO snap_posts(title,slug,description,post_type,status,created_at,allow_comments,allow_download,download_url,panorama_rows,post_img_size_pct,post_border_px,post_border_color,post_bg_color,post_shadow,fedi_enabled,is_sensitive,content_warning) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $p->execute([(string)($o['title']??''),$slug,(string)($o['description']??''),$type,(string)($o['status']??'published'),$date,!empty($o['allow_comments'])?1:0,!empty($o['allow_download'])?1:0,(string)($o['download_url']??''),max(1,min(3,(int)($o['panorama_rows']??1))),max(10,min(100,(int)($o['post_img_size_pct']??100))),max(0,min(50,(int)($o['post_border_px']??0))),(string)($o['post_border_color']??'#000000'),(string)($o['post_bg_color']??'#ffffff'),max(0,min(3,(int)($o['post_shadow']??0))),array_key_exists('fedi_enabled',$o)?(!empty($o['fedi_enabled'])?1:0):1,!empty($o['is_sensitive'])?1:0,substr((string)($o['content_warning']??''),0,255)]);
    $pid=(int)$pdo->lastInsertId();
    $pi=$pdo->prepare("INSERT INTO snap_post_images(post_id,image_id,sort_position,is_cover,img_size_pct,img_border_px,img_border_color,img_bg_color,img_shadow,img_crop_mode,img_focus_x,img_focus_y,img_zoom) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $link=$pdo->prepare('UPDATE snap_images SET post_id=? WHERE id=?');
    foreach(array_values($members)as$pos=>$m){$s=(array)($m['style']??[]);$iid=(int)($m['image_id']??0);if($iid<1)throw new InvalidArgumentException('Invalid GRAM image id.');$crop=($s['crop']??'fit')==='fill'?'fill':'fit';$pi->execute([$pid,$iid,$pos,$pos===0?1:0,max(10,min(100,(int)($s['size']??100))),max(0,min(50,(int)($s['bpx']??0))),(string)($s['bcol']??'#000000'),(string)($s['bg']??'#ffffff'),max(0,min(3,(int)($s['shadow']??0))),$crop,max(0,min(100,(int)($s['fx']??50))),max(0,min(100,(int)($s['fy']??50))),max(100,min(300,(int)($s['zoom']??100)))]);$link->execute([$pid,$iid]);}
    // A fresh post is left at sort_order=0 ON PURPOSE. The canonical feed order
    // (parade landing.php, the light table, smack-lt-gram — the 0.7.349 model) is
    // "sort_order=0 group first, newest id first; sort_order>0 is the manually
    // curated band BELOW it." Seating a new post at sort_order=1 (added Aug 2026 in
    // the Pixelix front-door commit 3bb44d5d) therefore buried every new post under
    // the whole 0-backlog — the "new posts not at top of feed" regression. Leaving
    // it at 0 puts it back at the top by recency, matching every reader.
    return $pid;
}

// ===== SNAPSMACK EOF =====
