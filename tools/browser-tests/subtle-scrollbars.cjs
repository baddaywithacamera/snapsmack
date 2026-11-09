// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium, firefox } = require('@playwright/test');
const root = path.resolve(__dirname, '../..');
const out = path.resolve(process.argv[2] || path.join(root, '_continuity/scrollbar-proof'));
fs.mkdirSync(out, {recursive:true});
const read = p => fs.readFileSync(path.join(root,p),'utf8');
const old = p => execFileSync('git',['show',`3675add9:${p}`],{cwd:root,encoding:'utf8'});
(async()=>{
 const results=[];
 for(const [engine,type] of [['chromium',chromium],['firefox',firefox]]){
  let browser;
  try{browser=await type.launch({headless:true,ignoreDefaultArgs:['--hide-scrollbars']});}catch(e){if(engine==='chromium')throw e;results.push({engine,status:'NOT CHECKED',reason:'Firefox runtime unavailable'});continue;}
  try{
   for(const variant of ['dark','light'])for(const width of [1920,1440,1024])for(const state of ['before','after']){
    const page=await browser.newPage({viewport:{width,height:1080}});
    const css=[state==='after'?read('assets/css/public-base.css'):old('assets/css/public-base.css'),read('skins/new-horizon/style.css'),read(`skins/new-horizon/variant-${variant}.css`) ].join('\n');
    const html=`<!doctype html><html><head><meta charset="utf-8"><style>${css}\n.proof-content{padding:40px;min-height:4000px}.proof-inner{height:80px;overflow:auto}.proof-inner-content{height:300px}</style></head><body class="new-horizon-v2 ${state==='after'?'ss-subtle-scrollbars':''}"><div id="page-wrapper"><header id="header"><h1>NEW HORIZON scrollbar — ${variant}</h1></header><main id="scroll-stage" class="${state==='after'?'ss-scroll-surface':''}"><div class="proof-content"><h2>Primary page scrolling</h2><p>Track blends into the page. The thumb remains draggable.</p><div class="proof-inner"><div class="proof-inner-content">Nested controls retain their native scrollbar.</div></div></div></main></div></body></html>`;
    await page.setContent(html);
    const geometry=await page.evaluate(()=>{const e=document.querySelector('#scroll-stage'),inner=document.querySelector('.proof-inner');const s=getComputedStyle(e,'::-webkit-scrollbar-thumb');return {scrollable:e.scrollHeight>e.clientHeight,thumb:s.backgroundColor,innerColor:getComputedStyle(inner).scrollbarColor,width:getComputedStyle(e,'::-webkit-scrollbar').width};});
    assert(geometry.scrollable);
    if(state==='after'){
     assert.equal(geometry.innerColor,'auto');
     if(engine==='chromium'){assert.equal(geometry.thumb,variant==='dark'?'rgb(59, 59, 59)':'rgb(187, 187, 187)');assert.equal(geometry.width,'12px');}
     await page.mouse.move(width/2,600);await page.mouse.wheel(0,450);await page.waitForTimeout(350);
     assert(await page.locator('#scroll-stage').evaluate(e=>e.scrollTop>0),'wheel scroll remains available');
     await page.locator('#scroll-stage').evaluate(e=>{e.style.scrollBehavior='auto';e.scrollTop=0;});
     const r=await page.locator('#scroll-stage').boundingBox();
     await page.mouse.move(r.x+r.width-6,r.y+30);await page.mouse.down();await page.mouse.move(r.x+r.width-6,r.y+200,{steps:10});await page.mouse.up();
     if(engine==='chromium')assert(await page.locator('#scroll-stage').evaluate(e=>e.scrollTop>0),'thumb remains draggable');
     await page.locator('#scroll-stage').evaluate(e=>e.scrollTop=0);
    }
    await page.mouse.move(100,500);
    const name=`${engine}-${variant}-${state}-${width}`;
    await page.screenshot({path:path.join(out,name+'.png')});
    fs.writeFileSync(path.join(out,name+'.html'),html+'\n<!-- ===== SNAPSMACK EOF ===== -->\n');
    results.push({engine,variant,state,width,status:'PASS',geometry});await page.close();
   }
  }finally{await browser.close();}
 }
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(results,null,2));console.log(JSON.stringify(results));
})().catch(e=>{console.error(e);process.exitCode=1;});
// ===== SNAPSMACK EOF =====


