// SNAPSMACK_EOF_HEADER: preserve canonical EOF marker below.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('./runtime.cjs');
const root=path.resolve(__dirname,'../..'),out=process.env.SNAPSMACK_BROWSER_OUTPUT||path.join(require('node:os').tmpdir(),'impact-print-controls');
fs.mkdirSync(out,{recursive:true});
(async()=>{const browser=await chromium.launch({headless:true,channel:'msedge'});const results=[];
try{for(const width of [1920,1440,1024]){
 const context=await browser.newContext({viewport:{width,height:1080},hasTouch:width===1024});
 for(const file of ['skins/impact-printer/style.css','assets/css/page-archive.css','assets/js/ss-engine-archive-filter.js'])await context.route('**/'+file+'*',r=>r.fulfill({path:path.join(root,file)}));
 const page=await context.newPage();await page.goto('https://pixhellated.ca/archive-view',{waitUntil:'domcontentloaded'});
 const sizes=()=>page.locator('#browse-grid .thumb-link').evaluateAll(es=>es.slice(0,24).map(e=>{const r=e.getBoundingClientRect();return[r.width,r.height]}));
 const before=await sizes();
 // Simulate the existing candidate manifest's two missing assets locally.
 // This does not install or modify anything on the site.
 await page.addStyleTag({url:'https://pixhellated.ca/assets/css/page-archive.css'});
 await page.addScriptTag({url:'https://pixhellated.ca/assets/js/ss-engine-archive-filter.js'});
 await page.evaluate(()=>document.dispatchEvent(new Event('DOMContentLoaded')));
 await page.evaluate(()=>document.fonts.ready);
 await page.evaluate(()=>Promise.all([...document.images].filter(i=>i.getBoundingClientRect().top<1080).map(i=>i.decode().catch(()=>{}))));
 await page.waitForTimeout(2200);
 assert.deepEqual(await sizes(),before,'archive asset must not change photo geometry');
 const panel=page.locator('#smack-archive-filter-panel'),button=page.locator('#smack-archive-filter-btn');
 assert.equal(await panel.isVisible(),false,'dropdown must start collapsed');
 const families=await page.locator('#infobox button,#infobox input,#infobox select,#infobox label,#infobox .saf-label').evaluateAll(es=>es.map(e=>({tag:e.tagName,font:getComputedStyle(e).fontFamily})));
 assert.ok(families.length>5);assert.ok(families.every(x=>x.font.includes('DotMatrix')));
 const fonts=await page.evaluate(()=>[...document.fonts].filter(f=>f.family.includes('DotMatrix')).map(f=>({family:f.family,status:f.status})));
 assert.ok(fonts.some(f=>f.family==='DotMatrix'&&f.status==='loaded'),'actual dot matrix font must load');
 await page.screenshot({path:path.join(out,'impact-closed-'+width+'.png')});
 if(width===1024)await button.tap();else await button.click();
 assert.equal(await panel.isVisible(),true);assert.equal(await button.getAttribute('aria-expanded'),'true');
 const rect=await panel.boundingBox();assert.ok(rect.x>=0&&rect.x+rect.width<=width);
 await page.screenshot({path:path.join(out,'impact-open-'+width+'.png')});
 await page.keyboard.press('Escape');assert.equal(await panel.isVisible(),false);
 await button.focus();await page.keyboard.press('Enter');assert.equal(await panel.isVisible(),true);
 results.push({width,families,fonts,panel:rect,photoGeometryUnchanged:true,keyboard:true,touch:width===1024});
 await context.close();
}fs.writeFileSync(path.join(out,'measurements.json'),JSON.stringify(results,null,2));console.log('PASS dropdown, loaded DotMatrix font, keyboard/touch and unchanged photos at1920/1440/1024. Candidate manifest assets supplied locally; not deployed. '+out);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
