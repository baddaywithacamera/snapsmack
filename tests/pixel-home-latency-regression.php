<?php
/** Interactive Home must never synchronously crawl remote federation peers. */
$root = dirname(__DIR__);
foreach (['pixel.php', 'smack-pixelfed.php'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        fwrite(STDERR, "Cannot read {$file}\n");
        exit(1);
    }
    $start = strpos($source, "if (\$panel === 'home')");
    $end = strpos($source, "if (\$panel === 'notifications')", $start === false ? 0 : $start);
    if ($start === false || $end === false || $end <= $start) {
        fwrite(STDERR, "Cannot find Home handler in {$file}\n");
        exit(1);
    }
    $handler = substr($source, $start, $end - $start);
    if (strpos($handler, 'sv_home_feed(') !== false || strpos($handler, 'set_time_limit') !== false) {
        fwrite(STDERR, "{$file} performs remote federation work while loading Home\n");
        exit(1);
    }
    if (strpos($handler, 'sv_home_timeline(') === false) {
        fwrite(STDERR, "{$file} no longer reads the inbound Home timeline\n");
        exit(1);
    }
}
echo "PASS: FEDIVERSE Home is local-only and bounded\n";
// ===== SNAPSMACK EOF =====
