<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
require_once __DIR__.'/../core/page-cache.php';
$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/fixture','HTTP_HOST'=>'example.test','HTTP_ACCEPT'=>'text/html'];
$_COOKIE=[];$_SESSION=[];
$s=['cache_enabled'=>'1','fediverse_enabled'=>'1','site_mode'=>'photoblog'];
if(!page_cache_eligible($s))throw new RuntimeException('HTML unexpectedly excluded');
$a=page_cache_file($s);
foreach(['application/activity+json','application/ld+json'] as $accept){$_SERVER['HTTP_ACCEPT']=$accept;if(page_cache_eligible($s))throw new RuntimeException('AP cached');}
$s['fediverse_enabled']='0';if($a===page_cache_file($s))throw new RuntimeException('Disable reused key');
$s['fediverse_enabled']='1';$s['site_mode']='smackthemup';if($a===page_cache_file($s))throw new RuntimeException('Mode reused key');
$i=file_get_contents(__DIR__.'/../index.php');
if(strpos($i,'page_cache_serve_or_start($settings)')<strpos($i,'// --- FEDIVERSE public profile'))throw new RuntimeException('Cache precedes gate');
echo "PASS: HTML eligibility, AP exclusion, settings-key isolation, profile-before-cache ordering. Installed cache NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
