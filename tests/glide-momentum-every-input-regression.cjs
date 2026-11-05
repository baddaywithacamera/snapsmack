'use strict';
/**
 * MOMENTUM ON EVERY INPUT THAT MOVES THE WALL, NOT JUST THE ONE I BUILT IT FOR.
 *
 * Sean reported "the physics tweak with the inertia is missing" while testing
 * a GLIDE fixture. The inertia was there -- and only on the drag. pointermove
 * kept a smoothed velocity and release handed it to the fling, while the WHEEL
 * handler moved `target` and never touched velocity at all. So the wall eased
 * to a halt the instant scrolling stopped. On a desktop skin, where the wheel
 * is the obvious way to move a wall, there was no momentum to feel anywhere.
 * He was not describing a weak effect; he was using the input that had none.
 *
 * That is the lesson worth recording: I built a feature for one input and
 * reported it as done. "Inertia works" was true of the code I wrote and false
 * of the product. This file now asks the question per input, so the next gap
 * of that shape fails here instead of reaching him.
 *
 * WHAT IT CANNOT DO. It does not run the wall. The fling lives inside a
 * requestAnimationFrame callback and rAF does not fire in the browser pane
 * available to me -- measured earlier in the week, not assumed. So this is a
 * code-path audit plus arithmetic on the engine's OWN constants, and the
 * moving picture stays Codex's to measure with numbers.
 */

const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, '..', 'assets', 'js', 'ss-engine-glide.js');
const src = fs.readFileSync(file, 'utf8');
const cssFile = path.join(__dirname, '..', 'skins', 'glide', 'style.css');
const css = fs.existsSync(cssFile) ? fs.readFileSync(cssFile, 'utf8') : '';

// Comments stripped before asking anything about code. Three separate checks
// of mine have been fooled by their own prose in one day.
const code = src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/(^|[^:])\/\/[^\r\n]*/g, '$1 ');

let pass = 0, fail = 0;
function cx(ok, what) {
    if (ok) { pass++; console.log('  ok    ' + what); }
    else { fail++; console.log('  FAIL  ' + what); }
}
/**
 * The body of a named addEventListener handler, bounded by the NEXT handler.
 *
 * MY FIRST VERSION TOOK A FIXED 1200 CHARACTERS AND THE MUTANT ESCAPED. With
 * the wheel's momentum lines deleted, that window ran past the end of the
 * wheel handler into pointerdown, which also writes state.vel and
 * state.flinging -- so every wheel check passed on a neighbour's code and the
 * exact bug this file exists to catch went unnoticed. Third time today a
 * source check of mine matched outside the region it was asking about.
 *
 * Bounding on the next addEventListener is a real edge: these handlers are
 * registered one after another, so the next registration is the end of this
 * body. If that ever stops being true, the length assertion below says so
 * rather than letting a window creep.
 */
function handler(name) {
    const at = code.indexOf("addEventListener('" + name + "'");
    if (at === -1) return '';
    const next = code.indexOf('addEventListener(', at + 20);
    return next === -1 ? code.slice(at) : code.slice(at, next);
}

console.log('THE CONSTANTS, READ FROM THE ENGINE RATHER THAN RETYPED');
function num(nameRe) {
    const m = code.match(nameRe);
    return m ? parseFloat(m[1]) : NaN;
}
const FRICTION = num(/GLIDE_FRICTION\s*=\s*([\d.]+)/);
const FLOOR = num(/GLIDE_VEL_FLOOR\s*=\s*([\d.]+)/);
const SLOP = num(/GLIDE_DRAG_SLOP\s*=\s*([\d.]+)/);
console.log('        -> friction ' + FRICTION + ', velocity floor ' + FLOOR + ', drag slop ' + SLOP + 'px');
cx(FRICTION > 0 && FRICTION < 1, 'friction is a decay factor below 1, or nothing ever stops');
cx(FLOOR > 0, 'there is a velocity floor, or the fling never ends');
cx(!Number.isNaN(SLOP) && SLOP >= 8 && SLOP <= 10,
   'the drag slop is the 8-10px Sean asked for (got ' + SLOP + ')');

