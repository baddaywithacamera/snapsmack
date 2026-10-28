// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('./runtime.cjs');
const root=path.resolve(__dirname,'../..');
const out=process.env.SNAPSMACK_BROWSER_OUTPUT||path.join(require('node:os').tmpdir(),'chaplin-nav-review');
fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 const results=[];
 try {
  const context=await browser.newContext();
  await context.route('**/skins/chaplin/style.css*',r=>r.fulfill({contentType:'text/css',path:path.join(root,'skins/chaplin/style.css')}));
  await context.route('**/assets/js/ss-engine-sticky-header.js*',r=>r.fulfill({contentType:'text/javascript',path:path.join(root,'assets/js/ss-engine-sticky-header.js')}));
  const page=await context.newPage();
  for(const width of [1920,1440,1024]) {
   const titles=[];
   for(const kind of ['about','blogroll','archive-view']) {
    await page.setViewportSize({width,height:1080});
    await page.goto('https://acolourlesslife.ca/'+kind,{waitUntil:'domcontentloaded'});
    await page.evaluate(()=>Promise.race([new Promise(r=>setTimeout(r,10000)),Promise.all([...document.images].filter(i=>i.getBoundingClientRect().top<1080).map(i=>i.decode().catch(()=>{})))]));
    const data=await page.evaluate(()=>{
     const header=document.querySelector('#rg-header'),h=header.getBoundingClientRect();
     const title=document.querySelector('.static-page-title,.blogroll-page-title');
     const t=title?.getBoundingClientRect(),s=title&&getComputedStyle(title);
     const first=document.querySelector('#browse-grid img'),f=first?.getBoundingClientRect();
     return {header:{top:h.top,bottom:h.bottom,position:getComputedStyle(header).position},title:t&&{x:t.x,width:t.width,size:s.fontSize,font:s.fontFamily,spacing:s.letterSpacing,border:s.borderBottomWidth},first:f&&{top:f.top}};
    });
    assert.equal(data.header.position,'relative','sticky off must remain in flow');
    if(kind==='archive-view')assert.ok(data.first.top>=data.header.bottom,'first row must clear header');
    else titles.push(data.title);
    await page.screenshot({path:path.join(out,`chaplin-${kind}-${width}.png`)});
    results.push({width,kind,...data});
    // Supply the EXISTING shared engine locally to verify skin compatibility.
    // This does not prove strict runtime emits enabled assets; core owns that.
    if(kind!=='blogroll') {
     await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/css/ss-engine-sticky-header.css'),'utf8')+'\n:root{--sticky-opacity:.3;--sticky-blur:8px}'});
     await page.addScriptTag({url:'https://acolourlesslife.ca/assets/js/ss-engine-sticky-header.js'});
     await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
     await page.evaluate(()=>window.scrollTo(0,300));
     await page.waitForTimeout(450);
     const sticky=await page.locator('#rg-header').evaluate(e=>({position:getComputedStyle(e).position,background:getComputedStyle(e).backgroundColor,blur:getComputedStyle(e).backdropFilter}));
     assert.equal(sticky.position,'fixed');assert.ok(sticky.background.includes('0.3'));assert.equal(sticky.blur,'blur(8px)');
     results.push({width,kind,isolatedEnabledEngine:sticky});
    }
   }
   assert.deepEqual(titles[1],titles[0],'blogroll title must match About title geometry and typography');
  }
  await context.close();
  for(const [skin,cls]of [['jive-turkey','jt'],['heuristic','he'],['instant-camera','tg'],['sliders','tg']]) {
   for(const width of [1920,1440,1024])for(const count of [2,3,4,5]) {
    const p=await browser.newPage({viewport:{width,height:900},hasTouch:true});
    await p.setContent('<ul class="'+cls+'-sticky-nav-links">'+Array.from({length:count},(_,i)=>'<li><a href="#">'+['Home','Blogroll','About Sean','Archive','Image use'][i]+'</a></li>').join('')+'</ul>');
    await p.addStyleTag({content:fs.readFileSync(path.join(root,'skins',skin,'style.css'),'utf8')});
    const gaps=await p.locator('li').evaluateAll(es=>es.slice(1).map((e,i)=>{
     const r=e.getBoundingClientRect(),prev=es[i].getBoundingClientRect(),a=e.querySelector('a').getBoundingClientRect(),s=getComputedStyle(e,'::before');
     const c=document.createElement('canvas').getContext('2d');c.font=s.font;
     return {before:r.left-prev.right,after:a.left-r.left-c.measureText('|').width};
    }));
    for(const g of gaps){assert.ok(Math.abs(g.before-20)<1,JSON.stringify({skin,width,count,g}));assert.ok(Math.abs(g.after-20)<1,JSON.stringify({skin,width,count,g}));}
    if(count===5&&width===1024)await p.screenshot({path:path.join(out,skin+'-nav-1024.png')});
    results.push({skin,width,count,gaps});await p.close();
   }
  }
  fs.writeFileSync(path.join(out,'measurements.json'),JSON.stringify(results,null,2));
  console.log('PASS CHAPLIN live-markup CSS review and 48 touch-context separator cases. Enabled engine supplied locally; not deployed certification. '+out);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
