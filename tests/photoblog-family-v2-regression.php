<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$root=dirname(__DIR__);
$skins=['50-shades-of-noah-grey','52-card-pickup','chaplin','full-monty','galleria','glide','hip-to-be-square',
 'impact-printer','new-horizon','rational-geo','scroll','show-n-tell','slickr','true-grit'];
$response=['status'=>200,'kind'=>'photo','item'=>['id'=>1,'img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Caption</p>')],'comments'=>[],'navigation'=>[]];
foreach($skins as $skin){
 $dir=$root.'/skins/'.$skin;$m=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($m['schema_version']??0)!==2||($m['security_policy']??0)!==2||($m['cms_controller']??'')!=='public')throw new RuntimeException("{$skin} boundary failed.");
 $php=glob($dir.'/*.php')?:[];if(count($php)!==1||basename($php[0])!=='layout.php')throw new RuntimeException("{$skin} retains legacy PHP.");
 $view=snapsmack_build_skin_view($response,['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/'.$skin.'/style.css','skin_presentation'=>snapsmack_skin_presentation([],$skin)]);
 ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view);$html=(string)ob_get_clean();
 if(!$ok||!str_contains($html,'<p>Caption</p>'))throw new RuntimeException("{$skin} failed central rendering.");
}

// Home-page contracts are as important as solo-photo routes.  Galleria and
// Hip 2 B Square own framed slider landings; neither may collapse to a list of
// titles during another strict-template migration.
$landingItem=['id'=>1,'img_title'=>'Photo','img_slug'=>'photo','img_file'=>'/media/photo.jpg','img_thumb_square'=>'/media/thumb.jpg','img_thumb_aspect'=>'/media/thumb-a.jpg'];
$landingResponse=['status'=>200,'kind'=>'landing','items'=>[$landingItem],'slider_items'=>[$landingItem],'navigation'=>[]];
foreach(['galleria','hip-to-be-square'] as $skin){
 $dir=$root.'/skins/'.$skin;
 $view=snapsmack_build_skin_view($landingResponse,['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/'.$skin.'/style.css','skin_presentation'=>snapsmack_skin_presentation([],$skin)]);
 ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view);$html=(string)ob_get_clean();
 foreach(['id="htbs-gallery-slider"','class="slider-track"','class="frame-mount"','src="/media/'] as $hook){
  if(!$ok||!str_contains($html,$hook))throw new RuntimeException("{$skin} landing lost {$hook}.");
 }
}

// Classic SMACKONEOUT home pages must remain newest-photo presentations.
$controller=(string)file_get_contents($root.'/core/public-controller.php');
foreach(['50-shades-of-noah-grey','full-monty','impact-printer','new-horizon','rational-geo','true-grit'] as $skin){
 if(!str_contains($controller,"'{$skin}'"))throw new RuntimeException("{$skin} lost its solo-home contract.");
}
foreach(['photographLanding(1, 0)','photographById($latestId)','snapsmack_photo_response($repository, $item, $navigation)'] as $contract){
 if(!str_contains($controller,$contract))throw new RuntimeException("Solo-home routing lost {$contract}.");
}
echo "Photoblog schema-v2 family regression passed.\n";
