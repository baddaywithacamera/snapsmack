<?php
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('slickr');
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
$root=dirname(__DIR__);$dir=$root.'/skins/slickr';
$landing=snapsmack_build_skin_view(['status'=>200,'kind'=>'landing','photo_count'=>1,'items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_aspect'=>'/media/thumb.jpg']],'rows'=>[['full'=>false,'items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_aspect'=>'/media/thumb.jpg','presentation_flex'=>150,'presentation_aspect'=>1.5]]]],'navigation'=>[]],['site_name'=>'Example','tagline'=>'Tagline','avatar_url'=>'/media/avatar.jpg','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/slickr/style.css','slickr_profile'=>['cover_url'=>'/media/cover.jpg','stats'=>[['value'=>'1','label'=>'Photos']],'tabs'=>[]]]);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$landing);$html=(string)ob_get_clean();
foreach(['sl-masthead','sl-cover','sl-profile-tabs','sl-landing-stream','justified-item'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SLICKR landing lost its {$hook} presentation structure.");
$photo=snapsmack_build_skin_view(['status'=>200,'kind'=>'photo','item'=>['img_title'=>'Photo','img_file'=>'/media/photo.jpg','img_description'=>snapsmack_trusted_html('<p>Description</p>'),'exif'=>['Camera'=>'Test']],'comments'=>[],'navigation'=>[]],['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/slickr/style.css','skin_presentation'=>snapsmack_skin_presentation([], 'slickr')]);
ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$photo);$html=(string)ob_get_clean();
foreach(['sl-single-flow','sl-main-column','sl-photo-wrap','sl-description-block','sl-sidebar','sl-metadata-table'] as $hook)if(!$ok||!str_contains($html,$hook))throw new RuntimeException("SLICKR photo route lost its {$hook} presentation structure.");
echo "PASS: slickr behavior is CMS-owned and its presentation contract is strict.
";
