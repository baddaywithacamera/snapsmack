<?php
/**
 * GRAMOFSMACK compatibility adapter for Pixelfed clients (Pixelix).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/thumb-generator.php';
require_once __DIR__ . '/core/alt-text.php';
require_once __DIR__ . '/core/gram-client-authoring.php';
require_once __DIR__ . '/core/pixelix-lifecycle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function px_json(array $body, int $code = 200): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; }
function px_setting(PDO $pdo, string $key, string $fallback = ''): string {
    $s=$pdo->prepare('SELECT setting_val FROM snap_settings WHERE setting_key=? LIMIT 1'); $s->execute([$key]);
    $v=$s->fetchColumn(); return $v === false ? $fallback : (string)$v;
}
function px_base(): string {
    $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https';
    return ($https?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').'/';
}
function px_mode(PDO $pdo): string {
    $mode=px_setting($pdo,'site_mode','photoblog');return in_array($mode,['photoblog','carousel','smacktalk'],true)?$mode:'photoblog';
}
function px_gram_authoring_gate(PDO $pdo): void {
    if(px_mode($pdo)!=='carousel')px_json(['error'=>'Pixelix publishing is currently available on GRAMOFSMACK; this server remains available for reading and Fediverse interaction.'],422);
}
function px_offline_gate(PDO $pdo): void {
    if (px_setting($pdo,'gram_authoring_enabled','0') !== '1') px_json(['error'=>'Offline posting is disabled by the site owner.'],403);
}
function px_budget(PDO $pdo): void {
    try { snapsmack_gram_authoring_budget($pdo,1); }
    catch(RuntimeException $e){px_json(['error'=>$e->getMessage()],$e->getCode()===429?429:500);}
}
function px_scopes(string $raw): string {
    $asked=array_values(array_unique(array_filter(preg_split('/\s+/',trim($raw))?:[])));$unknown=array_diff($asked,['read','write']);
    if($unknown)px_json(['error'=>'Unsupported OAuth scope'],422);$allowed=array_values(array_intersect(['read','write'],$asked));return implode(' ',$allowed?:['read']);
}
function px_require_scope(array $token, string $scope): void {
    $scopes=preg_split('/\s+/',trim((string)($token['scopes']??'')))?:[];
    if(!in_array($scope,$scopes,true))px_json(['error'=>'This token does not have the required '.$scope.' scope'],403);
}
function px_registration_budget(PDO $pdo): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$key=hash('sha256','oauth-app:'.$ip);$own=!$pdo->inTransaction();
    if($own)$pdo->beginTransaction();
    try{$s=$pdo->prepare('INSERT IGNORE INTO snap_oauth_rate_limits(bucket_key,window_started_at,request_count) VALUES(?,NOW(),0)');$s->execute([$key]);
        $q=$pdo->prepare('SELECT window_started_at,request_count FROM snap_oauth_rate_limits WHERE bucket_key=? FOR UPDATE');$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);
        $count=(int)($r['request_count']??0);$expired=!$r||strtotime((string)$r['window_started_at'])<=time()-3600;if($expired)$count=0;
        if($count>=10)throw new RuntimeException('Too many OAuth client registrations. Try again later.',429);
        $u=$pdo->prepare('UPDATE snap_oauth_rate_limits SET window_started_at=IF(?=1,NOW(),window_started_at),request_count=? WHERE bucket_key=?');$u->execute([$expired?1:0,$count+1,$key]);if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function px_bearer(PDO $pdo): array {
    $h=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if (!$h && function_exists('getallheaders')) { $a=getallheaders(); $h=$a['Authorization']??$a['authorization']??''; }
    if (!preg_match('/^Bearer\s+(\S+)$/i',$h,$m)) px_json(['error'=>'The access token is invalid'],401);
    $s=$pdo->prepare("SELECT t.*,a.client_id FROM snap_oauth_tokens t JOIN snap_oauth_apps a ON a.id=t.app_id
      WHERE t.token_hash=? AND t.revoked_at IS NULL AND t.token_expires_at>NOW() LIMIT 1");
    $s->execute([hash('sha256',$m[1])]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row) px_json(['error'=>'The access token is invalid'],401); return $row;
}
function px_avatar(PDO $pdo, string $base): string {
    // Same resolution as sv_avatar() in core/fediverse.php: the avatar is the
    // per-skin PROFILE AVATAR, stored as "<active_skin>__skin_avatar". This
    // endpoint used to read a 'fediverse_avatar' key that nothing ever writes,
    // so Pixelix showed a blank avatar no matter what the operator uploaded.
    $slug = px_setting($pdo, 'active_skin', '');
    $path = $slug !== '' ? px_setting($pdo, $slug . '__skin_avatar', '') : '';
    if ($path === '') $path = px_setting($pdo, 'fediverse_avatar', ''); // legacy key, kept as fallback
    if ($path === '') return '';
    if (!preg_match('#^https?://#', $path)) {
        if (!is_file(__DIR__ . '/' . ltrim($path, '/'))) return '';   // never advertise a broken image
        $path = $base . ltrim($path, '/');
    }
    return $path;
}
function px_actor(PDO $pdo): array {
    // 'fediverse_handle' is the real handle key (sv_handle); 'fediverse_username'
    // was another never-written key, so every Pixelix profile said "snapsmack".
    $base=px_base(); $user=px_setting($pdo,'fediverse_handle',px_setting($pdo,'fediverse_username','snapsmack'));
    $name=px_setting($pdo,'fediverse_display_name',px_setting($pdo,'site_name','GRAMOFSMACK'));
    $avatar=px_avatar($pdo,$base);
    $mode=px_mode($pdo);$countSql=$mode==='photoblog'?"SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date<=NOW()":($mode==='smacktalk'?"SELECT COUNT(*) FROM snap_posts WHERE status='published' AND created_at<=NOW() AND post_type='longform'":"SELECT COUNT(*) FROM snap_posts WHERE status='published' AND created_at<=NOW() AND post_type IN ('single','carousel','panorama')");
    $followers=0;$following=0;
    try{$followers=(int)$pdo->query("SELECT COUNT(*) FROM snap_ap_followers WHERE is_active=1")->fetchColumn();}catch(Throwable $e){}
    try{$following=(int)$pdo->query("SELECT COUNT(*) FROM snap_ap_following WHERE state='accepted'")->fetchColumn();}catch(Throwable $e){}
    return ['id'=>'1','username'=>$user,'acct'=>$user,'display_name'=>$name,'locked'=>false,'bot'=>false,
      'discoverable'=>true,'group'=>false,'created_at'=>'2020-01-01T00:00:00.000Z','note'=>px_setting($pdo,'site_description',''),
      'url'=>$base.'ap/actor','avatar'=>$avatar,'avatar_static'=>$avatar,'header'=>'','header_static'=>'',
      'followers_count'=>$followers,'following_count'=>$following,'statuses_count'=>(int)$pdo->query($countSql)->fetchColumn(),
      'last_status_at'=>null,'emojis'=>[],'fields'=>[]];
}
function px_media(PDO $pdo, int $id): ?array {
    $s=$pdo->prepare('SELECT * FROM snap_images WHERE id=? LIMIT 1'); $s->execute([$id]); $i=$s->fetch(PDO::FETCH_ASSOC); if(!$i)return null;
    $url=px_base().ltrim($i['img_file'],'/'); $preview=$i['img_thumb_aspect']?px_base().ltrim($i['img_thumb_aspect'],'/'):$url;
    return ['id'=>(string)$id,'type'=>'image','url'=>$url,'preview_url'=>$preview,'optimized_url'=>$preview,'remote_url'=>null,'text_url'=>null,
      'meta'=>['original'=>['width'=>(int)$i['img_width'],'height'=>(int)$i['img_height'],'aspect'=>((int)$i['img_height']?:1)?((int)$i['img_width']/max(1,(int)$i['img_height'])):1]],
      'description'=>(string)($i['img_alt']??''),'blurhash'=>null,'license'=>null];
}
function px_status(PDO $pdo, int $id): ?array {
    if(px_mode($pdo)==='photoblog'){
        $s=$pdo->prepare("SELECT * FROM snap_images WHERE id=? AND img_status='published' AND img_date<=NOW() LIMIT 1");$s->execute([$id]);$i=$s->fetch(PDO::FETCH_ASSOC);if(!$i)return null;
        $media=px_media($pdo,$id);$caption=(string)($i['img_description']??'');$created=(string)($i['img_date']??'now');$url=px_base().ltrim((string)$i['img_slug'],'/');
        return ['id'=>(string)$id,'created_at'=>gmdate('Y-m-d\TH:i:s.000\Z',strtotime($created)),'in_reply_to_id'=>null,'in_reply_to_account_id'=>null,'sensitive'=>(bool)($i['is_sensitive']??false),'spoiler_text'=>(string)($i['content_warning']??''),'visibility'=>'public','language'=>null,'uri'=>px_base().'ap/note/i'.$id,'url'=>$url,'replies_count'=>0,'reblogs_count'=>0,'favourites_count'=>0,'favourited'=>false,'reblogged'=>false,'muted'=>false,'bookmarked'=>false,'content'=>nl2br(htmlspecialchars($caption,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'content_text'=>$caption,'liked_by'=>null,'place'=>null,'reblog'=>null,'application'=>['name'=>'SnapSmack','website'=>'https://snapsmack.ca/'],'account'=>px_actor($pdo),'media_attachments'=>$media?[$media]:[],'mentions'=>[],'tags'=>[],'emojis'=>[],'card'=>null,'poll'=>null,'comments_disabled'=>!(bool)($i['allow_comments']??true)];
    }
    $s=$pdo->prepare("SELECT * FROM snap_posts WHERE id=? AND status='published' AND created_at<=NOW() LIMIT 1"); $s->execute([$id]); $p=$s->fetch(PDO::FETCH_ASSOC); if(!$p)return null;
    $q=$pdo->prepare('SELECT pi.image_id,i.img_slug FROM snap_post_images pi JOIN snap_images i ON i.id=pi.image_id WHERE pi.post_id=? ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 10'); $q->execute([$id]);
    $images=$q->fetchAll(PDO::FETCH_ASSOC);$media=[];foreach($images as$image){$m=px_media($pdo,(int)$image['image_id']);if($m)$media[]=$m;}
    // GRAMOFSMACK renders a post at its cover photograph's public slug. The
    // snap_posts slug is an internal authoring identifier and is not routable.
    $publicSlug=px_mode($pdo)==='smacktalk'?(string)$p['slug']:(string)($images[0]['img_slug']??'');$publicUrl=$publicSlug!==''?px_base().ltrim($publicSlug,'/'):px_base();
    $plain=px_mode($pdo)==='smacktalk'?(string)($p['content']??$p['description']??''):(string)($p['description']??'');$content=nl2br(htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
    return ['id'=>(string)$id,'created_at'=>gmdate('Y-m-d\TH:i:s.000\Z',strtotime($p['created_at'])),'in_reply_to_id'=>null,
      'in_reply_to_account_id'=>null,'sensitive'=>(bool)($p['is_sensitive']??false),'spoiler_text'=>(string)($p['content_warning']??''),
      'visibility'=>'public','language'=>null,'uri'=>px_base().'ap/note/p'.$id,'url'=>$publicUrl,
      'replies_count'=>0,'reblogs_count'=>0,'favourites_count'=>0,'favourited'=>false,'reblogged'=>false,'muted'=>false,
      'bookmarked'=>false,'content'=>$content,'content_text'=>$plain,'liked_by'=>null,'place'=>null,'reblog'=>null,'application'=>['name'=>'SnapSmack','website'=>'https://snapsmack.ca/'],
      'account'=>px_actor($pdo),'media_attachments'=>$media,'mentions'=>[],'tags'=>[],'emojis'=>[],'card'=>null,'poll'=>null,'comments_disabled'=>!(bool)$p['allow_comments']];
}
function px_timeline(PDO $pdo): array {
    $limit=max(1,min(40,(int)($_GET['limit']??20)));$maxId=max(0,(int)($_GET['max_id']??0));
    $mode=px_mode($pdo);$sql=$mode==='photoblog'?"SELECT id FROM snap_images WHERE img_status='published' AND img_date<=NOW()":($mode==='smacktalk'?"SELECT id FROM snap_posts WHERE status='published' AND created_at<=NOW() AND post_type='longform'":"SELECT id FROM snap_posts WHERE status='published' AND created_at<=NOW() AND post_type IN ('single','carousel','panorama')");$params=[];
    // Mastodon/Pixelfed pagination is exclusive: max_id=N means records older
    // than N. Returning N again traps Pixelix in an endless refresh loop.
    if($maxId>0){$sql.=' AND id<?';$params[]=$maxId;}
    $sql.=' ORDER BY id DESC LIMIT '.(int)$limit;$q=$pdo->prepare($sql);$q->execute($params);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as$id){$s=px_status($pdo,(int)$id);if($s)$out[]=$s;}return $out;
}
function px_remote_account(string $actorUrl, string $handle, array $cached = []): array {
    $handle=ltrim(trim($handle!==''?$handle:(string)($cached['handle']??'')),'@');$host=(string)(parse_url($actorUrl,PHP_URL_HOST)?:'remote');
    if($handle===''){$path=trim((string)(parse_url($actorUrl,PHP_URL_PATH)?:''),'/');$bits=$path===''?[]:explode('/',$path);$handle=rawurldecode((string)(end($bits)?:'unknown')).'@'.$host;}
    $parts=explode('@',$handle,2);$username=$parts[0]?:'unknown';$acct=count($parts)>1?$handle:$username.'@'.$host;
    $avatar=(string)($cached['avatar_url']??'');$url=(string)($cached['profile_url']??'');if($url==='')$url=$actorUrl;
    return ['id'=>'remote-'.substr(hash('sha256',$actorUrl),0,24),'username'=>$username,'acct'=>$acct,'display_name'=>(string)($cached['name']??$username),
      'locked'=>false,'bot'=>false,'discoverable'=>true,'group'=>false,'created_at'=>'2020-01-01T00:00:00.000Z','note'=>(string)($cached['summary']??''),'url'=>$url,
      'avatar'=>$avatar,'avatar_static'=>$avatar,'header'=>'','header_static'=>'','followers_count'=>0,'following_count'=>0,'statuses_count'=>0,
      'last_status_at'=>null,'emojis'=>[],'fields'=>[]];
}
function px_relationship_accounts(PDO $pdo, string $kind): array {
    $limit=max(1,min(40,(int)($_GET['limit']??20)));$maxId=max(0,(int)($_GET['max_id']??0));$params=[];
    if($kind==='followers'){$sql="SELECT f.id,f.actor_url,f.actor_handle,a.handle,a.name,a.avatar_url,a.summary,a.profile_url FROM snap_ap_followers f LEFT JOIN snap_ap_actors a ON a.actor_url=f.actor_url WHERE f.is_active=1";}
    else{$sql="SELECT f.id,f.actor_url,f.actor_handle,a.handle,a.name,a.avatar_url,a.summary,a.profile_url FROM snap_ap_following f LEFT JOIN snap_ap_actors a ON a.actor_url=f.actor_url WHERE f.state='accepted'";}
    if($maxId>0){$sql.=' AND f.id<?';$params[]=$maxId;}$sql.=' ORDER BY f.id DESC LIMIT '.(int)$limit;$q=$pdo->prepare($sql);$q->execute($params);
    $out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as$row){$out[]=px_remote_account((string)$row['actor_url'],(string)($row['actor_handle']??''),$row);}return $out;
}
function px_dm_status(PDO $pdo, array $dm): array {
    $remote=px_remote_account((string)$dm['remote_actor_url'],(string)($dm['remote_handle']??''));$mine=$dm['direction']==='out';$author=$mine?px_actor($pdo):$remote;$mentioned=$mine?$remote:px_actor($pdo);
    $plain=(string)($dm['body']??'');$media=[];if(!empty($dm['media_url'])){$url=(string)$dm['media_url'];$media[]=['id'=>'dm-'.(string)$dm['id'].'-media','type'=>'image','url'=>$url,'preview_url'=>$url,'remote_url'=>$url,'text_url'=>null,'meta'=>[],'description'=>null,'blurhash'=>null];}
    return ['id'=>'dm-'.(string)$dm['id'],'created_at'=>gmdate('Y-m-d\TH:i:s.000\Z',strtotime((string)$dm['created_at'])),'in_reply_to_id'=>$dm['in_reply_to']?:null,
      'in_reply_to_account_id'=>$mentioned['id'],'sensitive'=>false,'spoiler_text'=>'','visibility'=>'direct','language'=>null,'uri'=>(string)$dm['note_id'],'url'=>null,
      'replies_count'=>0,'reblogs_count'=>0,'favourites_count'=>0,'favourited'=>false,'reblogged'=>false,'muted'=>false,'bookmarked'=>false,
      'content'=>nl2br(htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'content_text'=>$plain,'reblog'=>null,'application'=>['name'=>'SnapSmack','website'=>'https://snapsmack.ca/'],
      'account'=>$author,'media_attachments'=>$media,'mentions'=>[['id'=>$mentioned['id'],'username'=>$mentioned['username'],'url'=>$mentioned['url'],'acct'=>$mentioned['acct']]],'tags'=>[],'emojis'=>[],'card'=>null,'poll'=>null];
}
function px_conversation_id(string $actorUrl): string { return substr(hash('sha256',$actorUrl),0,24); }
function px_conversations(PDO $pdo): array {
    $limit=max(1,min(40,(int)($_GET['limit']??20)));$q=$pdo->query("SELECT remote_actor_url,MAX(id) latest_id,MAX(CASE WHEN direction='in' AND is_read=0 THEN 1 ELSE 0 END) unread FROM snap_ap_dms WHERE is_deleted=0 GROUP BY remote_actor_url ORDER BY latest_id DESC LIMIT ".(int)$limit);
    $out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as$thread){$s=$pdo->prepare('SELECT * FROM snap_ap_dms WHERE id=? AND is_deleted=0 LIMIT 1');$s->execute([(int)$thread['latest_id']]);$last=$s->fetch(PDO::FETCH_ASSOC);if(!$last)continue;$remote=px_remote_account((string)$last['remote_actor_url'],(string)($last['remote_handle']??''));$out[]=['id'=>px_conversation_id((string)$last['remote_actor_url']),'unread'=>(bool)$thread['unread'],'accounts'=>[$remote],'last_status'=>px_dm_status($pdo,$last)];}return $out;
}
function px_conversation_actor(PDO $pdo, string $conversationId): ?string {
    $q=$pdo->query('SELECT DISTINCT remote_actor_url FROM snap_ap_dms WHERE is_deleted=0');foreach($q->fetchAll(PDO::FETCH_COLUMN) as$actor){if(hash_equals(px_conversation_id((string)$actor),$conversationId))return (string)$actor;}return null;
}

$route=trim((string)($_GET['route']??''),'/'); $method=$_SERVER['REQUEST_METHOD']??'GET'; $base=px_base();
try { snap_pixelix_lifecycle_maintenance($pdo,__DIR__,false,10); }
catch (Throwable $e) { error_log('Pixelix lifecycle maintenance failed: '.$e->getMessage()); }

if ($route==='api/v1/apps' && $method==='POST') {
    $name=trim((string)($_REQUEST['client_name']??'Pixelix'));$redirect=trim((string)($_REQUEST['redirect_uris']??''));
    if($name===''||strlen($name)>150)px_json(['error'=>'client_name must be between 1 and 150 bytes'],422);
    if($redirect===''||strlen($redirect)>1000||!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i',$redirect))px_json(['error'=>'redirect_uris must be one valid URI of at most 1000 bytes'],422);
    try{px_registration_budget($pdo);}catch(RuntimeException $e){px_json(['error'=>$e->getMessage()],$e->getCode()===429?429:500);}
    $cid=bin2hex(random_bytes(32)); $secret=bin2hex(random_bytes(32));
    $s=$pdo->prepare('INSERT INTO snap_oauth_apps(client_id,client_secret_hash,name,redirect_uri,scopes) VALUES(?,?,?,?,?)');
    $s->execute([$cid,hash('sha256',$secret),$name,$redirect,px_scopes((string)($_REQUEST['scopes']??'read write'))]);
    px_json(['id'=>(string)$pdo->lastInsertId(),'name'=>$name,'website'=>null,'redirect_uri'=>$redirect,'client_id'=>$cid,'client_secret'=>$secret,'vapid_key'=>'']);
}
if ($route==='oauth/authorize') {
    require_once __DIR__.'/core/auth-smack.php';
    $cid=(string)($_REQUEST['client_id']??''); $redirect=(string)($_REQUEST['redirect_uri']??''); $state=(string)($_REQUEST['state']??'');
    $s=$pdo->prepare('SELECT * FROM snap_oauth_apps WHERE client_id=? LIMIT 1');$s->execute([$cid]);$app=$s->fetch(PDO::FETCH_ASSOC);
    if(!$app || !hash_equals($app['redirect_uri'],$redirect)) px_json(['error'=>'invalid_client'],400);
    if($method==='POST') { $code=bin2hex(random_bytes(32));$i=$pdo->prepare('INSERT INTO snap_oauth_tokens(app_id,user_id,authorization_code_hash,redirect_uri,scopes,code_expires_at) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))');$i->execute([$app['id'],$_SESSION['user_id']??null,hash('sha256',$code),$redirect,$app['scopes']]);header('Location: '.$redirect.(strpos($redirect,'?')===false?'?':'&').'code='.rawurlencode($code).'&state='.rawurlencode($state),true,302);exit; }
    header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Connect Pixelix</title><style>body{font:16px system-ui;background:#111;color:#fff;display:grid;place-items:center;min-height:100vh;margin:0}.c{max-width:430px;padding:32px;border:1px solid #444;border-radius:18px;background:#191919}button{width:100%;padding:14px;border:0;border-radius:999px;font-weight:800}</style><form class="c" method="post"><h1>Connect '.htmlspecialchars($app['name']).'?</h1><p>This app will be able to read your SnapSmack profile and Fediverse posts. Publishing is available when this site&rsquo;s mode and Offline Posting settings support it.</p><input type="hidden" name="client_id" value="'.htmlspecialchars($cid).'"><input type="hidden" name="redirect_uri" value="'.htmlspecialchars($redirect).'"><input type="hidden" name="state" value="'.htmlspecialchars($state).'"><input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token()).'"><button>ALLOW PIXELIX</button></form>';exit;
}
if ($route==='oauth/token' && $method==='POST') {
    $cid=(string)($_POST['client_id']??'');$secret=(string)($_POST['client_secret']??'');$code=(string)($_POST['code']??'');$redirect=(string)($_POST['redirect_uri']??'');
    if(($_POST['grant_type']??'')==='refresh_token'){
        $refresh=(string)($_POST['refresh_token']??'');$oldHash=hash('sha256',$refresh);$token=bin2hex(random_bytes(32));$newRefresh=bin2hex(random_bytes(32));
        $pdo->beginTransaction();
        try{$s=$pdo->prepare("SELECT t.*,a.client_secret_hash FROM snap_oauth_tokens t JOIN snap_oauth_apps a ON a.id=t.app_id WHERE a.client_id=? AND t.refresh_token_hash=? AND t.revoked_at IS NULL AND t.refresh_expires_at>NOW() LIMIT 1 FOR UPDATE");$s->execute([$cid,$oldHash]);$row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row||!hash_equals($row['client_secret_hash'],hash('sha256',$secret))){$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}
            $u=$pdo->prepare('UPDATE snap_oauth_tokens SET token_hash=?,refresh_token_hash=?,token_expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY) WHERE id=? AND refresh_token_hash=? AND revoked_at IS NULL AND refresh_expires_at>NOW()');$u->execute([hash('sha256',$token),hash('sha256',$newRefresh),$row['id'],$oldHash]);
            if($u->rowCount()!==1){$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}
        px_json(['access_token'=>$token,'refresh_token'=>$newRefresh,'token_type'=>'Bearer','scope'=>$row['scopes'],'created_at'=>(string)time()]);
    }
    $codeHash=hash('sha256',$code);$token=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));$pdo->beginTransaction();
    try{$s=$pdo->prepare("SELECT t.*,a.client_secret_hash FROM snap_oauth_tokens t JOIN snap_oauth_apps a ON a.id=t.app_id WHERE a.client_id=? AND t.authorization_code_hash=? AND t.redirect_uri=? AND t.code_expires_at>NOW() AND t.token_hash IS NULL LIMIT 1 FOR UPDATE");$s->execute([$cid,$codeHash,$redirect]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row || !hash_equals($row['client_secret_hash'],hash('sha256',$secret))){$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}
        $u=$pdo->prepare('UPDATE snap_oauth_tokens SET token_hash=?,refresh_token_hash=?,authorization_code_hash=NULL,token_expires_at=DATE_ADD(NOW(),INTERVAL 30 DAY),refresh_expires_at=DATE_ADD(NOW(),INTERVAL 90 DAY) WHERE id=? AND authorization_code_hash=? AND token_hash IS NULL AND revoked_at IS NULL AND code_expires_at>NOW()');$u->execute([hash('sha256',$token),hash('sha256',$refresh),$row['id'],$codeHash]);
        if($u->rowCount()!==1){$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();px_json(['error'=>'invalid_grant'],400);}
    px_json(['access_token'=>$token,'refresh_token'=>$refresh,'token_type'=>'Bearer','scope'=>$row['scopes'],'created_at'=>(string)time()]);
}
if ($route==='oauth/revoke' && $method==='POST') { $token=(string)($_POST['token']??'');$pdo->prepare('UPDATE snap_oauth_tokens SET revoked_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);px_json([]); }

if (($route==='api/v1/instance'||$route==='api/v2/instance') && $method==='GET') {
    $actor=px_actor($pdo);$count=(int)$actor['statuses_count']; px_json(['uri'=>$_SERVER['HTTP_HOST']??'','title'=>px_setting($pdo,'site_name','GRAMOFSMACK'),'short_description'=>px_setting($pdo,'site_description',''),'description'=>'','thumbnail'=>'','email'=>'','version'=>'4.2.0 (compatible; SnapSmack)','languages'=>['en'],'registrations'=>false,'approval_required'=>true,'invites_enabled'=>false,'stats'=>['user_count'=>1,'status_count'=>$count,'domain_count'=>1],'configuration'=>['statuses'=>['max_characters'=>5000,'max_media_attachments'=>10],'media_attachments'=>['supported_mime_types'=>['image/jpeg','image/png','image/webp'],'image_size_limit'=>67108864,'video_size_limit'=>0]],'admin'=>$actor,'contact_account'=>$actor,'rules'=>[]]);
}
$token=px_bearer($pdo);
if ($route==='api/v1/accounts/verify_credentials' || $route==='api/v1/accounts/1') { px_require_scope($token,'read');px_json(px_actor($pdo)); }
if ($route==='api/pixelfed/v1/accounts/1') { px_require_scope($token,'read');px_json(px_actor($pdo)); }
if (preg_match('#^api/v1/accounts/1/(followers|following)$#',$route,$m) && $method==='GET') { px_require_scope($token,'read');px_json(px_relationship_accounts($pdo,$m[1])); }
if ($route==='api/pixelfed/v1/web/settings') {
    px_require_scope($token,'read');
    px_json(['enable_reblogs'=>false,'hide_collections'=>true,'hide_groups'=>true,'hide_stories'=>true]);
}
if ($route==='api/v1.1/compose/search/location' && $method==='GET') {
    // SNAPSMACK does not maintain a canonical places database. An empty JSON
    // result keeps Pixelix's optional city field harmless and avoids inventing
    // location metadata that cannot be persisted faithfully.
    px_require_scope($token,'read');px_json([]);
}
if (preg_match('#^api/v1\.1/collections/accounts/1$#',$route) && $method==='GET') {
    // Collections are advertised as hidden above; retain an empty fallback for
    // clients that already opened the screen before refreshing capabilities.
    px_require_scope($token,'read');px_json([]);
}
if (($route==='api/v1/media'||$route==='api/v2/media') && $method==='POST') {
    px_gram_authoring_gate($pdo);px_offline_gate($pdo);px_require_scope($token,'write');if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)px_json(['error'=>'file is required'],422);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['file']['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??'';if(!$ext)px_json(['error'=>'Unsupported media type'],422);
    if($_FILES['file']['size']>64*1024*1024)px_json(['error'=>'File is too large'],413);
    px_budget($pdo);
    $ym=date('Y/m');$dir=__DIR__.'/img_uploads/'.$ym;if(!is_dir($dir))mkdir($dir,0755,true);$name='pixelix-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.'.$ext;$path=$dir.'/'.$name;if(!move_uploaded_file($_FILES['file']['tmp_name'],$path))px_json(['error'=>'Could not save media'],500);
    $dim=@getimagesize($path);$rel='img_uploads/'.$ym.'/'.$name;$thumbs=snapsmack_generate_thumbs($rel,__DIR__,SNAPSMACK_THUMB_SQUARE,SNAPSMACK_THUMB_ASPECT_LONG);
    $slug=pathinfo($name,PATHINFO_FILENAME);$ins=$pdo->prepare("INSERT INTO snap_images(img_title,img_slug,img_file,img_description,img_alt,img_date,img_width,img_height,img_status,img_thumb_square,img_thumb_aspect,allow_comments,allow_download) VALUES('',?,?, '',?,NOW(),?,?,'draft',?,?,1,0)");$ins->execute([$slug,$rel,snap_sanitize_alt($_POST['description']??''),(int)($dim[0]??0),(int)($dim[1]??0),$thumbs['sq_path']??null,$thumbs['asp_path']??null]);$iid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO snap_oauth_media(image_id,token_id) VALUES(?,?)')->execute([$iid,(int)$token['id']]);px_json(px_media($pdo,$iid),$route==='api/v2/media'?202:200);
}
if (preg_match('#^api/v1/media/(\d+)$#',$route,$m) && $method==='PUT') {
    px_gram_authoring_gate($pdo);px_offline_gate($pdo);px_require_scope($token,'write');parse_str(file_get_contents('php://input'),$put);$alt=snap_sanitize_alt($put['description']??'');$s=$pdo->prepare('UPDATE snap_images i JOIN snap_oauth_media om ON om.image_id=i.id SET i.img_alt=? WHERE i.id=? AND i.post_id IS NULL AND om.token_id=?');$s->execute([$alt,(int)$m[1],(int)$token['id']]);if($s->rowCount()<1)px_json(['error'=>'Record not found'],404);px_json(px_media($pdo,(int)$m[1]));
}
if ($route==='api/v1/statuses' && $method==='POST') {
    px_gram_authoring_gate($pdo);px_offline_gate($pdo);px_require_scope($token,'write');$input=$_POST;$ctype=(string)($_SERVER['CONTENT_TYPE']??'');if(stripos($ctype,'application/json')!==false){$decoded=json_decode(file_get_contents('php://input'),true);if(is_array($decoded))$input=$decoded;}$ids=$input['media_ids']??[];if(!is_array($ids))$ids=[$ids];$ids=array_values(array_unique(array_map('intval',$ids)));if(!$ids||count($ids)>10)px_json(['error'=>'Attach between 1 and 10 images'],422);
    snapsmack_gram_ensure_post_columns($pdo); // heal a drifted snap_posts BEFORE the txn (ALTER implicit-commits)
    $pdo->beginTransaction();$place=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT i.id FROM snap_images i JOIN snap_oauth_media om ON om.image_id=i.id WHERE i.id IN ($place) AND i.post_id IS NULL AND om.token_id=? FOR UPDATE");$q->execute([...$ids,(int)$token['id']]);$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if(count($valid)!==count($ids)){$pdo->rollBack();px_json(['error'=>'One or more media IDs are invalid'],422);}
    $status=trim((string)($input['status']??''));$allowComments=empty($input['comments_disabled'])?1:0;
    try {
        $members=array_map(static fn($id)=>['image_id'=>$id,'style'=>['crop'=>'fit']],$ids);
        $pid=snapsmack_gram_create_post($pdo,$members,['slug_prefix'=>'pixelix','description'=>$status,'post_type'=>count($ids)>1?'carousel':'single','status'=>'published','allow_comments'=>$allowComments,'fedi_enabled'=>1,'is_sensitive'=>!empty($input['sensitive']),'content_warning'=>$input['spoiler_text']??'']);
        $u=$pdo->prepare("UPDATE snap_images SET img_status='published',img_description=?,allow_comments=? WHERE id=?");foreach($ids as$id)$u->execute([$status,$allowComments,$id]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();px_json(['error'=>'Post could not be created'],500); }
    // Kick the CLI delivery worker so the new post federates NOW, exactly like
    // the CMS's own posting pages do (smack-post-gram.php) — without this a
    // Pixelix post sat in the outbound queue until the next 10-minute cron tick.
    require_once __DIR__ . '/core/fediverse-kick.php';
    sv_kick_delivery();
    px_json(px_status($pdo,$pid));
}
if (preg_match('#^api/v1/statuses/(\d+)$#',$route,$m) && $method==='GET') { px_require_scope($token,'read');$s=px_status($pdo,(int)$m[1]);if(!$s)px_json(['error'=>'Record not found'],404);px_json($s); }
if (preg_match('#^api/v1/statuses/(\d+)/context$#',$route) && $method==='GET') { px_require_scope($token,'read');px_json(['ancestors'=>[],'descendants'=>[]]); }
if (($route==='api/v1/timelines/home'||preg_match('#^api/v1/accounts/1/statuses$#',$route)) && $method==='GET') { px_require_scope($token,'read');px_json(px_timeline($pdo)); }
if ($route==='api/v1/conversations' && $method==='GET') { px_require_scope($token,'read');px_json(px_conversations($pdo)); }
if (preg_match('#^api/v1/conversations/([a-f0-9]{24})/read$#',$route,$m) && $method==='POST') {
    px_require_scope($token,'write');$actor=px_conversation_actor($pdo,$m[1]);if($actor===null)px_json(['error'=>'Record not found'],404);
    $pdo->prepare("UPDATE snap_ap_dms SET is_read=1 WHERE remote_actor_url=? AND direction='in' AND is_deleted=0")->execute([$actor]);px_json([]);
}
if ($method==='GET' && in_array($route,['api/v1/notifications','api/v1/favourites','api/v1/bookmarks','api/v1/follow_requests','api/v1/mutes','api/v1/blocks'],true)) { px_require_scope($token,'read');px_json([]); }
if ($route==='api/v1/timelines/public' && $method==='GET') { px_require_scope($token,'read');px_json(px_timeline($pdo)); }
px_json(['error'=>'This Pixelix operation is not supported by GRAMOFSMACK.'],501);
// ===== SNAPSMACK EOF =====
