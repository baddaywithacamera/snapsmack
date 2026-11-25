// SNAPSMACK_EOF_HEADER
// Render ALFRED through the real PHP skin view and measure the Hitchcock geometry.
const {chromium}=require('@playwright/test'),{execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const docs=JSON.parse(execFileSync('php',{input:String.raw`<?php
define('SNAPSMACK_SKIN_RENDER',true);
require 'core/trusted-html.php';
require 'core/skin-render-helpers.php';
require 'core/skin-view-contract.php';
require 'core/skin-presentation.php';
$nav=[];
$children = [
  1 => ['About Séan', 'Artist’s Statement'],
  2 => ['Flickr Photostream', 'People', 'Engagement Shoot', 'Alberta Skies', 'Wedding Shoot', 'Panoramic Images'],
];
foreach (['Home','About Séan','Portfolio','Blog','Links','Contact'] as $i=>$label) {
  $items = [];
  foreach ($children[$i] ?? [] as $j=>$child) $items[] = ['label'=>$child, 'url'=>"/menu-$i/$j"];
  $nav[]=['label'=>$label,'url'=>$i===0?'/':'/menu-'.$i,'children'=>$items,'current'=>$i===0];
}
$site=['skin_slug'=>'alfred','site_name'=>'Sean McCormick Photography','base_url'=>'https://fixture.test/','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skins/alfred/style.css','base_style_url'=>'/assets/css/public-base.css','skin_presentation'=>snapsmack_skin_presentation([], 'alfred')];
$site['tagline'] = 'Saving the beauty in this moment for the ones that follow.';
$site['skin_presentation']['header_media']['logo'] = '/fixture-owner-logo.png';
$site['skin_presentation']['header_media']['show_tagline'] = true;
$site['social_dock'] = ['social'=>[
  ['label'=>'Mastodon','icon'=>'mastodon','url'=>'https://mastodon.social/@snap'],
  ['label'=>'Bluesky','icon'=>'bluesky','url'=>'https://bsky.app/profile/example.test'],
  ['label'=>'Pixelfed','icon'=>'pixelfed','url'=>'https://pixelfed.social/example'],
  ['label'=>'Linktree','icon'=>'linktree','url'=>'https://linktr.ee/example'],
  ['label'=>'Email','icon'=>'email','url'=>'mailto:example@example.test'],
], 'icon_sprite_url'=>'/assets/icons/crop-circles.svg'];
$posts=array_fill(0,6,['url'=>'/sample','title'=>'Fixture post','image_url'=>'/tile.svg']);
$cases=['home'=>['kind'=>'feed','posts'=>$posts],'solo'=>['kind'=>'single','post'=>['title'=>'An Uphill Battle','created_at'=>'2023-11-16 14:37:02'],'rendered_content'=>snapsmack_trusted_html('<p>Fixture body</p>'),'previous'=>['url'=>'/earlier','title'=>'Earlier post'],'next'=>['url'=>'/later','title'=>'Later post']],'portfolio'=>['kind'=>'page','item'=>['title'=>'Portfolio'],'rendered_content'=>snapsmack_trusted_html('<p>Fixture portfolio content</p>')]];
$out=[];
foreach($cases as $key=>$record){$view=snapsmack_build_skin_view($record+['status'=>200,'navigation'=>$nav],$site); ob_start(); snapsmack_render_strict_skin_template(realpath('skins/alfred'),'layout.php',$view);$out[$key]=ob_get_clean();}
echo json_encode($out,JSON_THROW_ON_ERROR);`}).toString());
(async()=>{const browser=await chromium.launch();const results=[];fs.mkdirSync('_review/hitchcock-parity',{recursive:true});try{
for(const [key,html] of Object.entries(docs))for(const width of [1920,1440,1024]){
 const page=await browser.newPage({viewport:{width,height:1080}});
 await page.route('https://fixture.test/**',async route=>{
  const p=new URL(route.request().url()).pathname;
  if(p=== '/'+key) return route.fulfill({contentType:'text/html',body:html.replace('<head>','<head><link rel="stylesheet" href="/assets/css/ss-engine-mosaic.css">')});
  if(p==='/tile.svg') return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="#456"/></svg>'});
  if(p==='/fixture-owner-logo.png') return route.fulfill({path:'_review/hitchcock-parity/fixture-owner-logo.png'});
  if(/^\/(?:assets\/(?:css|icons)|skins\/alfred)\/[a-zA-Z0-9_./-]+$/.test(p) && fs.existsSync('.'+p)) return route.fulfill({path:'.'+p});
  return route.abort();
 });
 await page.goto('https://fixture.test/'+key); await page.evaluate(()=>document.fonts.ready);
 const m=await page.evaluate(()=>{
 const v=s=>{const e=document.querySelector(s);if(!e)return null;const r=e.getBoundingClientRect();return {x:r.x,w:r.width,y:r.y,h:r.height,text:e.innerText,font:getComputedStyle(e).fontFamily};};
 const first=v('.main-menu > li > a'),last=v('.alfred-nav-search-btn');
 const glyphs = [...document.querySelectorAll('.site-header .dock-link use')].map(use => {
  const bounds = use.getBBox();
  return {id:use.getAttribute('href').split('#')[1],width:bounds.width,height:bounds.height};
 });
 return {centerDelta:(first.x+last.x+last.w-innerWidth)/2,nav:v('.navigation'),masthead:v('.site-header'),firstTile:v('.alfred-archive-grid .post'),glyphs,fontLoaded:[...document.fonts].some(f=>f.family==='Montserrat'&&f.status==='loaded'),card:v('.smacktalk-post'),date:v('.post-date'),title:v('.post-title'),tab:v('.post-nav-prev'),grid:(()=>{const e=document.querySelector('.alfred-archive-grid');if(!e)return null;const s=getComputedStyle(e);return [s.display,s.gridTemplateColumns,s.width,s.getPropertyValue('--ss-square-cols'),s.getPropertyValue('--alfred-square-cols'),s.columnGap,s.rowGap];})(),tiles:[...document.querySelectorAll('.alfred-archive-grid .post')].slice(0,3).map(e=>[e.getBoundingClientRect().x,e.getBoundingClientRect().width]),overflow:document.documentElement.scrollWidth-innerWidth};
 });
 assert(Math.abs(m.centerDelta)<=2,JSON.stringify(m));assert(m.fontLoaded);assert.equal(m.nav.h,60);
 if(key!=='home') assert(Math.abs(m.card.w-Math.min(width*.9,1040))<1,JSON.stringify(m));
 if(key==='solo'){assert.equal(m.date.text,'NOVEMBER 16, 2023');assert(m.date.y<m.title.y); assert(m.tab);}
 assert(m.overflow<=1); if(key==='home'){assert.equal(m.grid[0],'grid');const expected=(Math.min(width*.9,1040)*(1-2*.0395))/3;assert(m.tiles.every(t=>Math.abs(t[1]-expected)<1));}
 assert.deepEqual(m.glyphs.map(glyph=>glyph.id),['mastodon','bluesky','pixelfed','linktree','email']);
 assert(m.glyphs.every(glyph=>glyph.width>0 && glyph.height>0));
 if(key==='home') {
  const formerSpacing = await page.addStyleTag({content:'body.landing-page #scroll-stage.post-inner {padding-top:80px;}'});
  const formerTile = await page.locator('.alfred-archive-grid .post').first().boundingBox();
  await formerSpacing.evaluate(style=>style.remove());
  m.previousTileY = formerTile.y;
  assert.equal(m.previousTileY-m.firstTile.y,15);
 }
 if(width!==1440) {
  await page.screenshot({path:'_review/hitchcock-parity/staged-'+key+'-'+width+'.png'});
  if(key==='home') {
   await page.locator('.main-menu > li').filter({hasText:'Portfolio'}).first().hover();
   await page.screenshot({path:'_review/hitchcock-parity/staged-menu-'+width+'.png',clip:{x:0,y:0,width,height:440}});
  }
 }
 results.push({key,width,...m}); console.log(key,width,'center',m.centerDelta,'card',m.card?.w,'grid',JSON.stringify(m.grid),'tiles',JSON.stringify(m.tiles));await page.close();
}
fs.writeFileSync('_review/hitchcock-parity/staged-results.json',JSON.stringify(results,null,2));
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
