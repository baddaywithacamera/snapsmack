/**
 * SNAPSMACK — JIVE TURKEY tile-border engine (Layer 2): INSIDE COLOUR RING, WIDTH-PULSE
 *
 * SNAPSMACK_EOF_HEADER: last non-empty line of this file must be the SNAPSMACK EOF comment.
 *
 * The 70s companion to the JIVE TURKEY background. Each tile carries a colour
 * ring drawn INSIDE the tile on its edge — the .jt-ring overlay, masked to the
 * band of its own padding. This engine animates that band WIDTH: it HOLDS at
 * full (--tile-bw), SHRINKS IN to nothing, flips to the next colourway colour,
 * then POPS BACK OUT — a wave that walks across the grid in the chosen
 * direction. Because the band lives INSIDE the tile (the ring's padding is the
 * visible width, the photo is inset by --tile-bw underneath), the pulse never
 * grows into the gutter: the full Tile Spacing (--grid-gap) stays pure
 * background between tiles, and the image is never covered or resized.
 *
 * This restores the width-pulse of 0.7.425 (removed in 0.7.428, which left a
 * constant-width band that only recoloured) WITHOUT the outward box-shadow that
 * ate the tile spacing: the pulse is now the ring's padding, not an outward
 * shadow. Keeps the reliable-paint + hidden-tab fallback + tile-count re-scan so
 * lazy-loaded / infinite-scroll tiles still get their border.
 *
 * TRANSITION: how long the shrink-in / pop-out takes is controllable via
 * data-jt-border-trans (0-100). Higher = slower, more visible shrink/grow. It is
 * CLAMPED so the transition never exceeds the hold — the band always rests at
 * full width between colour changes.
 *
 * The band WIDTH pulses; the COLOUR flips at the zero point. AURORA/PARADE use a
 * DIFFERENT border engine; this one is JIVE TURKEY only.
 *
 * All inputs come off the .jt-jive-turkey-bg carrier as data-jt-border-* plus
 * the active colourway (data-jt-colourway / data-jt-colourways, and the live
 * window.__JT_COLOURWAY the admin preview sets).
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
  function ringOf(tile){ return tile.querySelector('.jt-ring'); }
  function scan(){
    var tiles=[].slice.call(document.querySelectorAll('.jt-tile'));
    if(!tiles.length){ S=null; lastCount=0; return; }
    var h=host();
    var enabled=A(h,'data-jt-border-enabled','1')!=='0';
    var W=Math.max(2,Math.min(30,parseFloat(A(h,'data-jt-border-width',8))||8));
    var SPD=Math.max(0,Math.min(100,parseFloat(A(h,'data-jt-border-speed',60))));
    var WAVE=Math.max(0,Math.min(100,parseFloat(A(h,'data-jt-border-wave',45))));
    var TRN=Math.max(0,Math.min(100,parseFloat(A(h,'data-jt-border-trans',35))));
    if(isNaN(TRN)) TRN=35;
    var DIR=A(h,'data-jt-border-dir','dtlbr');
    var curName=(A(h,'data-jt-colourway','')||'').toUpperCase();
    var CW={}; try{CW=JSON.parse(A(h,'data-jt-colourways','{}'))||{};}catch(e){}
    var COLS=(CW[curName]&&CW[curName].colors)?CW[curName].colors.slice():['#d99a2b','#bd4e1f','#6b3f24'];
    COLS=liveCols(COLS);
    // INSIDE ring, width-pulse: the .jt-ring's own padding is the visible band
    // (the mask shows only that padding). This engine writes ring padding+colour,
    // NEVER any grid layout property (gap/padding), so the 935px column and the
    // Tile Spacing are owned entirely by CSS. Clear any stale outward box-shadow.
    for(var ti=0; ti<tiles.length; ti++){
      tiles[ti].style.boxShadow='none';
      if(getComputedStyle(tiles[ti]).position==='static') tiles[ti].style.position='relative';
    }
    var geo=[],rows=1,cols=1;
    (function(){var rm=[],E=6;for(var i=0;i<tiles.length;i++){var t=tiles[i],tp=t.offsetTop,lf=t.offsetLeft,ro=-1;for(var r=0;r<rm.length;r++){if(Math.abs(rm[r].top-tp)<=E){ro=r;break;}}if(ro<0){ro=rm.length;rm.push({top:tp,cells:[]});}rm[ro].cells.push({i:i,left:lf});}rm.sort(function(a,b){return a.top-b.top;});for(var rr=0;rr<rm.length;rr++){rm[rr].cells.sort(function(a,b){return a.left-b.left;});cols=Math.max(cols,rm[rr].cells.length);for(var c=0;c<rm[rr].cells.length;c++)geo[rm[rr].cells[c].i]={row:rr,col:c};}rows=rm.length;})();
    S={enabled:enabled,tiles:tiles,geo:geo,rows:rows,cols:cols,W:W,SPD:SPD,WAVE:WAVE,TRN:TRN,DIR:DIR,COLS:COLS};
    lastCount=tiles.length;
  }
  function ord(d,r,c,rows,cols){switch(d){case 'ltr':return c;case 'rtl':return cols-1-c;case 'ttb':return r;case 'btt':return rows-1-r;case 'dbrtl':return (rows-1-r)+(cols-1-c);default:return r+c;}}
  // INSIDE ring width-pulse: set the ring's PADDING (visible band width) and its
  // colour. Padding 0 => band invisible (photo's flower bg shows), W => full band.
  // Never an outward shadow, so the gutter/tile-spacing is never touched.
  function paint(tile,w,col){
    var r=ringOf(tile); if(!r) return;
    var bw=(S&&S.W)?S.W:8;
    r.style.padding = (w>0.05) ? (w.toFixed(2)+'px') : '0px';
    r.style.inset   = (Math.max(0,bw-w)).toFixed(2)+'px'; // anchor band at the PHOTO edge: it collapses toward the photo, not the tile edge
    r.style.background = col||'transparent';
  }
  var reduced=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
  function paintAll(now){
    var n=document.querySelectorAll('.jt-tile').length;
    if(n!==lastCount && now-lastScan>300){ lastScan=now; scan(); }
    if(!S) return;
    S.COLS=liveCols(S.COLS);
    if(!S.enabled){ for(var j=0;j<S.tiles.length;j++){ var rj=ringOf(S.tiles[j]); if(rj){ rj.style.padding='0px'; rj.style.inset='0px'; rj.style.background='transparent'; } } return; }
    if(reduced){ for(var k=0;k<S.tiles.length;k++) paint(S.tiles[k],S.W,S.COLS[0]); return; }
    // Transition duration (seconds, one side): 0-100 slider -> 0.15s .. 2.0s.
    // Clamped to 45% of the cycle so a hold ALWAYS remains between colour changes.
    var t=now/1000, D=0.8+Math.pow((100-S.SPD)/100,2)*18;
    var transSec=0.15+(S.TRN/100)*1.85, half=Math.min(transSec, D*0.45), waveAmt=(S.WAVE/100)*0.9;
    for(var i=0;i<S.tiles.length;i++){
      var g=S.geo[i]||{row:0,col:0}, off=ord(S.DIR,g.row,g.col,S.rows,S.cols)*waveAmt, local=t/D+off, step=Math.floor(local), secIn=(local-step)*D, w;
      if(secIn<half){var pe=secIn/half;w=S.W*(2*pe-pe*pe);}
      else if(secIn>D-half){var ps=(secIn-(D-half))/half;w=S.W*(1-ps)*(1-ps);}
      else w=S.W;
      paint(S.tiles[i],w,S.COLS[((step%S.COLS.length)+S.COLS.length)%S.COLS.length]);
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
