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
 foreach(['class="site-header htbs-header"','id="htbs-gallery-slider"','class="slider-track"','class="frame-mount"','src="/media/'] as $hook){
  if(!$ok||!str_contains($html,$hook))throw new RuntimeException("{$skin} landing lost {$hook}.");
 }
}

// CHAPLIN keeps its silent-film newest-photo landing, not the generic archive
// title list that replaced it during the strict-template migration.
$chaplinDir=$root.'/skins/chaplin';
$chaplinView=snapsmack_build_skin_view($landingResponse,['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/chaplin/style.css','skin_presentation'=>snapsmack_skin_presentation([],'chaplin')]);
ob_start();$chaplinOk=snapsmack_render_strict_skin_template($chaplinDir,'layout.php',$chaplinView);$chaplinHtml=(string)ob_get_clean();
foreach(['id="chap-film-bg"','id="rg-header"','class="chap-landing-link"','id="rg-photobox"','class="chap-img-frame"','src="/media/photo.jpg"'] as $hook){
 if(!$chaplinOk||!str_contains($chaplinHtml,$hook))throw new RuntimeException("chaplin landing lost {$hook}.");
}
$chaplinArchiveResponse=['status'=>200,'kind'=>'archive','items'=>[$landingItem],'navigation'=>[]];
$chaplinArchiveView=snapsmack_build_skin_view($chaplinArchiveResponse,['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/chaplin/style.css','skin_presentation'=>snapsmack_skin_presentation([],'chaplin')]);
ob_start();$chaplinArchiveOk=snapsmack_render_strict_skin_template($chaplinDir,'layout.php',$chaplinArchiveView);$chaplinArchiveHtml=(string)ob_get_clean();
foreach(['class="fsog-archive-grid archive-grid h-feed"','class="fsog-thumb"','id="justified-grid"','src="/media/thumb.jpg"'] as $hook){
 if(!$chaplinArchiveOk||!str_contains($chaplinArchiveHtml,$hook))throw new RuntimeException("chaplin archive lost {$hook}.");
}

// Classic SMACKONEOUT home pages follow the manifest rather than a guessed
// skin-name list. A latest-post homepage without a dedicated landing opens the
// newest photograph; custom landing and archive choices stay on their feeds.
$controller=(string)file_get_contents($root.'/core/public-controller.php');
foreach(["['skin_has_landing']",'$homepageMode === \'latest_post\'','!$skinHasLanding','photographLanding(1, 0)','photographById($latestId)','snapsmack_photo_response($repository, $item, $navigation)'] as $contract){
 if(!str_contains($controller,$contract))throw new RuntimeException("Solo-home routing lost {$contract}.");
}
$entry=(string)file_get_contents($root.'/index.php');
if(!str_contains($entry,"['skin_has_landing'] = !empty(\$_active_manifest['features']['has_landing'])"))throw new RuntimeException('Manifest landing contract is not passed into the public controller.');

// Every settings-driven skin in this family must emit the CMS-generated style
// block. Losing it leaves the HTML intact but silently discards saved fonts,
// dimensions, colours and frame controls.
foreach(['50-shades-of-noah-grey','52-card-pickup','full-monty','galleria','glide','hip-to-be-square','impact-printer','new-horizon','onyx','rational-geo','true-grit'] as $skin){
 $layout=(string)file_get_contents($root.'/skins/'.$skin.'/layout.php');
 if(!str_contains($layout,"skin_presentation']['style"))throw new RuntimeException("{$skin} lost its generated presentation style.");
}

// TRUE GRIT must emit the saved masthead face and its same-origin font source.
// Omitting the CMS-owned presentation block silently falls back to Raleway.
$trueGritPresentation=snapsmack_skin_presentation(['header_font_family'=>'Special Elite'],'true-grit');
$trueGritView=snapsmack_build_skin_view($landingResponse,['site_name'=>'Found Textures','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/true-grit/style.css','skin_presentation'=>$trueGritPresentation]);
ob_start();$trueGritOk=snapsmack_render_strict_skin_template($root.'/skins/true-grit','layout.php',$trueGritView);$trueGritHtml=(string)ob_get_clean();
foreach(["font-family:'Special Elite'",'/font.php?family=Special%20Elite','class="site-title-text"'] as $hook){
 if(!$trueGritOk||!str_contains($trueGritHtml,$hook))throw new RuntimeException("true-grit masthead lost {$hook}.");
}
echo "Photoblog schema-v2 family regression passed.\n";
