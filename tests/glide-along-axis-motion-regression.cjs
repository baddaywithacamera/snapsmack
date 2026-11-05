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
const LEAN = -7;   // --glide-angle's default, the aesthetic tilt

console.log('\nTWO FRAMES, TWO VECTORS, AND THEY ARE NOT INTERCHANGEABLE');
// The whole fix. The pointer arrives in SCREEN pixels; the track translates in
// its OWN frame, inside a field the skin has already rotated by the lean. Put
// the lean in both and it is applied twice, and the track creeps sideways as it
// travels -- the drift Codex measured in a real browser.
cx(/var worldX = Math\.cos\(worldRadians\)/.test(src) && /var localX = Math\.cos\(localRadians\)/.test(src),
   'the engine derives a world vector AND a local vector');
// EVERY ASSIGNMENT, NOT JUST ONE OF THEM. The vectors are set twice -- once at
// start-up and once on resize -- and my first version of these two checks
// searched the whole file. A mutant that put the lean into the start-up local
// vector SURVIVED, because the regex was satisfied by the resize line, which
// was still correct. A source check that can pass on a different line from the
// one it is testing is not a check. So: collect every assignment and require
// all of them to agree.
const worldLines = src.split(/\r?\n/).filter(function (l) { return /worldRadians\s*=/.test(l); });
const localLines = src.split(/\r?\n/).filter(function (l) { return /localRadians\s*=/.test(l); });
cx(worldLines.length >= 2 && localLines.length >= 2,
   'both vectors are assigned at start-up and on resize (' + worldLines.length
   + ' world, ' + localLines.length + ' local)');
cx(worldLines.every(function (l) { return /\+\s*fine/.test(l); }),
   'EVERY world assignment adds the lean, because on screen the wall travels leaned');
cx(localLines.every(function (l) { return !/fine/.test(l); }),
   'and NO local assignment mentions the lean at all, because the field rotate() applies it');
cx(/\(dx \* worldX\) \+ \(dy \* worldY\)/.test(src),
   'the POINTER is projected with the world vector');
cx(/\(x \* localX\) \+ 'px,' \+ \(x \* localY\)/.test(src),
   'and the TRANSLATE is written with the local vector');
cx(!/\(dx \* localX\)/.test(src) && !/\(x \* worldX\)/.test(src),
   'and they are not swapped anywhere');

console.log('\nA LEANED VERTICAL WALL PAINTS PURE Y IN ITS OWN FRAME');
// THE ASSERTION I SHOULD HAVE MADE THE FIRST TIME. When Codex reported the
// translation was "mostly Y" I read the small X term as the lean composing
// correctly. It was the defect: a vertical wall must paint PURE Y locally and
// let the field's rotate() tilt the result. Had this check existed, the bug
// would not have reached his browser.
const vLocal = vec('vertical', 0);
cx(Math.abs(vLocal.x) < EPS,
   'local X is 0 for a vertical wall, so no sideways component is painted');
cx(Math.abs(vLocal.y - 1) < EPS, 'and local Y is 1, so the whole offset goes down the track');
const vWorld = vec('vertical', LEAN);
cx(Math.abs(vWorld.x) > 0.1,
   'while the WORLD vector keeps a real X term (' + vWorld.x.toFixed(4)
   + '), which is how a leaned drag reads correctly');
cx(Math.abs(vWorld.x - 0.1219) < 1e-3 && Math.abs(vWorld.y - 0.9925) < 1e-3,
   'and that world term is the 0.1219/0.9925 Codex measured -- the same number, now in the frame it belongs to');

console.log('\nA HORIZONTAL WALL MOVES EXACTLY AS IT DID');
// The claim that makes this safe to ship before any skin opts in: for axis 0
// the opted-in arithmetic IS the original translation.
const h = vec('horizontal', 0);
cx(Math.abs(h.x - 1) < EPS, 'local X is 1 for the horizontal axis');
cx(Math.abs(h.y) < EPS, 'local Y is 0, so x*localY contributes no vertical movement');
const x = 123.456;
cx(Math.abs(x * h.x - x) < EPS && Math.abs(x * h.y) < EPS,
   'so translate3d(x*localX, x*localY) equals translate3d(x, 0) for every offset');

