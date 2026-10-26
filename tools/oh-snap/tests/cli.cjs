/** SNAPSMACK_EOF_HEADER: real CLI round trips, policy rejection and PNG/ZIP outputs. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os');
const {spawnSync}=require('node:child_process');
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'ohsnap-cli-')),cli=path.resolve(__dirname,'../scripts/cli.cjs');
function call(...args){const r=spawnSync(process.execPath,[cli,...args],{encoding:'utf8',env:process.env});return {code:r.status,result:JSON.parse(r.stdout)};}
const project=path.join(tmp,'new.ohsnap'),edit=path.join(tmp,'edit.json'),edited=path.join(tmp,'edited.ohsnap');
assert.equal(call('new','--mode','SMACKTHEMUP','--output',project).code,0);
fs.writeFileSync(edit,JSON.stringify({overrides:{'--bg-page':'#17212b','--grid-gap':'28px'},user_css:'.single-post { border: 2px solid #b9e678; }'}));
assert.equal(call('edit','--project',project,'--input',edit,'--output',edited).code,0);
const info=call('inspect','--project',edited);assert.equal(info.result.data.overrides['--bg-page'],'#17212b');
assert.ok(info.result.data.user_css.includes('border: 2px'));assert.equal(info.result.data.mode,'GRAMOFSMACK');
assert.equal(call('validate','--project',edited).code,0);
const png=path.join(tmp,'preview.png');assert.equal(call('render','--project',edited,'--view','archive','--width','390','--output',png).code,0);
const bytes=fs.readFileSync(png);assert.equal(bytes.readUInt32BE(16),390);assert.equal(bytes.readUInt32BE(20),1000);
const zip=path.join(tmp,'skin.zip');assert.equal(call('export','--project',edited,'--output',zip).code,0);assert.equal(fs.readFileSync(zip).readUInt32LE(),0x04034b50);
// Stored ZIP entries can be independently decoded without trusting the writer.
const archive=fs.readFileSync(zip);let offset=0;const files={};
while(archive.readUInt32LE(offset)===0x04034b50){const length=archive.readUInt32LE(offset+18),nameLength=archive.readUInt16LE(offset+26),extra=archive.readUInt16LE(offset+28);const start=offset+30+nameLength+extra;files[archive.subarray(offset+30,offset+30+nameLength).toString()]=archive.subarray(start,start+length).toString();offset=start+length;}
assert.equal(Object.keys(files).length,5);assert.deepEqual(JSON.parse(files['manifest.json']).modes,['carousel','smackthemup']);
fs.writeFileSync(edit,JSON.stringify({user_css:'body { background:url(https://bad.invalid/image) }'}));
assert.notEqual(call('edit','--project',project,'--input',edit,'--output',path.join(tmp,'bad.ohsnap')).code,0);
assert.ok(!fs.existsSync(path.join(tmp,'bad.ohsnap')));
const before=fs.readFileSync(project);assert.notEqual(call('edit','--project',project,'--input',edit,'--output',project,'--overwrite').code,0);assert.deepEqual(fs.readFileSync(project),before);
assert.notEqual(call('inspect','--project',project,'--result',project).code,0);assert.deepEqual(fs.readFileSync(project),before);
console.log(JSON.stringify({passed:10,evidence:tmp,commands:['new','edit','inspect','validate','render','export'],files:Object.keys(files)}));
// ===== SNAPSMACK EOF =====
