<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
$root=dirname(__DIR__);$dir=$root.'/skins/show-n-tell';
$site=['site_name'=>'Example','tagline'=>'Portfolio','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/show-n-tell/style.css','skin_presentation'=>['style'=>snapsmack_trusted_html(''),'options'=>['htbs_slider_enabled'=>'1','htbs_slider_autoplay'=>'1','htbs_slider_interval'=>'5000','htbs_overlay_enabled'=>'1','htbs_overlay_position'=>'bottom-left','htbs_overlay_style'=>'scrim','htbs_overlay_name'=>'Photographer','htbs_overlay_tagline'=>'Portfolio','htbs_grid_row_height'=>'280','htbs_frame_style'=>'galleria']]];
$landing=snapsmack_build_skin_view(['status'=>200,'kind'=>'landing','items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_file'=>'/media/photo.jpg','img_thumb_aspect'=>'/media/thumb.jpg']],'navigation'=>[]],$site);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$landing);$html=(string)ob_get_clean();
foreach(['snt-header','snt-hero-slider','ss-slider','snt-hero-slide','snt-overlay--bottom-left','snt-overlay--scrim','snt-grid-section','snt-justified-grid'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SHOW N TELL landing lost {$hook}.");
foreach(['data-auto-advance="true"','data-auto-interval="5000"','data-row-height="280"'] as $owned)if(!str_contains($html,$owned))throw new RuntimeException("SHOW N TELL control lost authority over {$owned}.");
$photo=snapsmack_build_skin_view(['status'=>200,'kind'=>'photo','item'=>['img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Description</p>')],'navigation'=>[]],$site);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$photo);$html=(string)ob_get_clean();foreach(['snt-single','snt-frame-galleria','frame-mount','frame-border','frame-mat','frame-image','post-description'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SHOW N TELL photo route lost {$hook}.");
echo "SHOW N TELL parity regression passed.\n";
