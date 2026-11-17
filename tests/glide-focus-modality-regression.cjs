'use strict';
/**
 * SS-1217: A POINTER-ORIGIN FOCUS MUST NOT FREEZE A GLIDE ROW.
 *
 * The resting gate in paint() deliberately refuses to idle-drift a row that
 * holds a :focus-visible tile, so a KEYBOARD user tabbing through a row is never
 * scrolled out from under -- that stays. The bug (live on portalproject, Codex's
 * repro): a focus LEFT OVER from a pointer or overlay interaction matched
 * :focus-visible:not([data-glide-restored-focus]) and froze the whole row for
 * good -- one clicked tile in row 4 stopped while the other eight drifted.
 *
 * The fix marks pointer-origin focus with data-glide-restored-focus (the same
 * flag the viewer already uses on close), so the resting gate ignores it;
 * genuine keyboard focus is left unmarked, so it still pauses the row and stays
 * reachable. This file locks that logic in the ENGINE SOURCE (comments stripped
 * first -- a comment is not code). It does NOT run the wall: rAF does not fire
 * in the pane here, so the rendered resume is Codex's to confirm on the real
 * install (SS-1217 ruling 4). Structure proven here; moving picture there.
 */
const fs = require('fs');
const path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'ss-engine-glide.js'), 'utf8');
const code = src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\r\n]*/g, '$1 ');

let pass = 0, fail = 0;
function cx(ok, what) { ok ? (pass++, console.log('  ok    ' + what)) : (fail++, console.log('  FAIL  ' + what)); }

console.log('THE ACCESSIBILITY PAUSE IS INTACT');
cx(/querySelector\(\s*['"]:focus-visible:not\(\[data-glide-restored-focus\]\)['"]\s*\)/.test(code),
   'the resting gate still refuses a row holding a :focus-visible tile that is not restored-marked');

console.log('\nPOINTER-ORIGIN FOCUS IS MARKED, KEYBOARD FOCUS IS NOT');
cx(/glideLastInputPointer\s*=\s*false/.test(code),
   'input modality starts as not-pointer (keyboard pauses by default)');
cx(/addEventListener\(\s*['"]pointerdown['"][\s\S]{0,80}glideLastInputPointer\s*=\s*true/.test(code),
   'a pointerdown sets the modality to pointer');
cx(/addEventListener\(\s*['"]keydown['"][\s\S]{0,80}glideLastInputPointer\s*=\s*false/.test(code),
   'a keydown resets the modality to keyboard');
cx(/addEventListener\(\s*['"]focusin['"]/.test(code),
   'the wall listens for focusin to decide the modality of each new focus');
cx(/if\s*\(\s*!glideLastInputPointer\s*\)\s*return/.test(code),
   'keyboard focus (no pointer immediately before it) is left UNMARKED -- it still pauses the row');
cx(/setAttribute\(\s*['"]data-glide-restored-focus['"]/.test(code)
   && /removeAttribute\(\s*['"]data-glide-restored-focus['"]/.test(code),
   'pointer-origin focus is marked data-glide-restored-focus and the mark is cleared on focusout');

console.log('\nTHE VIEWER-CLOSE RESTORE PATH IS UNCHANGED');
cx(/viewer\.addEventListener\(\s*['"]close['"]/.test(src)
   && /data-glide-restored-focus/.test(src.slice(src.indexOf("viewer.addEventListener('close'"))),
   'closing the viewer still marks the tile it restores focus to');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
