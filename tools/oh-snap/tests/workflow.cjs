/** SNAPSMACK_EOF_HEADER: full frontend workflow and real CMS gate checks. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const http=require('node:http');
const {execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..');
const out=path.resolve(root,'../../.codex-temp/ohsnap-031-tests');
fs.mkdirSync(out,{recursive:true});
const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css','.png':'image/png'};
const server=http.createServer((req,res)=>{
    const file=path.resolve(root,'src','.'+decodeURIComponent(new URL(req.url,'http://localhost').pathname));
    if(!file.startsWith(path.join(root,'src')+path.sep)){res.writeHead(403).end();return;}
    fs.readFile(file,(err,data)=>{if(err){res.writeHead(404).end();return;}res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');res.end(data);});
});
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const browser=await chromium.launch({headless:true,channel:process.env.OHSNAP_TEST_BROWSER || 'msedge'});
    const page=await browser.newPage({viewport:{width:1440,height:1000}}), errors=[];
    page.on('pageerror',err=>errors.push(err.message));
    await page.addInitScript(()=>{
        window.__test={files:{},exports:[],confirm:true,calls:[],aiRequests:[]};
        window.__TAURI__={core:{invoke:async(cmd,args)=>{
            window.__test.calls.push(cmd);
            if(cmd==='shared_profiles')return Array.from({length:30},(_,i)=>({name:`Fixture site ${i+1}`,url:`https://fixture-${i+1}.invalid`}));
            if(cmd==='vault_get')return null;
            if(cmd==='vault_set'||cmd==='vault_delete')return;
            if(cmd==='confirm_action')return window.__test.confirm;
            if(cmd==='plugin:dialog|save')return args.options.defaultPath;
            if(cmd==='plugin:dialog|open')return window.__test.openPath || Object.keys(window.__test.files)[0];
            if(cmd==='save_project'){window.__test.files[args.path]=args.content;return;}
            if(cmd==='load_project')return window.__test.files[args.path];
            if(cmd==='export_shareable_package'){window.__test.exports.push(args);return;}
            throw new Error('Unexpected native command '+cmd);
        }}};
        const original=window.fetch;
        window.fetch=async(url,options)=>{
            if(String(url).includes('api.anthropic.com')){
                window.__test.aiRequests.push(JSON.parse(options.body));
                if(window.__test.badAI)return new Response(JSON.stringify({content:[{text:'{bad json'}]}),{status:200});
                return new Response(JSON.stringify({content:[{text:JSON.stringify({overrides:{'--bg-page':'#17212b','--grid-gap':'28px'},user_css:'.thumb-container { border: 2px solid #b9e678; padding: 10px; }',required_scripts:[],required_styles:[],summary:'A blue gallery with framed photographs'})}]}),{status:200});
            }
            return original(url,options);
        };
        window.alert=message=>{window.__test.lastAlert=String(message);};
    });
    const results=[];
    try {
        await page.goto(`http://127.0.0.1:${server.address().port}/index.html`);
        await page.waitForTimeout(100);
        const start=await page.locator('#btn-new-offline').boundingBox();assert.ok(start.y>=0&&start.y+start.height<1000,'Start controls must remain visible with a large shared fleet.');
        await page.click('#btn-new-offline');
        await page.waitForFunction(()=>document.querySelector('#preview-frame').contentDocument?.querySelector('[data-preview-site]'));
        assert.equal(await page.locator('#preview-frame').getAttribute('sandbox'),'allow-same-origin');
        assert.deepEqual(await page.evaluate(()=>{
            const doc=document.querySelector('#preview-frame').contentDocument;
            return Object.entries(OhSnapPolicy.definitions(window.OH_SNAP_CONTRACT.modes.SMACKONEOUT.variables)).filter(([key,meta])=>getComputedStyle(doc.documentElement).getPropertyValue(key).trim()!==String(meta.default)+(meta.unit||'')).map(([key])=>key);
        }),[],'Control defaults must match exported starter CSS.');
        await page.evaluate(()=>OhSnapSettings.save({ai_provider:'claude',claude_key:'fake-test-key'}));
        await page.click('#btn-ai-toggle');await page.fill('#ai-input','Make a blue editorial gallery with framed images.');await page.click('#btn-ai-send');
        await page.waitForFunction(()=>window.__test.aiRequests.length===1&&!document.querySelector('#btn-ai-send').disabled);
        assert.equal(await page.evaluate(()=>OhSnapProject.current().overrides['--bg-page']),'#17212b');
        assert.match(await page.evaluate(()=>OhSnapProject.current().user_css),/border: 2px/);
        results.push('AI proposal applied through actual UI, with current design/markup context');
        await page.fill('#ai-input','Increase that spacing a little.');await page.click('#btn-ai-send');
        await page.waitForFunction(()=>window.__test.aiRequests.length===2&&!document.querySelector('#btn-ai-send').disabled);
        const second=await page.evaluate(()=>window.__test.aiRequests[1]);assert.equal(second.messages.length,3);assert.match(second.system,/#17212b/);
        results.push('AI follow-up includes conversation and current CSS');
        const beforeCancel=await page.evaluate(()=>JSON.stringify(OhSnapProject.current().overrides));
        await page.evaluate(()=>{window.__test.confirm=false;});
        await page.fill('#ai-input','Another change');await page.click('#btn-ai-send');
        await page.waitForFunction(()=>window.__test.aiRequests.length===3&&!document.querySelector('#btn-ai-send').disabled);
        assert.equal(await page.evaluate(()=>JSON.stringify(OhSnapProject.current().overrides)),beforeCancel);
        await page.evaluate(()=>{window.__test.confirm=true;});results.push('AI cancellation preserves design');
        await page.evaluate(()=>{window.__test.badAI=true;});
        await page.fill('#ai-input','A deliberately malformed response test');await page.click('#btn-ai-send');
        await page.waitForFunction(()=>window.__test.aiRequests.length===4&&!document.querySelector('#btn-ai-send').disabled);
        assert.equal(await page.evaluate(()=>JSON.stringify(OhSnapProject.current().overrides)),beforeCancel);
        assert.match(await page.locator('.ai-msg-error').last().textContent(),/(no|invalid) design JSON/);
        await page.evaluate(()=>{window.__test.badAI=false;});results.push('Malformed provider output leaves design intact and re-enables controls');
        await page.evaluate(()=>OhSnapProject.save());
        const saved=await page.evaluate(()=>OhSnapProject.current());
        await page.evaluate(()=>OhSnapProject.load());
        await page.evaluate(()=>enterOfflineProject(OhSnapProject.current(),false));
        assert.equal(await page.evaluate(()=>OhSnapProject.current().user_css),saved.user_css);
        results.push('Native-command save/open round trip retains custom CSS');
        await page.evaluate(()=>OhSnapProject.applyGeneration({overrides:{'--bg-page':'#ab1212'},user_css:'.single-post { padding: 20px; }'}));
        await page.evaluate(()=>OhSnapProject.undo());assert.equal(await page.evaluate(()=>OhSnapProject.current().overrides['--bg-page']),'#17212b');
        await page.evaluate(()=>OhSnapProject.save());await page.evaluate(()=>OhSnapProject.load());await page.evaluate(()=>enterOfflineProject(OhSnapProject.current(),false));
        await page.evaluate(()=>OhSnapProject.redo());assert.equal(await page.evaluate(()=>OhSnapProject.current().overrides['--bg-page']),'#ab1212');
        results.push('Undo/redo and active history position survive save/reopen');
        await page.evaluate(()=>OhSnapProject.updatePreviewContent({site_name:'Montréal — 夜',lead_title:'L’été — 夜 <script>',lead_caption:'A caption & a photograph.'},5));
        await page.waitForFunction(()=>document.querySelector('#preview-frame').contentDocument?.body?.textContent.includes('Montréal — 夜'));
        for(const kind of ['archive','landing','page','post']){
            await page.click(`[data-view="${kind}"]`);await page.waitForTimeout(80);
            assert.ok(await page.evaluate(()=>document.querySelector('#preview-frame').contentDocument.body.textContent.length>50));
        }
        assert.equal(await page.evaluate(()=>document.querySelector('#preview-frame').contentDocument.querySelectorAll('script').length),0);
        results.push('Unicode-safe post/archive/landing/static-page preview');
        for(const width of [1280,768,390]){
            await page.click(`[data-width="${width}"]`);await page.waitForTimeout(300);
            const size=await page.evaluate(()=>{const doc=document.querySelector('#preview-frame').contentDocument;return {width:doc.documentElement.clientWidth,scroll:doc.documentElement.scrollWidth};});
            assert.equal(size.width,width,'Preview must render at the selected real width.');
            assert.ok(size.scroll<=size.width+2,JSON.stringify(size));
            await page.screenshot({path:path.join(out,`preview-${width}.png`)});
        }
        results.push('Desktop/tablet/phone layouts have no horizontal overflow');
        await page.evaluate(()=>{OhSnapProject.updatePreviewContent({site_name:'Stress test'},100);OhSnapPreview.switchView('archive');});
        await page.waitForFunction(()=>document.querySelector('#preview-frame').contentDocument?.querySelectorAll('[data-preview-card]').length===100);
        await page.evaluate(()=>{OhSnapProject.updatePreviewContent({site_name:'Empty test'},0);OhSnapPreview.switchView('post');});
        await page.waitForFunction(()=>document.querySelector('#preview-frame').contentDocument?.body?.textContent.includes('No photographs to show yet.'));
        results.push('100-image and empty-site scenarios render without errors');
        const blocked=await page.evaluate(()=>{
            const checks=[];
            for(const input of [{overrides:{'--made-up':'2px'}},{overrides:{'--grid-gap':'999px'}},{user_css:'body {background:url(//bad.invalid/image)}'},{required_scripts:['asset:public:ss-engine-wall']}]){
                const before=JSON.stringify(OhSnapProject.current());
                try{OhSnapProject.applyGeneration(input);checks.push(false);}catch{checks.push(true);}
            }
            return checks;
        });assert.ok(blocked.every(Boolean));results.push('Unknown variables, invalid ranges, remote CSS and unmounted dependencies are rejected');
        const writes=await page.evaluate(async()=>{
            OhSnapProject.newProject({source:'connected',skin_slug:'test-connected',connected_source:{url:'https://fixture.invalid',oh_snap_ready:true}});
            controlsApplyExternal({'--bg-page':'#123456'});window.__test.confirm=false;let writes=0;
            await OhSnapProject.pushToSite({pushVars:async()=>{writes++;}});window.__test.confirm=true;return writes;
        });assert.equal(writes,0);results.push('Cancelling a variable push sends no site write');
        const exports=[];
        assert.deepEqual(await page.locator('#offline-mode option').evaluateAll(options=>options.map(option=>option.value)),['SMACKONEOUT','GRAMOFSMACK','SMACKTALK']);
        assert.match(await page.locator('#offline-mode option[value=GRAMOFSMACK]').textContent(),/SMACKTHEMUP/);
        for(const mode of ['SMACKONEOUT','GRAMOFSMACK','SMACKTALK']){
            await page.evaluate(mode=>{document.querySelector('#offline-mode').value=mode;enterOfflineProject();OhSnapProject.updateMetadata({skin_slug:'test-'+mode.toLowerCase()});},mode);
            await page.evaluate(()=>OhSnapProject.exportPackage());
            const pack=await page.evaluate(()=>window.__test.exports.at(-1));assert.ok(pack);
            const dir=path.join(out,mode);fs.mkdirSync(dir,{recursive:true});for(const file of pack.files)fs.writeFileSync(path.join(dir,file.path),file.content);
            const manifest=JSON.parse(pack.files.find(file=>file.path==='manifest.json').content);assert.equal(manifest.schema_version,2);assert.equal(manifest.templates.default,'layout.php');
            if(mode==='GRAMOFSMACK'){assert.deepEqual(manifest.modes,['carousel','smackthemup']);assert.equal(manifest.features.fedistructure_only,false);}
            exports.push({mode,dir,files:pack.files});
        }
        fs.writeFileSync(path.join(out,'exports.json'),JSON.stringify(exports,null,2));
        const gate=execFileSync('php',[path.join(__dirname,'verify-exports.php'),out],{encoding:'utf8'});console.log(gate.trim());
        results.push('All three normal skin families (including both GRAMOFSMACK and SMACKTHEMUP modes) pass the actual CMS policy and strict renderer');
        assert.deepEqual(errors,[]);
        assert.equal(await page.evaluate(()=>getComputedStyle(document.querySelector('#tab-css')).display),'none');
        fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({passed:results,pageErrors:errors},null,2));
        console.log(JSON.stringify({passed:results,pageErrors:errors},null,2));
    } finally {await browser.close();server.close();}
})().catch(err=>{console.error(err);server.close();process.exitCode=1;});
// ===== SNAPSMACK EOF =====
