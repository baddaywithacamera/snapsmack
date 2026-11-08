'use strict';
/**
 * The infinite-scroll engine honours explicit data-grid-selector /
 * data-item-selector. (SS-986D, Codex-requested enabler.)
 *
 * The shared engine derives its grid/tile selectors from the sentinel id by
 * convention (.<P>-grid / .<P>-tile, plus the justified special case). The
 * SMACKTALK feed, GLIDE hashtag and TRUE-GRIT landing do not follow that class
 * convention, so their skin layout states the selectors outright on the sentinel:
 * data-grid-selector and data-item-selector. The engine must prefer those when
 * present and fall back to the convention (and the justified case) when absent,
 * so every existing sentinel pages exactly as before.
 *
 * Source-structure check (comments stripped -- a comment is not code). The real
 * >24-item rendered paging on the non-convention skins is measured on an install
 * by Codex (PM ruling); this locks the engine logic so it cannot silently
 * regress.
 */
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-tag-infinite.js'), 'utf8');
const code = src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\r\n]*/g, '$1 ');

let pass = 0, fail = 0;
function cx(ok, what) { ok ? (pass++, console.log('  ok    ' + what)) : (fail++, console.log('  FAIL  ' + what)); }

cx(/var\s+gridSel\s*=\s*sentinel\.dataset\.gridSelector/.test(code),
   'the engine reads the sentinel data-grid-selector');
cx(/var\s+itemSelAttr\s*=\s*sentinel\.dataset\.itemSelector/.test(code),
   'the engine reads the sentinel data-item-selector');
cx(/grid\s*=\s*gridSel\s*\?\s*document\.querySelector\(gridSel\)/.test(code),
   'it PREFERS data-grid-selector for the grid when present');
cx(/itemSel\s*=\s*itemSelAttr\s*\?\s*itemSelAttr/.test(code),
   'it PREFERS data-item-selector for the tiles when present');
cx(/document\.querySelector\('\.'\s*\+\s*P\s*\+\s*'-grid'\)/.test(code)
   && /'\.'\s*\+\s*P\s*\+\s*'-grid \.'\s*\+\s*P\s*\+\s*'-tile'/.test(code),
   'the .<P>-grid / .<P>-tile convention fallback is intact');
cx(/getElementById\('justified-grid'\)/.test(code)
   && /'#justified-grid > \.justified-row'/.test(code),
   'the justified special-case fallback is intact');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
