<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$skins = ['the-grid','aurora','sudden-impact','parade','jive-turkey','heuristic','instant-camera','game-on','sliders'];
$responses = [
    ['status'=>200,'kind'=>'landing','items'=>[['img_title'=>'Photo','img_slug'=>'photo']],'navigation'=>[]],
    ['status'=>200,'kind'=>'photo','item'=>['id'=>1,'img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Caption</p>')],'comments'=>[['comment_author'=>'Reader','comment_text'=>'<script>no</script>']],'navigation'=>[]],
    ['status'=>200,'kind'=>'search','results'=>['photographs'=>[['img_title'=>'Match']],'posts'=>[]],'navigation'=>[]],
    ['status'=>404,'kind'=>'not_found','navigation'=>[]],
];
$styles=[];
foreach($skins as $skin){
    $dir=$root.'/skins/'.$skin;
    $manifest=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['schema_version']??0)!==2||($manifest['security_policy']??0)!==2||($manifest['cms_controller']??'')!=='public') throw new RuntimeException("{$skin} manifest boundary failed.");
    $php=glob($dir.'/*.php')?:[];
    if(count($php)!==1||basename($php[0])!=='layout.php') throw new RuntimeException("{$skin} retains legacy PHP.");
    $styles[$skin]=hash_file('sha256',$dir.'/style.css');
    foreach($responses as $response){
        $view=snapsmack_build_skin_view($response,['site_name'=>'Example','tagline'=>'Tag','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/'.$skin.'/style.css']);
        ob_start(); $ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view); $html=(string)ob_get_clean();
        if(!$ok||!str_contains($html,'<!doctype html>')||stripos($html,'<script>no</script>')!==false) throw new RuntimeException("{$skin}/{$response['kind']} render boundary failed.");
    }
}
if(count(array_unique($styles))!==count($styles)) throw new RuntimeException('Grid-family presentations are not distinct.');
echo "Grid-family schema-v2 regression passed.\n";
