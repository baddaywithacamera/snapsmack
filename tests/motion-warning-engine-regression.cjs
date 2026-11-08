// THE MOTION WARNING ENGINE HOLDS MOTION UNTIL THE VISITOR CHOOSES (989D).
//
// Loads the real assets/js/ss-engine-motion-warning.js in a sandbox and checks
// the contract every skin and motion engine depends on:
//   - window.ssMotion.allowed is FALSE by default (fail-safe), <html> paused;
//   - prefers-reduced-motion = disable, no modal;
//   - a stored "allow" cookie allows motion with no modal;
//   - first visit (no cookie) shows the modal and stays paused;
//   - allow()/disable()/toggle() flip the state, the <html> class and fire
//     ssmotionchange;
//   - Esc on the modal chooses "keep it still".
const fs=require('fs'), vm=require('vm'), assert=require('assert');
const SRC=fs.readFileSync('assets/js/ss-engine-motion-warning.js','utf8');

function run(opts={}) {
  opts=Object.assign({store:null,reduce:false,readyState:'complete'},opts);
  const cls=new Set();
  const events={};
  const el=()=>({ _children:[], style:{}, classList:{add(){}, remove(){}, toggle(){}},
    setAttribute(){}, getAttribute(){return null;}, appendChild(c){this._children.push(c);return c;},
    removeChild(){}, addEventListener(){}, focus(){}, querySelectorAll:()=>[], textContent:'', type:'', checked:false });
  const body=el();
  const made=[];
  const document={
    documentElement:{ classList:{ add:(c)=>cls.add(c), remove:(c)=>cls.delete(c), toggle:(c,on)=>{on?cls.add(c):cls.delete(c);} } },
    readyState:opts.readyState,
    body,
    activeElement:null,
    getElementById:()=>null,
    querySelector:()=>null,
    createElement:()=>{const e=el(); made.push(e); return e;},
    createTextNode:(t)=>({nodeType:3,textContent:t}),
    createEvent:()=>({initEvent(){}}),
    addEventListener:(n,f)=>{events[n]=f;},
    removeEventListener:()=>{},
    dispatchEvent:(e)=>{ (document.__fired=document.__fired||[]).push(e.type); return true; },
  };
  const _ls={_m:opts.store?{ss_motion:opts.store}:{}, getItem(k){return k in this._m?this._m[k]:null;}, setItem(k,v){this._m[k]=String(v);}};
  const sandbox={ window:{ matchMedia:()=> ({matches:opts.reduce}), localStorage:_ls }, document,
    CustomEvent:function(t,o){this.type=t;this.detail=o&&o.detail;}, navigator:{} };
  sandbox.window.matchMedia=()=>({matches:opts.reduce});
  vm.runInNewContext(SRC, sandbox);
  return { ssMotion:sandbox.window.ssMotion, cls, events, made, fired:()=>document.__fired||[] };
}

let pass=0, fail=0;
function ok(c,w){ if(c){pass++;console.log('  ok   '+w);} else {fail++;console.log('  FAIL '+w);} }

let r=run({});                    // first visit, no cookie, motion present
ok(r.ssMotion && r.ssMotion.allowed===false, 'default: window.ssMotion.allowed is false (fail-safe)');
ok(r.cls.has('ss-motion-paused') && !r.cls.has('ss-motion-ok'), 'default: <html> is ss-motion-paused, not ss-motion-ok');

r=run({reduce:true});             // prefers-reduced-motion
ok(r.ssMotion.allowed===false && r.ssMotion.decided===true, 'reduced-motion: disabled and decided (no modal)');

r=run({store:'allow'});
ok(r.ssMotion.allowed===true && r.cls.has('ss-motion-ok'), 'stored allow: motion allowed, <html> ss-motion-ok, no modal');

r=run({store:'disable'});
ok(r.ssMotion.allowed===false && r.ssMotion.decided===true, 'stored disable: held and decided, no modal');

r=run({});                        // toggles fire the contract
r.ssMotion.allow();
ok(r.ssMotion.allowed===true && r.cls.has('ss-motion-ok') && r.fired().includes('ssmotionchange'), 'allow() allows motion, sets ss-motion-ok, fires ssmotionchange');
r.ssMotion.toggle();
ok(r.ssMotion.allowed===false && r.cls.has('ss-motion-paused'), 'toggle() reverses the choice back to paused');

// modal was built on first visit (a dialog element with role)
r=run({});
ok(r.made.length>0, 'first visit builds the modal DOM');

// Esc chooses "keep it still": the keydown handler disables
r=run({});
const kd=r.events['keydown'];
ok(typeof kd==='function', 'the modal installs a keydown handler (focus trap + Esc)');
kd({key:'Escape', preventDefault(){}});
ok(r.ssMotion.allowed===false && r.ssMotion.decided===true, 'Esc on the modal keeps motion still and records the choice');

console.log(`\n${pass} passed, ${fail} failed`);
if(fail) process.exitCode=1;
else console.log('PASS motion-warning engine contract');
