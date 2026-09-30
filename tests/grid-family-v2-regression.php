<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$root = dirname(__DIR__);
$skins = ['the-grid','aurora','sudden-impact','parade','jive-turkey','heuristic','instant-camera','game-on','sliders'];
$responses = [
    ['status'=>200,'kind'=>'landing','items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_aspect'=>'/media/thumb.jpg']],'navigation'=>[]],
    ['status'=>200,'kind'=>'photo','item'=>['id'=>1,'img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Caption</p>')],'comments'=>[['comment_author'=>'Reader','comment_text'=>'<script>no</script>']],'navigation'=>[]],
    ['status'=>200,'kind'=>'search','results'=>['photographs'=>[['img_title'=>'Match']],'posts'=>[]],'navigation'=>[]],
    ['status'=>404,'kind'=>'not_found','navigation'=>[]],
];
$styles=[];
$prefixes=['the-grid'=>'tg','aurora'=>'au','sudden-impact'=>'tg','parade'=>'pa','jive-turkey'=>'jt','heuristic'=>'he','instant-camera'=>'tg','game-on'=>'go','sliders'=>'tg'];
foreach($skins as $skin){
    $dir=$root.'/skins/'.$skin;
    $manifest=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
    if(($manifest['schema_version']??0)!==2||($manifest['security_policy']??0)!==2||($manifest['cms_controller']??'')!=='public') throw new RuntimeException("{$skin} manifest boundary failed.");
    $php=glob($dir.'/*.php')?:[];
    if(count($php)!==1||basename($php[0])!=='layout.php') throw new RuntimeException("{$skin} retains legacy PHP.");
    $styles[$skin]=hash_file('sha256',$dir.'/style.css');
    foreach($responses as $response){
        $view=snapsmack_build_skin_view($response,['site_name'=>'Example','tagline'=>'Tag','language'=>'en','direction'=>'ltr','skin_slug'=>$skin,'skin_style_url'=>'/skins/'.$skin.'/style.css','skin_presentation'=>snapsmack_skin_presentation([], $skin)]);
        ob_start(); $ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view); $html=(string)ob_get_clean();
        if(!$ok||!str_contains($html,'<!doctype html>')||stripos($html,'<script>no</script>')!==false) throw new RuntimeException("{$skin}/{$response['kind']} render boundary failed.");
        $prefix=$prefixes[$skin];
        if(!str_contains($html,'class="'.$prefix.'-content-wrap')) throw new RuntimeException("{$skin}/{$response['kind']} lost its family content hook.");
        if(($response['kind']??'')==='landing'&&!str_contains($html,'class="'.$prefix.'-tile')) throw new RuntimeException("{$skin} lost its tile contract.");
        if($skin!=='instant-camera'&&(str_contains($html,'class="ic-scrim"')||str_contains($html,'class="ic-panel"'))) throw new RuntimeException("{$skin} received INSTANT CAMERA structure.");
        if($skin==='instant-camera'&&(!str_contains($html,'class="ic-scrim"')||!str_contains($html,'class="ic-panel"'))) throw new RuntimeException('INSTANT CAMERA layout no longer owns its backdrop structure.');
    }
}
if(count(array_unique($styles))!==count($styles)) throw new RuntimeException('Grid-family presentations are not distinct.');
$instantCameraCss=(string)file_get_contents($root.'/skins/instant-camera/style.css');
if(!preg_match('/body\s*\{[^}]*isolation:\s*isolate\s*;/s',$instantCameraCss)) throw new RuntimeException('Instant Camera lost the stacking context that keeps its negative backdrop layers visible.');
foreach(['.ic-bg'=>-3,'.ic-scrim'=>-2,'.ic-panel'=>-1] as $selector=>$layer){
    if(!preg_match('/'.preg_quote($selector,'/').'\s*\{[^}]*z-index:\s*'.preg_quote((string)$layer,'/').'\s*;/s',$instantCameraCss)) throw new RuntimeException("Instant Camera backdrop layer {$selector} lost its z-index contract.");
}
$game=snapsmack_skin_presentation(['go_puzzle_mode'=>'moving','go_puzzle_density'=>'500','go_modal_theme'=>'invalid'],'game-on');
if(($game['options']['go_puzzle_mode']??'')!=='moving'||($game['options']['go_puzzle_density']??-1)!==100.0||($game['options']['go_modal_theme']??'')==='invalid') throw new RuntimeException('GAME ON options escaped central manifest validation.');
$instantManifest=json_decode((string)file_get_contents($root.'/skins/instant-camera/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$defaultScrim=(float)$instantManifest['options']['ic_scrim']['default'];
$instantDefault=snapsmack_skin_presentation([],'instant-camera');
$instantMinimum=snapsmack_skin_presentation(['ic_scrim'=>'10','ic_panel_opacity'=>'0'],'instant-camera');
$instantMaximum=snapsmack_skin_presentation(['ic_scrim'=>'90','ic_panel_opacity'=>'100'],'instant-camera');
foreach([[$instantDefault,number_format($defaultScrim/100,2,'.','')],[$instantMinimum,'0.10'],[$instantMaximum,'0.90']] as [$presentation,$expected]){
    if(!str_contains((string)$presentation['style'],'--ic-scrim:'.$expected)) throw new RuntimeException('INSTANT CAMERA scrim control did not own its rendered value.');
}
if(!str_contains((string)$instantMinimum['style'],'--panel-bg:transparent')||!str_contains((string)$instantMaximum['style'],'--panel-bg:rgba(255,255,255,1.00)')) throw new RuntimeException('INSTANT CAMERA panel opacity control sweep failed.');
$presentationSource=(string)file_get_contents($root.'/core/skin-presentation.php');
if(preg_match('/snapsmack_instant_camera_presentation[\s\S]*?\$settings\s*\[\s*[\'\"]ic_/', $presentationSource)) throw new RuntimeException('INSTANT CAMERA presentation bypasses its manifest-declared controls.');
echo "Grid-family schema-v2 regression passed.\n";
