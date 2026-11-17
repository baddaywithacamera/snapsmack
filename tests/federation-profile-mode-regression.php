<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
declare(strict_types=1);
$library=file_get_contents(__DIR__.'/../core/fediverse.php');
$start=strpos($library,'function sv_enabled(');$end=strpos($library,"\n}",$start)+2;eval(substr($library,$start,$end-$start));
$index=file_get_contents(__DIR__.'/../index.php');
$start=strpos($index,'    // --- FEDIVERSE public profile');$end=strpos($index,'    // --- CMS-OWNED PUBLIC CONTROLLER',$start);
$route=substr($index,$start,$end-$start);
require_once __DIR__.'/../core/page-cache.php';
$route=str_replace("require_once __DIR__ . '/core/fediverse.php';",'', $route);
$route=str_replace("include __DIR__ . '/core/public-profile.php';",'throw new ProfileReached("html");',$route);
$route=str_replace("header('Content-Type: application/activity+json; charset=utf-8');",'', $route);
$route=str_replace('echo json_encode(sv_actor_doc($pdo, $settings), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);','throw new ProfileReached("json");',$route);
final class ProfileReached extends RuntimeException {}
function sv_handle(array $settings): string { return 'fixture'; }
$checks=0;
foreach (['photoblog','carousel','smacktalk','smackthemup'] as $mode) foreach (['0','1'] as $enabled) foreach (['application/activity+json','text/html'] as $accept) foreach (['fixture','ordinary-slug'] as $requested_slug) {
 $settings=['site_mode'=>$mode,'fediverse_enabled'=>$enabled];$_SERVER['HTTP_ACCEPT']=$accept;$reached=false;
 try {eval($route);}catch(ProfileReached $e){$reached=true;if($e->getMessage()!==($accept==='text/html'?'html':'json'))throw new RuntimeException('Wrong representation');}
 $expected=$mode!=='smackthemup' && $enabled==='1' && $requested_slug==='fixture';
 if($reached!==$expected)throw new RuntimeException("Unexpected profile dispatch: $mode / $enabled / $accept / $requested_slug");
 $checks++;
}
echo "Federation profile gate: $checks dispatch checks PASS; installed/network NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
