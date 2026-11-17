<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../core/fediverse.php');
foreach(['sv_is_direct','sv_reply_is_public','sv_handle_inbox'] as $name){$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\nfunction ",$start+1);$f=substr($source,$start,$end-$start);$f=substr($f,0,strrpos($f,"\n}")+2);eval($f);}
final class PublicReplyPath extends RuntimeException {}
function sv_actor_url(array $settings): string{return 'https://local.invalid/ap/actor';}
function sv_cache_actor(PDO $pdo,array $actor):void{}
function sv_capture_dm(PDO $pdo,array $settings,array $obj,string $actor,string $handle):void{$GLOBALS['dm_count']++;}
function sv_inbox_log(PDO $pdo,string $type,string $actor,?string $object,string $message):void{$GLOBALS['receipts'][]=[$object,$message];}
function sv_url_is_ours(string $url,array $settings):bool{return true;}
function sv_resolve_target(string $url,PDO $pdo):?array{throw new PublicReplyPath();}
$pdo=new PDO('sqlite::memory:');$settings=[];$actor=['id'=>'https://remote.invalid/users/test','preferredUsername'=>'test'];
$public='https://www.w3.org/ns/activitystreams#Public';$followers='https://remote.invalid/users/test/followers';
$cases=[
 ['followers-only',['to'=>[$followers]],[],false,false],
 ['no-address',[],[],false,false],
 ['explicit-empty-object',['to'=>[]],['to'=>[$public]],false,false],
 ['wrapper-cannot-promote',['to'=>[$followers]],['to'=>[$public]],false,false],
 ['public-to',['to'=>[$public]],[],true,false],
 ['public-cc',['to'=>[$followers],'cc'=>[$public]],[],true,false],
 ['public-string',['to'=>$public],[],true,false],
 ['compact-public',['cc'=>['as:Public']],[],true,false],
 ['wrapper-only',[],['to'=>[$public]],true,false],
 ['direct',['to'=>['https://local.invalid/ap/actor']],[],false,true],
];$checks=0;
foreach($cases as [$label,$address,$wrapper,$publicExpected,$dmExpected]){
 $obj=['id'=>'https://remote.invalid/private-note','type'=>'Note','inReplyTo'=>'https://local.invalid/ap/note/p/1','content'=>'Private body']+$address;
 $activity=['type'=>'Create','actor'=>$actor['id'],'object'=>$obj]+$wrapper;
 $GLOBALS['dm_count']=0;$GLOBALS['receipts']=[];$publicReached=false;
 try{$status=sv_handle_inbox($pdo,$settings,$activity,$actor);if($status!==202)throw new RuntimeException('Wrong acknowledgement');}catch(PublicReplyPath $e){$publicReached=true;}
 if($publicReached!==$publicExpected)throw new RuntimeException($label.': wrong public path');
 if(($GLOBALS['dm_count']===1)!==$dmExpected)throw new RuntimeException($label.': DM handling changed');
 if(!$publicExpected)foreach($GLOBALS['receipts'] as [$id,$message])if($id!==null||str_contains($message,'Private body'))throw new RuntimeException('Private content logged');
 $checks+=3;
}
echo "Federation reply audience: $checks checks PASS; public path stopped before target lookup, no live probes.\n";
// ===== SNAPSMACK EOF =====