console.log('\nEVERY INPUT THAT MOVES THE WALL ALSO CARRIES MOMENTUM');
const wheel = handler('wheel');
const move = handler('pointermove');
cx(wheel !== '', 'there is a wheel handler to inspect');
// The bound has to be real, or every check below can pass on a neighbour.
cx(wheel.length > 40 && wheel.length < 1100,
   'the wheel handler body is bounded to a plausible size (' + wheel.length
   + ' chars), so these checks cannot read the next handler');
cx(!/addEventListener\('pointerdown'/.test(wheel),
   'and it demonstrably stops before pointerdown');
cx(/state\.vel\s*=/.test(wheel),
   'WHEEL: writes a velocity -- this is the gap Sean felt, and it is closed');
cx(/state\.flinging\s*=/.test(wheel),
   'WHEEL: and arms the fling, so the wall coasts after the last notch');
cx(/!reduced/.test(wheel),
   'WHEEL: honours prefers-reduced-motion, as release already did');
cx(/state\.vel\s*=/.test(move),
   'DRAG: pointermove keeps a smoothed velocity');
cx(/state\.flinging\s*=\s*true/.test(code),
   'DRAG: release arms the fling');

console.log('\nTHE BROWSER OWN DRAG-AND-DROP CANNOT EAT THE FLING');
// This was the other half of "the inertia is missing", and the worse half. A
// tile is a link wrapped round a photograph, both natively draggable, so a
// mouse drag started a NATIVE drag and the browser fired pointercancel. The
// cancel path correctly clears velocity and returns, so the wall never coasted
// at all -- the fling was not weak, it was never reached.
//
// Measured by Codex in a headed browser: cancelled=true on all four
// orientations with velocity 4.32 and 9.00 sitting unused and an 11-22px
// easing tail. With native dragstart suppressed, the same gestures released at
// velocity 10.62 and 22.12 and travelled 206-209px and 441-443px, which is the
// 170px and 362px this file's own arithmetic predicted.
cx(/addEventListener\('dragstart'/.test(code),
   'the engine suppresses native drag-and-drop');
const dragstart = handler('dragstart');
cx(/preventDefault\(\)/.test(dragstart),
   'by preventing the default, which is what stops the browser taking the gesture');
cx(/closest\('\[data-glide-row\]'\)/.test(dragstart),
   'SCOPED to a row the engine actually drives, not the document or the whole wall');
cx(!/stopPropagation/.test(dragstart),
   'and it does not stop propagation, so nothing else listening is broken');
// The cancel path must KEEP clearing velocity. A genuine pointercancel -- a
// touch interrupted by the system -- still has to kill the fling. Suppressing
// the native drag removes the FALSE cancels; it must not weaken the real ones.
cx(/if \(cancelled\) \{[\s\S]{0,200}state\.vel = 0;[\s\S]{0,120}state\.flinging = false;/.test(code),
   'and a real pointercancel still clears velocity and the fling');

console.log('\nTOUCH GOES DOWN THE SAME PATH, AND NOTHING EXCLUDES IT');
// Touch reaches pointerdown/move/up only if the browser is not handling the
// gesture itself, which is what touch-action: none buys.
cx(/touch-action:\s*none/.test(css),
   'the row sets touch-action: none, so the browser hands touch to the engine');
const down = handler('pointerdown');
cx(/pointerType\s*===\s*'mouse'/.test(down),
   "the button guard is narrowed to pointerType 'mouse', so a touch is never dropped by it");
cx(!/pointerType\s*===\s*'touch'/.test(code),
   'and nothing anywhere branches on a touch pointer, so touch is the drag path exactly');

console.log('\nTHE FLING IS INDIFFERENT TO AXIS MODE');
// The fling works on target and vel, which are scalar offsets along the track.
// alongAxis only changes how an offset is read in and written out.
const paintAt = code.indexOf('function paint(');
const paintBody = paintAt === -1 ? '' : code.slice(paintAt, paintAt + 1400);
cx(/state\.target\s*\+=\s*state\.vel/.test(paintBody),
   'the fling advances the same scalar target the drag does');
// A check ending in "|| true" sat here for one run. It could not fail, which
// makes it worse than nothing: a green line claiming coverage it never had.
// Deleted rather than reworded.
const flingSection = paintBody.slice(0, paintBody.indexOf('state.current +='));
cx(flingSection !== '' && !/alongAxis|vertical/.test(flingSection),
   'and the fling arithmetic itself never consults alongAxis or vertical, so momentum runs identically in axis mode');

console.log('\nHOW LONG IT ACTUALLY COASTS, FROM THOSE CONSTANTS');
// Sean asked for a drift of "a second or longer depending on how fast he let
// go". Numbers, so nobody has to argue about feel.
function coast(v0) {
    let v = v0, px = 0, frames = 0;
    while (Math.abs(v) >= FLOOR && frames < 2000) { px += v; v *= FRICTION; frames++; }
    return { ms: Math.round(frames * 1000 / 60), px: Math.round(px) };
}
const gentle = coast(4), normal = coast(12), flick = coast(80);
console.log('        gentle v0=4  -> ' + gentle.ms + ' ms, ' + gentle.px + ' px');
console.log('        normal v0=12 -> ' + normal.ms + ' ms, ' + normal.px + ' px');
console.log('        flick  v0=80 -> ' + flick.ms + ' ms, ' + flick.px + ' px');
cx(normal.ms >= 600, 'a normal input drifts for most of a second or more (' + normal.ms + ' ms)');
cx(flick.ms > normal.ms && normal.ms > gentle.ms,
   'and a harder input drifts longer than a gentler one, which is the whole request');
cx(flick.px > gentle.px * 5,
   'distance scales with speed rather than being a fixed glide');

console.log('\nTHE START-OF-DRAG WEIGHT: REPORTED, NOT ASSERTED, BECAUSE IT DOES NOT EXIST');
// Sean's original request had two halves. The drift is the second half and it
// now runs on every input. The first half -- "a tiny bit of drag/weight when
// he starts moving a row" -- IS NOT IMPLEMENTED, and I am not going to invent
// a resistance curve and call it his request. What exists instead:
//
//   a DRAG-RECOGNITION THRESHOLD, which I FIRST DESCRIBED WRONG. I said the
//   wall does not move until the pointer has travelled GLIDE_DRAG_SLOP px.
//   It is not a dead zone: `state.target += projected * sensitivity` sits
//   OUTSIDE that if-block and runs on every pointermove, so the wall tracks
//   the pointer from the first pixel. What the threshold gates is whether the
//   gesture COUNTS as a drag -- `moved`, the is-dragging class and pointer
//   capture -- which is what tells a tap from a drag for somebody with a
//   tremor. Corrected here because I stated the wrong version in the room.
//
//   a LAG: current eases toward target by a fixed fraction each frame, so the
//   wall trails the pointer. That is weight of a kind, but it is constant
//   through the drag rather than something felt at the start.
//
// Named here so it cannot be quietly counted as delivered.
const ease = code.match(/state\.current\s*\+=\s*\(state\.target\s*-\s*state\.current\)\s*\*\s*\(?\s*reduced\s*\?\s*1\s*:\s*([\d.]+)/);
console.log('  --    drag-recognition threshold: ' + SLOP + 'px before the gesture counts');
console.log('        as a drag. The wall itself tracks the pointer from the first pixel.');
console.log('  --    lag: current closes ' + (ease ? ease[1] : '?') + ' of the gap per frame');
console.log('  --    NO start-of-drag resistance ramp exists. Sean asked for one;');
console.log('        it is not built, and tuning a feel curve is his call, not mine.');
cx(ease !== null, 'the lag factor is present and readable, so the weight that does exist is measurable');

console.log('\n' + pass + ' checks, ' + fail + ' failed');
console.log('NOT CHECKED HERE: the wall moving. rAF does not fire in my pane, so');
console.log('travel-after-release in px and ms is Codex\'s measurement to take in a');
console.log('headed browser, for both axis and non-axis, with the engine file hashed.');
process.exit(fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
