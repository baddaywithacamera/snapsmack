<?php
declare(strict_types=1);
$root=dirname(__DIR__);require_once $root.'/core/custom-code-policy.php';require_once $root.'/core/skin-security-policy.php';
if(snapsmack_owner_custom_code_enabled([]))throw new RuntimeException('Custom code is not off by default.');
if(snapsmack_owner_custom_head([])!=='')throw new RuntimeException('Disabled custom code was returned.');
$normal=snapsmack_public_csp(false);$custom=snapsmack_public_csp(true);
if(str_contains($normal,"'unsafe-inline'")===false)throw new RuntimeException('Normal CSP must retain inline styles.');
if(!preg_match("/script-src 'self';/",$normal)||preg_match("/script-src[^;]*unsafe-inline/",$normal)||preg_match("/script-src[^;]*https:/",$normal))throw new RuntimeException('Normal script CSP permits inline or remote execution.');
if(!preg_match("/script-src[^;]*'unsafe-inline'[^;]*https:/",$custom))throw new RuntimeException('Owner-code CSP relaxation is not exact.');
$tmp=sys_get_temp_dir().'/snapsmack-skin-js-'.bin2hex(random_bytes(4));mkdir($tmp);file_put_contents($tmp.'/manifest.json',json_encode(['schema_version'=>2,'security_policy'=>2,'cms_controller'=>'public','view_model'=>'snapsmack.public.v1','templates'=>['default'=>'layout.php']]));file_put_contents($tmp.'/layout.php',"<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>");file_put_contents($tmp.'/evil.js','alert(1)');
try{$types=array_column(snapsmack_skin_security_findings($tmp),'type');if(!in_array('bundled-javascript',$types,true))throw new RuntimeException('Skin JavaScript was accepted.');}finally{unlink($tmp.'/manifest.json');unlink($tmp.'/layout.php');unlink($tmp.'/evil.js');rmdir($tmp);}
$back=(string)file_get_contents($root.'/smack-back.php');if(str_contains($back,'name="skin_allow_custom_js"')||str_contains($back,'save_skin_js_settings'))throw new RuntimeException('Skin JS bypass remains in the UI.');
$scripts=(string)file_get_contents($root.'/smack-scripts.php');if(!str_contains($scripts,'owner_custom_code_enabled')||!str_contains($scripts,'csrf_verify()'))throw new RuntimeException('Owner custom-code control is not explicit and CSRF-protected.');
echo "Owner custom-code and public CSP regression passed.\n";
