<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../core/fediverse.php');
foreach(['sv_activity_object_uri','sv_inbox_log','sv_handle_inbox'] as $name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);$f=substr($source,$start,$end-$start);$f=substr($f,0,strrpos($f,"\n}")+2);eval($f);}
function sv_actor_url(array $settings):string{return 'https://local.invalid/ap/actor';}
function sv_url_is_ours(string $url,array $settings):bool{return parse_url($url,PHP_URL_HOST)==='local.invalid';}
function sv_verify_forwarded_create(array $activity,string $actor,string $signer=''):?array{return null;}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_ap_followers(actor_url TEXT PRIMARY KEY,is_active INTEGER);CREATE TABLE snap_ap_inbox_log(id INTEGER PRIMARY KEY AUTOINCREMENT,verb TEXT,actor_url TEXT,object_ref TEXT,outcome TEXT)');
$pdo->exec("INSERT INTO snap_ap_followers VALUES('https://remote.invalid/actor',1),('https://other.invalid/actor',1)");
$actor=['id'=>'https://remote.invalid/actor'];$settings=[];$checks=0;
function checkModeration(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$send=function(string $type,$object,array $extra=[])use($pdo,&$settings,$actor){return sv_handle_inbox($pdo,$settings,['type'=>$type,'actor'=>$actor['id'],'object'=>$object]+$extra,$actor);};
checkModeration($send('Block','https://other.invalid/actor')===202,'foreign block acknowledged');
checkModeration((int)$pdo->query("SELECT is_active FROM snap_ap_followers WHERE actor_url='https://remote.invalid/actor'")->fetchColumn()===1,'foreign block does not deactivate sender');
foreach(['https://local.invalid/ap/actor',['id'=>'https://local.invalid/ap/actor']]as $object)checkModeration($send('Block',$object)===202,'local block acknowledged idempotently');
checkModeration((int)$pdo->query("SELECT is_active FROM snap_ap_followers WHERE actor_url='https://remote.invalid/actor'")->fetchColumn()===0,'sender deactivated');
checkModeration((int)$pdo->query("SELECT is_active FROM snap_ap_followers WHERE actor_url='https://other.invalid/actor'")->fetchColumn()===1,'other follower untouched');
checkModeration(sv_handle_inbox($pdo,$settings,['type'=>'Block','actor'=>'https://forged.invalid/actor','object'=>'https://local.invalid/ap/actor'],$actor)===401,'actor mismatch rejected');
checkModeration($send('Flag',['https://foreign.invalid/post','https://local.invalid/post'],['content'=>'<b>Review this</b> '.str_repeat('é',200)])===202,'report accepted');
$row=$pdo->query("SELECT * FROM snap_ap_inbox_log WHERE verb='Flag' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
checkModeration($row['object_ref']==='https://local.invalid/post','local report target retained');
checkModeration(str_contains($row['outcome'],'REPORT for owner review')&&!str_contains($row['outcome'],'<b>'),'owner receipt plain text');
checkModeration(strlen($row['outcome'])<=190&&mb_check_encoding($row['outcome'],'UTF-8'),'report receipt bounded valid UTF8');
checkModeration($send('Flag','https://foreign.invalid/post')===202,'foreign report acknowledged');
checkModeration($pdo->query("SELECT object_ref FROM snap_ap_inbox_log ORDER BY id DESC LIMIT 1")->fetchColumn()===null,'foreign report not promoted to owner target');
foreach([['type'=>'Article','content'=>'PRIVATE BODY'],'https://remote.invalid/opaque',['type'=>['Note','Image'],'content'=>'PRIVATE BODY']]as $object){checkModeration($send('Create',$object)===202,'unsupported create acknowledged');$row=$pdo->query('SELECT * FROM snap_ap_inbox_log ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);checkModeration($row['verb']==='Create'&&$row['object_ref']===null&&!str_contains($row['outcome'],'PRIVATE BODY')&&str_contains($row['outcome'],'unsupported object type'),'unsupported type leaves privacy-safe receipt');}
echo "Federation moderation/unsupported receipts: $checks checks PASS. Signature verification, queued delivery and installed UI NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
