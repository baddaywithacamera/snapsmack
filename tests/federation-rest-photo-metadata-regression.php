<?php
// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
declare(strict_types=1);
$source=file_get_contents(__DIR__.'/../core/fediverse.php');
foreach(['sv_client_plain_text','sv_account_actor_id','sv_rest_photo_metadata','sv_map_status_row','sv_map_tag_status','sv_masto_map_statuses'] as $name){
 $start=strpos($source,'function '.$name.'(');$end=strpos($source,"\n}",$start)+2;eval(substr($source,$start,$end-$start));
}
$checks=0;
function rest_metadata_check(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$status=['uri'=>'https://remote.invalid/objects/1','content'=>'<p>A caption</p>','sensitive'=>true,'spoiler_text'=>'Storm < lightning & snow > rain','account'=>['uri'=>'https://remote.invalid/actor'],'media_attachments'=>[
 ['type'=>'video','url'=>'https://remote.invalid/video','description'=>'not a photograph'],
 ['type'=>'image','url'=>'','description'=>'no usable URL'],
 ['type'=>'image','url'=>'https://remote.invalid/one.jpg','description'=>'Door <left> & "right"'],
 ['type'=>'image','preview_url'=>'https://remote.invalid/two.jpg','description'=>'Second photograph'],
]];
foreach(['sv_map_status_row','sv_map_tag_status','sv_masto_map_statuses'] as $mapper){
 $row=$mapper==='sv_masto_map_statuses'?$mapper([$status],5)[0]:$mapper($status,'remote.invalid');
 rest_metadata_check($row['sensitive']===true,$mapper.' loses sensitive flag');
 rest_metadata_check($row['summary']==='Storm < lightning & snow > rain',$mapper.' loses plain content warning');
 rest_metadata_check($row['images']===['https://remote.invalid/one.jpg','https://remote.invalid/two.jpg'],$mapper.' changes URL list');
 rest_metadata_check(count($row['attachments'])===2,$mapper.' metadata is not aligned');
 rest_metadata_check($row['attachments'][0]['description']==='Door <left> & "right"',$mapper.' loses authored ALT');
 rest_metadata_check($row['attachments'][1]['description']==='Second photograph',$mapper.' loses second ALT');
 $plain=$status;unset($plain['sensitive'],$plain['spoiler_text']);$plain['media_attachments'][2]['description']='';
 $row=$mapper==='sv_masto_map_statuses'?$mapper([$plain],5)[0]:$mapper($plain,'remote.invalid');
 rest_metadata_check($row['sensitive']===false && $row['summary']==='',$mapper.' invents warning');
 rest_metadata_check($row['attachments'][0]['description']==='',$mapper.' invents ALT');
 $text=$plain;$text['media_attachments']=[];
 rest_metadata_check(($mapper==='sv_masto_map_statuses'?$mapper([$text],5):$mapper($text,'remote.invalid'))===($mapper==='sv_masto_map_statuses'?[]:null),$mapper.' changes photo-only contract');
}
$boundary=['media_attachments'=>[null, ['type'=>'image','url'=>'https://remote.invalid/long.jpg','description'=>str_repeat('é',2001)]]];
$metadata=sv_rest_photo_metadata($boundary);
rest_metadata_check(count($metadata['attachments'])===1,'non-array media must be ignored');
rest_metadata_check(mb_strlen($metadata['attachments'][0]['description'])===2000,'ALT must retain a valid 2000-character boundary');
rest_metadata_check(mb_check_encoding($metadata['attachments'][0]['description'],'UTF-8'),'ALT boundary must preserve UTF-8');
rest_metadata_check(sv_rest_photo_metadata([])['attachments']===[],'missing media list must remain empty');
rest_metadata_check(mb_strlen(sv_rest_photo_metadata(['spoiler_text'=>str_repeat('é',5001)])['summary'])===5000,'CW boundary must retain 5000 characters');
echo "REST photo metadata: $checks checks PASS; network and installed renderer NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
