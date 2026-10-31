/** SNAPSMACK_EOF_HEADER: real browser events against grid-modal; preserve EOF marker. */
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('./runtime.cjs');
const args=process.argv.slice(2);function option(k,f){const i=args.indexOf(k);return i<0?f:args[i+1];}
const root=path.resolve(__dirname,'../..'), fixtures=option('--fixtures','C:/dev/snapsmack-staging/glide-fixture-a8983e6c/output');
const engine=option('--engine',path.join(root,'assets/js/ss-engine-grid-modal.js'));
const output=option('--output','C:/dev/snapsmack-staging/overlay-keyboard');
const skins=['aurora','game-on','heuristic','instant-camera','jive-turkey','parade','sliders','sudden-impact','the-grid'];
const origin='http://127.0.0.1:18765';fs.mkdirSync(output,{recursive:true});
(async()=>{const browser=await chromium.launch({channel:'msedge',headless:true});const results=[];
try{for(const skin of skins)for(const width of [1920,1440,1024]){
 const ctx=await browser.newContext({viewport:{width,height:1080},hasTouch:width===1024});const p=await ctx.newPage();const errors=[],requests=[];p.on('pageerror',e=>errors.push(e.message));
 const html=fs.readFileSync(path.join(fixtures,skin+'--landing.html'),'utf8');
 await p.route('**/*',r=>{const u=new URL(r.request().url());if(u.origin!==origin)return r.abort();if(u.searchParams.get('modal')==='1'){requests.push(u.pathname);return r.fulfill({contentType:'text/html',body:`<section data-probe-photo="${u.pathname}"><h1>${u.pathname}</h1><button type="button">Focus probe</button><input aria-label="Input probe"><textarea aria-label="Text probe"></textarea><select aria-label="Select probe"><option>A</option><option>B</option></select><div contenteditable="true" aria-label="Editable probe"></div></section>`})}if(u.pathname.endsWith('.svg')){return r.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#777"/></svg>'})}return r.fulfill({contentType:'text/html',body:html})});
 async function load(start,auto){await p.goto(origin+start);const hooks=await p.evaluate(({auto})=>{const o=document.querySelector('[data-grid-url][id$="-modal-overlay"]');if(!o)return null;const prefix=o.id.replace(/-modal-overlay$/,'');const links=[...document.querySelectorAll('.'+prefix+'-tile a')];links.forEach((a,i)=>{if(i>2)a.closest('.'+prefix+'-tile').remove();else a.href='/photograph-'+[21,4,90][i]});o.setAttribute('data-grid-url','/');if(auto)o.setAttribute('data-autoopen','1');else o.removeAttribute('data-autoopen');return {prefix,count:Math.min(links.length,3)}} ,{auto});await p.addScriptTag({content:'window.SnapSlider=function(){};'});await p.addScriptTag({path:engine});return hooks;}
 const checks=[];async function check(name,expected){await p.waitForTimeout(100);const actual=new URL(p.url()).pathname;checks.push({name,expected,actual,pass:actual===expected});}
 try{const hooks=await load('/'+skin,false);if(!hooks||hooks.count!==3)throw Error('Fixture does not emit three tile links and overlay');
 await p.locator('.'+hooks.prefix+'-tile a').first().click();await p.waitForFunction(()=>document.querySelector('[data-probe-photo]'));await check('open-first','/photograph-21');
 await p.keyboard.press('ArrowLeft');await check('first-does-not-wrap','/photograph-21');
 await p.keyboard.press('ArrowRight');await check('right-next','/photograph-4');
 await p.keyboard.press('ArrowLeft');await check('left-previous','/photograph-21');
 await p.keyboard.press('ArrowRight');await check('filtered-grid-next-not-date','/photograph-4');await p.keyboard.press('Space');await check('space-previous','/photograph-21');await p.keyboard.press('ArrowRight');await check('return-middle','/photograph-4');
 await p.keyboard.press('ArrowRight');await check('next-last','/photograph-90');
 await p.keyboard.press('ArrowRight');await check('last-does-not-wrap','/photograph-90');
 for(const selector of ['input','textarea','select','[contenteditable]']){await p.locator('[data-probe-photo] '+selector).focus();await p.keyboard.press('ArrowLeft');await check('editable-left-'+selector,'/photograph-90');await p.keyboard.press('Space');await check('editable-space-'+selector,'/photograph-90');if(selector==='input'||selector==='textarea')checks.push({name:'space-reaches-'+selector,pass:await p.locator('[data-probe-photo] '+selector).inputValue()===' '});} await p.screenshot({path:path.join(output,skin+'-'+width+'-open.png')});await p.locator('[data-probe-photo] button').focus();await p.keyboard.press('Control+ArrowLeft');await check('modified-arrow-stays','/photograph-90');await p.keyboard.press('Escape');await p.waitForTimeout(100);checks.push({name:'escape-closes',pass:await p.locator('[data-grid-url][id$="-modal-overlay"]').evaluate(o=>o.hasAttribute('hidden'))});await p.screenshot({path:path.join(output,skin+'-'+width+'.png')});
 await load('/photograph-4',true);await p.waitForFunction(()=>document.querySelector('[data-probe-photo]'));await p.keyboard.press('ArrowRight');await check('matching-deep-link','/photograph-90');
 await load('/not-in-loaded-tiles',true);await p.waitForFunction(()=>document.querySelector('[data-probe-photo]'));await p.keyboard.press('ArrowRight');await check('unmatched-deep-link-stays','/not-in-loaded-tiles');
 results.push({skin,width,hooks,checks,requests,errors,pass:checks.every(c=>c.pass)&&!errors.length});
 }catch(e){results.push({skin,width,checks,requests,errors,error:e.message,pass:false})}finally{await ctx.close()}
}}finally{await browser.close();fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({engine,fixtures,scope:'Real landing markup/styles; synthetic three-tile subset and fragment endpoint. No live controller, database, install or carousel verification.',results},null,2));}
const passed=results.filter(r=>r.pass).length;console.log(JSON.stringify({passed,total:results.length,output}));process.exitCode=passed===results.length?0:1;
})().catch(e=>{console.error(e);process.exitCode=2});
// ===== SNAPSMACK EOF =====
