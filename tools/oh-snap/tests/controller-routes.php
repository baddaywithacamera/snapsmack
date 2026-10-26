<?php
/** SNAPSMACK_EOF_HEADER: real controller/runtime with a disposable repository data fixture. */
define('BASE_URL','https://fixture.invalid/');
require_once dirname(__DIR__,3).'/core/skin-render-helpers.php';
require_once dirname(__DIR__,3).'/core/skin-view-contract.php';
require_once dirname(__DIR__,3).'/core/public-runtime.php';
class OhSnapFixturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {return new OhSnapFixtureStatement($query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {$s=new OhSnapFixtureStatement($query);$s->execute();return $s;}
}
class OhSnapFixtureStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private string $sql) {}
    public function execute(?array $params=null):bool {
        $marker='OH-SNAP-CONTROLLER-MARKER';
        $photo=['id'=>1,'img_title'=>$marker,'img_slug'=>'route-photo','img_file'=>'/fixture-photo.jpg','img_thumb_aspect'=>'/fixture-thumb.jpg','img_width'=>1200,'img_height'=>800,'img_description'=>'<p>CONTROLLER-BODY-MARKER</p>','allow_comments'=>0];
        $post=['id'=>2,'title'=>$marker,'slug'=>'route-post','post_slug'=>'route-post','post_type'=>'longform','content'=>'<p>CONTROLLER-BODY-MARKER</p>','allow_comments'=>0];
        if(str_contains($this->sql,'snap_pages'))$this->rows=[['id'=>3,'title'=>$marker,'slug'=>'route-page','content'=>'<p>CONTROLLER-BODY-MARKER</p>']];
        elseif(str_contains($this->sql,'COUNT(') && !str_contains($this->sql,'GROUP BY') && !str_contains($this->sql,'SELECT p.id'))$this->rows=[['count'=>1]];
        elseif(str_contains($this->sql,'snap_collections') && !str_contains($this->sql,'JOIN snap_images'))$this->rows=[['id'=>4,'title'=>$marker,'slug'=>'route-collection','cover_path'=>'/cover.jpg','description'=>'<p>CONTROLLER-BODY-MARKER</p>']];
        elseif(str_contains($this->sql,'snap_albums') && !str_contains($this->sql,'WHERE m.image_id'))$this->rows=[['id'=>5,'album_name'=>$marker,'cover_path'=>'/cover.jpg']];
        elseif(str_contains($this->sql,'snap_images'))$this->rows=[$photo];
        elseif(str_contains($this->sql,'snap_posts'))$this->rows=[$post];
        else $this->rows=[];
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return $this->rows[0]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $this->rows;}
    public function fetchColumn(int $column=0):mixed {return array_values($this->rows[0]??[0])[$column]??0;}
}
$inputs=['landing'=>[], 'resolve'=>['slug'=>'route-photo'], 'post'=>['view'=>'post','slug'=>'route-post'], 'archive'=>['view'=>'archive'], 'page'=>['view'=>'page','slug'=>'route-page'], 'search'=>['query'=>'test'], 'hashtag'=>['tag'=>'test'], 'albums'=>['view'=>'albums'], 'collections'=>['view'=>'collections'], 'collection'=>['view'=>'collection','slug'=>'route-collection']];
$total=0;
foreach(['photoblog','carousel','smacktalk','smackthemup','fedistructure'] as $siteMode) foreach($inputs as $name=>$input){
    $response=snapsmack_public_runtime(new OhSnapFixturePDO(),$input,['active_skin'=>'ohsnap-new-skin','site_mode'=>$siteMode,'homepage_mode'=>'archive','skin_has_landing'=>true,'global_comments_enabled'=>'0','community_comments_enabled'=>'0','htbs_show_filmstrip'=>'0']);
    $view=snapsmack_build_skin_view($response,['site_name'=>'Controller fixture','skin_style_url'=>'style.css']);
    ob_start();$ok=snapsmack_render_strict_skin_template(dirname(__DIR__).'/kit','layout.php',$view);$html=ob_get_clean();
    if(!$ok||!str_contains($html,'OH-SNAP-CONTROLLER-MARKER'))throw new RuntimeException("{$siteMode}/{$name} lost actual controller content: ".json_encode($response));
    if(in_array($name,['resolve','post','page'],true)&&!str_contains($html,'CONTROLLER-BODY-MARKER'))throw new RuntimeException("{$siteMode}/{$name} lost content body.");
    $total++;
}
echo "Real public controller/runtime → starter: {$total}/50 route/mode cases retained production-shaped content.\n";
// ===== SNAPSMACK EOF =====
