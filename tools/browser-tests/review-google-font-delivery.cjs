// SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker below.
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('./runtime.cjs');
const root=path.resolve(__dirname,'../..');
const output=process.env.SNAPSMACK_BROWSER_OUTPUT||path.join(root,'opaudits/evidence/027/2026-10-29-google-font-delivery');
const data=JSON.parse(execFileSync('php',['-r',
 "require 'core/skin-presentation.php'; require_once 'core/custom-code-policy.php'; $p=['options'=>['title_font'=>'Poiret One']]; snapsmack_attach_local_font_faces($p); echo json_encode(['head'=>(string)$p['style'],'csp'=>snapsmack_public_csp(false)]);"
],{cwd:root,encoding:'utf8'}));
(async()=>{
 fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({headless:true,channel:'msedge'}),results=[];
 try{
  for(const mutation of [false,true]){
   const context=await browser.newContext();
   const errors=[];
   const page=await context.newPage();
   page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
   const policy=mutation?data.csp.replace('https://fonts.googleapis.com','').replace('https://fonts.gstatic.com',''):data.csp;
   await context.route('https://font-review.invalid/',r=>r.fulfill({status:200,headers:{'Content-Type':'text/html','Content-Security-Policy':policy},body:'<!doctype html><html><head>'+data.head+'<style>body{background:#0a0a0a;color:#f0f0f0}h1{font:48px "Poiret One",serif}</style></head><body><h1>A Colourless Life</h1></body></html>'}));
   await page.goto('https://font-review.invalid/',{waitUntil:'load'});
   const loaded=await page.evaluate(async()=>{await document.fonts.ready; await document.fonts.load('48px "Poiret One"');return [...document.fonts].filter(f=>f.family.replace(/["']/g,'')==='Poiret One'&&f.status==='loaded').length;});
   results.push({mutation,loaded,errors});
   if(!mutation){
    for(const width of [1920,1440,1024]){await page.setViewportSize({width,height:900});await page.screenshot({path:path.join(output,`font-${width}.png`)});}
    assert.ok(loaded>0,'Selected Google face must load with actual CMS CSP; '+errors.join('\n'));
   }else assert.equal(loaded,0,'Removing Google host permission must reproduce the failure');
   await context.close();
  }
 }finally{fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(results,null,2));await browser.close();}
 console.log('PASS selected Google font loads under real CMS CSP; blocked-host mutation fails delivery.');
})().catch(e=>{console.error(e);process.exitCode=1;});
// ===== SNAPSMACK EOF =====
