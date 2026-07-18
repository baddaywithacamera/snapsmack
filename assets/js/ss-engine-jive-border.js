/**
 * SNAPSMACK — JIVE TURKEY tile-border engine (Layer 2): INSIDE RESERVED COLOUR BAND
 *
 * SNAPSMACK_EOF_HEADER: last non-empty line of this file must be the SNAPSMACK EOF comment.
 *
 * The 70s companion to the JIVE TURKEY background. Each tile carries a colour band
 * drawn INSIDE the tile edge, around the photo. The band HOLDS at full width,
 * SHRINKS to nothing, flips to the next colourway colour, then GROWS back — a wave
 * that walks across the grid in the chosen direction.
 *
 * GEOMETRY (this is the whole point, and where earlier versions went wrong):
 *   - The band lives INSIDE the tile. The skin reserves its width by insetting the
 *     photo (.jt-tile > a is inset by --jt-band-reserve, = the border width), so the
 *     image is statically smaller and NEVER reflows as the band pulses.
 *   - The band is painted on the tile's existing .jt-ring overlay — an absolutely
 *     positioned hollow frame (padding + mask) sitting in that reserved inset. We set
 *     only the ring's colour (background) and thickness (padding). The photo is never
 *     covered and never resizes.
 *   - The band NEVER grows outward into the gutter. TILE SPACING (--grid-gap, the CSS
 *     grid gap) stays pure background: border-edge to border-edge = the number you set.
 *   - This engine writes NO layout property (no grid.gap, no box-shadow). Spacing and
 *     the reserved band are entirely CSS, correct on the first paint.
 *
 * Colour-agnostic: reads the active colourway and re-tints on jt:colourway /
 * window.__JT_COLOURWAY so SURPRISE / CYCLE keep the border matched. AURORA/PARADE
 * use a DIFFERENT border engine; this one is JIVE TURKEY only.
 */
