const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('../tools/browser-tests/runtime.cjs');
const root = path.resolve(__dirname, '..');
const result = spawnSync('php', ['-r', String.raw`
require 'core/trusted-html.php'; require 'core/skin-render-helpers.php'; require 'core/skin-view-contract.php'; require 'core/asset-registry.php';
define('SNAPSMACK_SKIN_RENDER', true); define('BASE_URL', 'https://impact.test/');
$items=[]; foreach ([0,1,2,0,1,2] as $id=>$orientation) $items[]=['img_slug'=>'photo-'.$id,'img_title'=>'Photograph '.$id,'img_orientation'=>$orientation,'img_thumb_aspect'=>'/photo.svg'];
$manifest=json_decode(file_get_contents('skins/impact-printer/manifest.json'),true);
$view=snapsmack_build_skin_view(['kind'=>'archive','items'=>$items,'navigation'=>[],'active_filters'=>['cat:3']],[
 'site_name'=>'IMPACT PRINTER','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/impact-printer/style.css','skin_variant_url'=>'/skins/impact-printer/variant-plain.css','base_style_url'=>'/assets/css/public-base.css',
 'archive_layout'=>'thumbs','archive_thumb_style'=>'cropped','archive_grid'=>['cols'=>4,'step'=>'m'], 'skin_presentation'=>[], 'footer'=>[],
 'registered_assets'=>snapsmack_skin_declared_assets($manifest),
 'crop_circles'=>['search'=>['action'=>'/','placeholder'=>'Search or #tag...'], 'filters'=>[
 ['label'=>'categories','type'=>'cat','items'=>[['id'=>3,'label'=>'Rust']]],
 ['label'=>'albums','type'=>'alb','items'=>[['id'=>9,'label'=>'Cars']]]]]]);
ob_start(); include 'skins/impact-printer/layout.php'; $html=ob_get_clean();
echo json_encode(['html'=>$html,'row'=>(string)$view['response']['archive_controls']]);
`], { cwd: root, encoding: 'utf8' });
assert.equal(result.status, 0, result.stderr);
const fixture = JSON.parse(result.stdout);
assert.ok(fixture.html.includes(fixture.row) && fixture.row.includes('smack-archive-filter-btn'));
(async () => {
 const browser = await chromium.launch({ headless:true });
 try {
  for (const width of [1920,390]) {
   const page=await browser.newPage({viewport:{width,height:1080}});
   let baseline=false;
   await page.route('https://impact.test/**',async route=>{
    const u=new URL(route.request().url());
    if(u.pathname==='/photo.svg') return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="300" height="200" fill="gray"/></svg>'});
    const file=path.join(root,u.pathname);
    if(fs.existsSync(file)&&fs.statSync(file).isFile()) return route.fulfill({path:file});
    let html=fixture.html;
    if(baseline) html=html.replace(fixture.row,'').replace(/<link[^>]+page-archive\.css[^>]*>/g,'');
    return route.fulfill({contentType:'text/html',body:html});
   });
   baseline=true;await page.goto('https://impact.test/images?layout=thumbs&q=metal');
   const sizes=()=>page.locator('#browse-grid .thumb-link').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return [r.width,r.height]}));
   const before=await sizes();
   baseline=false;await page.reload();
   assert.deepEqual(await sizes(),before,'reconnecting controls must not change photo geometry at '+width);
   assert.equal(await page.locator('#infobox').count(),1);
   await page.locator('#smack-archive-filter-btn').click();
   assert.equal(await page.locator('#smack-archive-filter-btn').getAttribute('aria-expanded'),'true');
   assert.ok(await page.locator('#smack-archive-filter-panel').isVisible());
   await page.locator('[data-type="alb"]').check();
   await page.waitForURL(u=>u.searchParams.getAll('f[]').includes('alb:9'));
   const url=new URL(page.url());
   assert.equal(url.pathname,'/images');assert.equal(url.searchParams.get('q'),'metal');assert.equal(url.searchParams.get('layout'),'thumbs');
   assert.deepEqual(url.searchParams.getAll('f[]'),['cat:3','alb:9']);
   await page.locator('.archive-search-input').fill('brass');
   await page.locator('.archive-search-input').press('Enter');
   await page.waitForURL(u=>u.searchParams.get('q')==='brass');
   console.log('PASS IMPACT controls '+width+': filter/search work, photo geometry unchanged');
   await page.close();
  }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