console.log('\nTHE MEASURED EDGE IS A FACT ABOUT THE TRACK, NOT ABOUT THE LEAN');
// vertical now derives from the LOCAL axis. Which edge of a tile is the
// direction of travel is a property of the track's own box, and the lean is
// not in that frame. It also removes a wart: the lean used to break the 45deg
// tie, so the measured edge depended on an aesthetic setting.
// Same discipline as the vectors: `vertical` is assigned twice, so check every
// assignment rather than asking whether the file contains one good one.
const vertLines = src.split(/\r?\n/).filter(function (l) { return /vertical\s*=\s*alongAxis/.test(l); });
cx(vertLines.length >= 2,
   'vertical is assigned at start-up and on resize (' + vertLines.length + ' places)');
cx(vertLines.every(function (l) { return /Math\.abs\(localY\) > Math\.abs\(localX\)/.test(l); }),
   'EVERY assignment decides it from the local vector');
cx(vertLines.every(function (l) { return !/world/.test(l); }),
   'and none of them consults the leaned vector, so --glide-angle cannot change which edge is measured');
const d = vec('diagonal_down', 0);
cx(Math.abs(Math.abs(d.x) - Math.abs(d.y)) < 1e-12,
   'diagonal_down is an exact tie at 45deg (|x| === |y|)');
cx(!(Math.abs(d.y) > Math.abs(d.x)),
   'and the tie resolves to horizontal -- arbitrary, but FIXED, which makes it a decision rather than a stray bit');

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
cx(/vertical = alongAxis && Math\.abs\(localY\) > Math\.abs\(localX\)/.test(resize),
   'resize recomputes vertical from the local vector');
cx(/worldX = Math\.cos\(worldRadians\)/.test(resize) && /localX = Math\.cos\(localRadians\)/.test(resize),
   'and recomputes BOTH vectors, so they cannot drift apart');

console.log('\nWHAT A REAL BROWSER FOUND, INCLUDING MY MISREADING OF IT');
// I READ A DEFECT AS A CONFIRMATION, IN PUBLIC, AND THIS IS THE CORRECTION.
//
// Codex measured a real Chrome fixture at 1920x945 and reported the track
// translation as "mostly Y". I told him that was positive evidence the lean
// was composing into the motion, on the reasoning that a dropped lean would
// have read as pure Y. The reasoning was backwards. A vertical wall must paint
// PURE Y in its own frame; the field's rotate() is what tilts it on screen.
// The small X term I welcomed WAS the double-counted lean, and it is the
// sideways drift he then isolated in his fixture.
//
// He diagnosed it, asked for an authoritative core fix with separate local and
// world vectors rather than his fixture's ad hoc trigonometry, and that is
// what the sections above now assert. The lesson for me is narrower than "be
// careful": I had no assertion that said what the local vector MUST be, so
// there was nothing to contradict me when I reasoned my way to the wrong
// answer. There is now.
console.log('  --    Codex, real Chrome at 1920x945: tile 500x750, field matrix -7deg,');
console.log('        track translation mostly Y -- which I wrongly called confirmation.');
console.log('        The X term was the lean counted twice: the sideways drift.');
console.log('  --    He isolated it in a fixture: excluding the lean from the paint');
console.log('        vector left local X at ~1e-12 and only Y moving. Wheel over');
console.log('        900,450 moved only track 2; edge tracks unchanged.');
console.log('  --    HIS WORDS on his own fixture: "not a completed visual proof".');
console.log('        Open on his side: other widths, full-period continuous reversal,');
console.log('        Allow Preferences.');
console.log('  --    STILL NOT CHECKED BY ME AT ALL: any of it. rAF does not fire in my');
console.log('        pane, so the moving picture is his measurement, never mine.');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
process.exit(fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
