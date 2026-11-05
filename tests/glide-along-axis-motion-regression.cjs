'use strict';
/**
 * THE GLIDE WALL CAN MOVE ALONG ITS AXIS WITHOUT ROTATING THE PHOTOGRAPHS.
 *
 * Codex reported that a vertical axis turns the photographs sideways, and
 * asked for the smallest engine support for vertical-dominant movement. The
 * cause is not in this engine: the engine never rotated anything. axisX/axisY
 * only PROJECTED THE POINTER -- which drag direction drives the wall -- while
 * the wall itself always translated on X. The apparent direction came from the
 * SKIN rotating the whole field: skins/glide/style.css:29 rotates .glide-field
 * by --glide-axis-angle plus the -7deg lean, and skins/sliders/style.css:1373
 * does the same to .sl-glide-field with its axis angle defaulting to -45deg.
 *
 * So the motion can run along the axis instead, which lets a skin drop the
 * axis term from its rotate() and keep only the lean. It is OPT-IN, via
 * data-flow-motion="axis", and this file's main job is the safety claim:
 * WITHOUT THE FLAG, NOTHING CHANGES. Both skins expose the axis as an owner
 * setting, so sites with a non-horizontal axis exist; switching them all at
 * once would leave the field rotated AND the motion following the axis, which
 * is two transforms for one intention.
 *
 * WHAT THIS FILE DOES NOT PROVE, said plainly. It does not run the wall. The
 * transform is written inside a requestAnimationFrame callback, and rAF does
 * not fire in the browser pane available to me -- I measured that earlier and
 * will not pretend otherwise. So RENDERED VERTICAL MOTION IS NOT VERIFIED
 * HERE. What is verified is the arithmetic, taken from the engine's own angle
 * table rather than retyped, and the structural facts the advice rests on.
 * Codex owns tools/browser-tests/ and has a real GLIDE install; the moving
 * picture is his to confirm.
 */

const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', 'assets', 'js', 'ss-engine-glide.js');
const src = fs.readFileSync(file, 'utf8');

let pass = 0, fail = 0;
function cx(ok, what) {
    if (ok) { pass++; console.log('  ok    ' + what); }
    else { fail++; console.log('  FAIL  ' + what); }
}

// COMMENTS STRIPPED BEFORE ASKING ABOUT CODE. My first version of the check
// below searched the whole file for rotate( and failed -- on MY OWN COMMENT,
// which explains that a skin may drop the axis term from its rotate(). A
// comment is not code, and a check that cannot tell them apart measures the
// prose. The same mistake in the other direction once let a mutant survive
// because my comment satisfied a name-count check.
const code = src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\r\n]*/g, '$1 ');

console.log('THE ENGINE ITSELF NEVER ROTATES ANYTHING');
// The whole advice to Codex rests on this. If the engine ever starts rotating,
// the division of labour has moved and the advice is stale.
cx(code.includes('rotate') === false, 'no rotate anywhere in the engine CODE');
cx(/rotate\s*\(/.test(src), 'and the only mentions are in comments, which is why they are stripped first');
cx(!/\bstyle\.transform\s*=\s*[^;]*rotate/.test(src), 'and it never writes a rotation into a transform');

console.log('\nMOVING ALONG THE AXIS IS OFF UNLESS A SKIN ASKS');
cx(/dataset\.flowMotion\s*===\s*'axis'/.test(src),
   'the gate is an explicit data-flow-motion="axis", so absent means off');
cx(/var\s+alongAxis\s*=/.test(src) && /var\s+vertical\s*=\s*alongAxis\s*&&/.test(src),
   'and vertical is derived from it, so it cannot be true on its own');
cx(src.includes("'translate3d(' + x + 'px,0,0)'"),
   'the un-opted path still writes the ORIGINAL expression, not a computed one that agrees');

console.log('\nTHE ANGLE TABLE, READ FROM THE ENGINE RATHER THAN RETYPED');
// Retyping the angles would make this a test of my memory of the file.
const m = src.match(/var baseAngles = (\{[^}]*\})/);
cx(!!m, 'the baseAngles table was found in the source');
const baseAngles = m ? eval('(' + m[1] + ')') : {};
console.log('        -> ' + JSON.stringify(baseAngles));
cx(Object.keys(baseAngles).length === 4, 'it names four axes (' + Object.keys(baseAngles).join(', ') + ')');

