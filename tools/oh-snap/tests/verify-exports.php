<?php
/** SNAPSMACK_EOF_HEADER: actual CMS policy and renderer verification of exported skins. */
require_once dirname(__DIR__, 3) . '/core/skin-security-policy.php';
require_once dirname(__DIR__, 3) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__, 3) . '/core/skin-view-contract.php';
define('BASE_URL','/');
$count=0;
foreach (['SMACKONEOUT','GRAMOFSMACK','SMACKTALK'] as $mode) {
    $dir=$argv[1].'/'.$mode;
    $findings=snapsmack_skin_security_gate($dir);
    if ($findings) throw new RuntimeException($mode.': '.json_encode($findings));
    foreach (['photo','post','archive','landing','page','search','hashtag','albums','collections','collection','blogroll','not_found'] as $kind) {
        $item=['title'=>'Unicode 夜 <script>alert(1)</script>','img_file'=>'/photo.jpg','img_thumb_aspect'=>'/thumb.jpg','url'=>'/sample','content'=>SnapTrustedHtml::__snapsmackCmsOnly('<p>Trusted production content.</p>')];
        $view=snapsmack_build_skin_view(['kind'=>$kind,'item'=>$item,'items'=>[$item],'navigation'=>[['label'=>'Home','url'=>'/']]],['site_name'=>'Test <script>','skin_style_url'=>'style.css']);
        ob_start();$ok=snapsmack_render_strict_skin_template($dir,'layout.php',$view);$html=ob_get_clean();
        if (!$ok || !str_contains($html,'<html') || !str_contains($html,'id="system-footer"') || str_contains($html,'<script>alert(1)</script>')) throw new RuntimeException($mode.' '.$kind.' did not render safely.');
        if (in_array($kind,['photo','post','page','collection'],true) && !str_contains($html,'<p>Trusted production content.</p>')) throw new RuntimeException('Trusted content was not rendered through the actual route.');
        $count++;
    }
}
echo "CMS skin gate: 3/3 packages accepted; strict template rendering: {$count}/36 route/mode combinations passed.\n";
// ===== SNAPSMACK EOF =====
