<?php
// Render the actual strict component in a separate request for each case.
if ($argc === 1) {
    foreach ([['photoblog','archive',71,1],['photoblog','landing',72,1],['carousel','landing',73,1],['smackthemup','landing',74,1],['photoblog','image',75,0],['photoblog','page',76,0],['carousel','archive',77,0],['longform','landing',78,0],['photoblog','archive',0,0]] as $case) {
        $args = implode(' ', array_map('escapeshellarg', array_map('strval', $case)));
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.$args, $exit);
        if ($exit) exit($exit);
    }
    echo "PASS strict scroll-time rendered eligibility and duplicate suppression\n";
    exit;
}
require __DIR__.'/../core/stats-logger.php';
require __DIR__.'/../core/skin-render-helpers.php';
define('BASE_URL','https://example.com/blog/');
$GLOBALS['settings']=['site_mode'=>$argv[1]];
$GLOBALS['snapsmack_hit']=['id'=>(int)$argv[3],'page_type'=>$argv[2]];
$html=snap_render_html(snap_render_component('registered-assets',['assets'=>[]]));
$html.=snap_render_html(snap_render_component('registered-assets',['assets'=>[]]));
ob_start(); snapsmack_scrolltime_tag($GLOBALS['settings']); $html.=ob_get_clean();
if (substr_count($html,'id="ss-scrolltime"') !== (int)$argv[4]) throw new Exception('Wrong tracker count: '.implode(' ',$argv));
if ((int)$argv[4] && (!str_contains($html,'data-hit="'.$argv[3].'"') || !str_contains($html,'data-endpoint="https://example.com/blog/api.php?route=stats/dwell"'))) throw new Exception('Wrong hit or endpoint');
