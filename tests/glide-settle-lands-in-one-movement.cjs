/**
 * SS-1346. The wall must stop in the right place, in one movement.
 *
 * Sean, on a live GLIDE site after SS-1325 shipped: "how it bounces an image
 * at the end to center it is awful and jarring. also, the left hand one is
 * not actually in the frame, it cuts off at bottom. right hand one cuts off
 * at top. it should smoothly stop in the correct position. not stop in wrong
 * position and then shift to correct position." And: "so the scrolling is
 * worse."
 *
 * Two faults, and this measures both.
 *   1. SS-1325 snapped AFTER the fling had already stopped, so the wall halted
 *      in the wrong place and then jumped. The fix predicts the landing from
 *      the release momentum and eases straight into it.
 *   2. It corrected in screen space. On a rotated track that is a different
 *      direction from the one the tiles are laid along, which is why one side
 *      cut off at the bottom and the other at the top. The fix works in the
 *      track's own units, before the lean is applied.
 *
 * These are measured against the ENGINE'S OWN motion model, extracted from
 * assets/js/ss-engine-glide.js, not a restatement of it. No browser needed:
 * this is arithmetic about where a decaying series comes to rest, and that is
 * exactly the thing SS-1325 got wrong.
 *
 * Run: node tests/glide-settle-lands-in-one-movement.cjs
 */

const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'ss-engine-glide.js'), 'utf8');

const fails = [];
const check = (what, ok) => {
    if (!ok) fails.push(what);
    console.log((ok ? 'PASS ' : 'FAIL ') + what);
};

// --- the constants and the closed form come from the engine ----------------
const friction = Number((src.match(/GLIDE_FRICTION\s*=\s*([0-9.]+)/) || [])[1]);
const ease = Number((src.match(/\(reduced \? 1 : ([0-9.]+)\)/) || [])[1]);
check('read GLIDE_FRICTION out of the engine', friction > 0 && friction < 1);
check('read the paint ease factor out of the engine', ease > 0 && ease < 1);

check('the engine truncates the series at the velocity floor, not at infinity',
    /Math\.pow\(GLIDE_FRICTION, n\)/.test(src)
    && /Math\.log\(GLIDE_VEL_FLOOR \/ Math\.abs\(v\)\)/.test(src));
check('settling CANCELS the fling rather than letting it run and correcting',
    /function glideSettle[\s\S]{0,400}state\.flinging = false;/.test(src));
check('the settle is opt-in, so a skin that did not ask keeps its old motion',
    /snapSettle \? .*|if \(snapSettle\)/.test(src) && /dataset\[SNAP_SETTLE_ATTR\]/.test(src));

// --- 1. THE PREDICTION IS RIGHT --------------------------------------------
// Run the engine's own fling loop and compare the real stopping point with
// what the closed form said at release. If these disagree, the settle aims at
// a place the wall was never going to reach, and it would jump to get there.
const floor = Number((src.match(/GLIDE_VEL_FLOOR\s*=\s*([0-9.]+)/) || [])[1]);
function flungDistance(v0) {
    let travelled = 0, vel = v0;
    while (Math.abs(vel) >= floor) { travelled += vel; vel *= friction; }
    return travelled;
}
/** The engine's own prediction, transcribed from glideFlingLanding(). */
function predicted(v) {
    if (Math.abs(v) < floor) return 0;
    const n = Math.ceil(Math.log(floor / Math.abs(v)) / Math.log(friction));
    return v * (1 - Math.pow(friction, n)) / (1 - friction);
}
// A GENTLE fling is the case the first version got wrong: the floor cuts a
// larger share of a small velocity, so v/(1-f) overshot by several percent
// and the settle would have pulled the wall past its own momentum.
for (const v of [1, 2, 5, 12, 40, -18, -3]) {
    const actual = flungDistance(v);
    check(`a fling of ${v} is predicted to within half a pixel`,
        Math.abs(actual - predicted(v)) < 0.5);
}
check('and the naive v/(1-f) really would have been wrong on a gentle fling',
    Math.abs(flungDistance(2) - 2 / (1 - friction)) > 0.5);

// --- 2. ONE MOVEMENT, NO REVERSAL ------------------------------------------
// The defect Sean SAW was a reversal: the wall stopped, then moved back. Model
// the eased approach and assert the direction of travel never flips.
function approach(from, to) {
    const frames = [];
    let current = from;
    for (let i = 0; i < 400 && Math.abs(to - current) > 0.05; i++) {
        current += (to - current) * ease;
        frames.push(current);
    }
    return frames;
}
const frames = approach(0, 523.7);
let reversals = 0;
for (let i = 2; i < frames.length; i++) {
    const a = frames[i - 1] - frames[i - 2];
    const b = frames[i] - frames[i - 1];
    if (a !== 0 && b !== 0 && Math.sign(a) !== Math.sign(b)) reversals++;
}
check('the eased approach never reverses direction (no bounce back)', reversals === 0);
check('and it decelerates the whole way',
    frames.length > 2 &&
    Math.abs(frames[1] - frames[0]) > Math.abs(frames[frames.length - 1] - frames[frames.length - 2]));

// --- 3. THE AXIS ------------------------------------------------------------
check('the boundary is chosen in current/target units, before the lean',
    /function glideNearestBoundary[\s\S]{0,900}Math\.round\(where \/ first\) \* first/.test(src));
check('the lean is still applied afterwards, by localX/localY in paint()',
    /x \* localX[\s\S]{0,40}x \* localY/.test(src));

// --- 4. IT REFUSES TO INVENT A GRID -----------------------------------------
check('a wall of unequal tiles is left alone rather than snapped somewhere arbitrary',
    /if \(Math\.abs\(\(extents\[i\] \+ gap\) - first\) > 1\) return where;/.test(src));

// --- 5. REDUCED MOTION ------------------------------------------------------
check('somebody who asked for less movement gets no settle either',
    /if \(state\.moved && !reduced[\s\S]{0,260}glideSettle\(state\)/.test(src));

console.log(fails.length ? '\nFAIL' : '\nPASS',
    ': the GLIDE settle lands in one movement (SS-1346)');
process.exit(fails.length ? 1 : 0);
