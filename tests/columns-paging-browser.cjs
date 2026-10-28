// SNAPSMACK_EOF_HEADER
// Browser regression: archive paging must follow the actual scroll container.
const {chromium}=require('../tools/browser-tests/runtime.cjs');
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true});const failures=[];
for(const mode of ['document','body','panel'])for(const observer of [true,false])for(const short of [false,true]){
 const page=await browser.newPage({viewport:{width:900,height:600}});let requests=[];
 if(!observer)await page.addInitScript(()=>{window.IntersectionObserver=undefined;});
 const css=mode==='body'?'html{height:100%;overflow:hidden}body{height:100%;overflow:auto}':mode==='panel'?'html,body{height:100%;overflow:hidden}#panel{height:400px;overflow:auto}':'';
 const html=n=>`<!doctype html><style>body{margin:0}${css}.tile{height:${short?200:2500}px}.scroll-wall-sentinel{height:1px}</style><div id="panel"><main id="browse-grid" class="archive-grid"><article class="tile" data-page="${n}">Page ${n}</article></main>${n<4?`<div class="scroll-wall-sentinel" data-next="${n+1}" data-has-more="1"></div>`:''}</div><script src="/engine.js"></script>`;
 await page.route('http://paging.test/**',async route=>{const u=new URL(route.request().url());if(u.pathname==='/engine.js')return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(__dirname,'../assets/js/ss-engine-columns.js'),'utf8')});const n=Number(u.searchParams.get('page')||1);if(n>1)requests.push(n);await route.fulfill({contentType:'text/html',body:html(n)});});
 try{await page.goto('http://paging.test/archive',{waitUntil:'networkidle'});await page.waitForTimeout(150);
 if(!short){assert.deepEqual(requests,[],'long initial page must not fetch while idle');for(let n=2;n<=4;n++){await page.evaluate(mode=>{const el=mode==='body'?document.body:mode==='panel'?document.querySelector('#panel'):document.scrollingElement;el.scrollTop=el.scrollHeight;},mode);await page.waitForFunction(n=>document.querySelectorAll('.tile').length>=n,n);await page.waitForTimeout(100);assert.equal(requests.length,n-1,'must stop after adding one long page');}}
 else await page.waitForFunction(()=>document.querySelectorAll('.tile').length===4);
 assert.deepEqual(requests,[2,3,4],'each page fetched once through exhaustion');await page.evaluate(()=>window.dispatchEvent(new Event('scroll')));await page.waitForTimeout(100);assert.equal(requests.length,3);console.log('PASS',mode,observer?'observer':'fallback',short?'short':'long');
 }catch(e){failures.push(`${mode}/${observer}/${short}: ${e.message}`);console.log('FAIL',failures.at(-1));}finally{await page.close();}
}await browser.close();if(failures.length)process.exitCode=1;})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
