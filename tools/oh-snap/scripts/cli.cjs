/** SNAPSMACK_EOF_HEADER: headless automation through the actual OH SNAP UI/services. */
const fs=require('node:fs'), path=require('node:path'), http=require('node:http'), crypto=require('node:crypto');
const root=path.resolve(__dirname,'../src');
function read(file){if(fs.statSync(file).size>4_000_000)throw Error('Input exceeds 4 MB');return JSON.parse(fs.readFileSync(file,'utf8').replace(/^\uFEFF/,''));}
function atomic(file,data){const target=path.resolve(file),tmp=target+'.'+crypto.randomUUID()+'.tmp';fs.mkdirSync(path.dirname(target),{recursive:true});try{fs.writeFileSync(tmp,data);fs.renameSync(tmp,target);}finally{if(fs.existsSync(tmp))fs.unlinkSync(tmp);}}
function zip(files){
    // Standard, uncompressed ZIP records. File contents come from the same
    // project.exportPackage used by the desktop UI, not a second skin builder.
    const parts=[],directory=[];let offset=0;
    for(const file of files){
        const name=Buffer.from(file.path),data=Buffer.from(file.content);let crc=0xffffffff;
        for(const byte of data){crc^=byte;for(let n=0;n<8;n++)crc=(crc>>>1)^((crc&1)?0xedb88320:0);}crc=(crc^0xffffffff)>>>0;
        const local=Buffer.alloc(30);local.writeUInt32LE(0x04034b50);local.writeUInt16LE(20,4);local.writeUInt16LE(33,12);local.writeUInt32LE(crc,14);local.writeUInt32LE(data.length,18);local.writeUInt32LE(data.length,22);local.writeUInt16LE(name.length,26);
        const central=Buffer.alloc(46);central.writeUInt32LE(0x02014b50);central.writeUInt16LE(20,4);central.writeUInt16LE(20,6);central.writeUInt16LE(33,14);central.writeUInt32LE(crc,16);central.writeUInt32LE(data.length,20);central.writeUInt32LE(data.length,24);central.writeUInt16LE(name.length,28);central.writeUInt32LE(offset,42);
        parts.push(local,name,data);directory.push(central,name);offset+=local.length+name.length+data.length;
    }
    const catalog=Buffer.concat(directory),end=Buffer.alloc(22);end.writeUInt32LE(0x06054b50);end.writeUInt16LE(files.length,8);end.writeUInt16LE(files.length,10);end.writeUInt32LE(catalog.length,12);end.writeUInt32LE(offset,16);
    return Buffer.concat([...parts,catalog,end]);
}
async function main(){
    const argv=process.argv.slice(2),opts={};let command=argv.shift(),resultFile=null,browser,server;
    let envelope,code=0;
    try{
        if(command==='--help'||!command){console.log('OH SNAP CLI: capabilities | new | inspect | edit | validate | render | export\nOptions: --project FILE --input JSON --output FILE --overwrite --mode MODE --view post|archive|landing|page --width 1280 --result JSON');return;}
        for(let i=0;i<argv.length;i++){
            const key=argv[i];if(!key.startsWith('--')||!['--project','--input','--output','--mode','--view','--width','--result','--overwrite'].includes(key))throw Error('Unknown option '+key);
            if(key==='--overwrite'){opts.overwrite=true;continue;}
            if(!argv[i+1]||argv[i+1].startsWith('--'))throw Error('Missing value for '+key);
            opts[key.slice(2)]=argv[++i];
        }
        resultFile=opts.result;
        const protectedPaths=[opts.project,opts.input,opts.output].filter(Boolean).map(x=>path.resolve(x));
        if(resultFile&&protectedPaths.includes(path.resolve(resultFile))){resultFile=null;throw Error('--result must differ from input/output files');}
        const commands=['capabilities','new','inspect','edit','validate','render','export'];
        if(!commands.includes(command))throw Error('Unknown command '+command);
        if(command==='capabilities'){
            envelope={ok:true,protocol:1,app:'ohsnap',data:{commands,paid_ai_fallback:false,result_file:true,scope:'Saved offline projects; actual UI policy, preview and package generator'}};
        }else{
            const writes=['new','edit','render','export'].includes(command);
            if(command!=='new'&&!opts.project)throw Error('--project is required');
            if(command==='edit'&&!opts.input)throw Error('--input is required');
            if(writes){
                if(!opts.output)throw Error('--output is required');
                const output=path.resolve(opts.output);
                if([opts.project,opts.input].filter(Boolean).some(x=>path.resolve(x)===output))throw Error('Output must differ from input files');
                if(fs.existsSync(output)&&!opts.overwrite)throw Error('Output exists; choose another or pass --overwrite');
            }
            const project=opts.project?read(opts.project):null;
            if(project&&project.source!=='offline')throw Error('CLI currently supports offline skin projects only');
            const {chromium}=require('playwright');
            server=http.createServer((req,res)=>{
                try{const file=path.resolve(root,'.'+decodeURIComponent(new URL(req.url,'http://localhost').pathname));
                    if(!file.startsWith(root+path.sep)){res.writeHead(403).end();return;}
                    const mime={'.html':'text/html','.js':'text/javascript','.css':'text/css'};res.setHeader('Content-Type',mime[path.extname(file)]||'application/octet-stream');
                    fs.readFile(file,(err,data)=>err?res.writeHead(404).end():res.end(data));
                }catch{res.writeHead(400).end();}
            });
            await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
            const origin=`http://127.0.0.1:${server.address().port}`;
            browser=await chromium.launch({headless:true,channel:process.env.OHSNAP_TEST_BROWSER||'msedge'});
            const page=await browser.newPage({viewport:{width:1500,height:1200}});
            await page.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
            await page.addInitScript(project=>{
                window.__cli={project,pack:null,alerts:[]};window.alert=text=>window.__cli.alerts.push(String(text));
                window.__TAURI__={core:{invoke:async(cmd,args)=>{
                    if(cmd==='shared_profiles')return [];
                    if(cmd==='vault_get')return null;
                    if(cmd==='plugin:dialog|open')return 'cli-project.ohsnap';
                    if(cmd==='load_project')return JSON.stringify(window.__cli.project);
                    if(cmd==='plugin:dialog|save')return 'cli-export.zip';
                    if(cmd==='export_shareable_package'){window.__cli.pack=args;return;}
                    throw Error('CLI does not permit native command '+cmd);
                }}};
            },project);
            await page.goto(origin+'/index.html');
            if(command==='new'){
                let mode=opts.mode||'SMACKONEOUT';if(mode==='SMACKTHEMUP')mode='GRAMOFSMACK';
                if(!['SMACKONEOUT','GRAMOFSMACK','SMACKTALK'].includes(mode))throw Error('Unsupported new-project family');
                await page.selectOption('#offline-mode',mode);await page.click('#btn-new-offline');
            }else{
                const loaded=await page.evaluate(async()=>{const value=await OhSnapProject.load();if(value)enterOfflineProject(value,false);return !!value;});
                if(!loaded)throw Error((await page.evaluate(()=>window.__cli.alerts)).join('\n')||'Project load failed');
            }
            if(command==='edit'){
                const update=read(opts.input);
                if(!update||Array.isArray(update)||Object.keys(update).some(x=>!['name','skin_slug','author','skin_version','overrides','user_css','preview_content'].includes(x)))throw Error('Unknown design update field');
                await page.evaluate(update=>{
                    if(update.name!==undefined)OhSnapProject.updateProjectName(update.name);
                    OhSnapProject.updateMetadata(Object.fromEntries(Object.entries(update).filter(([key])=>['skin_slug','author','skin_version'].includes(key))));
                    OhSnapProject.applyGeneration({overrides:update.overrides||{},user_css:update.user_css||'',summary:'CLI design edit'});
                    if(update.preview_content)OhSnapProject.updatePreviewContent(update.preview_content);
                },update);
            }
            const validation=await page.evaluate(()=>OhSnapProject.validateProject());
            if(validation.errors.length)throw Error(validation.errors.join('\n'));
            let data;
            if(command==='render'){
                const view=opts.view||'post',width=Number(opts.width||1280);
                if(!['post','archive','landing','page'].includes(view)||![390,768,1280].includes(width))throw Error('Unsupported preview view or width');
                await page.evaluate(({view,width})=>{OhSnapPreview.switchView(view);const frame=document.querySelector('#preview-frame');frame.style.width=width+'px';frame.style.height='1000px';},{view,width});
                await page.waitForFunction(()=>document.querySelector('#preview-frame').contentDocument?.querySelector('[data-preview-site]'));
                fs.mkdirSync(path.dirname(path.resolve(opts.output)),{recursive:true});
                await page.locator('#preview-frame').screenshot({path:opts.output,type:'png'});data={output:path.resolve(opts.output),view,width};
            }else if(command==='export'){
                await page.evaluate(()=>OhSnapProject.exportPackage());
                const pack=await page.evaluate(()=>window.__cli.pack);
                if(!pack)throw Error((await page.evaluate(()=>window.__cli.alerts)).join('\n')||'Package generation failed');
                atomic(opts.output,zip(pack.files));data={output:path.resolve(opts.output),files:pack.files.map(f=>f.path)};
            }else if(command==='validate')data=validation;
            else{
                data=await page.evaluate(()=>OhSnapProject.current());
                if(writes){atomic(opts.output,JSON.stringify(data,null,2)+'\n');data={output:path.resolve(opts.output),project_id:data.project_id,mode:data.mode};}
            }
            envelope={ok:true,protocol:1,app:'ohsnap',data};
        }
    }catch(error){code=2;envelope={ok:false,protocol:1,app:'ohsnap',error:{code:'operation',message:error.message}};}
    finally{if(browser)await browser.close();if(server)await new Promise(resolve=>server.close(resolve));}
    if(resultFile)atomic(resultFile,JSON.stringify(envelope,null,2)+'\n');else console.log(JSON.stringify(envelope));process.exitCode=code;
}
main().catch(error=>{console.error(error.message);process.exitCode=2;});
// ===== SNAPSMACK EOF =====
