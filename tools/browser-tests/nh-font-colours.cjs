// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
const {chromium}=require('./runtime.cjs');
const fs=require('fs');
const path=require('node:path');
const {pathToFileURL}=require('node:url');
const fixture=process.argv[2]; const output=process.argv[3];
if(!fixture||!output) throw new Error('Pass generated NH colour fixture directory and evidence directory');
fs.mkdirSync(output,{recursive:true});
(async()=>{const b=await chromium.launch({headless:true});const results=[];for(const width of [1920,1440,1024])for(const variant of ['dark','light'])for(const state of ['default','grey']){const p=await b.newPage({viewport:{width,height:1000}});await p.goto(pathToFileURL(path.resolve(fixture,variant+'-'+state+'.html')).href);const visible=await p.locator('#sig-text').isVisible();const actual=await p.evaluate(()=>Object.fromEntries(['.static-page-title','#body','.description','#sig-text'].map(s=>[s,getComputedStyle(document.querySelector(s)).color])));const expected=state==='grey'?['rgb(128, 128, 128)','rgb(128, 128, 128)','rgb(128, 128, 128)','rgb(128, 128, 128)']:variant==='dark'?['rgb(255, 255, 255)','rgb(204, 204, 204)','rgb(170, 170, 170)','rgb(204, 204, 204)']:['rgb(17, 17, 17)','rgb(51, 51, 51)','rgb(85, 85, 85)','rgb(51, 51, 51)'];const pass=visible && JSON.stringify(Object.values(actual))===JSON.stringify(expected);results.push({width,variant,state,actual,pass});await p.screenshot({path:path.join(output,variant+'-'+state+'-'+width+'.png')});await p.close();}await b.close();fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(results,null,2));console.log(JSON.stringify({pass:results.filter(x=>x.pass).length,total:results.length,failures:results.filter(x=>!x.pass)}));if(results.some(x=>!x.pass))process.exitCode=1;})();

// ===== SNAPSMACK EOF =====
