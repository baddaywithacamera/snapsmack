<?php
/** PHOTOBLOGS.FYI — body-only renderer for [photoblogs_directory]. */
function pbdir_public_html(PDO $pdo): string {
    try {
        $health = (bool)$pdo->query("SHOW COLUMNS FROM snap_directory_listings LIKE 'feed_status'")->fetchColumn();
        $where = $health ? "state='active' AND feed_status<>'dead'" : "state='active'";
        $order = $health ? 'COALESCE(last_post_at,updated_at)' : 'updated_at';
        $rows = $pdo->query("SELECT * FROM snap_directory_listings WHERE {$where} ORDER BY {$order} DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $rows = []; }
    foreach ($rows as &$row) $row['_topics'] = json_decode((string)($row['topics'] ?? '[]'), true) ?: [];
    unset($row);

    $counts = [];
    foreach ($rows as $row) foreach ($row['_topics'] as $value) {
        $key = trim((string)$value);
        if ($key !== '') $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    uksort($counts, 'strcasecmp');
    $q = trim((string)($_GET['q'] ?? ''));
    $topic = trim((string)($_GET['topic'] ?? ''));
    $sort = in_array(($_GET['sort'] ?? 'recent'), ['recent','name'], true) ? (string)$_GET['sort'] : 'recent';
    $filtered = [];
    foreach ($rows as $row) {
        if ($topic !== '' && !array_filter($row['_topics'], fn($v) => strcasecmp(trim((string)$v), $topic) === 0)) continue;
        $haystack = strtolower(($row['name'] ?? '') . ' ' . ($row['host'] ?? '') . ' ' . ($row['description'] ?? '') . ' ' . implode(' ', $row['_topics']));
        if ($q !== '' && !str_contains($haystack, strtolower($q))) continue;
        $filtered[] = $row;
    }
    if ($sort === 'name') usort($filtered, fn($a,$b) => strcasecmp((string)$a['name'], (string)$b['name']));
    else {
        $recent = $inactive = [];
        $inactive_before = strtotime('-30 days');
        foreach ($filtered as $listing) {
            $updated = strtotime((string)($listing['last_post_at'] ?? $listing['updated_at'] ?? '')) ?: 0;
            if ($updated >= $inactive_before) $recent[] = $listing; else $inactive[] = $listing;
        }
        usort($recent, fn($a,$b) => strcmp((string)($b['last_post_at'] ?? $b['updated_at'] ?? ''), (string)($a['last_post_at'] ?? $a['updated_at'] ?? '')));
        $rotation_day = gmdate('Y-m-d');
        usort($inactive, fn($a,$b) => strcmp(hash('sha256',$rotation_day.':'.($a['id'] ?? '')), hash('sha256',$rotation_day.':'.($b['id'] ?? ''))));
        $filtered = []; $inactive_i = 0;
        foreach ($recent as $i => $listing) {
            $filtered[] = $listing;
            if (($i + 1) % 4 === 0 && isset($inactive[$inactive_i])) $filtered[] = $inactive[$inactive_i++];
        }
        while (isset($inactive[$inactive_i])) $filtered[] = $inactive[$inactive_i++];
    }
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $url = fn($v) => '/page.php?slug=directory&amp;' . $h(http_build_query(array_filter($v, fn($x) => $x !== '')));
    ob_start(); ?>
<div class="pbf-directory">
<header class="pbf-directory-head"><p class="pbf-kicker">Find &middot; Follow &middot; Be Found</p><h1>The Directory<span>.</span></h1><p>Independent photography blogs, on their own sites. Browse by photographer or by what they shoot.</p></header>
<form class="pbf-directory-controls" method="get" action="/page.php"><input type="hidden" name="slug" value="directory"><input type="search" name="q" value="<?php echo $h($q); ?>" placeholder="Search photographers, topics, places&hellip;" aria-label="Search the directory"><?php if ($topic !== ''): ?><input type="hidden" name="topic" value="<?php echo $h($topic); ?>"><?php endif; ?><button>Search</button><a class="<?php echo $sort==='recent'?'on':''; ?>" href="<?php echo $url(['q'=>$q,'topic'=>$topic,'sort'=>'recent']); ?>">Recently updated</a><a class="<?php echo $sort==='name'?'on':''; ?>" href="<?php echo $url(['q'=>$q,'topic'=>$topic,'sort'=>'name']); ?>">A&ndash;Z</a></form>
<?php if ($counts): ?><nav class="pbf-directory-topics" aria-label="Browse by topic"><a class="<?php echo $topic===''?'on':''; ?>" href="<?php echo $url(['q'=>$q,'sort'=>$sort]); ?>">All <b><?php echo count($rows); ?></b></a><?php foreach ($counts as $name=>$count): ?><a class="<?php echo strcasecmp($name,$topic)===0?'on':''; ?>" href="<?php echo $url(['q'=>$q,'topic'=>$name,'sort'=>$sort]); ?>"><?php echo $h($name); ?> <b><?php echo (int)$count; ?></b></a><?php endforeach; ?></nav><?php endif; ?>
<?php if (!$filtered): ?><p class="pbf-directory-empty"><?php echo !$rows?'No blogs are listed yet.':'No blogs match that search.'; ?></p><?php else: ?><ul class="pbf-directory-list"><?php foreach ($filtered as $row): ?><li><h3><a href="<?php echo $h($row['site_url']); ?>" rel="noopener"><?php echo $h($row['name']); ?></a><span><?php echo $h($row['host']); ?></span></h3><?php if (trim((string)$row['description'])!==''): ?><p><?php echo $h($row['description']); ?></p><?php endif; ?><small><?php if ($row['_topics']): ?>Topics: <?php foreach (array_slice($row['_topics'],0,6) as $i=>$name): ?><?php if ($i): ?> &middot; <?php endif; ?><a href="<?php echo $url(['topic'=>$name,'sort'=>$sort]); ?>"><?php echo $h($name); ?></a><?php endforeach; ?><?php endif; ?><?php $activity=$row['last_post_at']??$row['updated_at']??''; ?><?php if ($row['_topics']&&$activity!==''): ?> &middot; <?php endif; ?><?php if ($activity!==''): ?>Updated <?php echo $h(date('M j, Y',strtotime((string)$activity))); ?><?php endif; ?></small></li><?php endforeach; ?></ul><?php endif; ?>
<p class="pbf-directory-join">Run a SnapSmack photo blog? <a href="/for-admins">Learn how to join the directory.</a></p>
</div>
<?php return (string)ob_get_clean();
}
// ===== SNAPSMACK EOF =====
