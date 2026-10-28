/** SNAPSMACK_EOF_HEADER: browser geometry check of actual editor-preview HTML and mosaic engine. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('../tools/browser-tests/runtime.cjs');
const root=path.resolve(__dirname,'..'),out=process.env.SNAPSMACK_BROWSER_OUTPUT||path.join(root,'.codex-temp/desktop-cli');
(async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        const page=await browser.newPage({viewport:{width:1440,height:1100}});
        const errors=[];page.on('pageerror',error=>errors.push(error.message));
        await page.route('https://preview.invalid/**',async route=>{
            const url=new URL(route.request().url());let file=path.resolve(root,'.'+url.pathname);
            if(url.pathname==='/preview')return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(out,'actual-post-preview.html'),'utf8')});
            if(/^\/fixture-\d.jpg$/.test(url.pathname))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#884422"/></svg>'});
            if(!file.startsWith(root+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
            return route.fulfill({path:file,contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':undefined});
        });
        await page.goto('https://preview.invalid/preview');
        await page.waitForFunction(()=>document.querySelectorAll('.snap-mosaic img').length===5&&[...document.querySelectorAll('.snap-mosaic img')].every(img=>img.complete&&img.naturalWidth>0));
        const geometry=await page.locator('.snap-mosaic').evaluate(el=>{const box=el.getBoundingClientRect();return {width:box.width,height:box.height,images:[...el.querySelectorAll('img')].map(img=>{const r=img.getBoundingClientRect();return {x:r.x-box.x,y:r.y-box.y,width:r.width,height:r.height};})};});
        assert.ok(geometry.width>200&&geometry.height>100);assert.equal(geometry.images.length,5);
        for(const img of geometry.images){assert.ok(img.width>0&&img.height>0);assert.ok(img.x>=-1&&img.y>=-1&&img.x+img.width<=geometry.width+1&&img.y+img.height<=geometry.height+1);}
        assert.deepEqual(errors,[]);await page.locator('.snap-mosaic').screenshot({path:path.join(out,'actual-post-preview-mosaic.png')});
        let liveImages=null;
        if(process.argv.includes('--live')){
            const live=await browser.newPage({viewport:{width:1440,height:1100}});
            await live.goto('https://baddaywithacamera.ca/ive-got-wood',{waitUntil:'domcontentloaded'});
            await live.waitForFunction(()=>document.querySelectorAll('.snap-mosaic img').length===5);
            assert.equal(await live.locator('.snap-mosaic').count(),2);
            await live.locator('.snap-mosaic').last().scrollIntoViewIfNeeded();
            await live.waitForFunction(()=>[...document.querySelectorAll('.snap-mosaic img')].every(img=>img.complete&&img.naturalWidth>0));
            const panels=await live.locator('.snap-mosaic').evaluateAll(es=>es.map(e=>{const r=e.getBoundingClientRect();return {top:r.top,bottom:r.bottom,ids:JSON.parse(e.dataset.mosaic).map(i=>i.id),tiles:[...e.querySelectorAll('img')].map(i=>{const b=i.getBoundingClientRect();return {x:b.x,y:b.y,height:b.height};})};}));
            assert.deepEqual(panels.map(p=>p.ids),[[2526],[2523,2527,2528,2529]]);
            assert.ok(Math.abs(panels[1].top-panels[0].bottom-4)<1);
            const [left,right,smallLeft,smallRight]=panels[1].tiles;
            assert.ok(right.x>left.x&&right.height>left.height*1.4&&smallLeft.y>left.y&&smallRight.x>smallLeft.x);
            await live.screenshot({path:path.join(out,'ive-got-wood-restored-lower-layout.png'),fullPage:true});
            liveImages=5;
        }
        console.log(JSON.stringify({local_preview_images:5,local_geometry:geometry,live_mosaic_images:liveImages,pageErrors:errors}));
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
// ===== SNAPSMACK EOF =====