(function(){
  if(window.__jtBorderEngine) return; window.__jtBorderEngine=true;
  var S=null, lastCount=-1, lastScan=0;
  function host(){ return document.querySelector('.jt-jive-turkey-bg')||document.documentElement; }
  function A(h,n,d){ var v=h&&h.getAttribute?h.getAttribute(n):null; return v==null?d:v; }
  function liveCols(fallback){
    if(window.__JT_COLOURWAY&&window.__JT_COLOURWAY.colors&&window.__JT_COLOURWAY.colors.length)
      return window.__JT_COLOURWAY.colors.slice();
    return fallback;
  }
  // Resolve (or self-sufficiently create) each tile's .jt-ring hollow frame.
  function ensureRing(tile){
    var r=tile.querySelector('.jt-ring');
    if(!r){
      r=document.createElement('div'); r.className='jt-ring';
      r.style.position='absolute'; r.style.inset='0'; r.style.pointerEvents='none';
      r.style.borderRadius='inherit';
      r.style.webkitMask='linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)';
      r.style.webkitMaskComposite='xor';
      r.style.mask='linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)';
      r.style.maskComposite='exclude';
      tile.appendChild(r);
    }
    if(getComputedStyle(tile).position==='static') tile.style.position='relative';
    return r;
  }
  function scan(){
    var tiles=[].slice.call(document.querySelectorAll('.jt-tile'));
    if(!tiles.length){ S=null; lastCount=0; return; }
    var h=host();
    var enabled=A(h,'data-jt-border-enabled','1')!=='0';
    var W=Math.max(2,Math.min(30,parseFloat(A(h,'data-jt-border-width',8))||8));
    var SPD=Math.max(0,Math.min(100,parseFloat(A(h,'data-jt-border-speed',60))));
    var WAVE=Math.max(0,Math.min(100,parseFloat(A(h,'data-jt-border-wave',45))));
    var DIR=A(h,'data-jt-border-dir','dtlbr');
    var curName=(A(h,'data-jt-colourway','')||'').toUpperCase();
    var CW={}; try{CW=JSON.parse(A(h,'data-jt-colourways','{}'))||{};}catch(e){}
    var COLS=(CW[curName]&&CW[curName].colors)?CW[curName].colors.slice():['#d99a2b','#bd4e1f','#6b3f24'];
    COLS=liveCols(COLS);
    // NB: no grid.gap, no box-shadow. Spacing + reserved band are CSS. We only paint rings.
    var rings=tiles.map(ensureRing);
    var geo=[],rows=1,cols=1;
    (function(){var rm=[],E=6;for(var i=0;i<tiles.length;i++){var t=tiles[i],tp=t.offsetTop,lf=t.offsetLeft,ro=-1;for(var r=0;r<rm.length;r++){if(Math.abs(rm[r].top-tp)<=E){ro=r;break;}}if(ro<0){ro=rm.length;rm.push({top:tp,cells:[]});}rm[ro].cells.push({i:i,left:lf});}rm.sort(function(a,b){return a.top-b.top;});for(var rr=0;rr<rm.length;rr++){rm[rr].cells.sort(function(a,b){return a.left-b.left;});cols=Math.max(cols,rm[rr].cells.length);for(var c=0;c<rm[rr].cells.length;c++)geo[rm[rr].cells[c].i]={row:rr,col:c};}rows=rm.length;})();
    S={enabled:enabled,rings:rings,geo:geo,rows:rows,cols:cols,W:W,SPD:SPD,WAVE:WAVE,DIR:DIR,COLS:COLS};
    lastCount=tiles.length;
  }
  function ord(d,r,c,rows,cols){switch(d){case 'ltr':return c;case 'rtl':return cols-1-c;case 'ttb':return r;case 'btt':return rows-1-r;case 'dbrtl':return (rows-1-r)+(cols-1-c);default:return r+c;}}
  // Paint the band as a hollow frame INSIDE the tile: padding = current width, mask leaves
  // only that outer frame. Never over the photo (photo is inset by --jt-band-reserve).
  function paint(ring,w,col){
    if(w>0.05){ ring.style.padding=w.toFixed(2)+'px'; ring.style.background=col; ring.style.display='block'; }
    else { ring.style.display='none'; }
  }
  var TRANS=0.45;
  var reduced=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
  function paintAll(now){
    var n=document.querySelectorAll('.jt-tile').length;
    if(n!==lastCount && now-lastScan>300){ lastScan=now; scan(); }
    if(!S) return;
    S.COLS=liveCols(S.COLS);
    if(!S.enabled){ for(var j=0;j<S.rings.length;j++) S.rings[j].style.display='none'; return; }
    if(reduced){ for(var k=0;k<S.rings.length;k++) paint(S.rings[k],S.W,S.COLS[0]); return; }
    var t=now/1000, D=0.8+Math.pow((100-S.SPD)/100,2)*18, half=Math.min(TRANS/2,D*0.45), waveAmt=(S.WAVE/100)*0.9;
    for(var i=0;i<S.rings.length;i++){
      var g=S.geo[i]||{row:0,col:0}, off=ord(S.DIR,g.row,g.col,S.rows,S.cols)*waveAmt, local=t/D+off, step=Math.floor(local), secIn=(local-step)*D, w;
      if(secIn<half){var pe=secIn/half;w=S.W*(2*pe-pe*pe);}
      else if(secIn>D-half){var ps=(secIn-(D-half))/half;w=S.W*(1-ps)*(1-ps);}
      else w=S.W;
      paint(S.rings[i],w,S.COLS[((step%S.COLS.length)+S.COLS.length)%S.COLS.length]);
    }
  }
  window.addEventListener('jt:colourway',function(ev){if(S&&ev&&ev.detail&&ev.detail.colors)S.COLS=ev.detail.colors.slice();});
  function start(){
    scan();
    (function raf(){ paintAll(performance.now()); requestAnimationFrame(raf); })();
    setInterval(function(){ if(document.hidden) paintAll(performance.now()); }, 250);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start);
  else start();
})();
// ===== SNAPSMACK EOF =====
