<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$root=dirname(__DIR__);$inventory=require $root.'/core/manifest-inventory.php';
foreach(['photogram','onyx'] as $skin){
 $dir=$root.'/skins/'.$skin;$m=json_decode((string)file_get_contents($dir.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($m['schema_version']??0)!==2||($m['security_policy']??0)!==2||($m['cms_controller']??'')!=='public')throw new RuntimeException("{$skin} boundary failed.");
 $php=glob($dir.'/*.php')?:[];if(count($php)!==1||basename($php[0])!=='layout.php')throw new RuntimeException("{$skin} retains legacy PHP.");
 $view=snapsmack_build_skin_view(['status'=>200,'kind'=>'landing','items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_aspect'=>'/media/thumb.jpg']],'photo_count'=>1,'like_count'=>2,'comment_count'=>3,'navigation'=>[['label'=>'About','url'=>'/about']]],['site_name'=>'Example','site_description'=>'Biography','avatar_url'=>'/media/avatar.jpg','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/'.$skin.'/style.css']);
 ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view);$html=(string)ob_get_clean();if(!$ok)throw new RuntimeException("{$skin} failed strict rendering.");
 if($skin==='photogram'){
  foreach(['pg-profile-header','pg-profile-stats','pg-grid-cell','pg-nav'] as $hook)if(!str_contains($html,$hook))throw new RuntimeException("PHOTOGRAM lost its {$hook} presentation structure.");
  foreach(['aria-label="Home"','aria-label="Browse photographs"','aria-label="Search"','aria-label="About"','pg-icon-outline','pg-icon-filled'] as $hook)if(!str_contains($html,$hook))throw new RuntimeException("PHOTOGRAM lost its screenshot navigation contract: {$hook}.");
  if(substr_count($html,'href="/about"') < 2)throw new RuntimeException('PHOTOGRAM avatar and profile navigation no longer share the CMS-resolved About destination.');
  foreach(['>Discover<','>Create<','id="pg-discover"','pg-nav-create'] as $invention)if(str_contains($html,$invention))throw new RuntimeException("PHOTOGRAM restored an unrequested text control: {$invention}.");
  $photogramCss=(string)file_get_contents($dir.'/style.css');
  if(!str_contains($photogramCss,'.pg-grid-cell {')||!str_contains($photogramCss,'aspect-ratio: var(--pg-tile-aspect, 1 / 1)')||!str_contains($photogramCss,'.pg-grid-cell img')||!str_contains($photogramCss,'object-fit: cover'))throw new RuntimeException('PHOTOGRAM lost configurable non-square INSTANT CAMERA tile framing.');
  if(str_contains($html,'site-header')||str_contains($html,'archive-grid'))throw new RuntimeException('PHOTOGRAM regressed to the generic public skeleton.');
  $photoView=snapsmack_build_skin_view(['status'=>200,'kind'=>'photo','item'=>['img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Caption</p>')],'navigation'=>[]],['site_name'=>'Example','avatar_url'=>'/media/avatar.jpg','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/photogram/style.css']);
  ob_start();$photoOk=snapsmack_render_strict_skin_template($dir,'layout.php',$photoView);$photoHtml=(string)ob_get_clean();
  foreach(['pg-top-bar','pg-post-author','pg-post-image-wrap','pg-action-bar','pg-caption'] as $hook)if(!$photoOk||!str_contains($photoHtml,$hook))throw new RuntimeException("PHOTOGRAM photo route lost its {$hook} presentation structure.");
 }
}
$instantCompanion=snapsmack_skin_presentation([
 'active_skin'=>'instant-camera',
 'instant-camera__ic_format'=>'polaroid',
], 'photogram');
if(!str_contains((string)($instantCompanion['style']??''),'--pg-tile-aspect:823 / 1000'))throw new RuntimeException('PHOTOGRAM no longer inherits the CMS-owned INSTANT CAMERA print ratio.');
$ordinaryCompanion=snapsmack_skin_presentation(['active_skin'=>'the-grid'], 'photogram');
if(str_contains((string)($ordinaryCompanion['style']??''),'snapsmack-photogram-print-format'))throw new RuntimeException('PHOTOGRAM inherited an INSTANT CAMERA format on an unrelated site.');
$photogram=json_decode((string)file_get_contents($root.'/skins/photogram/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(empty($photogram['features']['mobile_only']))throw new RuntimeException('PHOTOGRAM lost its mobile role.');
foreach(['smack-photogram','smack-photogram-feed'] as $handle)if(empty($inventory['scripts'][$handle]['path']))throw new RuntimeException("{$handle} is not CMS registered.");
$onyx=json_decode((string)file_get_contents($root.'/skins/onyx/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(empty($onyx['features']['fedistructure_only']))throw new RuntimeException('ONYX lost its service role.');
echo "Special-role schema-v2 skin regression passed.\n";
