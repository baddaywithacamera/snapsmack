/**
 * SNAPSMACK — JIVE TURKEY tile-border engine (Layer 2): OUTWARD COLOUR BORDER
 *
 * SNAPSMACK_EOF_HEADER: last non-empty line of this file must be the SNAPSMACK EOF comment.
 *
 * The 70s companion to the JIVE TURKEY background. Each tile carries a colour
 * band drawn as an OUTWARD box-shadow that grows into the gutter, never over the
 * photo — so the image never resizes and no image area is lost. The band width
 * HOLDS at full, SHRINKS IN to nothing, flips to the next colourway colour, then
 * POPS BACK OUT — a wave that walks across the grid in the chosen direction.
 * (Restored from 8493148f "width-pulse OUTSIDE the image"; keeps the reliable-
 * paint + hidden-tab fallback + tile-count re-scan from the later rewrite so
 * lazy-loaded / infinite-scroll tiles still get their border.)
 *
 * TRANSITION: how long the shrink-in / pop-out takes is controllable via
 * data-jt-border-trans (0-100). Higher = slower, more visible shrink/grow.
 * It is CLAMPED so the transition never exceeds the hold — the band always
 * rests at full width between colour changes.
 *
 * The inside .jt-ring (if the skin renders one) is hidden — this engine paints
 * the tile's own box-shadow, not the ring. AURORA/PARADE use a DIFFERENT border
 * engine; this one is JIVE TURKEY only.
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
    // GUTTER = TILE SPACING (--grid-gap) in CSS; the band width is reserved as grid
    // padding (--jt-band-reserve), so the grid never exceeds the 935px Instagram column.
    // This engine NEVER writes grid.style.gap or any layout property.
    // OUTWARD border: hide any inside ring; the band is the tile's own box-shadow.
    for(var ti=0; ti<tiles.length; ti++){
      var r=tiles[ti].querySelector('.jt-ring'); if(r) r.style.display='none';
      if(getComputedStyle(tiles[ti]).position==='static') tiles[ti].style.position='relative';
    }
    var geo=[],rows=1,cols=1;
    (function(){var rm=[],E=6;for(var i=0;i<tiles.length;i++){var t=tiles[i],tp=t.offsetTop,lf=t.offsetLeft,ro=-1;for(var r=0;r<rm.length;r++){if(Math.abs(rm[r].top-tp)<=E){ro=r;break;}}if(ro<0){ro=rm.length;rm.push({top:tp,cells:[]});}rm[ro].cells.push({i:i,left:lf});}rm.sort(function(a,b){return a.top-b.top;});for(var rr=0;rr<rm.length;rr++){rm[rr].cells.sort(function(a,b){return a.left-b.left;});cols=Math.max(cols,rm[rr].cells.length);for(var c=0;c<rm[rr].cells.length;c++)geo[rm[rr].cells[c].i]={row:rr,col:c};}rows=rm.length;})();
    S={enabled:enabled,tiles:tiles,geo:geo,rows:rows,cols:cols,W:W,SPD:SPD,WAVE:WAVE,TRN:TRN,DIR:DIR,COLS:COLS};
    lastCount=tiles.length;
  }
  function ord(d,r,c,rows,cols){switch(d){case 'ltr':return c;case 'rtl':return cols-1-c;case 'ttb':return r;case 'btt':return rows-1-r;case 'dbrtl':return (rows-1-r)+(cols-1-c);default:return r+c;}}
  // OUTWARD band via box-shadow: grows into the gutter, never over the photo.
  function paint(tile,w,col){ tile.style.boxShadow = (w>0.05) ? ('0 0 0 '+w.toFixed(2)+'px '+col) : 'none'; }
  var reduced=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
  function paintAll(now){
    var n=document.querySelectorAll('.jt-tile').length;
    if(n!==lastCount && now-lastScan>300){ lastScan=now; scan(); }
    if(!S) return;
    S.COLS=liveCols(S.COLS);
    if(!S.enabled){ for(var j=0;j<S.tiles.length;j++) S.tiles[j].style.boxShadow='none'; return; }
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
