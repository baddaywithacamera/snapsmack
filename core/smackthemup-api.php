<?php
/**
 * SNAPSMACK — SMACKTHEMUP's SNAP SLAPPER-only publishing API.
 *
 * GET  smackthemup/capabilities
 * POST smackthemup/upload       multipart field `image` (JPEG)
 * POST smackthemup/album        find-or-create a public folder-drop album
 * POST smackthemup/publish      one public photograph + memberships
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/thumb-generator.php';
require_once __DIR__ . '/snap-tags.php';
require_once __DIR__ . '/alt-text.php';
require_once __DIR__ . '/api-input-safety.php';

function stu_reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function stu_error(string $message, int $status): void { stu_reply(['ok'=>false,'error'=>$message], $status); }
function stu_slug(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $value) ?? '';
    return trim(preg_replace('/[\s_-]+/', '-', $value) ?? '', '-');
}
function stu_unique_slug(PDO $pdo, string $base): string {
    $base = stu_slug($base) ?: 'photo-' . date('Ymd-His');
    $slug = $base; $n = 2;
    $q = $pdo->prepare('SELECT id FROM snap_images WHERE img_slug=? LIMIT 1');
    while (true) { $q->execute([$slug]); if (!$q->fetchColumn()) return $slug; $slug = $base . '-' . $n++; }
}

$parts = explode('/', trim($GLOBALS['route'] ?? ($_GET['route'] ?? ''), '/'));
$resource = $parts[1] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$settings = $pdo->query('SELECT setting_key,setting_val FROM snap_settings')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
if (($settings['site_mode'] ?? 'photoblog') !== 'smackthemup') {
    stu_error('WRONG MODE. This publishing door is only available on a SMACKTHEMUP installation; the site mode was not changed.', 409);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!$header && function_exists('getallheaders')) { $h=getallheaders(); $header=$h['Authorization']??$h['authorization']??''; }
if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) stu_error('SNAP SLAPPER publishing key required.', 401);
$q = $pdo->prepare("SELECT id FROM snap_ohsnap_keys WHERE key_hash=? AND key_type='smackthemup_publish' AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
try { $q->execute([hash('sha256',$m[1])]); $key=$q->fetch(PDO::FETCH_ASSOC); }
catch (Throwable $e) { stu_error('Publishing-key store is unavailable.', 503); }
if (!$key) stu_error('Wrong, expired, or revoked publishing scope.', 403);
$pdo->prepare('UPDATE snap_ohsnap_keys SET last_used_at=NOW() WHERE id=?')->execute([(int)$key['id']]);

if ($resource === 'capabilities' && $method === 'GET') {
    $cats=$pdo->query('SELECT id,cat_name AS name FROM snap_categories ORDER BY cat_name')->fetchAll(PDO::FETCH_ASSOC);
    $albums=$pdo->query('SELECT id,album_name AS name FROM snap_albums ORDER BY album_name')->fetchAll(PDO::FETCH_ASSOC);
    stu_reply(['ok'=>true,'site_mode'=>'smackthemup','scope'=>'smackthemup.publish','visibility'=>['public'],
        'individual_photos'=>true,'folder_album_batches'=>true,'idempotency'=>true,'federation'=>false,
        'categories'=>$cats,'albums'=>$albums]);
}

if ($resource === 'upload' && $method === 'POST') {
    $f=$_FILES['image']??null;
    if (!$f || ($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) stu_error('Choose one JPEG photograph to upload.',400);
    if (($f['size']??0)>20*1024*1024) stu_error('Photograph is larger than the 20 MB server limit.',413);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if ($mime!=='image/jpeg') stu_error('Only JPEG photographs are accepted by this endpoint.',415);
    $root=dirname(__DIR__); $ym=date('Y/m'); $dir=$root.'/img_uploads/'.$ym;
    if (!is_dir($dir) && !mkdir($dir,0755,true)) stu_error('The upload folder could not be created.',500);
    $name=preg_replace('/[^a-z0-9_.-]/','',strtolower(basename((string)$f['name']))) ?: 'photo.jpg';
    if (!preg_match('/\.jpe?g$/',$name)) $name.='.jpg';
    $name=date('YmdHis').'-'.bin2hex(random_bytes(4)).'-'.substr($name,-100);
    $dest=$dir.'/'.$name;
    if (!move_uploaded_file($f['tmp_name'],$dest)) stu_error('The photograph could not be saved.',500);
    stu_reply(['ok'=>true,'path'=>'img_uploads/'.$ym.'/'.$name]);
}

if ($resource === 'album' && $method === 'POST') {
    $b=json_decode((string)file_get_contents('php://input'),true);
    $title=mb_substr(trim(strip_tags((string)($b['title']??''))),0,255);
    if($title==='')stu_error('Album title is required.',400);
    $q=$pdo->prepare('SELECT id FROM snap_albums WHERE LOWER(album_name)=LOWER(?) LIMIT 1');$q->execute([$title]);
    if($id=(int)($q->fetchColumn()?:0))stu_reply(['ok'=>true,'album_id'=>$id,'created'=>false]);
    $pdo->prepare('INSERT INTO snap_albums(album_name,album_description) VALUES(?,?)')->execute([$title,mb_substr(trim(strip_tags((string)($b['description']??''))),0,20000)]);
    stu_reply(['ok'=>true,'album_id'=>(int)$pdo->lastInsertId(),'created'=>true],201);
}

if ($resource === 'publish' && $method === 'POST') {
    $b=json_decode((string)file_get_contents('php://input'),true);
    if (!is_array($b)) stu_error('A JSON photograph record is required.',400);
    if (($b['visibility']??'public')!=='public') stu_error('SMACKTHEMUP is public-only. Private, unlisted, and followers-only photographs are refused.',400);
    $path=trim((string)($b['path']??''));
    if (!snap_api_safe_upload_path($path) || !is_file(dirname(__DIR__).'/'.$path)) stu_error('The uploaded photograph path is missing or outside img_uploads.',400);
    $idem=substr(trim((string)($b['idempotency_key']??'')),0,200);
    if ($idem==='') stu_error('idempotency_key is required so interrupted batches can resume without duplicates.',400);
    $existing=$pdo->prepare("SELECT id,img_slug FROM snap_images WHERE img_source_file=? LIMIT 1");
    $existing->execute(['slapper:'.$idem]);
    if ($row=$existing->fetch(PDO::FETCH_ASSOC)) stu_reply(['ok'=>true,'duplicate'=>true,'image_id'=>(int)$row['id'],'url'=>rtrim(BASE_URL,'/').'/'.$row['img_slug']]);
    $title=mb_substr(trim(strip_tags((string)($b['title']??''))),0,255);
    $caption=mb_substr(trim(strip_tags((string)($b['caption']??''))),0,20000);
    $alt=snap_sanitize_alt((string)($b['alt']??''));
    $date=preg_match('/^\d{4}-\d{2}-\d{2}/',(string)($b['date']??'')) ? (string)$b['date'] : date('Y-m-d H:i:s');
    $slug=stu_unique_slug($pdo,$title ?: pathinfo($path,PATHINFO_FILENAME));
    $thumb=snapsmack_generate_thumbs($path,dirname(__DIR__),SNAPSMACK_THUMB_SQUARE,SNAPSMACK_THUMB_ASPECT_LONG);
    $cats=array_values(array_unique(array_filter(array_map('intval',(array)($b['category_ids']??[])))));
    $albums=array_values(array_unique(array_filter(array_map('intval',(array)($b['album_ids']??[])))));
    $collections=array_values(array_unique(array_filter(array_map('intval',(array)($b['collection_ids']??[])))));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO snap_images(img_title,img_slug,img_description,img_alt,img_date,img_file,img_source_file,img_status,img_thumb_square,img_thumb_aspect,sort_order,allow_comments) VALUES(?,?,?,?,?,?,?,'published',?,?,0,?)")
            ->execute([$title,$slug,$caption,$alt,$date,$path,'slapper:'.$idem,$thumb['sq_path']??null,$thumb['asp_path']??null,array_key_exists('allow_comments',$b)?(!empty($b['allow_comments'])?1:0):1]);
        $id=(int)$pdo->lastInsertId();
        $ins=$pdo->prepare('INSERT IGNORE INTO snap_image_cat_map(image_id,cat_id) SELECT ?,id FROM snap_categories WHERE id=?'); foreach($cats as $v)$ins->execute([$id,$v]);
        $ins=$pdo->prepare('INSERT IGNORE INTO snap_image_album_map(image_id,album_id) SELECT ?,id FROM snap_albums WHERE id=?'); foreach($albums as $v)$ins->execute([$id,$v]);
        $ins=$pdo->prepare("INSERT IGNORE INTO snap_collection_items(collection_id,item_type,item_id,image_id) SELECT id,'image',?,? FROM snap_collections WHERE id=? AND published=1"); foreach($collections as $v)$ins->execute([$id,$id,$v]);
        if (!empty($b['tags'])) snap_sync_tags($pdo,$id,is_array($b['tags'])?implode(' ',$b['tags']):(string)$b['tags']);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); stu_error('The photograph could not be published.',500); }
    stu_reply(['ok'=>true,'duplicate'=>false,'image_id'=>$id,'slug'=>$slug,'url'=>rtrim(BASE_URL,'/').'/'.$slug],201);
}

stu_error('Unknown SMACKTHEMUP endpoint.',404);
// ===== SNAPSMACK EOF =====
