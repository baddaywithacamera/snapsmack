<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('scroll');
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
$root=dirname(__DIR__);$dir=$root.'/skins/scroll';
$site=['site_name'=>'Example','owner_name'=>'Photographer','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/scroll/style.css','skin_presentation'=>['style'=>snapsmack_trusted_html(''),'options'=>['scroll_byline_prefix'=>'PHOTOGRAPHY BY','photographer_name'=>'Photographer','scroll_masthead_lines'=>'USED CAR|PARTS','scroll_wall_layout'=>'square']]];
$landing=snapsmack_build_skin_view(['status'=>200,'kind'=>'landing','items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_aspect'=>'/media/thumb.jpg']],'navigation'=>[]],$site);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$landing);$html=(string)ob_get_clean();
foreach(['scroll-profile','scroll-photographer','scroll-masthead','scroll-sticky-nav','scroll-wall','ss-square-wall','ss-masonry-item'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SCROLL landing lost {$hook}.");
$photo=snapsmack_build_skin_view(['status'=>200,'kind'=>'photo','item'=>['img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Description</p>')],'navigation'=>[]],$site);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$photo);$html=(string)ob_get_clean();foreach(['scroll-solo-header','scroll-solo-stage','scroll-solo-photobox','scroll-infobox'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SCROLL photo route lost {$hook}.");
echo "PASS: scroll behavior is CMS-owned and its presentation contract is strict.
";
