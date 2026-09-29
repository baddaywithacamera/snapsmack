<?php
declare(strict_types=1);
$root=dirname(__DIR__);if(!class_exists('ZipArchive')){echo "SKIP: ZipArchive unavailable.\n";exit(0);}define('SNAPSMACK_ROOT',$root);define('SKINS_DIR',$root.'/skins');require_once $root.'/core/skin-registry.php';
$make=static function(array $entries):array{$path=tempnam(sys_get_temp_dir(),'skinzip-');$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);foreach($entries as $name=>$body)$z->addFromString($name,$body);$z->close();$z=new ZipArchive();$z->open($path);return[$path,$z];};
[$path,$zip]=$make(['../escape.php'=>'x']);try{if(snapsmack_skin_zip_safety_findings($zip)===[])throw new RuntimeException('Traversal ZIP accepted.');}finally{$zip->close();unlink($path);}
[$path,$zip]=$make(['/absolute.php'=>'x']);try{if(snapsmack_skin_zip_safety_findings($zip)===[])throw new RuntimeException('Absolute ZIP path accepted.');}finally{$zip->close();unlink($path);}
[$path,$zip]=$make(['skin/layout.php'=>str_repeat('x',5000001)]);try{if(snapsmack_skin_zip_safety_findings($zip)===[])throw new RuntimeException('Oversized ZIP member accepted.');}finally{$zip->close();unlink($path);}
$source=(string)file_get_contents($root.'/core/skin-registry.php');foreach(['unsupported manifest or security policy version','staged package escaped its root','snapsmack_skin_security_gate'] as $needle)if(!str_contains($source,$needle))throw new RuntimeException("Installer lacks {$needle} gate.");
echo "Strict skin package lifecycle regression passed.\n";
