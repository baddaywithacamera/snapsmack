<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
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
    ['status'=>200,'kind'=>'post','item'=>['id'=>2,'title'=>'Carousel','content'=>snapsmack_trusted_html('<p>Carousel caption</p>')],'photographs'=>[
        ['id'=>11,'img_title'=>'First','img_alt'=>'First slide','img_file'=>'/media/first.jpg','is_framed'=>true,'frame_style'=>'--slide-bg:#abcdef'],
        ['id'=>12,'img_title'=>'Second','img_alt'=>'Second & slide','img_file'=>'/media/second.jpg','is_framed'=>false,'frame_style'=>''],
    ],'navigation'=>[]],
    ['status'=>200,'kind'=>'search','query'=>'cat','results'=>['photographs'=>[['img_title'=>'Match','img_slug'=>'match','img_file'=>'/media/match.jpg']],'posts'=>[]],'navigation'=>[]],
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
    $layoutSource=(string)file_get_contents($dir.'/layout.php');
    if(str_contains($layoutSource,"snap_render_component('public-page'")) throw new RuntimeException("{$skin} still delegates its document skeleton to the CMS.");
    $styles[$skin]=hash_file('sha256',$dir.'/style.css');
    foreach($responses as $response){
        $view=snapsmack_build_skin_view($response,['site_name'=>'Example','tagline'=>'Tag','language'=>'en','direction'=>'ltr','skin_slug'=>$skin,'skin_style_url'=>'/skins/'.$skin.'/style.css','skin_presentation'=>snapsmack_skin_presentation([], $skin)]);
        ob_start(); $ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view); $html=(string)ob_get_clean();
        if(!$ok||!str_contains($html,'<!doctype html>')||stripos($html,'<script>no</script>')!==false) throw new RuntimeException("{$skin}/{$response['kind']} render boundary failed.");
        $prefix=$prefixes[$skin];
        if(!str_contains($html,'class="'.$prefix.'-content-wrap')) throw new RuntimeException("{$skin}/{$response['kind']} lost its family content hook.");
        foreach(['id="'.$prefix.'-modal-overlay"','id="'.$prefix.'-modal-frame"','id="'.$prefix.'-lightbox"'] as $modalHook)if(!str_contains($html,$modalHook))throw new RuntimeException("{$skin}/{$response['kind']} lost route modal/lightbox hook: {$modalHook}");
        if(($response['kind']??'')==='landing'&&!str_contains($html,'class="'.$prefix.'-tile')) throw new RuntimeException("{$skin} lost its tile contract.");
        if($skin==='game-on'&&($response['kind']??'')==='search'&&(!str_contains($html,'class="go-search-view"')||!str_contains($html,'/media/match.jpg'))) throw new RuntimeException('GAME ON search fell through or lost its photograph results.');
        if(($response['kind']??'')==='post'){
            if(!str_contains($html,'id="tg-carousel" class="ss-slider"')||substr_count($html,'class="slider-slide')!==2)throw new RuntimeException("{$skin} post route lost its CMS photograph carousel.");
            if(substr_count($html,'class="ss-slider-dot')!==3||substr_count($html,'data-slide-index=')!==2)throw new RuntimeException("{$skin} post carousel indicator count drifted from its photographs.");
            foreach(['/media/first.jpg','/media/second.jpg'] as $source)if(!str_contains($html,'data-lightbox-src="'.$source.'"'))throw new RuntimeException("{$skin} post carousel lost its escaped lightbox source.");
            if(!str_contains($html,'alt="Second &amp; slide"'))throw new RuntimeException("{$skin} post carousel alt text escaped its render boundary.");
        }
        if($skin!=='instant-camera'&&$skin!=='sliders'&&(str_contains($html,'class="ic-scrim"')||str_contains($html,'class="ic-panel"'))) throw new RuntimeException("{$skin} received INSTANT CAMERA structure.");
        if($skin==='instant-camera'&&(!str_contains($html,'class="ic-scrim"')||!str_contains($html,'class="ic-panel"'))) throw new RuntimeException('INSTANT CAMERA layout no longer owns its backdrop structure.');
        if($skin==='sliders'&&(!str_contains($html,'class="ic-bg sl-glide-bg"')||!str_contains($html,'class="ic-scrim"')||!str_contains($html,'data-glide-wall'))) throw new RuntimeException('SLIDERS layout no longer owns its moving-wall and controlled scrim structure.');
        if($skin==='parade'&&(!str_contains($html,'class="pa-parade-bg pa-flag-bg"')||!str_contains($html,'class="pa-panel"'))) throw new RuntimeException('PARADE layout no longer owns its backdrop structure.');
        if($skin!=='parade'&&(str_contains($html,'class="pa-parade-bg')||str_contains($html,'class="pa-panel"'))) throw new RuntimeException("{$skin} received PARADE structure.");
    }
}
if(count(array_unique($styles))!==count($styles)) throw new RuntimeException('Grid-family presentations are not distinct.');
$renderHelpers=(string)file_get_contents($root.'/core/skin-render-helpers.php');
foreach(['gram-page','ic-scrim','pa-parade-bg','go-puzzle-field'] as $leakedSkeleton)if(str_contains($renderHelpers,$leakedSkeleton))throw new RuntimeException("CMS render helpers retain grid-family skeleton: {$leakedSkeleton}");
$instantCameraCss=(string)file_get_contents($root.'/skins/instant-camera/style.css');
if(!preg_match('/body\s*\{[^}]*isolation:\s*isolate\s*;/s',$instantCameraCss)) throw new RuntimeException('Instant Camera lost its bounded stacking context.');
foreach(['.ic-bg'=>0,'.ic-scrim'=>1,'.ic-panel'=>2,'.tg-content-wrap'=>3] as $selector=>$layer){
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
$paradeMinimum=snapsmack_skin_presentation(['pa_panel_opacity'=>'0','pa_navbar_opacity'=>'0','pa_flag_opacity'=>'0','pa_flag_speed'=>'1'],'parade');
$paradeMaximum=snapsmack_skin_presentation(['pa_panel_opacity'=>'100','pa_navbar_opacity'=>'100','pa_flag_opacity'=>'100','pa_flag_speed'=>'100'],'parade');
if(!str_contains((string)$paradeMinimum['style'],'--panel-bg:transparent')||!str_contains((string)$paradeMaximum['style'],'--panel-bg:rgba(255,255,255,1.00)'))throw new RuntimeException('PARADE panel opacity control sweep failed.');
if(($paradeMinimum['flag']['opacity']??-1)!==0||($paradeMaximum['flag']['opacity']??-1)!==100||($paradeMinimum['flag']['speed']??-1)!==1||($paradeMaximum['flag']['speed']??-1)!==100)throw new RuntimeException('PARADE flag controls did not own their rendered values.');
$frameMinimum=snapsmack_skin_presentation(['go_frame_size_pct'=>'75','go_frame_border_px'=>'0','go_frame_border_color'=>'#123456','go_frame_bg_color'=>'#abcdef','go_frame_shadow'=>'0'],'game-on');
$frameMaximum=snapsmack_skin_presentation(['go_frame_size_pct'=>'100','go_frame_border_px'=>'20','go_frame_border_color'=>'#654321','go_frame_bg_color'=>'#fedcba','go_frame_shadow'=>'3'],'game-on');
foreach(['--tile-img-size:75%','--tile-border-w:0px','--tile-border-c:#123456','--tile-bg:#abcdef','--tile-shadow:none'] as $expected)if(!str_contains((string)$frameMinimum['style'],$expected))throw new RuntimeException("Grid frame minimum control lost: {$expected}");
foreach(['--tile-img-size:100%','--tile-border-w:20px','--tile-border-c:#654321','--tile-bg:#fedcba','--tile-shadow:12px 12px 32px'] as $expected)if(!str_contains((string)$frameMaximum['style'],$expected))throw new RuntimeException("Grid frame maximum control lost: {$expected}");
echo "Grid-family schema-v2 regression passed.\n";
// ===== SNAPSMACK EOF =====
