<?php
/** SMACKTHEMUP scoped publishing/organization contract regression. */
$root=dirname(__DIR__); $fail=0;
$check=function(bool $ok,string $label)use(&$fail){echo($ok?'ok   ':'FAIL ').$label."\n";if(!$ok)$fail++;};
$api=(string)file_get_contents($root.'/core/smackthemup-api.php');
$router=(string)file_get_contents($root.'/api.php');
$keys=(string)file_get_contents($root.'/smack-api-keys.php');
$gyss=(string)file_get_contents($root.'/core/gyss-api.php');
$nav=(string)file_get_contents($root.'/core/gram-nav-links.php');
$schema=(string)file_get_contents($root.'/database/schema/snapsmack_canonical.sql');
$skins=(string)file_get_contents($root.'/smack-skin.php');
$manifest=(string)file_get_contents($root.'/projects/snapsmack-ca/install-manifest.php');
$footer=(string)file_get_contents($root.'/core/footer.php');
$share=(string)file_get_contents($root.'/assets/js/ss-engine-public-share.js');
$check(str_contains($router,"strpos(\$route, 'smackthemup')"),'dedicated API route exists');
$check(str_contains($api,"key_type='smackthemup_publish'"),'only the SNAP SLAPPER publish key opens the write API');
$check(str_contains($api,"!== 'smackthemup'")&&str_contains($api,'409'),'publishing is mode-bound and wrong mode is 409');
$check(str_contains($api,"visibility']??'public'")&&str_contains($api,'public-only'),'non-public visibility is refused');
$check(str_contains($api,'idempotency_key is required')&&str_contains($api,"'slapper:'.\$idem"),'publish requires resumable idempotency');
$check(str_contains($api,"\$resource === 'album'")&&str_contains($api,"'created'=>false"),'folder batches can idempotently select or create an album');
$check(str_contains($api,"'federation'=>false"),'capability document reports federation off');
$check(str_contains($keys,'smackthemup_publish'),'admin can mint the narrow publishing key');
$check(str_contains($gyss,"'can_upload'=> false"),'GYSS explicitly reports that it cannot upload');
$check(str_contains($gyss,"'collection_map' => \$collection_map")&&str_contains($gyss,"\$upd['album_ids']")&&str_contains($gyss,"\$upd['collection_ids']"),'GYSS syncs and edits album/category/collection membership');
$check(str_contains($gyss,"\$resource === 'organize-group'")&&str_contains($gyss,"'cover_image_id'")&&str_contains($gyss,"'sort_order'"),'GYSS can curate descriptions, covers, and collection order');
$check(str_contains($gyss,"['delete-stepup','delete-image']")&&str_contains($gyss,'INTERVAL 15 MINUTE'),'single delete has a 15-minute server window');
$check(str_contains($gyss,"isset(\$data['ids'])")&&str_contains($gyss,'exactly one image id'),'bulk delete is refused');
$check(str_contains($gyss,'password_verify')&&str_contains($gyss,'totp_verify'),'delete step-up requires password and TOTP');
$check(str_contains($schema,'snap_gyss_delete_audit'),'delete audit/window tables are canonical');
$check(str_contains($nav,"\$_gn_mode === 'smackthemup'")&&str_contains($nav,'>Albums<')&&str_contains($nav,'>Categories<'),'required public navigation is present');
$check(str_contains($skins,"['carousel', 'smackthemup']")&&str_contains($skins,"\$_cur_mode !== 'smackthemup'"),'gram skins work without changing the permanent hybrid mode');
$check(str_contains($manifest,"'smackthemup' => 'the-grid'"),'remote installer supplies the Grid renderer skin');
$check(str_contains($footer,'stu-email-this')&&str_contains($footer,'EMAIL THIS')&&str_contains($footer,'mailto:?subject='),'public photo/taxonomy pages expose EMAIL THIS without server mail');
$check(str_contains($footer,"rtrim((string)BASE_URL")&&!str_contains($footer,"\$_SERVER['HTTP_HOST']"),'share links use the configured canonical origin, never the request Host header');
$check(str_contains($footer,'SHARE TO BLUESKY')&&str_contains($footer,'SHARE TO FACEBOOK')&&str_contains($footer,'stu-copy-link'),'public pages expose explicit share and copy-link actions');
$check(str_contains($share,'navigator.clipboard')&&str_contains($share,"execCommand('copy')"),'copy-link action has a secure API and compatibility fallback');
echo $fail?"\n{$fail} CHECK(S) FAILED\n":"\nALL PASS\n"; exit($fail?1:0);
// ===== SNAPSMACK EOF =====
