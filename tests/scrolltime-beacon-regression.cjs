const fs=require('fs'),vm=require('vm'),assert=require('assert');
let now=100000, timers=[], events={}, docEvents={}, sent=[];
const document={visibilityState:'visible',getElementById:()=>({getAttribute:n=>n==='data-hit'?'71':'/api.php?route=stats/dwell'}),addEventListener:(n,f)=>docEvents[n]=f};
vm.runInNewContext(fs.readFileSync('assets/js/ss-engine-scrolltime.js','utf8'),{document,window:{addEventListener:(n,f)=>events[n]=f},navigator:{sendBeacon:(url,body)=>sent.push({url,body})},Blob,Date:{now:()=>now},setInterval:(f,ms)=>timers.push({f,ms}),Math,JSON,parseInt});
for(let i=0;i<20;i++){now+=1000;timers.find(t=>t.ms===1000).f();}
timers.find(t=>t.ms===15000).f();
(async()=>{assert.equal(sent.length,1);let data=JSON.parse(await sent[0].body.text());assert.equal(data.hit,71);assert(data.ms>=15000);assert.equal(sent[0].url,'/api.php?route=stats/dwell');document.visibilityState='hidden';docEvents.visibilitychange();let before=JSON.parse(await sent.at(-1).body.text()).ms;now+=60000;timers[0].f();events.pagehide();assert.equal(JSON.parse(await sent.at(-1).body.text()).ms,before);console.log('PASS real engine emits dwell payload and excludes hidden time');})().catch(e=>{console.error(e);process.exitCode=1;});