function vec(axis, fine) {
    const rad = ((baseAngles[axis] || 0) + (fine || 0)) * Math.PI / 180;
    return { x: Math.cos(rad), y: Math.sin(rad) };
}
const EPS = 1e-9;

console.log('\nA HORIZONTAL WALL MOVES EXACTLY AS IT DID');
// This is the claim that makes the change safe to ship before any skin opts
// in: for axis 0 the opted-in arithmetic IS the original translation.
const h = vec('horizontal', 0);
cx(Math.abs(h.x - 1) < EPS, 'axisX is 1 for the horizontal axis');
cx(Math.abs(h.y) < EPS, 'axisY is 0, so x*axisY contributes no vertical movement');
const x = 123.456;
cx(Math.abs(x * h.x - x) < EPS && Math.abs(x * h.y) < EPS,
   'so translate3d(x*axisX, x*axisY) equals translate3d(x, 0) for every offset');

console.log('\nA VERTICAL WALL MOVES DOWN THE PAGE, NOT ACROSS IT');
const v = vec('vertical', 0);
cx(Math.abs(v.y - 1) < EPS, 'axisY is 1 for the vertical axis');
cx(Math.abs(v.x) < EPS, 'and axisX is 0, so no sideways movement remains');
cx(Math.abs(v.y) > Math.abs(v.x), 'the dominant axis is vertical, which is what selects height measurement');

console.log('\nTHE LEAN DOES NOT FLIP THE MEASURED EDGE');
// --glide-angle is the aesthetic tilt, -7deg by default. It is ADDED to the
// axis angle, so a careless implementation could let the lean decide which
// edge is measured. At -7 on a vertical wall the dominant axis must stay
// vertical, or the period would suddenly be measured across the tiles.
const leaned = vec('vertical', -7);
cx(Math.abs(leaned.y) > Math.abs(leaned.x),
   'a -7deg lean on a vertical wall is still vertical-dominant');
const leanedH = vec('horizontal', -7);
cx(Math.abs(leanedH.x) > Math.abs(leanedH.y),
   'and a -7deg lean on a horizontal wall is still horizontal-dominant');
// 45 degrees is the genuine tie. Stated rather than hidden: diagonal_down
// lands with |x| === |y| before any lean, so the lean breaks the tie. Which
// way it breaks is arbitrary but it must be DECIDED, not left to a stray
// floating-point bit.
const d = vec('diagonal_down', 0);
cx(Math.abs(Math.abs(d.x) - Math.abs(d.y)) < 1e-12,
   'diagonal_down is an exact tie at 45deg (|x| === |y|), so the > test resolves it to horizontal');
const dLean = vec('diagonal_down', -7);
cx(Math.abs(dLean.x) > Math.abs(dLean.y),
   'and with the -7deg lean it is horizontal-dominant, which is the shallower direction');

console.log('\nEVERY MEASUREMENT FOLLOWS THE SAME DIRECTION');
// A period measured on one edge and a fill measured on the other is a wall
// that wraps in the wrong place. These four must agree, and they are the
// pieces Codex would otherwise have had to find himself.
cx(/vertical \? kids\[i\]\.offsetHeight : kids\[i\]\.offsetWidth/.test(src),
   'tile extent: height when vertical, width otherwise');
cx(/vertical \? cs\.rowGap : cs\.columnGap/.test(src),
   'gap: row-gap when vertical, column-gap otherwise');
cx(/vertical \? window\.innerHeight : window\.innerWidth/.test(src),
   'viewport: innerHeight when vertical, innerWidth otherwise');
cx(/downPage \? track\.scrollHeight : track\.scrollWidth/.test(src),
   'track length: scrollHeight when vertical, scrollWidth otherwise');

console.log('\nTHE DERIVED FLAG IS REFRESHED WHEN THE VECTOR IS');
// The resize handler recomputes the vector. A stale `vertical` would measure
// the wrong edge after a breakpoint changed the lean.
const resize = src.slice(src.indexOf("addEventListener('resize'"));
cx(/vertical = alongAxis && Math\.abs\(axisY\) > Math\.abs\(axisX\)/.test(resize),
   'resize recomputes vertical alongside axisX and axisY');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
console.log('NOT CHECKED HERE: the wall actually moving. rAF does not fire in my');
console.log('browser pane, so rendered vertical motion needs Codex\'s runner and a');
console.log('real install. Stated, not skipped quietly.');
process.exit(fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
