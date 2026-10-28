"""SS-1007: render the real engine in isolated Chrome, including its historical reference."""
import importlib.util,json,subprocess,time
from pathlib import Path
root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('render_compare',root/'tools/_build/skin-render-compare.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
current=(root/'assets/js/ss-engine-organized-mayhem.js').read_text(encoding='utf-8')
before=subprocess.check_output(['git','show','58302957:assets/js/ss-engine-organized-mayhem.js'],cwd=root).decode()
historical=subprocess.check_output(['git','show','35f06bee:assets/js/ss-engine-organized-mayhem.js'],cwd=root).decode()
c=m.Chrome()
def run(source,w,h,ambient=True,count=120,seed=1,aspects=[1],drift=True,reduced=False):
 c.send('Emulation.setDeviceMetricsOverride',width=w,height=h,deviceScaleFactor=1,mobile=False)
 c.send('Page.navigate',url='about:blank');time.sleep(.05)
 setup='''document.body.innerHTML='<div data-mayhem></div>';document.body.style.margin='0';
 const host=document.querySelector('[data-mayhem]');host.style.cssText='position:fixed;inset:0;overflow:hidden';
 Object.assign(host.dataset,{apiUrl:'/pool',ambient:AMBIENT?'1':'0',pan:AMBIENT?'0':'1',initialCount:String(COUNT),drift:DRIFT?'1':'0',warp:'1'});
 window.matchMedia=()=>({matches:REDUCED});let seed=SEED;Math.random=()=>{seed=(seed*1664525+1013904223)>>>0;return seed/4294967296};
 window.framesToRun=[];window.requestAnimationFrame=f=>{framesToRun.push(f);return framesToRun.length};
 window.fetch=()=>Promise.resolve({json:()=>Promise.resolve({images:Array.from({length:COUNT},(_,i)=>{
 const aspect=ASPECTS[i%ASPECTS.length];return {id:i,title:'Print '+i,url:'#'+i,aspect,src:'data:image/svg+xml,'+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="400" height="'+(400/aspect)+'"><rect width="100%" height="100%" fill="teal"/></svg>')};})})});
 window.errors=[];console.error=(...x)=>errors.push(x.join(' '));
 '''.replace('AMBIENT',json.dumps(ambient)).replace('COUNT',str(count)).replace('SEED',str(seed)).replace('ASPECTS',json.dumps(aspects)).replace('DRIFT',json.dumps(drift)).replace('REDUCED',json.dumps(reduced))
 c.evaluate(setup);c.evaluate(source)
 time.sleep(.1)
 c.evaluate('for(let i=0;i<5;i++){let f=framesToRun.shift();if(f)f(1000+i*16)}')
 c.evaluate('Promise.all([...document.images].map(i=>i.decode().catch(()=>{})))',True)
 result=c.evaluate('''(()=>{let holes=0,total=0;for(let y=4;y<innerHeight;y+=16)for(let x=4;x<innerWidth;x+=16){total++;if(!document.elementsFromPoint(x,y).some(e=>e.tagName==='IMG'))holes++}return {holes,total,images:document.images.length,errors,geometry:[...document.querySelectorAll('.om-card')].map(e=>[e.style.cssText,e.firstChild.src])}})()''')
 return result
try:
 for dims in [(1920,1080),(1280,720),(390,844),(844,390)]:
  a=run(before,*dims);b=run(current,*dims);h=run(historical,*dims)
  print(json.dumps({'viewport':dims,'before':[a['holes'],a['total'],a['images']],'restored':[b['holes'],b['total'],b['images']],'september':[h['holes'],h['total'],h['images']]}),flush=True)
  assert b['holes']==0,(dims,b['holes'])
  assert b['geometry']==h['geometry'],'Ambient geometry differs from September reference'
 for dims in [(1920,1080),(390,844)]:
  for seed in [1,7,41]:
   b=run(current,*dims,seed=seed,aspects=[.823]);assert b['holes']==0,(dims,seed,b['holes'])
 for reduced,drift in [(False,False),(True,True)]:
  run(current,390,844,drift=drift,reduced=reduced)
  first=c.evaluate("document.querySelector('.om-world').style.transform")
  c.evaluate('for(let i=0;i<5;i++){let f=framesToRun.shift();if(f)f(20000+i*16)}')
  assert c.evaluate("document.querySelector('.om-world').style.transform")==first,'Disabled/reduced motion drifted'
 a=run(before,1280,720,False);b=run(current,1280,720,False)
 assert a==b,'Interactive table changed'
 print('PASS: ambient full coverage and September geometry; interactive table unchanged')
finally:c.close()
