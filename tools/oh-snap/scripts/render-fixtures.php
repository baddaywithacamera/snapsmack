<?php
/** SNAPSMACK_EOF_HEADER: build-time production template fixtures. */
require_once dirname(__DIR__, 3) . '/core/trusted-html.php';
require_once dirname(__DIR__, 3) . '/core/skin-security-policy.php';
require_once dirname(__DIR__, 3) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__, 3) . '/core/skin-view-contract.php';
if (!defined('BASE_URL')) define('BASE_URL', '/');
$layout = dirname(__DIR__) . '/kit/layout.php';
$findings = snapsmack_skin_policy_scan_template_v2($layout, 'layout.php');
if ($findings) throw new RuntimeException(json_encode($findings));
$item = ['title'=>'The Long Way Home', 'img_title'=>'The Long Way Home', 'img_slug'=>'sample', 'url'=>'/sample', 'img_file'=>'/preview-photo.jpg', 'img_thumb_aspect'=>'/preview-photo.jpg', 'excerpt'=>'A quiet road, late light, and enough time to notice.', 'content'=>snapsmack_trusted_html('<p>A quiet road, late light, and enough time to notice.</p>')];
$out=[];
foreach (['photo','post','archive','landing','page','search','albums','collections','collection','hashtag','blogroll','not_found'] as $kind) {
    $response=['kind'=>$kind,'page_title'=>$kind==='page'?'About the photographer':'Photographs','item'=>$item,'items'=>[$item], 'navigation'=>[['label'=>'Home','url'=>'/'],['label'=>'Archive','url'=>'/archive'],['label'=>'About','url'=>'/about']], 'previous'=>['url'=>'/previous'],'next'=>['url'=>'/next'],'next_page'=>'/archive?page=2'];
    $view=snapsmack_build_skin_view($response,['site_name'=>'My Photo Blog','tagline'=>'Photographs and field notes','language'=>'en','direction'=>'ltr','skin_style_url'=>'style.css']);
    ob_start();
    if (!snapsmack_render_strict_skin_template(dirname($layout),'layout.php',$view)) throw new RuntimeException('Template rendering failed.');
    $out[$kind]=ob_get_clean();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
// ===== SNAPSMACK EOF =====
