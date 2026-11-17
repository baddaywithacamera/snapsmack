<?php
// SNAPSMACK_EOF_HEADER: last non-empty line is the canonical EOF marker.
declare(strict_types=1);
require_once __DIR__.'/../core/alt-text.php';
$source=file_get_contents(__DIR__.'/../core/fediverse.php');
$start=strpos($source,'function sv_image_attachment(');$end=strpos($source,"\nfunction ",$start+1);
$function=substr($source,$start,$end-$start);$function=substr($function,0,strrpos($function,"\n}")+2);eval($function);
function sv_fedi_bake_url(array $img,array $settings): ?string { return $settings['bake']??null; }
function sv_frame_url(array $img,array $settings): ?string { return $settings['frame']??null; }
function sv_base(array $settings): string { return 'https://fixture.invalid/'; }
function sv_media_type(string $file): string { return 'image/jpeg'; }
function sv_focal_point(array $img): ?array { return null; }
$checks=0;
foreach ([[],['frame'=>'https://fixture.invalid/frame.jpg'],['bake'=>'https://fixture.invalid/bake.jpg']] as $settings) {
    foreach ([true,false] as $derive) {
        $img=['img_alt'=>' A door < & "light" > ', 'img_title'=>'Title','img_description'=>'Caption','img_file'=>'door.jpg'];
        $att=sv_image_attachment($img,$settings,'Caller fallback',$derive);
        if($att['name']!=='A door < & "light" >')throw new RuntimeException('Authored ALT lost');
        if(json_decode(json_encode($att),true)['name']!==$att['name'])throw new RuntimeException('ALT JSON roundtrip changed');
        $img['img_alt']=' ';
        if(sv_image_attachment($img,$settings,'Caller fallback',$derive)['name']!=='Caller fallback')throw new RuntimeException('Caller fallback lost');
        $att=sv_image_attachment($img,$settings,'',$derive);
        if($att['name']!==($derive?'Title':''))throw new RuntimeException('Derive contract changed');
        $checks+=4;
    }
}
echo "Federation attachment ALT: $checks checks PASS; network delivery NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
