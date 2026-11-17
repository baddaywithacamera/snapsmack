<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be // ===== SNAPSMACK EOF =====
// Contract tests use a small WP API double; this is not an installed WP integration test.
define('ABSPATH', __DIR__);
function add_action(...$args) {}
function add_filter(...$args) {}
function absint($v) { return abs((int)$v); }
function post_password_required($id) { return $id === 8; }
function apply_filters($name, $value, ...$args) { return $value; }
function wp_attachment_is_image($id) { return in_array($id, [1,2,3], true); }
function esc_url_raw($url) { return $url; }
function get_shortcode_regex($tags) { return '\\[(\\[?)(foogallery)\\b([^\\]]*)(\/?)\\](?:([^[]*)\\[\/foogallery\\])?(\\]?)'; }
function shortcode_parse_atts($s) { preg_match_all('/(\w+)="([^"]*)"/', $s, $m, PREG_SET_ORDER); $a=[];foreach($m as $v)$a[$v[1]]=$v[2];return $a; }
class FooGallery {
 public $ID; public $attachment_ids=[1,2,3];
 public function __construct($id){$this->ID=$id;}
 public static function get_by_id($id){return in_array($id,[7,8,9],true)?new self($id):false;}
 public static function get_by_slug($slug){return $slug==='example'?new self(7):false;}
 public function is_published(){return $this->ID!==9;}
 public function attachments(){return array_map(fn($id)=>(object)['ID'=>absint($id)],$this->attachment_ids);}
}
require dirname(__DIR__, 2).'/smackpress-wp-companion/smackpress-wp-companion.php';
$checks=0;
function run_case($input,$ids){global $checks;$images=[];foreach([1,2,3] as $id)$images[$id]=['url'=>'https://example.test/'.$id.'.jpg'];$out=smackpress_expand_foogallery($input,$images);preg_match_all('/smackpress-image id="(\d+)"/',$out,$m);if($m[1]!==array_map('strval',$ids))throw new RuntimeException($input.' => '.$out);$checks++;return $out;}
run_case('[foogallery id="7"]',[1,2,3]);
run_case('[foogallery id="7" attachment_ids="3,1"]',[3,1]);
run_case('[foogallery gallery="example" attachment_ids="2"]',[2]);
run_case('<!-- wp:fooplugins/foogallery {"id":7,"attachment_ids":[2,1]} /--> ',[2,1]);
run_case('[foogallery id="7" attachment_ids="2"] [foogallery id="7"]',[2,1,2,3]);
foreach(['[foogallery id="8"]','[foogallery id="9"]','[foogallery id="999"]','[foogallery id="7" attachment_ids="3,99"]','[[foogallery id="7"]]'] as $input){$out=run_case($input,[]);if($out!==$input)throw new RuntimeException('Unresolved/private embed was changed');}
echo "FooGallery companion contract: PASS ($checks cases; WP doubles, not installed integration)\n";
// ===== SNAPSMACK EOF =====
