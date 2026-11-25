// SNAPSMACK_EOF_HEADER
// Render CHAPLIN's restored archive frame against both real stylesheets.
const {chromium}=require('../tools/browser-tests/runtime.cjs');
const fs=require('node:fs');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 const shared=fs.readFileSync('assets/css/page-archive.css','utf8');
 const skin=fs.readFileSync('skins/chaplin/style.css','utf8');
 // Mutation restores the faulty landscape constraint; this must fail geometry.
 const baseline=skin+'\n.chap-archive-grid.cropped-grid > .chap-archive-item {align-self:start; aspect-ratio:3/2; overflow:hidden}';
 let checks=0;
 for(const width of [1920,1440,1024])for(const mode of ['cropped','square']){
  const page=await browser.newPage({viewport:{width,height:844}});
  const tiles=[2/3,3/4,1,3/2,3/2].map((r,i)=>{
   if(mode==='square')r=1;
   const portrait=r<1?' chap-thumb-portrait':'';
   return `<a class="chap-archive-item" href="#${i}"><div class="chap-thumb${portrait}" style="aspect-ratio:${r}"><img alt="fixture ${i}" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='600'%3E%3C/svg%3E"></div><h2 class="chap-archive-title">Fixture ${i}</h2></a>`;
  }).join('');
  const html=css=>`<style>${shared}\n${css}</style><section id="browse-grid" class="chap-archive-grid archive-grid h-feed ${mode}-grid archive-layout-${mode} archive-cols-4 archive-thumbs-m">${tiles}</section><div id="photo" class="chap-img-frame"><img width="100" height="150" alt="photo control"></div>`;
  const measure=()=>[...document.querySelectorAll('.chap-thumb')].map(t=>({thumb:t.getBoundingClientRect().toJSON(),link:t.parentElement.getBoundingClientRect().toJSON()}));
  await page.setContent(html(baseline));const before=await page.evaluate(measure);
  const photoBefore=await page.locator('#photo').boundingBox();
  if(mode==='cropped')assert(before.some(v=>v.thumb.bottom>v.link.bottom+1),'old CSS must reproduce clipping');
  await page.setContent(html(skin));const after=await page.evaluate(measure);
  assert.equal(after.length,5,'every prepared tile must be measured');
  for(const {thumb,link} of after){assert(thumb.width>0 && thumb.height>0,'frame must have area');assert(thumb.bottom<=link.bottom+1,'link must not clip frame');if(mode==='square')assert(Math.abs(thumb.width-thumb.height)<1,'square mode must stay square');}
  const photoAfter=await page.locator('#photo').boundingBox(); assert.equal(photoAfter.width,photoBefore.width); assert.equal(photoAfter.height,photoBefore.height);
  console.log(`PASS ${width}px ${mode}: ${after.length} shapes, unclipped frames, photo unchanged`);checks++;await page.close();
 }
 await browser.close();console.log(`${checks} cases passed`);
})().catch(e=>{console.error(e);process.exitCode=1});
// ===== SNAPSMACK EOF =====
