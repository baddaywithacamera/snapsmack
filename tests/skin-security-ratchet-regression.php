<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-security-policy.php';
$root=dirname(__DIR__);$failed=[];
foreach(glob($root.'/skins/*/manifest.json')?:[] as $manifest){$dir=dirname($manifest);$findings=snapsmack_skin_security_gate($dir);if($findings)$failed[basename($dir)]=$findings;}
if($failed){fwrite(STDERR,json_encode($failed,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");exit(1);}
$tmp=sys_get_temp_dir().'/snapsmack-strict-'.bin2hex(random_bytes(6));mkdir($tmp);
try{file_put_contents($tmp.'/manifest.json','{"schema_version":1}');file_put_contents($tmp.'/layout.php',"<?php defined('SNAPSMACK_SKIN_RENDER') || exit; echo 'legacy';");if(snapsmack_skin_security_gate($tmp)===[])throw new RuntimeException('Schema-v1 skin escaped the strict gate.');}finally{foreach(glob($tmp.'/*')?:[] as $file)unlink($file);rmdir($tmp);}
echo "Strict fleet skin security gate: PASS\n";
