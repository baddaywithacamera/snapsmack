// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
// Offline geometry checks; live installation and owner acceptance are separate.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('./runtime.cjs');
const root = path.resolve(__dirname, '../..');
const css = fs.readFileSync(path.join(root, 'skins/chaplin/style.css'), 'utf8');
const marker = '/* Ornaments mask the rules,';
const baseline = css.slice(0, css.indexOf(marker));
const frame = '<div class="chap-img-frame"><div class="chap-frame-deco">'
  + ['tl','tr','bl','br','mid-top','mid-bot'].map(p => '<div class="chap-orn chap-orn-masked chap-orn-'+p+'"></div>').join('')
  + '</div><img class="post-image chap-photo" width="1200" height="800" alt="fixture">'
  + '<div class="chap-frame-lines">'+['top','bot','lft','rgt'].map(p => '<div class="chap-line chap-line-'+p+'"></div>').join('')+'</div></div>';
async function measure(browser, sheet, width, kind) {
  const page = await browser.newPage({viewport:{width,height:1080}});
  await page.route('**/*', r => r.abort());
  await page.setContent('<body class="'+(kind==='about'?'static-transmission':kind==='archive'?'archive-page':'')+'">'
    +(kind==='about'?'<div id="photobox" class="page-hero"><div class="main-photo">':kind==='archive'?'<div class="fsog-thumb">':'<div id="rg-photobox"><div class="rg-photo-wrap">')
    +frame+'</div></div></body>');
  await page.addStyleTag({content:sheet});
  await page.addStyleTag({content:':root{--chap-bg:#0a0a0a;--chap-frame-gap:47px;--chap-orn-gap:20px;--chap-orn-corner-size:48px;--chap-line-h-inset:0px;--chap-line-v-inset:0px;--chap-frame-total:6px;--chap-frame-stripes:#eee;--header-height:56px;--chap-bottom-chrome:50px;--chap-photo-pad-v:92px}body{margin:0}'});
  const result = await page.evaluate(() => {
    const frame=document.querySelector('.chap-img-frame'),deco=frame.querySelector('.chap-frame-deco'),lines=frame.querySelector('.chap-frame-lines');
    const r=deco.getBoundingClientRect();
    return {maskAboveRules:Number(getComputedStyle(deco).zIndex)>Number(getComputedStyle(lines).zIndex),
      cornerInset:parseFloat(getComputedStyle(lines.querySelector('.chap-line-top')).left),
      decoDisplay:getComputedStyle(deco).display,linesDisplay:getComputedStyle(lines).display,
      left:r.left,right:r.right,width:innerWidth,overflow:document.documentElement.scrollWidth-innerWidth};
  });
  await page.close(); return result;
}
(async()=>{
  assert.ok(css.includes(marker),'repair marker missing');
  const browser=await chromium.launch({headless:true,channel:'msedge'});
  try {
    for(const width of [1920,1440,1024]) {
      const home=await measure(browser,css,width,'home');
      assert.ok(home.maskAboveRules,'rules must not paint over ornament masks');
      assert.equal(home.cornerInset,68,'48px corner plus owner 20px gap');
      assert.ok(home.left>=0&&home.right<=width,'outer frame must fit viewport');
      assert.equal(home.overflow,0,'frame must not create horizontal scrolling');
      const about=await measure(browser,css,width,'about');
      assert.equal(about.decoDisplay,'none');assert.equal(about.linesDisplay,'none');
      const archive=await measure(browser,css,width,'archive');
      assert.equal(archive.cornerInset,0,'landing corner gap must not alter archive thumbnails');
      assert.notEqual(archive.decoDisplay,'none','About exception must not hide archive ornaments');
    }
    const mutation=await measure(browser,baseline,1024,'home');
    assert.equal(mutation.maskAboveRules,false,'reverting CSS must reproduce masking defect');
    assert.equal(mutation.cornerInset,0,'reverting CSS must reproduce joined corner rules');
    const aboutMutation=await measure(browser,baseline,1920,'about');
    assert.notEqual(aboutMutation.decoDisplay,'none','reverting CSS must expose unwanted About frame');
    console.log('PASS CHAPLIN frame geometry at 1920/1440/1024; baseline mutation reproduces three defects.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
// ===== SNAPSMACK EOF =====
