// SNAPSMACK_EOF_HEADER
// Render CHAPLIN's nested archive frame against both real stylesheets.
const {chromium}=require('playwright');
const fs=require('node:fs');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const shared=fs.readFileSync('assets/css/page-archive.css','utf8');
 const skin=fs.readFileSync('skins/chaplin/style.css','utf8');
 // Mutation restores the faulty landscape constraint; this must fail geometry.
 const baseline=skin+'\n.fsog-archive-grid.cropped-grid .fsog-archive-item > .thumb-link {aspect-ratio:3/2 !important}';
 let checks=0;
 for(const width of [1920,390])for(const mode of ['cropped','square']){
  const page=await browser.newPage({viewport:{width,height:844}});
  const tiles=[2/3,3/4,1,3/2,3/2].map((r,i)=>{
   if(mode==='square')r=1;
   const orientation=r<1?'portrait':r>1?'landscape':'square';
   return `<article class="thumb-container fsog-archive-item"><a class="thumb-link" href="#${i}"><div class="fsog-thumb fsog-thumb-${orientation}" style="aspect-ratio:${r}"><div class="chap-img-frame"><img alt="fixture ${i}" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='600'%3E%3C/svg%3E"><div class="chap-frame-lines"><div class="chap-line chap-line-bot"></div></div></div></div></a></article>`;
  }).join('');
  const html=css=>`<style>${shared}\n${css}</style><section id="browse-grid" class="fsog-archive-grid ${mode}-grid">${tiles}</section><div id="photo" class="chap-img-frame"><img width="100" height="150" alt="photo control"></div>`;
  const measure=()=>[...document.querySelectorAll('.fsog-thumb')].map(t=>{const rect=e=>{const r=e.getBoundingClientRect();return {w:r.width,h:r.height}};return{thumb:rect(t),frame:rect(t.firstElementChild),link:rect(t.parentElement),article:rect(t.parentElement.parentElement)}});
  await page.setContent(html(baseline));const before=await page.evaluate(measure);
  const photoBefore=await page.locator('#photo').boundingBox();
  if(mode==='cropped')assert(before.some(v=>v.frame.h>v.link.h+1),'old CSS must reproduce clipping');
  await page.setContent(html(skin));const after=await page.evaluate(measure);
  for(const v of after){assert(Math.abs(v.thumb.h-v.frame.h)<1,'frame must fill thumbnail');assert(v.link.h>=v.frame.h-1,'link must not clip frame');assert(v.article.h>=v.frame.h-1,'article must not clip frame');if(mode==='square')assert(Math.abs(v.thumb.w-v.thumb.h)<1,'square mode must stay square');}
  const photoAfter=await page.locator('#photo').boundingBox(); assert.equal(photoAfter.width,photoBefore.width); assert.equal(photoAfter.height,photoBefore.height);
  console.log(`PASS ${width}px ${mode}: ${after.length} shapes, unclipped frames, photo unchanged`);checks++;await page.close();
 }
 await browser.close();console.log(`${checks} cases passed`);
})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
