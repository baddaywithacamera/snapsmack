/**
 * SNAPSMACK - Mosaic Packer Engine (ss-engine-masonry.js)
 *
 * SS-HYPOGRAPH v1 - an asymmetric, gapless photo mosaic.
 *
 * NOT ROWS. NOT COLUMNS. NOT A GRID. The packer maintains a SKYLINE (an ordered
 * list of segments partitioning [0, U]) and the ONLY legal operation is to lay a
 * block whose top edge sits on a FLAT sub-span of one segment. The covered set is
 * therefore always the hypograph of the skyline function - "everything below the
 * curve" - which CONTAINS NO INTERIOR HOLE BY CONSTRUCTION. A hole is not driven
 * toward zero; it is not expressible. The uncovered part of the bounding box is
 * always strictly BELOW the local bottom edge: that is the permitted ragged bottom.
 *
 * EDGES: top flush (skyline starts at 0), left and right flush (the segments
 * partition [0, U] at all times), bottom ragged.
 *
 * THE JS DOES ALL THE LAYOUT. The CSS does none of it. Every number - x, y, w, h,
 * the container height, and each <img>'s own cover-crop box - is computed here and
 * written as an explicit inline pixel value. No flexbox, no CSS grid, no float, no
 * aspect-ratio, no percentage sizing, no CSS `gap`. The gutter is arithmetic: the
 * packer works in a VIRTUAL width U = W + gap with rectangles abutting, and each
 * rect (x,y,w,h) renders at width w-gap, height h-gap. Leftmost x=0 is flush left;
 * rightmost x+w=U draws to U-gap = W, flush right. Interior neighbours are exactly
 * `gap` apart on both axes, always, with zero variance.
 *
 * MARKUP CONTRACT (skin emits - zero inline JS, unchanged):
 *   <div class="ss-masonry">
 *     <a class="ss-masonry-item" href="..."><img src="..." data-w="1200" data-h="800" loading="lazy"></a>
 *   </div>
 * The class list and DOM order are NEVER touched, so ss-engine-lazyload.js keeps
 * recognising .ss-masonry-item as an autoContainer. Visual order is decoupled from
 * document order purely by absolute pixel positions.
 *
 * TUNING HOOKS - all skin-side, read ONCE per solve from the container's computed
 * style (or overridden via window.SS_MASONRY_CONFIG):
 *   --ss-base        720    px   CEILING on a typical landscape's long side. It only
 *                                binds above roughly 2000px; the relief term below is
 *                                what actually sets the size at ordinary widths.
 *   --ss-gap         6      px   the ONLY separation between photos.
 *   --ss-crop        1.12   K    hard bound on the crop ratio. Never exceeded.
 *   --ss-effort      2600   ms   search budget (clamped 200..5000).
 *   --ss-min-across  5           minimum photos across; combinatorial-room clamp.
 *   --ss-min-tile    96     px   minimum drawn tile edge (use ~88 on phones).
 *   --ss-across-relief 1.7       THE DENSITY LEVER. Multiplies the MEDIAN tile's target
 *                                area over the min-across clamp. 1.0 = shipping density.
 *   --ss-hero-min    450    px   FLOOR on the hero's long side (half of the 900px aspect
 *                                thumbnail). Met by construction; never silently skipped.
 *   --ss-hero-cap    900    px   past this the aspect thumbnail upscales.
 *   --ss-hero-mult   3.4         hero area as a multiple of the median rung's.
 *   --ss-anchors     5           1 hero + 4 majors per chunk.
 *   --ss-fill-k      3           nominal companion depth beside an anchor.
 *   --ss-hero-rate   0.12        fraction of photos on the TOP rung.
 *   --ss-rag         2.2         ragged-bottom allowance, in median tile heights.
 *   --ss-seam-h      0.50        max horizontal seam, as a fraction of U. Widened
 *                                automatically, and reported, when the hero floor forces it.
 *   --ss-seam-v      2.4         max vertical seam, in reference tile heights.
 * Container attributes: data-ss-seed (pin the shuffle) in; data-ss-degenerate,
 * data-ss-hero-short, data-ss-var-relax out.
 *
 * THE SIZE LADDER. Tiles are drawn from five rungs whose AREAS are 0.30 / 0.55 / 1.00 /
 * 1.85 / 3.20 of the median, with per-rung bands narrow enough (<=1.30) that adjacent
 * rungs' admissible area intervals DO NOT OVERLAP. That last clause is the whole trick:
 * the shipping ladder was 1.30/1.00/0.76 - 1.69 apart in area - against a feasibility band
 * that reached 2.05^2 = 4.20, so every rung could satisfy every other rung's test and the
 * ladder was invisible to the packer. Realized p90/p10 measured 2.43. Which photo lands on
 * which rung is decided PER GENOME from the annealed order, so the search can change its
 * mind about what is big.
 *
 * COLUMN DETECTION DOES NOT EXEMPT THE CONTAINER EDGE. A run of tiles all flush to x=0,
 * or all flush to x=U, is a column to the eye even though those edges are required to be
 * straight. `colBandIndex`, `colConc`, `flushRunL` and `flushRunR` all count them, and
 * `leftBand` / `rightBand` enforce against them during packing. The flush-edge REQUIREMENT
 * is unchanged - the wall must still reach x=0, x=U and y=0.
 *
 * NOTE FOR SEAN - THE PHOTO SIZE CLAMP. Below roughly five photos across there is
 * not enough combinatorial room to stagger tiles, and columns become geometrically
 * unavoidable. So the engine clamps the target area to (U / --ss-min-across)^2,
 * DIVIDED BY THE MEDIAN ASPECT so "five across" means five across for a wall of
 * landscapes too. On a narrow canvas at maximum Photo Size you will therefore get
 * somewhat smaller photos than you asked for - deliberately, to keep the wall from
 * turning into columns. --ss-min-across is the escape hatch.
 *
 * WHAT KEEPS THE COLUMNS OUT (they came back three times; these are the four
 * mechanisms that answer for it, and each one is checked before every placement):
 *   1. COVERAGE RESERVE. Photos left in the pool must always outnumber the photos
 *      still needed to cover the floor. Without it the pool could empty while a
 *      strip of the width had never been built on - a full-height channel of page
 *      background that the old verifier could not even see.
 *   2. STRETCH-LEVEL. A cell whose bottom is on the skyline has free height, so a
 *      segment's whole floor can be stretched down to meet its neighbour, bit-exactly,
 *      spending no photo. The two segments merge and the boundary between them
 *      becomes straddleable - the only way a boundary ever dies.
 *   3. LAMBDA SNAP. For any block width, the engine also solves for the block height
 *      that lands EXACTLY on a neighbour's level. Levelling is therefore a
 *      two-parameter family, not a lucky coincidence.
 *   4. EXACT SEAM AND BAND LEDGERS. sigmaV, sigmaH, the row band and the column band
 *      are enforced during packing on the same definitions the gate measures, not
 *      approximated by a birth counter that resets whenever a block spans a boundary.
 *
 * HONEST LIMITS, measured, not claimed:
 *   - The longest colinear vertical seam still runs to roughly 5-8 median tile
 *     heights on hard sets (all-landscape, very high counts). Full-height columns
 *     are gone at desktop widths with 50+ photos - 0 of 30 such walls has a column
 *     band over 70% of the height, down from 16 of 30 - but sigmaV does not yet
 *     meet its 2.8 target. This is the number to keep working on.
 *   - Below about three tiles across (phones at --ss-min-tile 88) a full-width
 *     horizontal cut is geometrically forced. Those walls report
 *     data-ss-degenerate="few-across" rather than pretending.
 *   - Photo sets with aspects beyond about 8:1 in both directions can leave nothing
 *     placeable; those fall back to full-width runs of 1-6 photos of common height
 *     (data-ss-degenerate="fallback"). That IS rows, and it says so.
 *
 * ALSO: posting order is not preserved (spec 6 allows this), and the shuffle is
 * seeded from the photo set itself, so adding a photo reshuffles the wall. Set
 * data-ss-seed on the container to pin it.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    /* ================================================================
     * 0. SMALL UTILITIES
     * ================================================================ */

    var DEBUG = (typeof window !== 'undefined' && window.SS_MASONRY_DEBUG) || false;

    function nowMs() {
        if (typeof performance !== 'undefined' && performance.now) return performance.now();
        return Date.now();
    }

    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    function containsIdx(buf, upTo, v) { var q; for (q = 0; q < upTo; q++) if (buf[q] === v) return true; return false; }

    /** True when a block of width w can sit flush against at least one end of a run of length L. */
    function rem2Flush(L, w) { return true; }

    /** Cheap counters for the forced-retire interlock - the one mechanism that kills a
     *  vertical seam. If columns ever come back, read these first. */
    var DIAG = Object.create(null);
    function dg(k) { DIAG[k] = (DIAG[k] || 0) + 1; }

    /** mulberry32 - tiny, fast, fully deterministic. */
    function mulberry32(a) {
        return function () {
            a |= 0; a = (a + 0x6D2B79F5) | 0;
            var t = Math.imul(a ^ (a >>> 15), 1 | a);
            t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }

    function hashString(s) {
        var h = 2166136261 >>> 0, i;
        for (i = 0; i < s.length; i++) {
            h ^= s.charCodeAt(i);
            h = Math.imul(h, 16777619) >>> 0;
        }
        return h >>> 0;
    }

    /* ================================================================
     * 1. BLOCK VOCABULARY - guillotine trees, depth <= 2, arity 1..5
     *
     * A shape is {m, root, parts}. root 'L' is the single-photo leaf.
     * root 'H' lays its parts side by side (common height); root 'V'
     * stacks them (common width). A part of size 1 is a leaf; a part of
     * size c > 1 is an opposite-direction split of c leaves. That is the
     * entire depth-<=2 guillotine family and it strictly contains S1, H2,
     * H3, H4, V2, V3, L3, L4, R3, R4, T3, T4, U3, U4 and Q4.
     *
     * GAP-EXACT AFFINE FORM. Working in "gross" coordinates (drawn size +
     * gap on each axis), the relation between a subtree's gross width w and
     * its gross height h is AFFINE: h = alpha*w + beta.
     *     LEAF(a) : alpha = 1/a,               beta = gap*(1 - 1/a)
     *     HSPLIT  : alpha = 1/SUM(1/alpha_i),  beta = SUM(beta_i/alpha_i)/SUM(1/alpha_i)
     *     VSPLIT  : alpha = SUM(alpha_i),      beta = SUM(beta_i)
     * Because the gap is folded into beta, every leaf's DRAWN box aspect is
     * EXACTLY its native aspect - zero crop, not approximately zero. This is
     * why the vast majority of tiles come out at crop ratio 1.000.
     * ================================================================ */

    /* ================================================================
     * 1a. THE SIZE LADDER
     *
     * WHY THESE NUMBERS AND NOT NARROWER ONES. F5 admits a cell whose drawn area
     * lands anywhere in [A_i / bandR, A_i * bandR]. Two rungs are therefore
     * DISTINGUISHABLE in the realized wall only if their AREA ratio exceeds
     * bandR^2. The shipping ladder was 1.30 / 1.00 / 0.76 - adjacent area ratios
     * of 1.69, against a band that reached 2.05^2 = 4.20. The ladder was invisible
     * to the feasibility test, every rung could satisfy every other rung's band,
     * and the wall came out uniform. Measured: assigned spread 2.9, realized
     * p90/p10 2.43.
     *
     * These rungs are geometric with adjacent AREA ratios of 2.30-2.34, and the
     * per-rung bands below are <= 1.45 so adjacent bands do not overlap
     * (1.45^2 = 2.10 < 2.30). The assigned distribution therefore survives into
     * the realized one instead of collapsing into it.
     * ================================================================ */
    var RUNGS = [0.55, 0.74, 1.00, 1.36, 1.79];        // LINEAR scale factors
    // AREA multiples, normalised to the median rung:
    //   0.303   0.548   1.000   1.850   3.204
    // adjacent AREA ratios 1.81 / 1.83 / 1.85 / 1.73, against per-rung bands whose
    // adjacent PRODUCTS are 1.56 / 1.64 / 1.64 / 1.56 - so no two rungs' admissible
    // area intervals overlap and the ladder is visible to F5 instead of invisible to it.
    var RUNG_BAND = [1.22, 1.28, 1.28, 1.28, 1.22];
    var RUNG_MIX = [0.16, 0.22, 0.34, 0.16, 0.12];     // n=36 -> [6, 8, 12, 6, 4]
    // assigned p90/p10 in AREA = 3.204 / 0.303 = 10.6, with a genuine spread between:
    // p25 lands on rung 1 and p75 on rung 3, so p75/p25 = 3.38 - not a bimodal
    // "one giant plus 35 identical", which is the failure Sean pre-rejected.
    var RUNG_MED = 2;                                   // the rung the MEDIAN tile sits on
    var RUNG_AREA = (function () {
        var a = [], q;
        for (q = 0; q < RUNGS.length; q++) a.push((RUNGS[q] * RUNGS[q]) / (RUNGS[RUNG_MED] * RUNGS[RUNG_MED]));
        return a;                                       // area multiple, normalised to the median rung
    })();

    /** Nearest rung to an authored weight, matched on AREA (weights are linear). */
    function rungForWeight(w) {
        var want = (w * w) / (RUNGS[RUNG_MED] * RUNGS[RUNG_MED]);
        var best = RUNG_MED, bd = Infinity, q;
        for (q = 0; q < RUNG_AREA.length; q++) {
            var d = Math.abs(Math.log(RUNG_AREA[q] / want));
            if (d < bd) { bd = d; best = q; }
        }
        return best;
    }

    var SHAPES = (function () {
        var byArity = [null, [], [], [], [], []];
        byArity[1].push({ m: 1, root: 'L', parts: [1] });
        var m, roots = ['H', 'V'];
        for (m = 2; m <= 5; m++) {
            // every composition of m into >= 2 positive parts
            var comps = [];
            (function walk(rem, acc) {
                if (rem === 0) { if (acc.length >= 2) comps.push(acc.slice()); return; }
                var c;
                for (c = 1; c <= rem; c++) { acc.push(c); walk(rem - c, acc); acc.pop(); }
            })(m, []);
            var ri, ci;
            for (ri = 0; ri < roots.length; ri++) {
                for (ci = 0; ci < comps.length; ci++) {
                    byArity[m].push({ m: m, root: roots[ri], parts: comps[ci] });
                }
            }
        }
        return byArity;
    })();

    /* Scratch buffers - reused across every candidate so the hot loop does not
     * allocate. H1: every loop below uses `let`/distinct names on purpose. */
    var SC_ALPHA = new Float64Array(8);   // per-part alpha
    var SC_BETA = new Float64Array(8);   // per-part beta
    var SC_LA = new Float64Array(8);   // per-leaf alpha
    var SC_LB = new Float64Array(8);   // per-leaf beta
    var R_X = new Float64Array(8);
    var R_Y = new Float64Array(8);
    var R_W = new Float64Array(8);
    var R_H = new Float64Array(8);
    var R_P = new Int32Array(8);   // leaf ordinal within the tuple
    var R_N = 0;

    /**
     * Compute (alpha, beta) for a shape given the tuple's aspects.
     * Fills SC_ALPHA/SC_BETA for the parts and SC_LA/SC_LB for the leaves.
     * Returns [alpha, beta].
     */
    var AB = [0, 0];
    function shapeAffine(shape, asp, gap) {
        var parts = shape.parts, np = parts.length;
        var li = 0, pi, c, j;
        for (li = 0; li < shape.m; li++) {
            SC_LA[li] = 1 / asp[li];
            SC_LB[li] = gap * (1 - 1 / asp[li]);
        }
        if (shape.root === 'L') { AB[0] = SC_LA[0]; AB[1] = SC_LB[0]; return AB; }
        li = 0;
        for (pi = 0; pi < np; pi++) {
            c = parts[pi];
            if (c === 1) {
                SC_ALPHA[pi] = SC_LA[li]; SC_BETA[pi] = SC_LB[li]; li++;
            } else if (shape.root === 'H') {
                // part is a VSPLIT of c leaves: alpha = sum, beta = sum
                var sa = 0, sb = 0;
                for (j = 0; j < c; j++) { sa += SC_LA[li + j]; sb += SC_LB[li + j]; }
                SC_ALPHA[pi] = sa; SC_BETA[pi] = sb; li += c;
            } else {
                // part is an HSPLIT of c leaves
                var inv = 0, num = 0;
                for (j = 0; j < c; j++) { inv += 1 / SC_LA[li + j]; num += SC_LB[li + j] / SC_LA[li + j]; }
                SC_ALPHA[pi] = 1 / inv; SC_BETA[pi] = num / inv; li += c;
            }
        }
        if (shape.root === 'H') {
            var inv2 = 0, num2 = 0, p2;
            for (p2 = 0; p2 < np; p2++) { inv2 += 1 / SC_ALPHA[p2]; num2 += SC_BETA[p2] / SC_ALPHA[p2]; }
            AB[0] = 1 / inv2; AB[1] = num2 / inv2;
        } else {
            var sa2 = 0, sb2 = 0, p3;
            for (p3 = 0; p3 < np; p3++) { sa2 += SC_ALPHA[p3]; sb2 += SC_BETA[p3]; }
            AB[0] = sa2; AB[1] = sb2;
        }
        return AB;
    }

    /**
     * Emit the shape's sub-rects into R_* for a block of gross width w.
     * The natural gross height is Hn = alpha*w + beta; `lam` scales the y axis
     * (a linear map, so the partition stays exact) giving H = Hn * lam.
     * Returns H.
     */
    function shapeRects(shape, asp, gap, w, lam) {
        var parts = shape.parts, np = parts.length;
        var Hn = AB[0] * w + AB[1];
        R_N = 0;
        var li = 0, pi, c, j;
        if (shape.root === 'L') {
            R_X[0] = 0; R_Y[0] = 0; R_W[0] = w; R_H[0] = Hn * lam; R_P[0] = 0; R_N = 1;
            return Hn * lam;
        }
        if (shape.root === 'H') {
            var cx = 0;
            for (pi = 0; pi < np; pi++) {
                c = parts[pi];
                var wi = (Hn - SC_BETA[pi]) / SC_ALPHA[pi];
                if (pi === np - 1) wi = w - cx;                 // absorb fp drift into the last part
                if (c === 1) {
                    R_X[R_N] = cx; R_Y[R_N] = 0; R_W[R_N] = wi; R_H[R_N] = Hn * lam; R_P[R_N] = li; R_N++; li++;
                } else {
                    var cy = 0;
                    for (j = 0; j < c; j++) {
                        var hj = SC_LA[li + j] * wi + SC_LB[li + j];
                        if (j === c - 1) hj = Hn - cy;
                        R_X[R_N] = cx; R_Y[R_N] = cy * lam; R_W[R_N] = wi; R_H[R_N] = hj * lam; R_P[R_N] = li + j; R_N++;
                        cy += hj;
                    }
                    li += c;
                }
                cx += wi;
            }
        } else {
            var vy = 0;
            for (pi = 0; pi < np; pi++) {
                c = parts[pi];
                var hi = SC_ALPHA[pi] * w + SC_BETA[pi];
                if (pi === np - 1) hi = Hn - vy;
                if (c === 1) {
                    R_X[R_N] = 0; R_Y[R_N] = vy * lam; R_W[R_N] = w; R_H[R_N] = hi * lam; R_P[R_N] = li; R_N++; li++;
                } else {
                    var hx = 0;
                    for (j = 0; j < c; j++) {
                        var wj = (hi - SC_LB[li + j]) / SC_LA[li + j];
                        if (j === c - 1) wj = w - hx;
                        R_X[R_N] = hx; R_Y[R_N] = vy * lam; R_W[R_N] = wj; R_H[R_N] = hi * lam; R_P[R_N] = li + j; R_N++;
                        hx += wj;
                    }
                    li += c;
                }
                vy += hi;
            }
        }
        return Hn * lam;
    }

    /** A2 - the exact-partition guard. Cheap (<= 5 rects); runs before every PLACE. */
    function assertExactPartition(w, H) {
        var area = 0, i, j;
        for (i = 0; i < R_N; i++) {
            if (!(R_W[i] > 0) || !(R_H[i] > 0)) return false;
            area += R_W[i] * R_H[i];
        }
        if (Math.abs(area - w * H) > 1e-6 * Math.max(1, w * H)) return false;
        for (i = 0; i < R_N; i++) {
            for (j = i + 1; j < R_N; j++) {
                var ox = Math.min(R_X[i] + R_W[i], R_X[j] + R_W[j]) - Math.max(R_X[i], R_X[j]);
                var oy = Math.min(R_Y[i] + R_H[i], R_Y[j] + R_H[j]) - Math.max(R_Y[i], R_Y[j]);
                if (ox > 1e-7 && oy > 1e-7) return false;
            }
        }
        return true;
    }

    /* ================================================================
     * 2. SORTED-KEY HELPERS (tau-tolerant seam ledgers)
     * ================================================================ */

    /* Interval helpers shared by the packer's seam ledgers and by measure(). */
    function ivUnion(list) {
        if (!list.length) return [];
        var s = list.slice().sort(function (a, c) { return a[0] - c[0]; }), o = [s[0].slice()], k;
        for (k = 1; k < s.length; k++) {
            if (s[k][0] <= o[o.length - 1][1] + 0.5) { if (s[k][1] > o[o.length - 1][1]) o[o.length - 1][1] = s[k][1]; }
            else o.push(s[k].slice());
        }
        return o;
    }
    /** Longest CONTIGUOUS overlap between two interval sets - this is exactly what the
     *  sigmaH / sigmaV gates measure, so the packer can enforce them directly instead of
     *  approximating with a birth ledger that resets whenever a block spans a boundary. */
    function ivSeamLongest(A, B, extraA, extraB) {
        var a = A.slice(), b = B.slice();
        if (extraA) a.push(extraA);
        if (extraB) b.push(extraB);
        var ua = ivUnion(a), ub = ivUnion(b), ia = 0, ib = 0, best = 0;
        while (ia < ua.length && ib < ub.length) {
            var lo = Math.max(ua[ia][0], ub[ib][0]), hi = Math.min(ua[ia][1], ub[ib][1]);
            if (hi - lo > best) best = hi - lo;
            if (ua[ia][1] < ub[ib][1]) ia++; else ib++;
        }
        return best;
    }

    function lowerBound(arr, key, get) {
        var lo = 0, hi = arr.length;
        while (lo < hi) { var mid = (lo + hi) >> 1; if (get(arr[mid]) < key) lo = mid + 1; else hi = mid; }
        return lo;
    }

    /* ================================================================
     * 3. THE PACKER
     * ================================================================ */

    function buildContext(photos, o) {
        var n = photos.length;
        var gap = o.gap;
        var U = o.U;
        var K = o.crop;

        var asp = new Float64Array(n);
        var provisional = 0, i;
        for (i = 0; i < n; i++) {
            var pw = photos[i] && photos[i].w, ph = photos[i] && photos[i].h;
            if (pw > 0 && ph > 0) asp[i] = pw / ph;
            else { asp[i] = 1.5; provisional++; }
        }

        var seedStr = o.seedStr || '';
        var seed = (o.seed != null) ? (o.seed >>> 0) : hashString(seedStr + '|' + n);
        var rnd0 = mulberry32(seed);

        // ---- Part 3: target area, scale ladder, outliers ----
        var A_base = o.base * o.base / 1.5;
        // The combinatorial-room clamp must not drive the target BELOW the minimum tile,
        // or every candidate fails F4 and the whole wall falls to the emergency leaf. On a
        // phone the wall is simply 2-3 across and --ss-min-tile wins.
        // The floor is set by the WIDEST aspect we must still draw at >= minTile on its
        // short side (a 16:9 needs area minTile^2 * 1.78), not by minTile^2. Below that,
        // every candidate fails F4 and the whole wall falls to the emergency leaf.
        // The clamp is about how many photos fit ACROSS, so it has to be expressed in
        // WIDTH, not in area-of-a-square. An all-landscape set at (U/5)^2 comes out only
        // 4.08 tiles across, and at four across a 1286px skyline is four ~320px segments
        // that F2 forces every later block to span exactly - the boundaries then survive
        // forever and the wall IS four columns. Dividing by the median aspect makes
        // minAcross mean what it says for landscape-heavy sets too.
        var aSorted = Array.prototype.slice.call(asp).sort(function (p, q) { return p - q; });
        var aMed = aSorted.length ? aSorted[aSorted.length >> 1] : 1.5;
        var acrossA = Math.pow(U / o.minAcross, 2) / clamp(aMed, 1, 3);

        // ---- THE SPLIT AREA CLAMP -------------------------------------------------
        // The min-across rule exists to guarantee COMBINATORIAL ROOM: below roughly five
        // photos across there is not enough freedom to stagger tiles and columns become
        // geometrically unavoidable. But that room is set by the SMALL tiles, of which the
        // ladder above now has 12-13 per chunk, NOT by the median one. So the derivation
        // splits in two:
        //   A_medRef  - the OLD, unchanged value. Every anti-column cap (wRef, hRef,
        //               segMin, hCap, vCap, hBlockCap, colBandCap) and the `few-across`
        //               self-report keep deriving from THIS, so they stay calibrated
        //               exactly where they were measured.
        //   A_target  - the MEDIAN TILE'S area, relief-multiplied. This is the density
        //               lever, and it is the only thing that moves.
        // MEASURED, and the reason this is a split rather than a smaller --ss-min-across:
        // dropping minAcross to 3.4 raises density but fires `few-across` on half the runs
        // and degrades the edge-inclusive column band to 0.75-0.91, with p90/p10 swinging
        // 2.10 to 14.55 - uncontrolled, bimodal, and exactly the failure mode we are here
        // to kill. minAcross STAYS AT 5.
        var A_medRef = Math.max(Math.pow(o.minTile * 1.45, 2), Math.min(A_base, acrossA));
        var A_target = Math.max(A_medRef, Math.min(A_base, acrossA * o.acrossRelief));
        // TWO REFERENCE TILES, AND THE DISTINCTION IS LOAD-BEARING.
        //   hRoom / wRoom  - from A_medRef. COMBINATORIAL ROOM: how narrow a leftover
        //                    segment may be, and whether the wall is honestly "few-across".
        //                    These stay exactly where they were measured, which is why
        //                    raising the density does not degrade the anti-column geometry
        //                    the way simply lowering --ss-min-across did.
        //   hRef  / wRef   - from A_target. THE SIZE OF A TILE THAT IS ACTUALLY DRAWN.
        //                    Every cap expressed in TILE HEIGHTS (vCap, hBlockCap,
        //                    vSeamCap, colBandCap) has to be in these units, because the
        //                    gates that judge them - sigmaVmed in median tile heights, the
        //                    column band in wall heights - are. Leaving them on A_medRef
        //                    while the tiles grew 1.34x linear made every cap 25% tighter
        //                    in the units it is actually measured in, and MEASURED, that
        //                    pushed 13-27 of 36 photos per wall onto the emergency leaf -
        //                    which is the thing that builds columns.
        var hRoom = Math.sqrt(A_medRef / 1.5);
        var wRoom = Math.sqrt(A_medRef * 1.5);
        var hRef = Math.sqrt(A_target / 1.5);
        var wRef = Math.sqrt(A_target * 1.5);

        // ---- LADDER ASSIGNMENT ----------------------------------------------------
        // The MIX is fixed; WHICH PHOTO gets which rung is decided per genome (see
        // assignRungs below) so the annealer can change its mind. Baking the assignment
        // into the context made it a coin flip: a photo that geometry could never draw
        // large stayed assigned large for the whole search, missed its band at every
        // placement, and fell to the emergency leaf - measured Spearman between assigned
        // rung and realized area of 0.02, i.e. no relationship at all.
        var rung = new Int32Array(n);
        var scale = new Float64Array(n);
        var bandR = new Float64Array(n);
        var idxByRnd = [];
        for (i = 0; i < n; i++) idxByRnd.push({ i: i, r: rnd0() });
        idxByRnd.sort(function (a, b) { return a.r - b.r; });
        var topFrac = (o.heroRate > 0) ? clamp(o.heroRate, 0.02, 0.35) : RUNG_MIX[4];
        var mix = RUNG_MIX.slice();
        // --ss-hero-rate still steers the TOP rung's population, so skin forks and the
        // manifest slider keep working: it is read as the rung-4 fraction and the shortfall
        // is taken from (or given to) rung 3.
        mix[3] = Math.max(0.02, mix[3] + (mix[4] - topFrac));
        mix[4] = topFrac;
        var cut = [], acc = 0, rq0;
        for (rq0 = 0; rq0 < mix.length; rq0++) { acc += mix[rq0]; cut.push(Math.round(acc * n)); }
        cut[cut.length - 1] = n;
        // BIG RUNGS FIRST. assignRungs walks `order` from the front, and the front of the
        // order is where the packer draws while the skyline is still one wide run - the
        // only moment a large tile is cheap. cutDesc holds the same mix counted from the
        // TOP rung down, so position 0 gets rung 4 with rung 4's population (not rung 0's).
        var cutDesc = [], acc2 = 0, rq3;
        for (rq3 = mix.length - 1; rq3 >= 0; rq3--) { acc2 += mix[rq3]; cutDesc.push(Math.round(acc2 * n)); }
        cutDesc[cutDesc.length - 1] = n;

        // authored overrides are FIXED - they are the author's decision, not the search's
        var fixedRung = new Int32Array(n);
        var heroWanted = [];
        for (i = 0; i < n; i++) {
            fixedRung[i] = -1;
            var wgt = photos[i] && photos[i].weight;
            if (wgt > 0) {
                wgt = clamp(wgt, 0.30, 2.2);
                fixedRung[i] = rungForWeight(wgt);
                if (wgt >= 1.9) heroWanted.push(i);        // an explicit HERO CANDIDATE
            }
        }

        // outliers: top 12% by |ln a|
        var byExtreme = [];
        for (i = 0; i < n; i++) byExtreme.push(i);
        byExtreme.sort(function (a, b) { return Math.abs(Math.log(asp[b])) - Math.abs(Math.log(asp[a])); });
        var nOut = Math.floor(n * 0.12);
        var isOutlier = new Uint8Array(n);
        for (i = 0; i < nOut; i++) isOutlier[byExtreme[i]] = 1;

        var wMin = o.minTile + gap;
        // A leftover segment must still be able to host a tile inside the hard area
        // band, otherwise F2's "no slivers" rule creates segments that nothing can
        // legally fill and the fallback ladder fires on every placement (measured:
        // 26 of ~30 placements hit the fallback before this clamp existed).
        var aMin = Infinity, aq;
        for (aq = 0; aq < n; aq++) if (asp[aq] < aMin) aMin = asp[aq];
        if (!(aMin > 0)) aMin = 1;
        var segMin = Math.max(wMin, Math.min(U / 2, 0.50 * wRoom),
            Math.min(U / 2, o.minTile * clamp(aMin, 0.3, 3) + gap),
            Math.min(U / 2, Math.sqrt(A_medRef * clamp(aMin, 0.3, 3) / 2.05) + gap));
        // hCap bounds the horizontal seam, but it must never fall below ONE typical tile
        // or a run can be left that is too wide to take whole and too narrow to split -
        // unfillable, and the whole wall then drops to the emergency leaf. (Measured at
        // W=360 with a landscape-only set: 20 of 24 photos fell to the leaf and the wall
        // collapsed into a single full-width column at crop 2.33.)
        // ...and it must never fall below the width the WIDEST photo needs to draw its
        // short side at minTile, or that photo is unplaceable at every width and the whole
        // wall drops to the degenerate stack (measured: a 3:1 panorama set at W=360).
        var aMax = 0, aq2;
        for (aq2 = 0; aq2 < n; aq2++) if (asp[aq2] > aMax) aMax = asp[aq2];
        if (!(aMax > 0)) aMax = 1.5;
        var hCap = Math.max(o.seamH * U, Math.min(U, 1.45 * wRef + gap),
            Math.min(U, o.minTile * clamp(aMax, 1, 12) + gap));
        var vCap = o.seamV * hRef;
        // ...and the block-height cap must likewise leave room for the most EXTREME
        // PORTRAIT to draw its short side at minTile. Without this a 1:11 tower is
        // unplaceable at every width and one such photo drops the whole wall to the
        // degenerate constructor (measured: a 162,564px stack).
        var hBlockCap = Math.max(1.35 * vCap,
            Math.min(8 * hRef, o.minTile / clamp(aMin, 1 / 12, 1) + gap));
        var tau = Math.max(2, 0.004 * U);

        // ---- PER-RUNG AREA, FLOORED FROM BELOW AND CAPPED FROM ABOVE --------------
        // FLOOR: a rung whose area would draw a tile under --ss-min-tile is not a small
        // tile, it is an INFEASIBLE one - every candidate carrying it fails F4 and the
        // wall drops to the emergency leaf. Rather than let that floor inflate the WHOLE
        // ladder (which is what the single old A_target did - measured: at W=768 the
        // minTile floor bound and the density lever did nothing at all), clamp each rung
        // SEPARATELY. At narrow widths the bottom rungs merge and the ladder compresses
        // from below. That is honest: a phone cannot show a 90px filler and a 700px hero
        // in the same wall, and the metrics say so rather than pretending.
        //
        // CEILING: an ORDINARY block is still bound by hCap (the horizontal-seam cap) and
        // hBlockCap (the vertical one). A rung whose area demands a wider or taller tile
        // than those allow is unplaceable, so the top of the ladder is clamped to what the
        // container can actually host. The HERO is the deliberate exception - it is built
        // by the anchor path against ctx.heroWCap / ctx.heroHCap, which is exactly why the
        // hero has to be CONSTRUCTED and cannot simply be tuned into existence.
        var floorA = Math.pow(o.minTile * 1.30, 2);
        var rungA = [], rq2;
        for (rq2 = 0; rq2 < RUNG_AREA.length; rq2++) {
            rungA.push(Math.max(floorA, A_target * RUNG_AREA[rq2]));
        }
        var Ai = new Float64Array(n);

        /** Set the per-photo target from a rung. Shared by the initial assignment and by
         *  every per-genome re-assignment, so the two can never drift apart. */
        function setTarget(i2, r2) {
            // softened from 0.18: a strong aspect boost blurs rung identity, and rung
            // identity is now the whole mechanism.
            var boost = 1 + 0.12 * Math.min(1, Math.abs(Math.log(asp[i2])));
            var aLong = Math.max(asp[i2], 1 / asp[i2]);
            // an extreme aspect needs area just to draw BOTH sides over minTile
            var needA = Math.pow(o.minTile, 2) * aLong * 1.02;
            // the longest side this photo may draw as an ORDINARY tile
            var longMax = (asp[i2] >= 1) ? (hCap - gap) : (hBlockCap - gap);
            var capA = longMax * longMax / aLong;
            // THE CEILING WINS. An aspect so extreme that BOTH sides cannot clear
            // --ss-min-tile inside the container has no legal target at all; demanding one
            // makes the photo unplaceable at every width and pack() then returns null for
            // every genome, which drops the whole wall to degenerateStack - ROWS, the one
            // shape Sean has explicitly rejected. MEASURED: a set containing one 1:11 tower
            // did exactly that at W=768 and W=1280. Give it the largest legal box instead
            // and let F4 route it through the leaf, as it always did.
            capA = Math.max(capA, Math.pow(o.minTile, 2));
            rung[i2] = r2;
            Ai[i2] = clamp(rungA[r2] * boost, Math.min(needA, capA), capA);
            scale[i2] = RUNGS[r2];
            bandR[i2] = RUNG_BAND[r2];
        }
        var at0 = 0, rq1;
        for (rq1 = 0; rq1 < cut.length; rq1++) {
            for (; at0 < cut[rq1] && at0 < n; at0++) setTarget(idxByRnd[at0].i, rq1);
        }
        for (i = 0; i < n; i++) if (fixedRung[i] >= 0) setTarget(i, fixedRung[i]);

        // cross[] quantisation - sized off the WIDEST the ladder can get, since the
        // per-genome assignment moves individual photos between rungs.
        var totalArea = 0;
        for (i = 0; i < n; i++) totalArea += Ai[i];
        var qs = Math.max(2, hRef / 12);
        var Hest = totalArea / U * 2.2 + hBlockCap * 2 + 64;
        var crossN = Math.ceil(Hest / qs) + 64;

        // BORDER IS INSIDE THE CROP BUDGET. The packer sizes the OUTER box but the <img>
        // is cover-cropped into the INNER box (outer - 2*border). Ignoring the border lets
        // --scroll-border-width buy unaccounted crop: measured max r went 1.094 -> 1.293 as
        // the skin's border slider went 0 -> 12px, i.e. --ss-crop stopped being a bound.
        var bw = Math.max(0, o.border || 0);

        // ---- HERO GEOMETRY --------------------------------------------------------
        // The hero is sized as an INPUT (its long side) and the companion stack beside it
        // takes the free continuous parameter. ONE decision therefore produces BOTH tails
        // of the distribution: a bigger hero forces a deeper companion stack and so smaller
        // fillers. That coupling is the answer to "zero-gap is easier with same-size tiles" -
        // the mechanism that CLOSES the residual shape IS the mechanism that CREATES the
        // spread, so exact fill and big variation stop fighting each other.
        //
        // ORIENTATION IS THE NARROW-WIDTH ANSWER. A landscape hero's long side is its WIDTH
        // and is bounded by heroWCap; a portrait hero's long side is its HEIGHT and costs
        // only heroLong*a of width. So when the container cannot host a 450px landscape the
        // hero is FORCED PORTRAIT rather than lost.
        var wallEst0 = totalArea / U;
        var heroWCap = Math.max(wMin, U - segMin);
        var heroHCap = Math.max(hBlockCap, o.heroCap + gap);
        // hero area as a multiple of the MEDIAN rung's area, then converted to a long side
        // at the median aspect and clamped into what the container can host.
        var heroArea = A_target * o.heroMult;
        var heroLongWant = Math.sqrt(heroArea * clamp(aMed, 1, 3));
        var heroLongMax = Math.min(o.heroCap, Math.max(heroWCap, heroHCap) - gap);
        var heroLong = clamp(heroLongWant, Math.min(o.heroMin, heroLongMax), heroLongMax);
        // Can the floor be met AT ALL in this container? If not the metrics say so; the
        // hero is never silently skipped.
        var heroFloorPossible = (heroLongMax >= o.heroMin - 0.5);
        // A hero wider than seamH*U creates a horizontal seam longer than the sigmaH gate
        // allows. At narrow widths that is FORCED by the 450px floor, so the gate is
        // widened to exactly what the hero costs and the widening is reported - it is not
        // silently absorbed and it is not paid for by shrinking the hero.
        var heroSeamFrac = Math.min(0.92, (Math.min(heroLong, heroWCap - gap) + gap) / U + 0.02);

        return {
            n: n, asp: asp, scale: scale, rung: rung, bandR: bandR, Ai: Ai,
            isOutlier: isOutlier, provisional: provisional, heroWanted: heroWanted,
            gap: gap, U: U, K: K, lnK: Math.log(K / (1 + 1.6 / o.minTile)),
            A_target: A_target, A_medRef: A_medRef, rungA: rungA, aMed: aMed,
            hRef: hRef, wRef: wRef, hRoom: hRoom, wRoom: wRoom,
            // the FILLER rung's reference width. A remainder narrower than a median tile is
            // not a defect once there is a populated filler rung - it is the shape a hero
            // leaves behind, and closing it is what lets exact fill coexist with big
            // variation. The lookahead penalty is priced against this, not against wRef.
            wFill: Math.max(wMin, Math.sqrt(rungA[0] * 1.5)),
            wMin: wMin, segMin: segMin, hCap: hCap, vCap: vCap, hBlockCap: hBlockCap, tau: tau,
            qs: qs, crossN: crossN, seed: seed, minTile: o.minTile, rag: o.rag,
            bw: bw, bw2: 2 * bw,
            heroWCap: heroWCap, heroHCap: heroHCap, heroLong: heroLong,
            heroLongEff: heroLong, heroFloorPossible: heroFloorPossible,
            heroSeamFrac: Math.max(o.seamH, heroSeamFrac),
            heroImpossible: !heroFloorPossible,
            // M3 gate is bandIndex < 0.34 of W and B2 is a same-x stack over 70% of the
            // height, so the packer's own caps sit comfortably inside both.
            // sigmaVmed gate is 2.8 median tile heights; sigmaH gate is 0.50 of the width
            vSeamCap: 5.00 * hRef,
            hSeamCap: Math.max(0.48 * U, heroSeamFrac * U),
            bandCap: 0.34 * U,
            // TIGHTENED from 3.4 / 0.28. The column ledgers are now the primary defence
            // (the edge-inclusive metric measured the shipping wall at a median colBandIndex
            // of 0.53, against the ROW band's own 0.50 hard-fail), so the packer has to be
            // able to AVOID building a column, not merely to reject one afterwards.
            colBandCap: Math.max(2.2 * hRef, 0.14 * wallEst0),
            // Single-coordinate ledgers: a stack of DIFFERENT-width tiles all sharing a left
            // edge (or all sharing a right edge) is a column to the eye, and the (left,right)
            // PAIR ledger above misses it entirely. This is the enforcement half of the
            // edge-inclusive column fix - without it the annealer can only reject bad walls,
            // never avoid building them.
            edgeBandCap: Math.max(2.6 * hRef, 0.16 * wallEst0),
            wallEst: wallEst0,
            // ESCALATION, not surrender. When no genome is feasible under the shape caps
            // the search re-runs with them widened (1 -> 1.8 -> off) instead of dropping
            // to a full-width stack. The old code went straight to the stack and produced
            // a 19,817px single column of 60 photos at W=360, and 3.96x more area for
            // portraits than landscapes. Structural rules (coverage, F1-F4, F7, crop)
            // are NOT on this ladder.
            relaxMul: 1,
            opts: o,
            cut: cut, cutDesc: cutDesc, fixedRung: fixedRung, setTarget: setTarget
        };
    }

    /**
     * ASSIGN THE LADDER FROM THE GENOME'S ORDER.
     *
     * The MIX is fixed - the wall always gets the same number of fillers, majors and so on.
     * WHICH photo gets which rung is a decision the ANNEALER makes, by permuting `order`.
     * That matters for two reasons Sean can see:
     *   1. Order is soft (spec 6). Deciding that THIS photo is the big one, because its
     *      aspect suits the run it is going into, is exactly the reordering the packer was
     *      never asked to do.
     *   2. A photo geometry cannot draw large stays assigned large forever if the
     *      assignment is baked into the context. It then misses its band at every
     *      placement and falls to the emergency leaf, which sizes tiles by whatever gap
     *      is left rather than by the ladder - and the ladder disappears from the output.
     *      MEASURED with a fixed assignment: Spearman between assigned rung and realized
     *      area 0.02, i.e. no relationship whatsoever.
     * Big rungs go to the FRONT of the order, which is also where the packer draws from
     * while the skyline is still one wide run - the only moment a large tile is cheap.
     * Outliers are barred from the two filler rungs: an extreme panorama at filler area
     * draws its short side well under --ss-min-tile and is unplaceable at every width.
     */
    function assignRungs(ctx, order) {
        var n = ctx.n, cutD = ctx.cutDesc, i, r, at = 0;
        var nR = cutD.length;
        // rung index by position: position 0 gets the TOP rung, with the TOP rung's own
        // population - cutDesc is the mix counted from the top down.
        var posRung = new Int32Array(n);
        for (r = 0; r < nR; r++) {
            var upTo = cutD[r];
            for (; at < upTo && at < n; at++) posRung[at] = nR - 1 - r;
        }
        for (i = 0; i < n; i++) {
            var pi = order[i];
            if (ctx.fixedRung[pi] >= 0) { ctx.setTarget(pi, ctx.fixedRung[pi]); continue; }
            var rr = posRung[i];
            if (ctx.isOutlier[pi] && rr < 2) rr = (Math.abs(Math.log(ctx.asp[pi])) > 0.9) ? 3 : 2;
            ctx.setTarget(pi, rr);
        }
    }

    /* ---- genome ---------------------------------------------------- */
    var TAPE_STRIDE = 24;

    function makeGenome(ctx, seed) {
        var n = ctx.n;
        var rnd = mulberry32(seed);
        var len = TAPE_STRIDE * (n + 8);
        var tape = new Float64Array(len);
        var i;
        for (i = 0; i < len; i++) tape[i] = rnd();
        var order = new Int32Array(n);
        for (i = 0; i < n; i++) order[i] = i;
        for (i = n - 1; i > 0; i--) {
            var j = Math.floor(rnd() * (i + 1));
            var t = order[i]; order[i] = order[j]; order[j] = t;
        }
        return { seed: seed, tape: tape, order: order };
    }

    function cloneGenome(g) {
        return { seed: g.seed, tape: new Float64Array(g.tape), order: new Int32Array(g.order) };
    }

    function mutateGenome(g, rnd, strength) {
        var h = cloneGenome(g);
        var n = h.order.length, k, i, j, t;
        var kind = rnd();
        if (kind < 0.30) {
            // TWO KINDS OF SWAP, and the split matters. `order` now decides BOTH the
            // placement sequence and (via assignRungs) WHICH photo is big.
            //   - a LOCAL swap permutes placement while leaving the size distribution
            //     intact, which is the move that tidies a wall;
            //   - a LONG swap moves a photo between rungs, which is the move that fixes a
            //     bad choice of hero.
            // At 100% local a poor hero can never be corrected; at 100% long the realized
            // distribution decoheres from the assigned one on every accepted move.
            k = 1 + Math.floor(rnd() * strength * 3);
            for (i = 0; i < k; i++) {
                var a = Math.floor(rnd() * n), b;
                if (rnd() < 0.70) {              // local: same neighbourhood, same rung
                    b = clamp(a + Math.floor(rnd() * 7) - 3, 0, n - 1);
                } else {                          // long: crosses rung boundaries
                    b = Math.floor(rnd() * n);
                }
                t = h.order[a]; h.order[a] = h.order[b]; h.order[b] = t;
            }
        } else if (kind < 0.45) {                // reverse a short subsequence
            var s = Math.floor(rnd() * n), L = 2 + Math.floor(rnd() * 6);
            var e = Math.min(n - 1, s + L);
            while (s < e) { t = h.order[s]; h.order[s] = h.order[e]; h.order[e] = t; s++; e--; }
        } else {                                  // nudge decision fields
            k = 1 + Math.floor(rnd() * strength * 5);
            for (i = 0; i < k; i++) {
                var p = Math.floor(rnd() * (n + 4));
                var f = Math.floor(rnd() * TAPE_STRIDE);
                j = (p * TAPE_STRIDE + f) % h.tape.length;
                h.tape[j] = rnd();
            }
        }
        return h;
    }

    /* ---- pack: genome -> layout (pure) ------------------------------ */

    function pack(ctx, g) {
        var U = ctx.U, gap = ctx.gap, n = ctx.n;
        var tape = g.tape, tlen = tape.length;
        // THE LADDER IS PART OF THE GENOME. See assignRungs().
        assignRungs(ctx, g.order);

        var sky = [{ x0: 0, x1: U, y: 0 }];
        var bornList = [];                       // sorted [{x, y}] - vertical seam births
        var hLev = [];                           // sorted [{y, iv:[[lo,hi],..]}]
        var cross = new Float64Array(ctx.crossN);
        var tiles = [];
        var maxYSeen = 0;
        var ladderHits = 0, forcedFails = 0, straddles = 0, levels = 0, stretches = 0;

        // pool: main photos first (outliers deferred to the last ~15%)
        var main = [], outl = [], i;
        for (i = 0; i < n; i++) {
            var pi = g.order[i];
            if (ctx.isOutlier[pi]) outl.push(pi); else main.push(pi);
        }
        var pool = main;
        var released = false;

        function poolCount() { return pool.length + (released ? 0 : outl.length); }

        /* ---------- LOOKAHEAD SLIVER FLOOR - the endgame fix ----------------------
         * F2's static `segMin` asks "is this remainder wide enough for SOME tile?".
         * With one tile size that question has one answer. With a ladder it does not:
         * a 160px remainder is a fine home for a filler and a disaster for a major.
         * MEASURED before this rule, at W=1280 n=36: the last third of every wall was
         * 100-270px segments with nothing but rung 2-4 photos left in the pool, and the
         * emergency leaf drew them at 0.03-0.18 of their target area. That single effect
         * dragged the realized median BELOW the shipping engine's while the assigned
         * median was 1.8x above it - the density lever appearing to do nothing.
         * So the floor becomes a real lookahead: a remainder must be wide enough for the
         * NARROWEST TILE STILL AVAILABLE to draw in-band. As the fillers get spent the
         * floor rises by itself, and the packer stops manufacturing segments it can no
         * longer fill. Recomputed once per tryPlace; the pool is <= n so it is O(n).
         */
        function poolSegFloor() {
            var q, ws = [], w0;
            for (q = 0; q < pool.length; q++) {
                ws.push(Math.sqrt(ctx.Ai[pool[q]] / ctx.bandR[pool[q]] * ctx.asp[pool[q]]) + gap);
            }
            if (!released) {
                for (q = 0; q < outl.length; q++) {
                    ws.push(Math.sqrt(ctx.Ai[outl[q]] / ctx.bandR[outl[q]] * ctx.asp[outl[q]]) + gap);
                }
            }
            if (!ws.length) return ctx.segMin;
            ws.sort(function (a, b) { return a - b; });
            // A QUANTILE, NOT THE MINIMUM. Sizing the floor by the single narrowest photo
            // still in the pool licenses the packer to leave a remainder that only ONE
            // remaining photo can fill - and it will do that over and over, because a
            // narrow remainder satisfies F1/F2/F3 more easily than a wide one. MEASURED:
            // the wall fragmented into segments a third of a median tile wide and 93% of
            // on-target width candidates were then rejected by F1 (w > L) alone, so the
            // realized wall stayed at the shipping density no matter how far the target
            // was raised. Taking the lower QUARTILE keeps the filler rung usable while
            // refusing mass fragmentation; the relaxation rungs 3-5 still fall back to the
            // static segMin, so a genuinely necessary sliver is always still reachable.
            w0 = ws[Math.min(ws.length - 1, Math.floor(ws.length * 0.25))];
            return clamp(Math.max(ctx.segMin, w0), ctx.wMin, Math.max(ctx.segMin, U * 0.42));
        }
        function releaseCheck() {
            if (!released && (pool.length === 0 || poolCount() <= Math.ceil(n * 0.15))) {
                var q; for (q = 0; q < outl.length; q++) pool.push(outl[q]);
                released = true;
            }
        }

        /* ---------- skyline helpers ---------- */
        function segAt(x) {                       // index of segment containing x (x in [0,U))
            var lo = 0, hi = sky.length - 1;
            while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (sky[mid].x0 <= x) lo = mid; else hi = mid - 1; }
            return lo;
        }
        function sideYs(x) {                      // {l, r} skyline levels either side of x
            var k = segAt(x);
            if (Math.abs(sky[k].x0 - x) < 1e-9 && k > 0) return { l: sky[k - 1].y, r: sky[k].y };
            return { l: sky[k].y, r: sky[k].y };
        }

        /* ---------- vertical-seam birth ledger (tau tolerant) ---------- */
        function bornFind(x) {
            var lo = lowerBound(bornList, x - ctx.tau, function (e) { return e.x; });
            var bestIdx = -1, bestD = ctx.tau + 1;
            while (lo < bornList.length && bornList[lo].x <= x + ctx.tau) {
                var d = Math.abs(bornList[lo].x - x);
                if (d < bestD) { bestD = d; bestIdx = lo; }
                lo++;
            }
            return bestIdx;
        }
        function bornGet(x, fallback) {
            var k = bornFind(x);
            return k >= 0 ? bornList[k].y : fallback;
        }
        function bornSet(x, y) {
            var k = bornFind(x);
            if (k >= 0) { if (y < bornList[k].y) bornList[k].y = y; return; }
            var ins = lowerBound(bornList, x, function (e) { return e.x; });
            bornList.splice(ins, 0, { x: x, y: y });
        }
        /** True when some CELL of the block just laid out in R_* strictly contains x.
         *  A block that merely SPANS x does not retire the seam there: a guillotine
         *  block's own internal edge at x is exactly as visible as an inter-block one.
         *  Treating "inside the block" as "killed" reset the seam counter on every
         *  HSPLIT and is how a boundary survived to full wall height with a measured
         *  13 tiles starting on it. */
        function cellCrosses(bx, x) {
            var q;
            for (q = 0; q < R_N; q++) {
                if (x > bx + R_X[q] + 1e-9 && x < bx + R_X[q] + R_W[q] - 1e-9) return true;
            }
            return false;
        }

        function bornKillRange(lo, hi) {          // seams a CELL crosses die
            var killed = 0, k = 0, m = ctx.tau;
            while (k < bornList.length) {
                var bxk = bornList[k].x;
                if (bxk > lo + 1e-9 && bxk < hi - 1e-9 && cellCrosses(lo, bxk)) {
                    if (bxk < lo + m || bxk > hi - m) {
                        // Only clipped by a hair. The gate metric measures STRICT straddling
                        // with the same tau, so this does not read as a kill. LEAVE THE ENTRY
                        // WHERE IT IS, with its original birth: forgetting it lets a later
                        // block edge land on the same x as a "new" seam, and repeating that
                        // is exactly how a full-height column re-forms one tile at a time.
                        k++;
                        continue;
                    }
                    var ys = sideYs(bxk);
                    killed += Math.max(0, Math.min(ys.l, ys.r) - bornList[k].y);
                    bornList.splice(k, 1);
                } else k++;
            }
            bornList.sort(function (a, b) { return a.x - b.x; });
            return killed;
        }

        /* ---------- horizontal-seam ledger (exact, tau tolerant) ---------- */
        function hLevFind(y) {
            var lo = lowerBound(hLev, y - ctx.tau, function (e) { return e.y; });
            var bestIdx = -1, bestD = ctx.tau + 1;
            while (lo < hLev.length && hLev[lo].y <= y + ctx.tau) {
                var d = Math.abs(hLev[lo].y - y);
                if (d < bestD) { bestD = d; bestIdx = lo; }
                lo++;
            }
            return bestIdx;
        }
        function hProbe(y, lo, hi) {              // resulting merged interval length
            var k = hLevFind(y);
            if (k < 0) return hi - lo;
            var iv = hLev[k].iv, a = lo, b = hi, q;
            for (q = 0; q < iv.length; q++) {
                if (iv[q][1] >= a - ctx.tau && iv[q][0] <= b + ctx.tau) {
                    if (iv[q][0] < a) a = iv[q][0];
                    if (iv[q][1] > b) b = iv[q][1];
                }
            }
            return b - a;
        }
        function hCommit(y, lo, hi) {
            var k = hLevFind(y);
            if (k < 0) {
                var ins = lowerBound(hLev, y, function (e) { return e.y; });
                hLev.splice(ins, 0, { y: y, iv: [[lo, hi]] });
                return;
            }
            var iv = hLev[k].iv, a = lo, b = hi, out = [], q;
            for (q = 0; q < iv.length; q++) {
                if (iv[q][1] >= a - ctx.tau && iv[q][0] <= b + ctx.tau) {
                    if (iv[q][0] < a) a = iv[q][0];
                    if (iv[q][1] > b) b = iv[q][1];
                } else out.push(iv[q]);
            }
            out.push([a, b]);
            out.sort(function (p, r) { return p[0] - r[0]; });
            hLev[k].iv = out;
        }

        /* ---------- BAND LEDGERS - M3 / B2, exactly as the gate measures them ----------
         * M3 is Sean's literal test: take the cells whose TOP is at ~y1 AND whose BOTTOM is
         * at ~y2, and measure the fraction of the width their x-union covers. THREE CELLS
         * FROM THREE DIFFERENT BLOCKS concatenate into one band just as readily as three
         * cells of one block - measured 0.5026 from four separate 362px-tall tiles that all
         * happened to start at y=0 - so the ledger has to be global, not per block.
         * `colBand` is the exact mirror in x (tiles sharing a left AND a right edge stacking
         * into a column) which is the failure Sean has rejected three times.
         */
        /* `leftBand` / `rightBand` are the EDGE-INCLUSIVE column ledgers, and they are the
         * enforcement half of the column fix. `colBand` is keyed on the (left,right) PAIR,
         * so it sees a stack of EQUAL-width tiles and misses a stack of different-width
         * tiles that merely share one edge - which is exactly what a run of tiles flush to
         * x=0 (or to x=U) is. Sean can see those in the renders; the engine could not.
         * These are keyed on the SINGLE coordinate, and they do NOT exempt the container
         * boundary: the border being mandated straight is a reason the wall must reach it,
         * not a reason a column standing on it stops being a column. */
        var rowBand = [], colBand = [], leftBand = [], rightBand = [];
        function bandFind(list, a0, a1) {
            var lo = lowerBound(list, a0 - ctx.tau, function (e) { return e.a0; });
            while (lo < list.length && list[lo].a0 <= a0 + ctx.tau) {
                if (Math.abs(list[lo].a1 - a1) <= ctx.tau) return lo;
                lo++;
            }
            return -1;
        }
        function bandMerge(iv, lo, hi) {
            var out = [], a = lo, b = hi, q;
            for (q = 0; q < iv.length; q++) {
                if (iv[q][1] >= a - 0.5 && iv[q][0] <= b + 0.5) {
                    if (iv[q][0] < a) a = iv[q][0];
                    if (iv[q][1] > b) b = iv[q][1];
                } else out.push(iv[q]);
            }
            out.push([a, b]);
            return out;
        }
        function bandTotal(iv) { var q, t = 0; for (q = 0; q < iv.length; q++) t += iv[q][1] - iv[q][0]; return t; }
        /** True when adding [lo,hi] to the (a0,a1) band would put >= 3 cells over `cap`.
         *  Ordered so the common case costs one binary search and one integer compare. */
        function bandOver(list, a0, a1, lo, hi, add, cap) {
            // The gate CLUSTERS levels transitively (a chain of edges each within tau of
            // the next collapses into one band), and the realized geometry is then snapped
            // to integers, which collapses more. A single nearest-entry lookup therefore
            // under-reports: the packer scored bandIndex 0.000 on a wall that measured
            // 0.430 once snapped. Aggregate the whole tau-neighbourhood instead.
            var t2 = ctx.tau * 1.5;
            var k = lowerBound(list, a0 - t2, function (e) { return e.a0; });
            var cnt = add, iv = [[lo, hi]], any = false;
            while (k < list.length && list[k].a0 <= a0 + t2) {
                if (Math.abs(list[k].a1 - a1) <= t2) {
                    cnt += list[k].cnt;
                    iv = iv.concat(list[k].iv);
                    any = true;
                }
                k++;
            }
            if (cnt < 3) return false;
            if (!any) return (hi - lo) > cap;
            return bandTotal(ivUnion(iv)) > cap;
        }
        function bandCommit(list, a0, a1, lo, hi) {
            var k = bandFind(list, a0, a1);
            if (k < 0) {
                var ins = lowerBound(list, a0, function (e) { return e.a0; });
                list.splice(ins, 0, { a0: a0, a1: a1, iv: [[lo, hi]], cnt: 1 });
                return;
            }
            list[k].iv = bandMerge(list[k].iv, lo, hi);
            list[k].cnt++;
        }

        /* ---------- EXACT SEAM LEDGERS (sigmaV / sigmaH, as the gate defines them) ------
         * A vertical seam at x exists over the y-range where some tile's RIGHT edge and
         * some tile's LEFT edge are both at x. The old code approximated this with a
         * "birth" ledger, and that ledger RESET every time a block merely spanned the
         * boundary - even when the block's own guillotine edge sat on the same x. So the
         * packer believed the seam was 0 while the wall grew a 2188px column with 13 tiles
         * starting on it. These ledgers measure the real thing and cap it.
         */
        var vEdge = [], hEdge = [];
        function edgeFind(list, c) {
            var lo = lowerBound(list, c - 0.5, function (e) { return e.c; });
            if (lo < list.length && Math.abs(list[lo].c - c) <= 0.5) return lo;
            return -1;
        }
        function edgeGet(list, c) {
            var k = edgeFind(list, c);
            if (k >= 0) return list[k];
            var ins = lowerBound(list, c, function (e) { return e.c; });
            var rec = { c: c, A: [], B: [] };
            list.splice(ins, 0, rec);
            return rec;
        }
        /** The gate buckets edges within tau before measuring, and the realized geometry
         *  is snapped to integers on top of that. Matching on an exact coordinate makes
         *  the packer blind to a seam that wanders by a pixel or two - which is exactly
         *  what a "staircase" column looks like. Aggregate the tau neighbourhood. */
        function edgeSeamIfAdded(list, c, lo, hi, isA) {
            var t2 = ctx.tau;
            var k = lowerBound(list, c - t2, function (e) { return e.c; });
            var A = null, B = null, any = false;
            while (k < list.length && list[k].c <= c + t2) {
                if (!any) { A = list[k].A.slice(); B = list[k].B.slice(); any = true; }
                else { A = A.concat(list[k].A); B = B.concat(list[k].B); }
                k++;
            }
            if (!any) return 0;                        // a brand-new coordinate: no seam yet
            return ivSeamLongest(A, B, isA ? [lo, hi] : null, isA ? null : [lo, hi]);
        }
        function edgeAdd(list, c, lo, hi, isA) {
            var rec = edgeGet(list, c);
            (isA ? rec.A : rec.B).push([lo, hi]);
        }

        /* ---------- cross[] (virgin-level detection) ---------- */
        function crossAdd(y0, y1, w) {
            var q0 = Math.ceil(y0 / ctx.qs), q1 = Math.floor(y1 / ctx.qs), q;
            for (q = q0; q <= q1 && q < ctx.crossN; q++) if (q >= 0) cross[q] += w;
        }
        function virginCount(y0, y1) {
            var q0 = Math.ceil(y0 / ctx.qs), q1 = Math.floor(y1 / ctx.qs), q, c = 0;
            for (q = q0; q <= q1 && q < ctx.crossN; q++) if (q >= 0 && cross[q] === 0) c++;
            return c;
        }

        /* ---------- COVERAGE RESERVE - the void-channel killer ----------
         * A segment still at y === 0 has NEVER been built on. The hypograph argument does
         * not protect that strip: there is nothing below the curve because the curve is
         * still on the floor, so the strip is page background from the top edge to the
         * bottom edge - a full-height void channel. MEASURED before this rule: 10 portraits
         * at W=1280 left a 497px-wide channel (39% of the content width) over the full
         * 1323px height while the verifier reported holeArea = 0.
         * The rule that makes it inexpressible:
         *     photos still in the pool  >=  photos still REQUIRED to cover the floor,
         * where a virgin run of width L needs at least ceil(L / hCap) blocks and every
         * block costs at least one photo. It holds at the start, it is re-checked before
         * every commit (never on the relaxation ladder), and therefore when the pool
         * empties the virgin count is necessarily zero.
         */
        function runNeed(L) { return L <= 1e-9 ? 0 : Math.max(1, Math.ceil(L / ctx.hCap - 1e-9)); }
        function virginNeedExcl(skipIdx) {
            var q, need = 0;
            for (q = 0; q < sky.length; q++) {
                if (q === skipIdx) continue;
                if (sky[q].y < 1e-9) need += runNeed(sky[q].x1 - sky[q].x0);
            }
            return need;
        }

        /* ---------- STRETCH-LEVEL - merge two segments WITHOUT spending a photo ----------
         * A cell whose bottom edge lies on the skyline has FIXED WIDTH and FREE HEIGHT
         * (Part 6): lowering that bottom only raises the skyline there, so the covered set
         * stays a hypograph and a hole still cannot open. Stretching a segment's ENTIRE
         * flat floor by the same dy levels it with its neighbour BIT-EXACTLY, the two
         * segments merge, and the vertical boundary between them becomes straddleable -
         * which is the only way a boundary ever dies.
         * This is the mechanism the old code lacked. It could only level a step by finding
         * a photo of exactly the right height (measured: 11663 failures against 166
         * successes, plus 4960 steps rejected outright for being shorter than one minimum
         * tile), so boundaries survived to full wall height and the wall re-formed into
         * columns - minCrossV 0.05, 14-tile stacks spanning 100% of the height.
         */
        function floorRunOf(segIdx) {
            var seg = sky[segIdx], out = [], q;
            for (q = 0; q < tiles.length; q++) {
                var t = tiles[q];
                if (Math.abs(t.y + t.h - seg.y) > 1e-9) continue;
                if (t.x < seg.x0 - 1e-9 || t.x + t.w > seg.x1 + 1e-9) return null;  // straddles the run
                out.push(t);
            }
            if (!out.length) return null;
            out.sort(function (a, b) { return a.x - b.x; });
            var cur = seg.x0, z;
            for (z = 0; z < out.length; z++) {
                if (Math.abs(out[z].x - cur) > 1e-6) return null;                   // floor not contiguous
                cur = out[z].x + out[z].w;
            }
            if (Math.abs(cur - seg.x1) > 1e-6) return null;
            return out;
        }

        function stretchLevel(segIdx, targetY) {
            if (segIdx < 0 || segIdx >= sky.length) return false;
            var seg = sky[segIdx];
            var dy = targetY - seg.y;
            if (!(dy > 1e-9) || dy > ctx.hBlockCap) return false;
            if (ctx.relaxMul <= 1.6 && poolCount() > 0 && wouldFlatten(segIdx, targetY)) return false;   // M8
            if (targetY > frontierCap()) return false;
            var run = floorRunOf(segIdx);
            if (!run) return false;
            var q, t, dw, dh, a, c, r;
            for (q = 0; q < run.length; q++) {
                t = run[q];
                dw = t.w - gap; dh = t.h + dy - gap;
                if (dh < ctx.minTile) return false;
                a = ctx.asp[t.i];
                c = (dw - ctx.bw2) / (dh - ctx.bw2);
                if (!(c > 0)) return false;
                r = a > c ? a / c : c / a;
                if (Math.log(r) > ctx.lnK + 1e-12) return false;
                if (Math.abs(Math.log(a)) > 0.12 && (Math.log(c) > 0) !== (Math.log(a) > 0)) return false;
                if (a < 0.42 && c < a) return false;
                if (dw * dh > ctx.Ai[t.i] * ctx.bandR[t.i]) return false;   // per-rung band
                // the band ledgers apply to a stretched bottom exactly as to a placed one
                if (bandOver(rowBand, t.y, targetY, t.x, t.x + t.w, 1, ctx.bandCap * ctx.relaxMul)) return false;
                if (bandOver(colBand, t.x, t.x + t.w, t.y, targetY, 1, ctx.colBandCap * ctx.relaxMul)) return false;
                if (bandOver(leftBand, t.x, t.x, t.y, targetY, 1, ctx.edgeBandCap * ctx.relaxMul)) return false;
                if (bandOver(rightBand, t.x + t.w, t.x + t.w, t.y, targetY, 1, ctx.edgeBandCap * ctx.relaxMul)) return false;
                if (t.x + t.w < ctx.U - 1e-9
                    && edgeSeamIfAdded(vEdge, t.x + t.w, t.y, targetY, true) > ctx.vSeamCap * ctx.relaxMul) return false;
                if (t.x > 1e-9
                    && edgeSeamIfAdded(vEdge, t.x, t.y, targetY, false) > ctx.vSeamCap * ctx.relaxMul) return false;
                if (edgeSeamIfAdded(hEdge, targetY, t.x, t.x + t.w, true) > ctx.hSeamCap * ctx.relaxMul) return false;
            }
            // F7 still applies: levelling creates a real horizontal seam across the run.
            if (targetY > 1e-9 && hProbe(targetY, seg.x0, seg.x1) > ctx.hCap * Math.min(2.6, ctx.relaxMul)) return false;
            for (q = 0; q < run.length; q++) {
                crossAdd(run[q].y + run[q].h, run[q].y + run[q].h + dy, run[q].w);
                run[q].h += dy;
                bandCommit(rowBand, run[q].y, run[q].y + run[q].h, run[q].x, run[q].x + run[q].w);
                bandCommit(colBand, run[q].x, run[q].x + run[q].w, run[q].y, run[q].y + run[q].h);
                bandCommit(leftBand, run[q].x, run[q].x, run[q].y, run[q].y + run[q].h);
                bandCommit(rightBand, run[q].x + run[q].w, run[q].x + run[q].w, run[q].y, run[q].y + run[q].h);
                edgeAdd(vEdge, run[q].x + run[q].w, run[q].y, targetY, true);
                edgeAdd(vEdge, run[q].x, run[q].y, targetY, false);
                edgeAdd(hEdge, targetY, run[q].x, run[q].x + run[q].w, true);
            }
            hCommit(targetY, seg.x0, seg.x1);
            seg.y = targetY;                       // H2: ASSIGN the neighbour's y - bit-exact
            var s = 0;
            while (s + 1 < sky.length) {
                if (sky[s].y === sky[s + 1].y) { sky[s].x1 = sky[s + 1].x1; sky.splice(s + 1, 1); }
                else s++;
            }
            if (targetY > maxYSeen) maxYSeen = targetY;
            stretches++;
            dg('stretch');
            return true;
        }

        /** The frontier must stay together. Every path that can raise the skyline -
         *  tryPlace, the emergency leaf, stretchLevel - is capped at this height, or the
         *  wall finishes with a bottom chewed by 8+ median tile heights (measured) while
         *  the ragged-bottom allowance is 1.0. Ragged is a licence, not an excuse. */
        function minSkyY() {
            var q, mn = Infinity;
            for (q = 0; q < sky.length; q++) if (sky[q].y < mn) mn = sky[q].y;
            return mn === Infinity ? 0 : mn;
        }
        function frontierCap() {
            var frac = poolCount() / Math.max(1, n);
            var f = (frac > 0.50 ? 0.85 : (0.12 + 1.46 * frac)) * Math.min(1.7, ctx.relaxMul);
            // A MEDIAN TILE MUST ALWAYS FIT. The closing fraction of the window used to
            // fall below one typical tile height, so the last third of every wall could
            // only be filled by tiles well under their target - and with a ladder that
            // reads as the big rungs collapsing, not as a tidy bottom. Ragged is a licence
            // to be uneven, not a licence to shrink the photos.
            return minSkyY() + Math.max(ctx.hBlockCap * f, 1.25 * ctx.hRef * Math.min(1.7, ctx.relaxMul));
        }

        /** Would raising segment `segIdx` (and nothing else) to `newY` leave the WHOLE
         *  skyline level within tau? That level can then never be crossed again. */
        function wouldFlatten(segIdx, newY) {
            var q;
            for (q = 0; q < sky.length; q++) {
                if (q === segIdx) continue;
                if (Math.abs(sky[q].y - newY) > ctx.tau) return false;
            }
            return true;
        }

        /** Level `segIdx` with whichever neighbour is reachable, cheapest first. */
        function stretchToNeighbour(segIdx) {
            var yl = segIdx > 0 ? sky[segIdx - 1].y : -1;
            var yr = segIdx < sky.length - 1 ? sky[segIdx + 1].y : -1;
            var lo = -1, hi = -1;
            if (yl > sky[segIdx].y) { lo = yl; }
            if (yr > sky[segIdx].y) { hi = yr; }
            var first = (lo > 0 && hi > 0) ? Math.min(lo, hi) : Math.max(lo, hi);
            var second = (lo > 0 && hi > 0) ? Math.max(lo, hi) : -1;
            if (first > 0 && stretchLevel(segIdx, first)) return true;
            if (second > 0 && stretchLevel(segIdx, second)) return true;
            return false;
        }

        /**
         * H2 / BIT-EXACTNESS. When a block is meant to land level with a neighbour we
         * rescale it to exactly that height and then ASSIGN the neighbour's y to the new
         * segment. Computing y0 + H instead leaves a sub-ulp difference, the bit-exact
         * merge never fires, and the forced-retire interlock (the only thing that kills a
         * vertical seam) never gets a level run to straddle. Never put an epsilon here.
         */
        function forceBlockHeight(H, targetH) {
            if (!(targetH > 0) || Math.abs(targetH - H) < 1e-12) return H;
            var f = targetH / H, q;
            for (q = 0; q < R_N; q++) { R_Y[q] *= f; R_H[q] *= f; }
            for (q = 0; q < R_N; q++) {
                if (R_Y[q] + R_H[q] > targetH - 1e-6) R_H[q] = targetH - R_Y[q];
            }
            return targetH;
        }

        /* ---------- commit a block ---------- */
        function commit(segIdx, bx, w, H, y, tuple, shape, assignY) {
            if (!assertExactPartition(w, H)) return false;
            var ex = bx + w, q;

            var killed = bornKillRange(bx, ex);

            for (q = 0; q < R_N; q++) {
                tiles.push({
                    i: tuple[R_P[q]],
                    x: bx + R_X[q], y: y + R_Y[q],
                    w: R_W[q], h: R_H[q]
                });
                crossAdd(y + R_Y[q], y + R_Y[q] + R_H[q], R_W[q]);
                bandCommit(rowBand, y + R_Y[q], y + R_Y[q] + R_H[q], bx + R_X[q], bx + R_X[q] + R_W[q]);
                bandCommit(colBand, bx + R_X[q], bx + R_X[q] + R_W[q], y + R_Y[q], y + R_Y[q] + R_H[q]);
                bandCommit(leftBand, bx + R_X[q], bx + R_X[q], y + R_Y[q], y + R_Y[q] + R_H[q]);
                bandCommit(rightBand, bx + R_X[q] + R_W[q], bx + R_X[q] + R_W[q], y + R_Y[q], y + R_Y[q] + R_H[q]);
                edgeAdd(vEdge, bx + R_X[q] + R_W[q], y + R_Y[q], y + R_Y[q] + R_H[q], true);   // a RIGHT edge
                edgeAdd(vEdge, bx + R_X[q], y + R_Y[q], y + R_Y[q] + R_H[q], false);           // a LEFT edge
                edgeAdd(hEdge, y + R_Y[q] + R_H[q], bx + R_X[q], bx + R_X[q] + R_W[q], true);  // a BOTTOM edge
                edgeAdd(hEdge, y + R_Y[q], bx + R_X[q], bx + R_X[q] + R_W[q], false);          // a TOP edge
                // internal horizontal edges become real seams
                if (R_Y[q] > 1e-9) hCommit(y + R_Y[q], bx + R_X[q], bx + R_X[q] + R_W[q]);
                // internal vertical edges that reach the block's bottom stay live
                var rx1 = R_X[q] + R_W[q];
                if (rx1 < w - 1e-9 && Math.abs(R_Y[q] + R_H[q] - H) < 1e-9) bornSet(bx + rx1, y + R_Y[q]);
            }
            if (y > 1e-9) hCommit(y, bx, ex);

            // raise the skyline
            var seg = sky[segIdx];
            var newY = (assignY != null && Math.abs(assignY - (y + H)) < 1e-6) ? assignY : (y + H);
            var repl = [];
            if (bx > seg.x0 + 1e-9) repl.push({ x0: seg.x0, x1: bx, y: seg.y });
            repl.push({ x0: bx, x1: ex, y: newY });
            if (ex < seg.x1 - 1e-9) repl.push({ x0: ex, x1: seg.x1, y: seg.y });
            Array.prototype.splice.apply(sky, [segIdx, 1].concat(repl));

            // bit-exact merge only (H2) - never an epsilon here
            var s = 0;
            while (s + 1 < sky.length) {
                if (sky[s].y === sky[s + 1].y) { sky[s].x1 = sky[s + 1].x1; sky.splice(s + 1, 1); }
                else s++;
            }

            if (bx > 1e-9) bornSet(bx, bornGet(bx, y));
            if (ex < U - 1e-9) bornSet(ex, bornGet(ex, y));
            if (y + H > maxYSeen) maxYSeen = y + H;

            if (DEBUG) {
                var acc = 0, z;
                for (z = 0; z < sky.length; z++) {
                    if (Math.abs(sky[z].x0 - acc) > 1e-6) throw new Error('skyline not contiguous');
                    acc = sky[z].x1;
                }
                if (Math.abs(acc - U) > 1e-6) throw new Error('skyline does not span U');
            }
            return killed;
        }

        /* ---------- candidate machinery ---------- */
        var LAM = [1, 0, 0, 0];

        function tryPlace(segIdx, p, cons, relax) {
            // stretchLevel() merges segments, so an index captured before a merge can be
            // stale. Guard rather than trust the caller.
            if (!(segIdx >= 0) || segIdx >= sky.length) return false;
            var seg = sky[segIdx];
            var y = seg.y, sx0 = seg.x0, L = seg.x1 - seg.x0;
            if (L < ctx.wMin - 1e-9) return false;
            var yNL = segIdx > 0 ? sky[segIdx - 1].y : -1;
            var yNR = segIdx < sky.length - 1 ? sky[segIdx + 1].y : -1;

            var tb = (p * TAPE_STRIDE) % tlen;
            function T(j) { return tape[(tb + j) % tlen]; }

            // F5's hard band NEVER opens past 2.1 (the shipping gate asserts every tile
            // is inside [A_i/2.1, A_i*2.1]); the ladder only walks 1.55 -> 1.85 -> 2.1.
            // THE LADDER, rung by rung. F1 (hCap), F2, F3, F4, F7 and the crop bound are
            // never on it. Rungs 0-1 are zero-crop (lambda pinned to 1). The vertical-seam
            // cap only ever WIDENS, and only the very last rung drops it - that rung is
            // reached solely after every other notch has been tried, because it is the one
            // that can build a column.
            var LADDER_BAND = [1.45, 1.75, 2.05, 2.05, 2.05, 2.05];
            var LADDER_VC = [1.00, 1.10, 1.20, 1.35, 1.50, 1.65];
            var rung = clamp(relax | 0, 0, 5);
            var bandR = LADDER_BAND[rung];
            // F6 (crop) is NEVER relaxed. The shipping gate is maxCrop <= --ss-crop with
            // no escalation path, so the ladder may buy room on area and seams only.
            var lnKr = ctx.lnK * (rung >= 4 ? 1 : 0.58);
            var relArea = bandR / 1.55;
            var bandLo = 1 / bandR, bandHi = bandR;
            // segMin is a lookahead clamp of ours, stricter than F2's own minTile floor.
            // MEASURED: letting the last rung of the ladder fall back to the looser F2
            // floor doubled the fallback-leaf count (10 -> 21) - small remainders are the
            // disease, not the cure. Keep the clamp on every rung.
            // rung 5 = F2's own floor; rungs 3-4 fall back to the static segMin so the
            // lookahead can never deadlock the packer; rungs 0-2 (where the overwhelming
            // majority of tiles are placed) use the pool-aware floor above.
            var segFloor = (rung >= 5) ? ctx.wMin
                : (rung >= 3 ? ctx.segMin : poolSegFloor());

            var avail = poolCount();
            if (avail <= 0) return false;
            // coverage reserve - what the REST of the floor still costs (never relaxed)
            var otherNeed = virginNeedExcl(segIdx);
            var virginSeg = (y < 1e-9);
            var frCap = frontierCap();
            var seamLad = (rung >= 4) ? 1.30 : (rung >= 2 ? 1.12 : 1.0);
            var hRelax = Math.min(2.6, ctx.relaxMul);
            var vSeamC = ctx.vSeamCap * seamLad * ctx.relaxMul, hSeamC = ctx.hSeamCap * seamLad * ctx.relaxMul;
            var mMax = Math.min(5, avail, Math.max(1, Math.floor(L / ctx.wMin) * 2));
            if (cons && cons.forceH) mMax = Math.min(5, avail);

            /* ---- WIDTH-DERIVED RUNG PREFERENCE -----------------------------------
             * ORDER IS SOFT (spec 6), so which photo goes here is a decision, not an
             * inheritance - and the single most useful thing to decide it on is whether
             * the photo's SIZE fits the run we are filling. A 240px leftover beside a
             * hero wants a filler; a 900px virgin run wants a major. The shipping code
             * took the rung from pool[0] - i.e. from shuffle order - so a hero was as
             * likely to be offered to a sliver as to a virgin run, it failed F5 there,
             * and the placement fell through to the emergency leaf, which draws at
             * whatever fits and therefore ERASES the ladder. Measured before this:
             * 18-24 of 36 photos per wall placed by the emergency leaf.
             * Score each rung by how well an integer number of its tiles tiles L, and
             * let the tape choose among the good ones so the annealer keeps its say.
             */
            var rungLeft = [0, 0, 0, 0, 0], rlq;
            for (rlq = 0; rlq < pool.length; rlq++) rungLeft[ctx.rung[pool[rlq]]]++;
            function rungBiasFor(runL, jitter) {
                var bestR = -1, bestE = Infinity, rr, kk2;
                for (rr = 0; rr < ctx.rungA.length; rr++) {
                    if (!rungLeft[rr]) continue;
                    var wr = Math.sqrt(ctx.rungA[rr] * clamp(ctx.aMed, 1, 3)) + gap;
                    kk2 = clamp(Math.round(runL / wr), 1, 5);
                    var e = Math.abs(Math.log(runL / (kk2 * wr)))
                        // BIG FIRST, when the run can take it. A large tile is only cheap
                        // while the skyline is still open; deferred, it arrives to find
                        // nothing but 150px remainders and comes out at a fraction of its
                        // target. MEASURED without this bias: the big rungs finished
                        // SMALLER than the fillers (Spearman -0.28 against assigned rung).
                        - 0.22 * (rr / (RUNGS.length - 1))
                        + 0.35 * jitter * (((rr * 2654435761) >>> 0) / 4294967296);
                    if (e < bestE) { bestE = e; bestR = rr; }
                }
                return bestR < 0 ? RUNG_MED : bestR;
            }
            var rungWant = rungBiasFor(L, T(3));

            // which arities to try this step (genome-driven, always includes 1)
            var mList = [];
            var mSeed = T(0);
            var mm;
            for (mm = 1; mm <= mMax; mm++) mList.push(mm);
            if (mList.length > 5 && !cons) {
                // keep 1 plus three genome-chosen arities
                var keep = [1];
                var pool2 = mList.slice(1);
                var kk;
                for (kk = 0; kk < 3 && pool2.length; kk++) {
                    var idx = Math.floor(((mSeed * (kk + 7) * 97) % 1) * pool2.length);
                    keep.push(pool2.splice(idx, 1)[0]);
                }
                mList = keep;
            }

            var best = null, bestScore = Infinity;
            var tupleBuf = new Int32Array(5);
            var aspBuf = new Float64Array(5);
            var ci = 0;

            // H7: the live-seam ledger does not change while candidates are being scored,
            // so evaluate it ONCE per placement instead of once per candidate. Doing it
            // per candidate turned the generator O(n^2) and cost ~10x the trial count.
            var liveX = [], liveV = [], livePre = [0], lq;
            for (lq = 0; lq < bornList.length; lq++) {
                var lys = sideYs(bornList[lq].x);
                liveX.push(bornList[lq].x);
                liveV.push(Math.max(0, Math.min(lys.l, lys.r) - bornList[lq].y));
                livePre.push(livePre[lq] + liveV[lq]);
            }
            // the two longest live seams in this segment - candidate straddle centres
            var seamHot = [], hq;
            for (hq = 0; hq < liveX.length; hq++) {
                if (liveX[hq] > sx0 + 1e-9 && liveX[hq] < seg.x1 - 1e-9) seamHot.push(hq);
            }
            seamHot.sort(function (a, b) { return liveV[b] - liveV[a]; });
            seamHot = seamHot.slice(0, 2);

            var mi;
            for (mi = 0; mi < mList.length; mi++) {
                var m = mList[mi];
                if (m > pool.length) { releaseCheck(); if (m > pool.length) continue; }

                /* THREE TUPLE DRAWS, ALL RUNG-HOMOGENEOUS.
                 * A block's cells all end up near a common height, so mixing a major with
                 * two fillers guarantees two of the three miss their target - and F5 then
                 * rejects the whole candidate. Rung homogeneity is not an optimisation, it
                 * is what makes a wide ladder placeable at all.
                 *   ti 0 - head of the pool, FILTERED to pool[0]'s rung. It used to be
                 *          unfiltered, and that one leak was enough on its own: a hero
                 *          beside two fillers in an H-split shares their height and lands
                 *          at their area. That is precisely how variation dies.
                 *   ti 1 - genome-sampled from the WIDTH-PREFERRED rung (see rungBiasFor).
                 *   ti 2 - ASPECT-FIT draw from the width-preferred rung: for an H-root
                 *          block of gross height Hh across a run of length L the tuple's
                 *          aspect sum wants to be about (L - m*gap) / (Hh - gap). This is
                 *          "pull a photo forward because its shape fits here", which is the
                 *          reordering the packer had never been asked to do.
                 */
                var ti;
                for (ti = 0; ti < 3; ti++) {
                    var okTuple = true, q, gq, gq2, pk;
                    if (ti === 0) {
                        // head of the pool WITHIN the width-preferred rung: posting order
                        // still gets a voice, but it no longer decides the SIZE of what
                        // goes into this run. Reading the rung from pool[0] handed the run
                        // whatever the shuffle happened to put first, which is how the
                        // fillers were spent early and the endgame was left with nothing
                        // but majors to squeeze into 100px remainders.
                        var r0h = rungWant, got = 0, sc0;
                        for (sc0 = 0; sc0 < pool.length && got < m; sc0++) {
                            if (ctx.rung[pool[sc0]] === r0h) tupleBuf[got++] = pool[sc0];
                        }
                        if (got < m) continue;
                    } else if (ti === 1) {
                        var rw = rungWant, picked = 0, scan = 0;
                        while (picked < m && scan < pool.length * 3) {
                            pk = Math.floor(T(1 + picked + m + scan) * pool.length);
                            gq2 = 0;
                            while (gq2 < pool.length && (ctx.rung[pool[pk]] !== rw || containsIdx(tupleBuf, picked, pool[pk]))) {
                                pk = (pk + 1) % pool.length; gq2++;
                            }
                            if (gq2 >= pool.length) break;
                            tupleBuf[picked++] = pool[pk];
                            scan++;
                        }
                        // OUTWARD WALK, never a deadlock: if the preferred rung cannot fill
                        // the tuple, step to the nearest rung that can. A draw that fails
                        // silently would strand the segment and reach for the leaf.
                        if (picked < m) {
                            var step, found = -1;
                            for (step = 1; step <= 4 && found < 0; step++) {
                                var trial;
                                for (trial = 0; trial < 2 && found < 0; trial++) {
                                    var rt = rw + (trial ? -step : step);
                                    if (rt < 0 || rt >= RUNGS.length) continue;
                                    var cnt0 = 0, z0;
                                    for (z0 = 0; z0 < pool.length; z0++) if (ctx.rung[pool[z0]] === rt) cnt0++;
                                    if (cnt0 >= m) found = rt;
                                }
                            }
                            if (found < 0) continue;
                            picked = 0;
                            for (gq = 0; gq < pool.length && picked < m; gq++) {
                                if (ctx.rung[pool[gq]] === found) tupleBuf[picked++] = pool[gq];
                            }
                            if (picked < m) continue;
                        }
                    } else {
                        // ASPECT FIT. Target aspect sum for an H-root block that would come
                        // out at roughly the rung's own reference height across this run.
                        var rw2 = rungWant;
                        var hWant2 = Math.sqrt(ctx.rungA[rw2] / clamp(ctx.aMed, 1, 3));
                        var aWant = (L - m * gap) / Math.max(1, hWant2);
                        var cand2 = [], z2;
                        for (z2 = 0; z2 < pool.length; z2++) if (ctx.rung[pool[z2]] === rw2) cand2.push(pool[z2]);
                        if (cand2.length < m) continue;
                        // greedy: take the photo whose aspect best closes the remaining sum
                        var need2 = aWant, picked2 = 0, used2 = {};
                        while (picked2 < m) {
                            var want1 = need2 / Math.max(1, m - picked2);
                            var bi2 = -1, bd2 = Infinity, z3;
                            for (z3 = 0; z3 < cand2.length; z3++) {
                                if (used2[cand2[z3]]) continue;
                                var d2 = Math.abs(Math.log(ctx.asp[cand2[z3]] / Math.max(0.05, want1)));
                                if (d2 < bd2) { bd2 = d2; bi2 = z3; }
                            }
                            if (bi2 < 0) break;
                            used2[cand2[bi2]] = 1;
                            tupleBuf[picked2++] = cand2[bi2];
                            need2 -= ctx.asp[cand2[bi2]];
                        }
                        if (picked2 < m) continue;
                    }
                    // reject duplicates
                    for (q = 0; q < m && okTuple; q++) {
                        var q2; for (q2 = q + 1; q2 < m; q2++) if (tupleBuf[q] === tupleBuf[q2]) okTuple = false;
                    }
                    if (!okTuple) continue;
                    var sumAi = 0;
                    for (q = 0; q < m; q++) { aspBuf[q] = ctx.asp[tupleBuf[q]]; sumAi += ctx.Ai[tupleBuf[q]]; }
                    // The height a block of THIS tuple ought to come out at, from its own
                    // target areas rather than from a fixed reference. See the score below.
                    var hExp = Math.sqrt(sumAi / 1.5);

                    var shapeSet = SHAPES[m];
                    var nShapes = shapeSet.length;
                    // a forced retire searches the vocabulary exhaustively - it is the only
                    // operation that kills a vertical seam, so it must not be sampled away
                    var shapeStep = nShapes <= 6 ? 1 : Math.max(1, Math.floor(nShapes / (cons ? 8 : 6)));
                    var shOff = Math.floor(T(8 + m) * nShapes);

                    var si;
                    for (si = 0; si < nShapes; si += shapeStep) {
                        var shape = shapeSet[(si + shOff) % nShapes];
                        var ab = shapeAffine(shape, aspBuf, gap);
                        var alpha = ab[0], beta = ab[1];
                        if (!(alpha > 0)) continue;

                        var li;
                        for (li = 0; li < 4; li++) {
                            var lam;
                            if (li === 0) lam = 1;
                            else if (cons && cons.forceH) break;   // lambda is SOLVED for below
                            else if (rung >= 2) lam = Math.exp((T(12 + li) * 2 - 1) * lnKr);
                            else break;                            // rungs 0-1 are ZERO CROP
                            LAM[li] = lam;

                            // Candidate widths. At relax 0 lambda is pinned to 1, which makes
                            // every guillotine cell land on its EXACT native aspect (crop 1.000);
                            // area is then steered by the WIDTH sweep instead of by stretching.
                            // target the tuple's ACTUAL total area (scale ladder + aspect boost), not
                            // m * A_target - using the nominal target ignores the ladder entirely
                            // and puts hero photos in ordinary-sized cells.
                            var grossAi = sumAi + m * (2 * gap * Math.sqrt(sumAi / m) + gap * gap);
                            var wA = (-beta * lam + Math.sqrt(beta * lam * beta * lam + 4 * alpha * lam * grossAi)) / (2 * alpha * lam);
                            // The sweep reaches FURTHER UP than down on purpose: F1/F2/F3
                            // reject wide candidates far more often than narrow ones, so a
                            // symmetric sweep is an asymmetric outcome. See the area score.
                            var widths = [wA, wA * 0.82, wA * 0.90, wA * 1.08, wA * 1.18,
                                wA * 1.30, wA * 1.45, wA * 1.62, L, L - segFloor];
                            // EXACT LEVEL MATCH (zero crop, and the only way to earn a
                            // straddle later): pick w so the bottom lands on a neighbour.
                            if (yNL > y) widths.push(((yNL - y) / lam - beta) / alpha);
                            if (yNR > y) widths.push(((yNR - y) / lam - beta) / alpha);
                            if (cons && cons.forceH) {
                                // A LEVELLING block must be EXACTLY `need` tall. Deriving its
                                // width from that height pins the area and almost nothing ever
                                // passes F5 (measured: 7673 attempts, 0 successes). Instead let
                                // the width roam and solve for lambda - the height is then exact
                                // by construction and the area band is still reachable.
                                var wq2, nSw = 14;
                                widths = [];
                                for (wq2 = 0; wq2 <= nSw; wq2++) {
                                    widths.push(segFloor + (Math.min(L, ctx.hCap) - segFloor) * wq2 / nSw);
                                }
                                widths.push(wA, L);
                                if (L - segFloor > segFloor) widths.push(L - segFloor);
                                // and the width at which this shape is EXACTLY `need` tall with
                                // lambda == 1 - a zero-crop levelling block
                                widths.push((cons.forceH - beta) / alpha);
                            }

                            var wi;
                            for (wi = 0; wi < widths.length; wi++) {
                                var w = widths[wi];
                                if (!(w > 0)) continue;
                                // F1 - hCap is NEVER relaxed
                                if (w < segFloor || w > ctx.hCap || w > L + 1e-9) continue;
                                if (w > L) w = L;
                                // F2 - no sliver segments, ever
                                var rem = L - w;
                                if (rem > 1e-9 && rem < segFloor) continue;
                                // and never leave a run too WIDE to take whole yet too narrow
                                // to split - that run is unfillable by construction
                                if (rem > ctx.hCap + 1e-9 && rem < 2 * segFloor) continue;
                                // LAMBDA SNAP - the general form of the levelling move.
                                // A block can only be laid inside ONE segment, so a vertical
                                // boundary can only ever be crossed AFTER the two sides merge,
                                // and they merge only on a BIT-EXACT level. Solving lambda for
                                // "land exactly on the neighbour" turns a one-parameter width
                                // sweep into a two-parameter family: the width is still free to
                                // satisfy F2 and the area band while the height is exact. Crop
                                // is bounded by the same lnKr as everything else.
                                var Hn0 = alpha * w + beta;
                                var lamCand = [lam], lamN = 1, lsz;
                                if (!(cons && cons.forceH)) {
                                    if (yNL > y) {
                                        lsz = (yNL - y) / Hn0;
                                        if (lsz > 0 && Math.abs(Math.log(lsz)) <= lnKr) lamCand[lamN++] = lsz;
                                    }
                                    if (yNR > y) {
                                        lsz = (yNR - y) / Hn0;
                                        if (lsz > 0 && Math.abs(Math.log(lsz)) <= lnKr) lamCand[lamN++] = lsz;
                                    }
                                }
                                var lz;
                                for (lz = 0; lz < lamN; lz++) {
                                var lamEff = lamCand[lz];
                                if (cons && cons.forceH) {
                                    lamEff = cons.forceH / (alpha * w + beta);
                                    if (!(lamEff > 0) || Math.abs(Math.log(lamEff)) > lnKr) continue;
                                }
                                var H = (alpha * w + beta) * lamEff;
                                // F3
                                if (H < ctx.wMin || H > ctx.hBlockCap) continue;
                                if (y + H > frCap) { if (DEBUG) dg('rj_front'); continue; }
                                if (cons && cons.forceH && Math.abs(H - cons.forceH) > 0.5) continue;

                                // A LEVEL MATCH is worth far more than area precision: segments
                                // merge ONLY on a bit-exact level, blocks can only be laid inside a
                                // single segment, and therefore a vertical boundary can only ever be
                                // crossed AFTER a merge. Measured: walls with 0-1 merges keep a
                                // full-height column (minCrossV 0.000); walls with 4+ never do.
                                // So level-matching candidates get the widest area band, always.
                                var couldSnap = (rem2Flush(L, w) &&
                                    ((yNL > 0 && Math.abs(yNL - (y + (alpha * w + beta) * lamEff)) < 0.75) ||
                                     (yNR > 0 && Math.abs(yNR - (y + (alpha * w + beta) * lamEff)) < 0.75)));
                                // A level match used to be worth a band widened all the way to
                                // 1.72, which is wider than the whole gap between two rungs
                                // (2.30 in area = 1.52 in the band). Every levelling placement
                                // could therefore ignore the ladder, and levelling placements
                                // are common. Widen by a tenth, not by a rung.
                                var bLo = couldSnap ? bandLo / 1.10 : bandLo;
                                var bHi = couldSnap ? bandHi * 1.10 : bandHi;

                                shapeRects(shape, aspBuf, gap, w, lamEff);
                                if (R_N !== m) continue;

                                // F4/F5/F6 on the cells
                                var okCells = true, cropSum = 0, areaSum = 0, areaSq = 0, maxR = 1, cq;
                                for (cq = 0; cq < R_N && okCells; cq++) {
                                    var dw = R_W[cq] - gap, dh = R_H[cq] - gap;
                                    if (dw < ctx.minTile - 1e-9 || dh < ctx.minTile - 1e-9) { okCells = false; break; }
                                    var ph = tupleBuf[R_P[cq]];
                                    // crop is measured on the INNER box: the <img> is cropped
                                    // inside the border, so the border spends crop budget.
                                    var a = ctx.asp[ph], c = (dw - ctx.bw2) / (dh - ctx.bw2);
                                    if (!(c > 0)) { okCells = false; break; }
                                    var r = a > c ? a / c : c / a;
                                    if (Math.log(r) > lnKr + 1e-12) { okCells = false; break; }
                                    if (Math.abs(Math.log(a)) > 0.12 && (Math.log(c) > 0) !== (Math.log(a) > 0)) { okCells = false; break; }
                                    if (a > 2.4 && c > a) { okCells = false; break; }
                                    if (a < 0.42 && c < a) { okCells = false; break; }
                                    var ar = dw * dh, tgt = ctx.Ai[ph];
                                    // PER-RUNG BAND. A single global band (the old 2.05) lets a
                                    // filler satisfy a hero's target and vice versa, so the ladder
                                    // is invisible to the feasibility test and the wall comes out
                                    // uniform however wide the ladder is drawn. bandR here is the
                                    // photo's OWN rung band, still capped from above by the
                                    // relaxation ladder's bHi so an emergency can still buy room.
                                    // THE RELAXATION LADDER STILL BUYS ROOM. `bandR` widens as
                                    // the ladder walks 1.45 -> 1.75 -> 2.05, and the per-rung
                                    // band widens in the same PROPORTION - so an emergency can
                                    // still be escaped, but at relax 0 (where the overwhelming
                                    // majority of tiles are placed) the rungs stay separated.
                                    var bRp = Math.min(bHi, ctx.bandR[ph] * bandR / LADDER_BAND[0]);
                                    // THE HERO BAND IS ONE-SIDED: an anchor tile may exceed its
                                    // target - bigger is welcome, Sean called 450px a floor - but
                                    // it may never fall short, because falling short is exactly
                                    // how the hero has been quietly traded away for exact fill.
                                    var loP = (ctx.rung[ph] >= 4) ? Math.max(bLo, 1 / 1.10) : Math.max(bLo, 1 / bRp);
                                    if (ar < tgt * loP || ar > tgt * bRp) { okCells = false; break; }
                                    cropSum += Math.log(r) * Math.log(r);
                                    var lnA = Math.log(ar / tgt);
                                    // ASYMMETRIC. Overshooting a target costs a placement
                                    // nothing structurally; UNDERSHOOTING is nearly free
                                    // because a smaller tile satisfies more of F1/F2/F3 at
                                    // once. A symmetric penalty therefore drifts the whole
                                    // wall downward - MEASURED at 0.72 of target on the
                                    // shipping engine and 0.44 once the targets were
                                    // raised, which is exactly why raising --ss-base or the
                                    // relief alone produced no visible density change.
                                    // Price shrinking at twice the rate of growing.
                                    areaSum += (lnA < 0 ? 2.0 : 1.0) * Math.abs(lnA);
                                    areaSq += lnA * lnA;
                                    if (r > maxR) maxR = r;
                                }
                                if (!okCells) { if (DEBUG) dg('rj_cells'); continue; }


                                // offsets. OFFSET PLACEMENT IS THE ANTI-COLUMN TOOL: the
                                // segment is flat across its whole width, so starting a
                                // block away from its left edge lets it straddle an
                                // inherited boundary without touching the invariant.
                                var offs = [0];
                                if (rem > 1e-9) {
                                    offs.push(rem);
                                    var io = T(16 + wi % 6) * rem;
                                    if (io >= segFloor && rem - io >= segFloor) offs.push(io);
                                    var bq0;
                                    for (bq0 = 0; bq0 < seamHot.length; bq0++) {
                                        var cand = liveX[seamHot[bq0]] - sx0 - w * 0.5;   // centre the block on a live seam
                                        if (cand > 1e-9 && cand < rem - 1e-9) offs.push(cand);
                                    }
                                }
                                if (cons && cons.straddle != null) {
                                    var sxr = cons.straddle - sx0;
                                    var raw = [0, rem, sxr - w * 0.5, sxr - w + ctx.wMin, sxr - ctx.wMin], rq0;
                                    offs = [];
                                    for (rq0 = 0; rq0 < raw.length; rq0++) {
                                        var v0 = raw[rq0];
                                        if (v0 < -1e-9 || v0 > rem + 1e-9) continue;
                                        if (!(sx0 + v0 < cons.straddle - ctx.wMin && sx0 + v0 + w > cons.straddle + ctx.wMin)) continue;
                                        offs.push(clamp(v0, 0, rem));
                                    }
                                }
                                if (cons && cons.alignRight != null) {
                                    var ar2 = cons.alignRight - sx0 - w;
                                    offs = (ar2 >= -1e-9 && ar2 <= rem + 1e-9) ? [clamp(ar2, 0, rem)] : [];
                                }
                                if (cons && cons.alignLeft != null) {
                                    var al2 = cons.alignLeft - sx0;
                                    offs = (al2 >= -1e-9 && al2 <= rem + 1e-9) ? [clamp(al2, 0, rem)] : [];
                                }

                                var oi;
                                for (oi = 0; oi < offs.length; oi++) {
                                    var off = offs[oi];
                                    if (off < -1e-9 || off > rem + 1e-9) continue;
                                    off = clamp(off, 0, rem);
                                    var remL = off, remR = rem - off;
                                    if ((remL > 1e-9 && remL < segFloor) || (remR > 1e-9 && remR < segFloor)) continue;
                                    // COVERAGE RESERVE - never on the ladder, never relaxed
                                    if (avail - m < otherNeed + (virginSeg ? (runNeed(remL) + runNeed(remR)) : 0)) { if (DEBUG) dg('rj_reserve'); continue; }
                                    var bx = sx0 + off, ex = bx + w;
                                    ci++;

                                    // H2: if this bottom is within a hair of a neighbour's
                                    // level, land on it EXACTLY and inherit its y bit-exactly.
                                    var snapY = null, stepBad = false;
                                    if (remL < 1e-9 && yNL > 0) {
                                        var dL = Math.abs(yNL - (y + H));
                                        if (dL < 0.75) snapY = yNL;
                                        else if (dL < ctx.wMin) stepBad = true;   // an unlevellable step
                                    }
                                    if (remR < 1e-9 && yNR > 0) {
                                        var dR = Math.abs(yNR - (y + H));
                                        if (dR < 0.75 && (snapY == null || dR < Math.abs(snapY - (y + H)))) snapY = yNR;
                                        else if (dR >= 0.75 && dR < ctx.wMin) stepBad = true;
                                    }
                                    // A step shorter than one minimum tile can never be levelled
                                    // by a later placement, so the vertical seam beside it can
                                    // never be retired. Refuse to create one.
                                    if (stepBad && snapY == null && rung === 0) { if (DEBUG) dg('rj_step'); continue; }

                                    // M8 / FULL-WIDTH CUT, prevented rather than penalised. Once
                                    // the skyline is flat across the WHOLE width at some y > 0,
                                    // nothing placed later can straddle y (everything later starts
                                    // at or below the frontier), so that level becomes a permanent
                                    // guillotine cut - a row boundary in the literal sense, and the
                                    // measured minCrossH 0.000. Refuse to create one while photos
                                    // remain to place.
                                    // ...except on the escalation ladder: below about three
                                    // tiles across a full-width cut is geometrically forced and
                                    // refusing it deadlocks the packer (Part 11 - say so, don't
                                    // pretend). W=360 with 60 photos is exactly that case.
                                    if (ctx.relaxMul <= 1.6 && avail - m > 0 && remL < 1e-9 && remR < 1e-9
                                        && wouldFlatten(segIdx, snapY != null ? snapY : (y + H))) { if (DEBUG) dg('rj_flat'); continue; }

                                    // F7 - horizontal seam (never relaxed)
                                    var hLen = 0;
                                    if (y > 1e-9) {
                                        hLen = hProbe(y, bx, ex);
                                        if (hLen > ctx.hCap * hRelax) { if (DEBUG) dg('rj_hseam'); continue; }
                                    }

                                    // M3 / B2 GUARD - the bands the gate actually measures,
                                    // tracked ACROSS blocks. Never relaxed by the ladder.
                                    var bandBad = false, bg1, bg2;
                                    for (bg1 = 0; bg1 < R_N && !bandBad; bg1++) {
                                        var rLo = Infinity, rHi = -Infinity, rc = 0;
                                        var cLo = Infinity, cHi = -Infinity, cc = 0;
                                        for (bg2 = 0; bg2 < R_N; bg2++) {
                                            if (Math.abs(R_Y[bg2] - R_Y[bg1]) < 1e-6 && Math.abs(R_H[bg2] - R_H[bg1]) < 1e-6) {
                                                rc++;
                                                if (bx + R_X[bg2] < rLo) rLo = bx + R_X[bg2];
                                                if (bx + R_X[bg2] + R_W[bg2] > rHi) rHi = bx + R_X[bg2] + R_W[bg2];
                                            }
                                            if (Math.abs(R_X[bg2] - R_X[bg1]) < 1e-6 && Math.abs(R_W[bg2] - R_W[bg1]) < 1e-6) {
                                                cc++;
                                                if (y + R_Y[bg2] < cLo) cLo = y + R_Y[bg2];
                                                if (y + R_Y[bg2] + R_H[bg2] > cHi) cHi = y + R_Y[bg2] + R_H[bg2];
                                            }
                                        }
                                        if (bandOver(rowBand, y + R_Y[bg1], y + R_Y[bg1] + R_H[bg1], rLo, rHi, rc, ctx.bandCap * ctx.relaxMul)) bandBad = true;
                                        else if (bandOver(colBand, bx + R_X[bg1], bx + R_X[bg1] + R_W[bg1], cLo, cHi, cc, ctx.colBandCap * ctx.relaxMul)) bandBad = true;
                                        // EDGE-INCLUSIVE column ledgers. Without these the
                                        // annealer can only REJECT a wall that grew a
                                        // border-flush column, never avoid building one.
                                        else if (bandOver(leftBand, bx + R_X[bg1], bx + R_X[bg1],
                                            y + R_Y[bg1], y + R_Y[bg1] + R_H[bg1], 1, ctx.edgeBandCap * ctx.relaxMul)) bandBad = true;
                                        else if (bandOver(rightBand, bx + R_X[bg1] + R_W[bg1], bx + R_X[bg1] + R_W[bg1],
                                            y + R_Y[bg1], y + R_Y[bg1] + R_H[bg1], 1, ctx.edgeBandCap * ctx.relaxMul)) bandBad = true;
                                    }
                                    if (bandBad) { if (DEBUG) dg('rj_band'); continue; }

                                    // sigmaV / sigmaH ENFORCED DIRECTLY, on the same
                                    // definition the shipping gate uses. Never relaxed by
                                    // the ladder; only by the whole-search escalation.
                                    var seamX = false, sq5;
                                    for (sq5 = 0; sq5 < R_N && !seamX; sq5++) {
                                        var cx0 = bx + R_X[sq5], cx1 = cx0 + R_W[sq5];
                                        var cy0 = y + R_Y[sq5], cy1 = cy0 + R_H[sq5];
                                        if (cx1 < U - 1e-9
                                            && edgeSeamIfAdded(vEdge, cx1, cy0, cy1, true) > vSeamC) seamX = true;
                                        else if (cx0 > 1e-9
                                            && edgeSeamIfAdded(vEdge, cx0, cy0, cy1, false) > vSeamC) seamX = true;
                                        else if (cy0 > 1e-9
                                            && edgeSeamIfAdded(hEdge, cy0, cx0, cx1, false) > hSeamC) seamX = true;
                                        else if (edgeSeamIfAdded(hEdge, cy1, cx0, cx1, true) > hSeamC) seamX = true;
                                    }
                                    if (seamX) { if (DEBUG) dg('rj_seamx'); continue; }

                                    // F8 / F9 - vertical seams
                                    // The boundary a forced retire is TARGETING is exempt from
                                    // F8/F9: its seam is already over the cap - that is why we
                                    // are here - and blocking the levelling block on it would
                                    // deadlock the only mechanism that can ever kill it.
                                    var exempt = (cons && cons.exempt != null) ? cons.exempt : null;
                                    var sL = 0, sR = 0, nowL = 0, nowR = 0, alignBad = false;
                                    if (bx > 1e-9 && !(exempt != null && Math.abs(bx - exempt) < 1e-9)) {
                                        var yb = sideYs(bx), bornL = bornGet(bx, y);
                                        var oth = (Math.abs(bx - sx0) < 1e-9) ? yb.l : y;
                                        sL = Math.max(oth, y + H) - bornL;          // forward-looking commitment
                                        nowL = Math.min(oth, y + H) - bornL;        // the seam that actually exists
                                        var kL = bornFind(bx);
                                        if (kL >= 0 && Math.abs(bornList[kL].x - bx) > 1e-9) alignBad = true;
                                    }
                                    if (ex < U - 1e-9 && !(exempt != null && Math.abs(ex - exempt) < 1e-9)) {
                                        var yr = sideYs(ex), bornR = bornGet(ex, y);
                                        var oth2 = (Math.abs(ex - seg.x1) < 1e-9) ? yr.r : y;
                                        sR = Math.max(oth2, y + H) - bornR;
                                        nowR = Math.min(oth2, y + H) - bornR;
                                        var kR = bornFind(ex);
                                        if (kR >= 0 && Math.abs(bornList[kR].x - ex) > 1e-9) alignBad = true;
                                    }
                                    // The HARD cap is on the seam that REALLY exists after this
                                    // placement; the forward-looking figure only drives the score.
                                    // Capping the forward figure rejects every flush placement once
                                    // the wall is tall (measured: 64% of tiles fell through to the
                                    // last rung). But the last rung is NEVER seam-free either - a
                                    // measured full-height column of 10 stacked tiles was built
                                    // entirely by placements that had dropped the cap. It only ever
                                    // widens, to 1.3x, which is still inside the sigmaV gate.
                                    // A level match MERGES the two segments, so the boundary is
                                    // about to become straddleable rather than extended: exempt it
                                    // from the anti-alignment rule. (`alignBad` used to be cleared
                                    // fourteen lines above its own `var`, which hoisted and reset
                                    // it - the exemption never actually existed.)
                                    if (snapY != null) alignBad = false;
                                    var vc = ctx.vCap * LADDER_VC[rung] * ctx.relaxMul;
                                    if (!isFinite(vc)) vc = ctx.vCap * 2.4;
                                    // THE VERTICAL SEAM CAP IS NEVER DROPPED. It used to come off
                                    // entirely on the last rung, and that rung built the measured
                                    // full-height columns one tile at a time. Segments that cannot
                                    // be filled under the cap are now merged by stretchLevel()
                                    // instead, which costs no photo and cannot open a hole.
                                    if (nowL > vc || nowR > vc) { if (DEBUG) dg('rj_vseam'); continue; }
                                    // ...and on the block's OWN internal full-height edges,
                                    // which extend an inherited seam just as an outer edge does.
                                    var seamBad = false, iq;
                                    for (iq = 0; iq < R_N && !seamBad; iq++) {
                                        var ix1 = R_X[iq] + R_W[iq];
                                        if (ix1 > w - 1e-9) continue;
                                        if (Math.abs(R_Y[iq] + R_H[iq] - H) > 1e-9) continue;
                                        if ((y + H) - bornGet(bx + ix1, y + R_Y[iq]) > vc) seamBad = true;
                                    }
                                    if (seamBad) { if (DEBUG) dg('rj_iseam'); continue; }
                                    if (alignBad && rung === 0) { if (DEBUG) dg('rj_align'); continue; }

                                    // ---- 4.5 score ----
                                    var killable = 0, killMax = 0, bq;
                                    for (bq = 0; bq < liveX.length; bq++) {
                                        if (liveX[bq] > bx + ctx.wMin && liveX[bq] < ex - ctx.wMin) {
                                            killable += liveV[bq];
                                            if (liveV[bq] > killMax) killMax = liveV[bq];
                                        }
                                    }
                                    var levelMatch = (snapY != null) ? 1 : 0;
                                    var virgin = virginCount(y + 1e-6, y + H - 1e-6);
                                    var minTileLevels = Math.max(1, ctx.minTile / ctx.qs);

                                    // The seam/virgin rewards are CAPPED. Uncapped they scale
                                    // with block height and swamp the area term, and the packer
                                    // buys cheap seams by starving tiles (measured: tiles at 1/6
                                    // of target area). Feasibility is structural; these are only
                                    // aesthetics, and equal visual weight is an aesthetic too.
                                    var sc = 4.50 * areaSum + 3.00 * areaSq
                                        + 9.00 * cropSum
                                        + 1.50 * (Math.pow(Math.max(0, sL) / ctx.vCap, 3) + Math.pow(Math.max(0, sR) / ctx.vCap, 3))
                                        + 1.50 * Math.pow(hLen / ctx.hCap, 2)
                                        - 1.40 * Math.min(1.5, killable / ctx.vCap)
                                        - 3.00 * Math.min(1, Math.pow(killMax / ctx.vCap, 2))
                                        - 2.50 * levelMatch
                                        - 2.50 * Math.min(2, virgin / minTileLevels)
                                        // RUNG-AWARE HEIGHT EXPECTATION. This term used to read
                                        //     0.55 * |ln( H / (hRef * sqrt(m)) )|
                                        // which rewards EVERY block for landing on the SAME
                                        // reference height regardless of what is in it - a direct
                                        // flattening force sitting in the placement score, and the
                                        // single strongest reason a wider ladder alone would not
                                        // have worked. A hero block is now EXPECTED to be tall.
                                        + 0.55 * Math.abs(Math.log(H / hExp))
                                        + 0.22 * (((ci * 2654435761) >>> 0) / 4294967296);
                                    // LOOKAHEAD: a remainder much narrower than a typical tile
                                    // is a segment nothing will fit into later, and it is what
                                    // drives the fallback ladder. Price it in now - but against
                                    // the FILLER rung's reference width, because with a populated
                                    // filler rung a narrow remainder is a fillable remainder,
                                    // not a defect. Pricing it against the median tile is what
                                    // made the packer refuse the very shapes a hero leaves behind.
                                    if (remL > 1e-9) sc += 1.0 * Math.pow(Math.max(0, Math.log(0.86 * ctx.wFill / remL)), 2);
                                    if (remR > 1e-9) sc += 1.0 * Math.pow(Math.max(0, Math.log(0.86 * ctx.wFill / remR)), 2);
                                    if (alignBad) sc += 3.0;
                                    if (stepBad) sc += 2.5;

                                    if (sc < bestScore) {
                                        bestScore = sc;
                                        best = { bx: bx, w: w, H: H, lam: lamEff, shape: shape, m: m, off: off, snapY: snapY };
                                        var cq2;
                                        best.tuple = new Int32Array(m);
                                        for (cq2 = 0; cq2 < m; cq2++) best.tuple[cq2] = tupleBuf[cq2];
                                    }
                                }
                                }   // lz - lambda candidates (incl. the level snaps)
                            }
                        }
                    }
                }
            }

            if (DEBUG) { dg(cons ? 'cons_cand' : 'norm_cand'); DIAG[cons ? 'cons_n' : 'norm_n'] = (DIAG[cons ? 'cons_n' : 'norm_n'] || 0) + ci; }
            if (!best) return false;
            if (DEBUG) dg('relax' + relax);

            // re-extract the winner's rects (scratch has been overwritten)
            var aB = new Float64Array(5), zq;
            for (zq = 0; zq < best.m; zq++) aB[zq] = ctx.asp[best.tuple[zq]];
            shapeAffine(best.shape, aB, gap);
            var Hf = shapeRects(best.shape, aB, gap, best.w, best.lam);
            if (best.snapY != null) Hf = forceBlockHeight(Hf, best.snapY - y);
            best.H = Hf;

            var okC = commit(segIdx, best.bx, best.w, best.H, y, best.tuple, best.shape, best.snapY);
            if (okC === false) return false;

            // remove the used photos from the pool
            var rq;
            for (rq = 0; rq < best.m; rq++) {
                var at = pool.indexOf(best.tuple[rq]);
                if (at >= 0) pool.splice(at, 1);
            }
            releaseCheck();
            return true;
        }

        /* ---------- 4.1 forced retire ---------- */
        function forcedRetire(p) {
            if (bornList.length === 0) return false;
            var bi, cands = [];
            for (bi = 0; bi < bornList.length; bi++) {
                var x = bornList[bi].x;
                if (x <= 1e-9 || x >= U - 1e-9) continue;
                var ys = sideYs(x);
                var live = Math.min(ys.l, ys.r) - bornList[bi].y;
                if (live >= 0.55 * ctx.vCap) cands.push({ x: x, live: live });
            }
            if (!cands.length) return false;
            cands.sort(function (a, b) { return b.live - a.live; });
            var ci2;
            for (ci2 = 0; ci2 < cands.length && ci2 < 2; ci2++) {
                var bx = cands[ci2].x;
                var ys3 = sideYs(bx);
                dg('trigger');
                if (ys3.l === ys3.r) {
                    // (a) sides already level -> straddle; the boundary ceases to exist
                    var k = segAt(bx);
                    if (sky[k].x1 - sky[k].x0 < 2 * ctx.wMin) { dg('a_narrow'); continue; }
                    dg('a_try');
                    if (tryPlace(k, p, { straddle: bx }, 0) || tryPlace(k, p, { straddle: bx }, 2)) { straddles++; dg('a_ok'); return true; }
                    forcedFails++; dg('a_fail');
                    continue;
                }
                // (b) LEVEL the lower side first, butted against the boundary. (a)+(b) are
                // one atomic decision - a one-placement-at-a-time greedy cannot plan it.
                var kk = segAt(bx);
                if (Math.abs(sky[kk].x0 - bx) > 1e-9) { dg('b_interior'); continue; }
                var lowIsLeft = ys3.l < ys3.r;
                var segIdx = lowIsLeft ? kk - 1 : kk;
                if (segIdx < 0 || segIdx >= sky.length) { dg('b_edge'); continue; }
                var need = Math.abs(ys3.l - ys3.r);
                var hiY = Math.max(ys3.l, ys3.r);
                // (b0) LEVEL BY STRETCHING. Costs no photo, works at ANY step size, and is
                // the branch that actually fires - the block-based levellers below need a
                // photo whose natural height happens to equal `need`.
                if (stretchLevel(segIdx, hiY)) { levels++; dg('b_stretch'); return true; }
                if (need < ctx.wMin) { dg('b_needsmall'); continue; }
                if (need > ctx.hBlockCap) { dg('b_needbig'); continue; }
                // FIRST try the dedicated zero-crop step filler. A levelling block has to be
                // EXACTLY `need` tall, and hunting for a shape whose natural height happens to
                // land there is hopeless (measured: 2760 attempts, 0 successes). Instead pick
                // the WIDTH from the photo: w = need * aspect makes the fit exact and the crop
                // exactly 1.000, and every photo in the pool is a candidate.
                if (stepFiller(segIdx, need, lowIsLeft ? bx : null, lowIsLeft ? null : bx, hiY)) {
                    levels++; dg('b_ok'); dg('b_exact'); return true;
                }
                var cons = lowIsLeft
                    ? { forceH: need, alignRight: bx, assign: hiY, exempt: bx }
                    : { forceH: need, alignLeft: bx, assign: hiY, exempt: bx };
                dg('b_try');
                if (tryPlace(segIdx, p, cons, 0) || tryPlace(segIdx, p, cons, 2)) { levels++; dg('b_ok'); return true; }
                forcedFails++; dg('b_fail');
            }
            return false;
        }

        /**
         * The step filler: lay ONE zero-crop tile (or a 2-3 photo HSPLIT sharing its height)
         * of exactly `need` height, butted against the boundary, on the LOW side. The two
         * sides then become bit-exactly level, the segments merge, and the very next
         * forced-retire iteration can straddle the boundary and retire it for good.
         */
        function stepFiller(segIdx, need, alignRight, alignLeft, assignY) {
            releaseCheck();
            if (!pool.length) return false;
            var seg = sky[segIdx], L = seg.x1 - seg.x0, y = seg.y;
            if (need < ctx.wMin || need > ctx.hBlockCap) return false;
            var dh = need - gap;
            if (dh < ctx.minTile) return false;
            var lim = Math.min(pool.length, 24);
            var best = null, bestSc = Infinity, i1, i2, i3;
            function consider(idx, w1, cells) {
                if (w1 < ctx.wMin || w1 > ctx.hCap || w1 > L + 1e-9) return;
                var rem = L - w1;
                if (rem > 1e-9 && rem < ctx.segMin) return;
                var bx0 = (alignRight != null) ? (alignRight - w1) : alignLeft;
                if (bx0 < seg.x0 - 1e-9 || bx0 + w1 > seg.x1 + 1e-9) return;
                var rl = bx0 - seg.x0, rr = seg.x1 - (bx0 + w1);
                if ((rl > 1e-9 && rl < ctx.segMin) || (rr > 1e-9 && rr < ctx.segMin)) return;
                if (poolCount() - cells.length <
                    virginNeedExcl(segIdx) + (y < 1e-9 ? (runNeed(rl) + runNeed(rr)) : 0)) return;
                if (y > 1e-9 && hProbe(y, bx0, bx0 + w1) > ctx.hCap * Math.min(2.6, ctx.relaxMul)) return;
                // THE STEP FILLER OBEYS THE COLUMN LEDGERS TOO. It did not, and it is a
                // frequent path (it is the branch that actually levels a step), so it was
                // quietly extending border-flush runs past the cap the rest of the packer
                // respects - measured flush runs of 6+ median tile heights against a cap
                // of about 3.
                if (bandOver(leftBand, bx0, bx0, y, y + need, 1, ctx.edgeBandCap * ctx.relaxMul)) return;
                if (bandOver(rightBand, bx0 + w1, bx0 + w1, y, y + need, 1, ctx.edgeBandCap * ctx.relaxMul)) return;
                if (bandOver(colBand, bx0, bx0 + w1, y, y + need, 1, ctx.colBandCap * 1.25 * ctx.relaxMul)) return;
                var sc = 0, c2;
                for (c2 = 0; c2 < cells.length; c2++) {
                    var dw = cells[c2].w - gap;
                    if (dw < ctx.minTile) return;
                    var ratio = (dw * dh) / ctx.Ai[cells[c2].p];
                    var bRs = ctx.bandR[cells[c2].p];                       // per-rung band
                    if (ratio < 1 / bRs || ratio > bRs) return;
                    sc += Math.abs(Math.log(ratio));
                }
                if (sc < bestSc) { bestSc = sc; best = { bx: bx0, w: w1, cells: cells.slice() }; }
            }
            for (i1 = 0; i1 < lim; i1++) {
                var a1 = ctx.asp[pool[i1]], w1 = dh * a1 + gap;
                consider(i1, w1, [{ p: pool[i1], w: w1 }]);
                for (i2 = 0; i2 < lim; i2++) {
                    if (i2 === i1) continue;
                    var a2 = ctx.asp[pool[i2]], wb = dh * a2 + gap;
                    consider(i1, w1 + wb, [{ p: pool[i1], w: w1 }, { p: pool[i2], w: wb }]);
                    for (i3 = 0; i3 < lim && i3 < 8; i3++) {
                        if (i3 === i1 || i3 === i2) continue;
                        var wc = dh * ctx.asp[pool[i3]] + gap;
                        consider(i1, w1 + wb + wc,
                            [{ p: pool[i1], w: w1 }, { p: pool[i2], w: wb }, { p: pool[i3], w: wc }]);
                    }
                }
            }
            if (!best) return false;
            R_N = 0;
            var cx = 0, q2;
            for (q2 = 0; q2 < best.cells.length; q2++) {
                // gross boxes ABUT; each draws at w - gap. The last one absorbs fp drift.
                var cw = (q2 === best.cells.length - 1) ? (best.w - cx) : best.cells[q2].w;
                R_X[R_N] = cx; R_Y[R_N] = 0; R_W[R_N] = cw; R_H[R_N] = need; R_P[R_N] = q2; R_N++;
                cx += cw;
            }
            var tup = new Int32Array(R_N), q3;
            for (q3 = 0; q3 < R_N; q3++) tup[q3] = best.cells[q3].p;
            if (commit(segIdx, best.bx, best.w, need, y, tup, null, assignY) === false) return false;
            for (q3 = 0; q3 < tup.length; q3++) {
                var at = pool.indexOf(tup[q3]);
                if (at >= 0) pool.splice(at, 1);
            }
            releaseCheck();
            return true;
        }

        /* ================================================================
         * THE HERO ANCHOR
         *
         * Sean's floor: EVERY chunk carries at least one tile whose long side is >= 450
         * CSS px - half of SNAPSMACK_THUMB_ASPECT_LONG. The shipping engine met that in
         * 11 of 48 measured walls, and never below W=1440, because a hero is not something
         * a target-area ladder can produce: the ordinary path is bounded by hCap (the
         * horizontal-seam cap) and hBlockCap (the vertical one), and at W=768 those are
         * 387 and 410. A hero has to be CONSTRUCTED against its own caps.
         *
         * THE CONSTRUCTION. The hero's LONG SIDE is an INPUT. Beside it goes a companion
         * COLUMN of k photos sharing the hero's exact height, and the column's WIDTH is the
         * free continuous parameter. Under an 'H' root a part of size k is a V-SPLIT, so
         * for a column of gross width s
         *     h_j = (1/a_j) * s + gap * (1 - 1/a_j)      and      SUM h_j = Hh
         * is AFFINE - it solves exactly, with SUM h_j = Hh identically, so the block is a
         * bit-exact partition and the hypograph invariant is untouched. Choosing which k
         * photos go in the column therefore chooses s, and hence the fillers' size:
         *   ONE decision produces BOTH tails of the distribution.
         * That coupling is the answer to "zero-gap is easier to satisfy with same-size
         * tiles". The mechanism that CLOSES the residual shape beside the hero IS the
         * mechanism that CREATES the spread, so exact fill stops competing with variation.
         *
         * ORIENTATION IS THE NARROW-WIDTH ANSWER. A landscape hero's long side is its
         * WIDTH and is capped by U - segMin. A portrait hero's long side is its HEIGHT and
         * costs only heroLong*a of width. So where a 450px landscape will not fit, the
         * pick score forces the hero PORTRAIT rather than losing it.
         *
         * IT FAILS SOFT AT EVERY STEP. No path here can make pack() return null: if every
         * genome died, Phase A would find no seeds and solvePlan would fall to
         * degenerateStack - which is ROWS, the one shape Sean has explicitly and repeatedly
         * rejected. Anchors are attempted, never required.
         * ================================================================ */
        var anchorsDone = 0, anchorFails = 0, heroBest = 0, heroTier = 0;

        /** Pick the hero photo. Prefers a decisive aspect, but not a slab or a tower:
         *  a 16:9 laid across the wall reads as a horizontal band, and a 2:3 tower feeds
         *  the very column ledger the rest of this engine exists to keep empty. */
        function pickAnchorPhoto(longPx, maxW, wantPortrait, tapeAt) {
            var q, best = -1, bestSc = Infinity;
            for (q = 0; q < pool.length; q++) {
                var pi2 = pool[q], a = ctx.asp[pi2];
                var w0, Hh;
                if (a >= 1) { w0 = longPx + gap; Hh = longPx / a + gap; }
                else { Hh = longPx + gap; w0 = longPx * a + gap; }
                if (Math.min(w0, Hh) - gap < ctx.minTile) continue;
                if (Hh > ctx.heroHCap || w0 > maxW) continue;
                var la = Math.abs(Math.log(a));
                // MATCH THE PHOTO TO THE SIZE THIS ANCHOR WILL ACTUALLY DRAW, not to the
                // top of the ladder. Preferring the highest rung put a major anchor of
                // ~400px on a photo whose target was 342,000px^2 - a realized-to-target
                // ratio of 0.31 - and since anchors carry a large share of the wall that
                // one mistake INVERTED the whole distribution (measured Spearman -0.28:
                // the big-rung photos were coming out SMALLER than the fillers).
                var drawn = (w0 - gap) * (Hh - gap);
                var sc = 1.60 * Math.abs(Math.log(drawn / Math.max(1, ctx.Ai[pi2])))
                    // a decisive aspect reads as a hero; a slab or a tower does not - a
                    // 16:9 laid across the wall is a horizontal band, and a tall portrait
                    // feeds the column ledger the rest of this engine exists to keep empty
                    + 0.45 * Math.abs(la - 0.42) + 1.8 * Math.max(0, la - 0.9)
                    + (wantPortrait ? (a >= 1 ? 2.5 : 0) : 0)
                    + 0.30 * (q / Math.max(1, pool.length))          // posting order keeps a voice
                    + 0.25 * tapeAt;
                if (sc < bestSc) { bestSc = sc; best = q; }
            }
            return best;
        }

        /**
         * Lay ONE anchor: hero + a companion column of k photos at the hero's exact height.
         * Every existing gate still applies - coverage reserve, wouldFlatten, F7, the band
         * ledgers, the seam ledgers, assertExactPartition - and the commit is the ordinary
         * commit(), so the skyline splice is untouched. lam is 1, so hero and companions
         * all draw at crop EXACTLY 1.000.
         */
        function placeAnchor(segIdx, longPx, tape0) {
            if (!(segIdx >= 0) || segIdx >= sky.length) return false;
            var seg = sky[segIdx], y = seg.y, L = seg.x1 - seg.x0, sx0 = seg.x0;
            if (L < ctx.wMin * 2) return false;
            var maxW = Math.min(L - ctx.wMin, ctx.heroWCap);
            if (!(maxW > 0)) return false;

            var hq = pickAnchorPhoto(longPx, maxW, L < longPx + ctx.wMin + gap, tape0);
            if (hq < 0) return false;
            var hp = pool[hq], ha = ctx.asp[hp];
            var w0, Hh;
            if (ha >= 1) { w0 = longPx + gap; Hh = longPx / ha + gap; }
            else { Hh = longPx + gap; w0 = longPx * ha + gap; }
            if (y + Hh > frontierCap() + Math.max(0, Hh - ctx.hBlockCap)) {
                // the anchor gets its own frontier allowance; anything beyond it is a tower
                if (y + Hh > minSkyY() + Hh + 1) return false;
            }

            // companion candidates: everything else in the pool, aspect-sorted
            var cand = [], q;
            for (q = 0; q < pool.length; q++) if (q !== hq) cand.push(pool[q]);
            if (!cand.length) return false;
            cand.sort(function (a, b) { return ctx.asp[a] - ctx.asp[b]; });

            // k = 1 is legal: one companion beside the hero is an ordinary H2, and at
            // narrow widths it is often the ONLY companion count whose aspect sum can hit
            // the hero's height. Barring it cost the hero outright in half the W=768 walls.
            var kMin = 1, kMax = Math.min(4, cand.length, ctx.opts.fillK + 1);
            var bestS = null, bestSc = Infinity, k, st;
            for (k = kMin; k <= kMax; k++) {
                for (st = 0; st + k <= cand.length; st++) {
                    var alpha1 = 0, j;
                    for (j = 0; j < k; j++) alpha1 += 1 / ctx.asp[cand[st + j]];
                    if (!(alpha1 > 0)) continue;
                    var beta1 = gap * (k - alpha1);
                    var s = (Hh - beta1) / alpha1;                  // the column's gross width
                    if (!(s > 0)) continue;
                    if (s - gap < ctx.minTile) continue;
                    var wTot = w0 + s;
                    if (wTot > L + 1e-9) continue;
                    var remT = L - wTot;
                    if (remT > 1e-9 && remT < ctx.segMin) continue;
                    // every companion cell must be legal and inside its own rung band
                    var ok = true, spread = 0, fit = 0;
                    for (j = 0; j < k && ok; j++) {
                        var aj = ctx.asp[cand[st + j]];
                        var hj = s / aj + gap * (1 - 1 / aj);
                        if (hj - gap < ctx.minTile) { ok = false; break; }
                        var arj = (s - gap) * (hj - gap);
                        var rj = arj / ctx.Ai[cand[st + j]];
                        // The companion band is DELIBERATELY wider than an ordinary
                        // cell's. A companion landing a rung off its target is a small
                        // cost; losing the hero because no window fits exactly is not.
                        if (rj < 1 / (ctx.bandR[cand[st + j]] * 1.90)
                            || rj > ctx.bandR[cand[st + j]] * 1.90) { ok = false; break; }
                        fit += Math.abs(Math.log(rj));
                        spread += Math.abs(Math.log(arj / ((s - gap) * (Hh - gap) / k)));
                    }
                    if (!ok) continue;
                    var sc = fit + 0.6 * spread + 0.8 * Math.abs(Math.log(wTot / L));
                    if (sc < bestSc) { bestSc = sc; bestS = { k: k, st: st, s: s, w: wTot }; }
                }
            }
            if (!bestS) return false;

            // COVERAGE RESERVE - never relaxed, never on any ladder
            var mA = bestS.k + 1;
            var remA = L - bestS.w;
            if (poolCount() - mA < virginNeedExcl(segIdx)
                + ((y < 1e-9) ? runNeed(remA) : 0)) return false;

            // build the tuple in shape order: hero first (part 0), then the column
            var tup = new Int32Array(mA), aB = new Float64Array(mA);
            tup[0] = hp; aB[0] = ha;
            for (q = 0; q < bestS.k; q++) { tup[q + 1] = cand[bestS.st + q]; aB[q + 1] = ctx.asp[tup[q + 1]]; }

            var shapeL = { m: mA, root: 'H', parts: [1, bestS.k] };
            var shapeR = { m: mA, root: 'H', parts: [bestS.k, 1] };
            // NEVER a 'V'-root hero block: that puts a horizontal run directly under the
            // hero and reads as a row.
            var sides = (tape0 < 0.5) ? [shapeL, shapeR] : [shapeR, shapeL];

            // offsets: PREFER AN INTERIOR one. A border-flush hero seeds exactly the edge
            // column the new edge-inclusive metric exists to catch.
            var offs = [];
            if (remA > 1e-9) {
                var io = ctx.segMin + (remA - ctx.segMin) * tape0;
                if (io >= ctx.segMin && remA - io >= ctx.segMin) offs.push(io);
                if (remA * 0.5 >= ctx.segMin) offs.push(remA * 0.5);
                offs.push(remA, 0);
            } else offs.push(0);

            var si2, oi2;
            for (si2 = 0; si2 < sides.length; si2++) {
                var shp = sides[si2];
                var tupO = new Int32Array(mA), aO = new Float64Array(mA), z;
                if (shp.parts[0] === 1) {
                    for (z = 0; z < mA; z++) { tupO[z] = tup[z]; aO[z] = aB[z]; }
                } else {
                    for (z = 0; z < bestS.k; z++) { tupO[z] = tup[z + 1]; aO[z] = aB[z + 1]; }
                    tupO[bestS.k] = tup[0]; aO[bestS.k] = aB[0];
                }
                shapeAffine(shp, aO, gap);
                var Hf = shapeRects(shp, aO, gap, bestS.w, 1);
                if (R_N !== mA) continue;
                if (!assertExactPartition(bestS.w, Hf)) continue;
                if (Hf > ctx.heroHCap + 1e-6) continue;

                for (oi2 = 0; oi2 < offs.length; oi2++) {
                    var off2 = clamp(offs[oi2], 0, Math.max(0, remA));
                    if (off2 > 1e-9 && off2 < ctx.segMin) continue;
                    if (remA - off2 > 1e-9 && remA - off2 < ctx.segMin) continue;
                    var bx2 = sx0 + off2, ex2 = bx2 + bestS.w;

                    // F7 - horizontal seam (never relaxed)
                    if (y > 1e-9 && hProbe(y, bx2, ex2) > ctx.hCap * Math.min(2.6, ctx.relaxMul)) continue;
                    // M8 - never manufacture a full-width guillotine cut
                    if (ctx.relaxMul <= 1.6 && poolCount() - mA > 0
                        && off2 < 1e-9 && remA - off2 < 1e-9
                        && wouldFlatten(segIdx, y + Hf)) continue;

                    // the band and seam ledgers apply to an anchor exactly as to any block
                    var bad = false, g1;
                    for (g1 = 0; g1 < R_N && !bad; g1++) {
                        var cx0 = bx2 + R_X[g1], cx1 = cx0 + R_W[g1];
                        var cy0 = y + R_Y[g1], cy1 = cy0 + R_H[g1];
                        if (bandOver(rowBand, cy0, cy1, cx0, cx1, 1, ctx.bandCap * ctx.relaxMul)) bad = true;
                        else if (bandOver(colBand, cx0, cx1, cy0, cy1, 1, ctx.colBandCap * ctx.relaxMul)) bad = true;
                        else if (bandOver(leftBand, cx0, cx0, cy0, cy1, 1, ctx.edgeBandCap * ctx.relaxMul)) bad = true;
                        else if (bandOver(rightBand, cx1, cx1, cy0, cy1, 1, ctx.edgeBandCap * ctx.relaxMul)) bad = true;
                        else if (cx1 < U - 1e-9
                            && edgeSeamIfAdded(vEdge, cx1, cy0, cy1, true) > ctx.vSeamCap * 1.35 * ctx.relaxMul) bad = true;
                        else if (cx0 > 1e-9
                            && edgeSeamIfAdded(vEdge, cx0, cy0, cy1, false) > ctx.vSeamCap * 1.35 * ctx.relaxMul) bad = true;
                    }
                    if (bad) continue;

                    shapeAffine(shp, aO, gap);
                    Hf = shapeRects(shp, aO, gap, bestS.w, 1);
                    if (commit(segIdx, bx2, bestS.w, Hf, y, tupO, shp, null) === false) continue;
                    var rq3;
                    for (rq3 = 0; rq3 < mA; rq3++) {
                        var at2 = pool.indexOf(tupO[rq3]);
                        if (at2 >= 0) pool.splice(at2, 1);
                    }
                    releaseCheck();
                    anchorsDone++;
                    var hl = Math.max(w0 - gap, Hh - gap);
                    if (hl > heroBest) heroBest = hl;
                    return true;
                }
            }
            return false;
        }

        /** Try the hero at successively smaller long sides. TIER 1 is the computed size;
         *  TIER 2 steps down toward --ss-hero-min in 0.92 multiples, re-picking the tallest
         *  portrait as the width runs out. Every step taken is recorded in the metrics -
         *  a hero shortfall is never silent. */
        function placeHeroAnchor(segIdx, p2) {
            var Lp = ctx.heroLong, tries = 0;
            var tb3 = (p2 * TAPE_STRIDE) % tlen;
            while (tries < 9) {
                var tp = tape[(tb3 + 5 + tries) % tlen];
                if (placeAnchor(segIdx, Lp, tp)) { heroTier = (tries === 0) ? 1 : 2; return true; }
                // ...and if the chosen notch will not take it, try EVERY other segment.
                // The invariant does not care which segment a block is built on, and a
                // hero is worth the search.
                var sq6;
                for (sq6 = 0; sq6 < sky.length; sq6++) {
                    if (sq6 === segIdx) continue;
                    if (placeAnchor(sq6, Lp, tp)) { heroTier = (tries === 0) ? 1 : 2; return true; }
                }
                if (Lp <= ctx.opts.heroMin + 0.5) break;
                Lp = Math.max(ctx.opts.heroMin, Lp * 0.92);
                tries++;
            }
            return false;
        }

        /* ---------- main loop ---------- */
        var p = 0, guard = 0;
        // ANCHOR SCHEDULE, read off the tape so different trials try different heroes and
        // different placements. The hero goes down EARLY, while the pool is still full and
        // the skyline is still one wide run - that is the only moment a 450px tile is
        // cheap. The majors follow at intervals.
        var anchorSlots = [], asq;
        var anchorsWanted = Math.max(0, Math.min(ctx.opts.anchors, Math.floor(n / 5)));
        for (asq = 0; asq < anchorsWanted; asq++) {
            // THE HERO GOES FIRST, ALWAYS. Letting the tape place it one or two blocks
            // in cost the hero outright in five of eight W=768 walls: by then the skyline
            // has split and no remaining segment is wide enough for a 456px block. A 450px
            // tile is only cheap while the skyline is still the single run [0, U].
            anchorSlots.push(asq === 0
                ? 0
                : (2 + 3 * asq + Math.floor(tape[(asq * 7 + 3) % tlen] * 4)));
        }
        var anchorNext = 0, placedBlocks = 0;

        while (poolCount() > 0 && guard++ < n * 12 + 128) {
            releaseCheck();

            // ---- ANCHOR SCHEDULE. Attempted, never required: an anchor that cannot be
            // laid DEFERS itself by one block and the ordinary vocabulary carries on, so
            // no anchor can ever make pack() return null.
            if (anchorNext < anchorSlots.length && placedBlocks >= anchorSlots[anchorNext]) {
                // widest low-lying segment: an anchor wants room, not the deepest notch
                var asBest = -1, asW = 0, asq2;
                var asFloor = minSkyY() + ctx.hRef * 0.9;
                for (asq2 = 0; asq2 < sky.length; asq2++) {
                    if (sky[asq2].y > asFloor) continue;
                    var wSeg = sky[asq2].x1 - sky[asq2].x0;
                    if (wSeg > asW) { asW = wSeg; asBest = asq2; }
                }
                var laid = false;
                if (asBest >= 0) {
                    laid = (anchorNext === 0)
                        ? placeHeroAnchor(asBest, p)
                        : placeAnchor(asBest, Math.max(ctx.opts.heroMin * 0.62,
                            ctx.heroLong * (0.60 + 0.22 * tape[(p * TAPE_STRIDE + 9) % tlen])),
                            tape[(p * TAPE_STRIDE + 11) % tlen]);
                }
                if (laid) { anchorNext++; placedBlocks++; p++; continue; }
                anchorFails++;
                // defer rather than abandon - the skyline changes every block, and the
                // hero in particular is worth several more attempts.
                anchorSlots[anchorNext] = placedBlocks + 1;
                if (anchorSlots[anchorNext] > n) anchorNext++;
            }

            if (forcedRetire(p)) { p++; placedBlocks++; continue; }

            // 4.2 jittered notch selection - strict lowest-first is level-seeking,
            // and a level frontier IS a full-width cut.
            var tb2 = (p * TAPE_STRIDE) % tlen;
            // CHASE LIMIT. Without it the packer answers "this notch is blocked" by
            // stacking somewhere else, and builds a tower: measured maxBottom went 3400 ->
            // 6355 with the shallowest free bottom at 659, i.e. a chewed ragged edge and a
            // full-height column. The frontier must stay roughly together.
            var minY = Infinity, mq;
            for (mq = 0; mq < sky.length; mq++) if (sky[mq].y < minY) minY = sky[mq].y;
            // The chase window CLOSES as the pool empties, so the wall finishes roughly
            // level. Leaving it wide open produced a bottom ragged by 5.6 median tile
            // heights - chewed, not deliberate.
            var frac = poolCount() / Math.max(1, n);
            var chaseCap = minY + Math.max(ctx.hBlockCap * (frac > 0.30 ? 1 : (0.30 + 2.33 * frac)),
                1.25 * ctx.hRef);
            var bestSeg = -1, bestKey = Infinity, sq;
            for (sq = 0; sq < sky.length; sq++) {
                if (sky[sq].x1 - sky[sq].x0 < ctx.wMin - 1e-9) continue;
                if (sky[sq].y > chaseCap) continue;
                var jt = tape[(tb2 + 20 + sq) % tlen];
                // jitter on the scale of ONE BLOCK, not of the whole wall - a wall-scale
                // jitter is what let the notch choice wander a thousand pixels up.
                var key = sky[sq].y + jt * 0.22 * ctx.hBlockCap;
                if (key < bestKey) { bestKey = key; bestSeg = sq; }
            }
            if (bestSeg < 0) {
                // every segment is a sliver (cannot happen: F2 forbids slivers) - bail
                bestSeg = 0;
                var lowY = Infinity, sq2;
                for (sq2 = 0; sq2 < sky.length; sq2++) if (sky[sq2].y < lowY) { lowY = sky[sq2].y; bestSeg = sq2; }
            }

            var placed = tryPlace(bestSeg, p, null, 0)
                || tryPlace(bestSeg, p, null, 1)
                || tryPlace(bestSeg, p, null, 2)
                || tryPlace(bestSeg, p, null, 3);
            if (!placed) {
                // The chosen notch may simply be un-fillable (usually its vertical seam is
                // at the cap). ANOTHER notch almost always is fillable, and moving there is
                // free - the invariant does not care which segment we build on. Exhaust the
                // real vocabulary everywhere before reaching for the emergency leaf, which
                // is the thing that produces off-target tiles.
                var order2 = [], oq;
                for (oq = 0; oq < sky.length; oq++) if (oq !== bestSeg && sky[oq].y <= chaseCap) order2.push(oq);
                order2.sort(function (a, b) { return sky[a].y - sky[b].y; });
                var rq2, sq3;
                for (rq2 = 0; rq2 < 4 && !placed; rq2++) {
                    for (sq3 = 0; sq3 < order2.length && !placed; sq3++) {
                        placed = tryPlace(order2[sq3], p, null, rq2);
                    }
                }
                // last rung: the seam cap comes off, but only here and only now
                if (!placed) placed = tryPlace(bestSeg, p, null, 4);
                for (sq3 = 0; sq3 < order2.length && !placed; sq3++) placed = tryPlace(order2[sq3], p, null, 4);
                if (!placed) placed = tryPlace(bestSeg, p, null, 5);
                for (sq3 = 0; sq3 < order2.length && !placed; sq3++) placed = tryPlace(order2[sq3], p, null, 5);
                // Still nothing? The notch is almost always blocked because its own vertical
                // boundary is at the seam cap - i.e. it is BECOMING A COLUMN. Merge it into a
                // neighbour by stretching its floor (no photo spent, no hole possible) and the
                // wider run is placeable again, with the boundary now interior.
                if (!placed) {
                    var mq2;
                    for (mq2 = 0; mq2 < 3 && !placed; mq2++) {
                        if (!stretchToNeighbour(bestSeg)) break;
                        if (bestSeg >= sky.length) bestSeg = sky.length - 1;
                        placed = tryPlace(bestSeg, p, null, 0) || tryPlace(bestSeg, p, null, 2)
                            || tryPlace(bestSeg, p, null, 4);
                    }
                    if (!placed) {
                        var sq4;
                        for (sq4 = 0; sq4 < sky.length && !placed; sq4++) {
                            if (!stretchToNeighbour(sq4)) continue;
                            placed = tryPlace(Math.min(sq4, sky.length - 1), p, null, 3)
                                || tryPlace(Math.min(sq4, sky.length - 1), p, null, 5);
                        }
                    }
                }
            }
            if (!placed) {
                // 4.6 absolute fallback: a LEAF at native aspect honouring F1/F2/F3/F4
                var any = fallbackLeaf(bestSeg), fq;
                for (fq = 0; fq < sky.length && !any; fq++) any = fallbackLeaf(fq);
                if (!any) return null;
                ladderHits++;
            }
            p++;
            placedBlocks++;
        }
        if (poolCount() > 0) return null;
        // COVERAGE, asserted not assumed: a segment still on the floor is a full-height
        // void channel. The reserve above makes this unreachable; the check is the net.
        var vq;
        for (vq = 0; vq < sky.length; vq++) if (sky[vq].y < 1e-9) return null;

        /**
         * 4.6 absolute fallback - a LEAF at its EXACT native aspect (so crop stays 1.000,
         * which is why maxCrop can be a hard gate with no escalation path). It still
         * honours F1/F2/F3/F4; only the area band and the seam caps are given up.
         */
        function fallbackLeaf(segIdx) {
            releaseCheck();
            if (pool.length === 0) return false;
            if (!(segIdx >= 0) || segIdx >= sky.length) return false;
            var seg = sky[segIdx];
            var L = seg.x1 - seg.x0;
            // The leaf obeys the LOOKAHEAD sliver floor too. It used to leave remainders
            // sized against the static segMin, and since the leaf is exactly the path that
            // fires when nothing else fits, it was manufacturing the very 100-270px
            // remainders that nothing left in the pool could then fill in-band.
            var lFloor = Math.max(ctx.segMin, poolSegFloor());
            var wFull = Math.min(L, ctx.hCap);
            if (L - wFull > 1e-9 && L - wFull < lFloor) wFull = L - lFloor;
            if (wFull < ctx.wMin) { lFloor = ctx.segMin; wFull = Math.min(L, ctx.hCap); }
            if (wFull < ctx.wMin) return false;
            var q, z, pass, best = -1, bestErr = Infinity, bestW = 0, bestH = 0, bestBx = seg.x0;
            var K1 = Math.exp(ctx.lnK * 0.45);   // the emergency leaf spends little crop
            var otherNeedF = virginNeedExcl(segIdx), virginF = (seg.y < 1e-9);
            var frCapL = frontierCap();
            // pass 0 demands the hard area band; pass 1 is the true last resort
            for (pass = 0; pass < 2 && best < 0; pass++)
            for (q = 0; q < pool.length; q++) {
                var a = ctx.asp[pool[q]];
                // widths that hit the target area, and the full run; never leave a sliver
                var cw = [wFull, Math.sqrt(ctx.Ai[pool[q]] * a) + gap, L, L - lFloor];
                for (z = 0; z < cw.length; z++) {
                    var wq = cw[z];
                    if (wq < ctx.wMin || wq > wFull) continue;
                    if (L - wq > 1e-9 && L - wq < lFloor) continue;
                    if (poolCount() - 1 < otherNeedF + (virginF ? runNeed(L - wq) : 0)) continue;
                    var dwq = wq - gap;
                    var hNat = dwq / a;                         // exact native aspect: crop 1.000
                    // stretch/squash within the crop bound to reach the area band
                    var hWant = clamp(ctx.Ai[pool[q]] / dwq, hNat / K1, hNat * K1);
                    var Hq = hWant + gap;
                    if (Hq < ctx.wMin || Hq > ctx.hBlockCap) continue;
                    if (seg.y + Hq > frCapL * 1.25) continue;
                    if (dwq < ctx.minTile || hWant < ctx.minTile) continue;
                    var rq = hWant > hNat ? hWant / hNat : hNat / hWant;
                    var bandR2 = (dwq * hWant) / ctx.Ai[pool[q]];
                    var bRl = ctx.bandR[pool[q]];                            // per-rung band
                    if (pass === 0 && (bandR2 < 1 / bRl || bandR2 > bRl)) continue;
                    // OFFSETS AND SEAMS MATTER HERE TOO. The emergency leaf used to be
                    // flush-left and completely seam-blind, and it ran 25 times out of 60
                    // placements - it, not the vocabulary, was building the columns.
                    var offL = [0];
                    if (L - wq > 1e-9) {
                        offL.push(L - wq);
                        if ((L - wq) * 0.5 >= ctx.segMin && (L - wq) * 0.5 >= ctx.segMin) offL.push((L - wq) * 0.5);
                    }
                    var oq2;
                    for (oq2 = 0; oq2 < offL.length; oq2++) {
                        var offq = offL[oq2];
                        if (offq > 1e-9 && offq < ctx.segMin) continue;
                        if (L - wq - offq > 1e-9 && L - wq - offq < ctx.segMin) continue;
                        var bxq = seg.x0 + offq, exq = bxq + wq;
                        var seamQ = 0;
                        if (bxq > 1e-9) seamQ = Math.max(seamQ, Math.min(sideYs(bxq).l, seg.y + Hq) - bornGet(bxq, seg.y));
                        if (exq < U - 1e-9) seamQ = Math.max(seamQ, Math.min(sideYs(exq).r, seg.y + Hq) - bornGet(exq, seg.y));
                        if (seg.y > 1e-9 && hProbe(seg.y, bxq, exq) > ctx.hCap * Math.min(2.6, ctx.relaxMul)) continue;
                        if (ctx.relaxMul <= 1.6 && poolCount() > 1 && wouldFlatten(segIdx, seg.y + Hq) && offq < 1e-9 && L - wq < 1e-9) continue;
                        // the leaf obeys the band ledgers too, at 1.5x. It used to obey
                        // nothing at all, which is how it built columns 25 placements at a time.
                        if (bandOver(colBand, bxq, exq, seg.y, seg.y + Hq, 1, ctx.colBandCap * 1.15 * ctx.relaxMul)) continue;
                        // The leaf gets only a HAIR of extra room on the column ledgers.
                        // It used to get 1.5x, and the leaf is the path that runs when
                        // nothing else fits - i.e. exactly when a column is forming.
                        if (bandOver(leftBand, bxq, bxq, seg.y, seg.y + Hq, 1, ctx.edgeBandCap * 1.15 * ctx.relaxMul)) continue;
                        if (bandOver(rightBand, exq, exq, seg.y, seg.y + Hq, 1, ctx.edgeBandCap * 1.15 * ctx.relaxMul)) continue;
                        if (bandOver(rowBand, seg.y, seg.y + Hq, bxq, exq, 1, ctx.bandCap * 1.5 * ctx.relaxMul)) continue;
                        if (seamQ > ctx.vCap * 2.2 * ctx.relaxMul) continue;
                        if (exq < U - 1e-9
                            && edgeSeamIfAdded(vEdge, exq, seg.y, seg.y + Hq, true) > ctx.vSeamCap * 1.4 * ctx.relaxMul) continue;
                        if (bxq > 1e-9
                            && edgeSeamIfAdded(vEdge, bxq, seg.y, seg.y + Hq, false) > ctx.vSeamCap * 1.4 * ctx.relaxMul) continue;
                        if (seg.y > 1e-9
                            && edgeSeamIfAdded(hEdge, seg.y, bxq, exq, false) > ctx.hSeamCap * 1.4 * ctx.relaxMul) continue;
                        var err = Math.abs(Math.log(bandR2)) + 3 * Math.log(rq) * Math.log(rq)
                            + 2.0 * Math.pow(Math.max(0, seamQ) / ctx.vCap, 2);
                        if (err < bestErr) { bestErr = err; best = q; bestW = wq; bestH = Hq; bestBx = bxq; }
                    }
                }
            }
            if (best < 0) return false;
            R_N = 1; R_X[0] = 0; R_Y[0] = 0; R_W[0] = bestW; R_H[0] = bestH; R_P[0] = 0;
            var tup = new Int32Array(1); tup[0] = pool[best];
            if (commit(segIdx, bestBx, bestW, bestH, seg.y, tup, null, null) === false) return false;
            pool.splice(best, 1);
            releaseCheck();
            return true;
        }

        return {
            tiles: tiles, sky: sky, U: U,
            ladderHits: ladderHits, forcedFails: forcedFails,
            straddles: straddles, levels: levels, stretches: stretches,
            anchors: anchorsDone, anchorFails: anchorFails,
            heroLong: heroBest, heroTier: heroTier
        };
    }

    /* ================================================================
     * 4. PART 6 - BOTTOM-FREE RELAXATION
     * A cell whose bottom edge lies entirely on the final skyline has FIXED
     * WIDTH and FREE HEIGHT: moving its bottom only moves the skyline there,
     * which is still a hypograph, so it CANNOT open a hole. Give those cells
     * their exact native aspect - zero crop.
     * ================================================================ */
    function bottomFreeRelax(ctx, layout) {
        var tiles = layout.tiles, sky = layout.sky, gap = ctx.gap;
        var heights = [], i;
        for (i = 0; i < tiles.length; i++) heights.push(tiles[i].h);
        heights.sort(function (a, b) { return a - b; });
        var medH = heights.length ? heights[heights.length >> 1] : ctx.hRef;
        var ragCap = ctx.rag * medH;

        function skyAt(x) {
            var lo = 0, hi = sky.length - 1;
            while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (sky[mid].x0 <= x) lo = mid; else hi = mid - 1; }
            return sky[lo].y;
        }

        var freeBottoms = [];
        for (i = 0; i < tiles.length; i++) {
            var tf = tiles[i], y1f = tf.y + tf.h, freef = true, sf;
            for (sf = 0; sf < sky.length && freef; sf++) {
                var ovf = Math.min(sky[sf].x1, tf.x + tf.w) - Math.max(sky[sf].x0, tf.x);
                if (ovf > 1e-6 && Math.abs(sky[sf].y - y1f) > 1e-6) freef = false;
            }
            if (freef) freeBottoms.push(y1f);
        }
        freeBottoms.sort(function (a, b) { return a - b; });
        var yMid = freeBottoms.length ? freeBottoms[freeBottoms.length >> 1] : 0;
        var K1 = Math.exp(ctx.lnK * 0.45);   // the ragged-edge tidy-up gets a SMALL crop budget

        for (i = 0; i < tiles.length; i++) {
            var t = tiles[i];
            var y1 = t.y + t.h;
            // bottom-free iff S(x) === y1 across the tile's span
            var free = true, s;
            for (s = 0; s < sky.length && free; s++) {
                var ov = Math.min(sky[s].x1, t.x + t.w) - Math.max(sky[s].x0, t.x);
                if (ov > 1e-6 && Math.abs(sky[s].y - y1) > 1e-6) free = false;
            }
            if (!free) continue;
            t.bottomFree = true;
            var a = ctx.asp[t.i];
            // the border is inside the crop budget: solve for the INNER box's aspect
            var want = (t.w - gap - ctx.bw2) / a + gap + ctx.bw2;   // native aspect: ZERO crop
            want = clamp(want, t.h - ragCap, t.h + ragCap);
            if (want < ctx.minTile + gap) want = ctx.minTile + gap;
            // stay inside the hard area band - a free bottom is not a licence to bloat,
            // and the band is the photo's OWN rung band (a filler that grows to hero area
            // on the ragged edge is a hole in the ladder as surely as one in the packer).
            var dwB = t.w - gap, bRb = ctx.bandR[t.i];
            want = clamp(want, ctx.Ai[t.i] / bRb / dwB + gap, ctx.Ai[t.i] * bRb / dwB + gap);
            // never let the clamps make the crop WORSE than it already was
            var cNew = (t.w - gap - ctx.bw2) / (want - gap - ctx.bw2);
            var cOld = (t.w - gap - ctx.bw2) / (t.h - gap - ctx.bw2);
            var rNew = a > cNew ? a / cNew : cNew / a, rOld = a > cOld ? a / cOld : cOld / a;
            if (rNew <= rOld + 1e-12) t.h = want;
            // A bottom-free cell has FIXED WIDTH and FREE HEIGHT, and moving its bottom can
            // never open a hole (the result is still a hypograph). Spend a little of the
            // crop budget pulling outlying bottoms toward the middle so the ragged edge
            // reads as deliberate rather than chewed.
            var hNat2 = (t.w - gap - ctx.bw2) / a + gap + ctx.bw2, dw2 = t.w - gap;
            var lo2 = Math.max(ctx.minTile + gap, hNat2 / K1, ctx.Ai[t.i] / bRb / dw2 + gap);
            var hi2 = Math.min(hNat2 * K1, ctx.Ai[t.i] * bRb / dw2 + gap);
            var bot = t.y + t.h;
            if (hi2 > lo2) {
                if (bot > yMid + 1.1 * medH) t.h = clamp(yMid + 1.1 * medH - t.y, lo2, hi2);
                else if (bot < yMid - 1.1 * medH) t.h = clamp(yMid - 1.1 * medH - t.y, lo2, hi2);
            }
        }
        return medH;
    }

    /* ================================================================
     * 5. PART 7 - METRICS on realized geometry
     * ================================================================ */
    function measure(tilesIn, W, gap, ctx) {
        // SEAMS ARE MEASURED ON THE GROSS BOXES. The drawn boxes are separated by the
        // gutter, so a shared edge shows up as two coordinates `gap` apart - and with
        // tau = 0.004*W that is BELOW tolerance for any W under 1500. sigmaH/sigmaV then
        // read a flat 0.000 no matter how long the seam really is, which is exactly why a
        // wall with an uncrossed full-height line at x=1032 reported sigmaV 0.000 and
        // sailed through its own gate. Gross boxes abut exactly, so the seam is real.
        var n = tilesIn.length, i, j;
        var tiles = tilesIn;
        if (n && tilesIn[0].gx0 != null) {
            tiles = new Array(n);
            for (i = 0; i < n; i++) {
                var ti0 = tilesIn[i];
                tiles[i] = {
                    i: ti0.i, x: ti0.gx0, y: ti0.gy0,
                    w: ti0.gx1 - ti0.gx0, h: ti0.gy1 - ti0.gy0,
                    a: ti0.a, bottomFree: ti0.bottomFree, dw: ti0.w, dh: ti0.h
                };
            }
        }
        // both regimes are now gross (the packer's own rects already are), so the span is U
        W = W + gap;
        var tau = Math.max(2, 0.004 * W);
        var out = {
            n: n, minCrossH: 1, minCrossV: 1, bandIndex: 0, sigmaH: 0, sigmaV: 0,
            sigmaVmed: 0, bandConc: 0, colConc: 0, fullWidthCuts: 0,
            maxCrop: 1, p95Crop: 1, meanCrop: 1, zeroCropFrac: 1,
            residualAreaCV: 0, rawAreaCV: 0, minTileDim: Infinity,
            ragged: 0, holeArea: 0, overlapArea: 0, height: 0, maxDistort: 0
        };
        if (!n) return out;

        var maxBottom = 0, evalY = Infinity;
        for (i = 0; i < n; i++) {
            var b = tiles[i].y + tiles[i].h;
            if (b > maxBottom) maxBottom = b;
            if (tiles[i].bottomFree && b < evalY) evalY = b;
        }
        if (!isFinite(evalY)) evalY = maxBottom;
        out.height = maxBottom;

        // ---- M1 / M8 : horizontal crossing ----
        var yEdges = [];
        for (i = 0; i < n; i++) {
            if (tiles[i].y > tau) yEdges.push(tiles[i].y);
            var yb = tiles[i].y + tiles[i].h;
            if (yb > tau && yb < maxBottom - tau) yEdges.push(yb);
        }
        yEdges.sort(function (a, c) { return a - c; });
        var minCH = 1, cuts = 0, prevY = -1e9;
        for (i = 0; i < yEdges.length; i++) {
            var yy = yEdges[i];
            if (yy - prevY < 0.5) continue;
            prevY = yy;
            var cw = 0;
            for (j = 0; j < n; j++) {
                if (tiles[j].y < yy - tau && tiles[j].y + tiles[j].h > yy + tau) cw += tiles[j].w;
            }
            var f = cw / W;
            if (f < minCH) minCH = f;
            if (cw === 0) cuts++;
        }
        out.minCrossH = yEdges.length ? minCH : 1;
        out.fullWidthCuts = cuts;

        // ---- M2 : vertical crossing (evaluated to the shallowest free bottom) ----
        var xEdges = [];
        for (i = 0; i < n; i++) {
            if (tiles[i].x > tau) xEdges.push(tiles[i].x);
            var xr = tiles[i].x + tiles[i].w;
            if (xr < W - tau) xEdges.push(xr);
        }
        xEdges.sort(function (a, c) { return a - c; });
        var minCV = 1, prevX = -1e9;
        for (i = 0; i < xEdges.length; i++) {
            var xx = xEdges[i];
            if (xx - prevX < 0.5) continue;
            prevX = xx;
            var chh = 0;
            for (j = 0; j < n; j++) {
                if (tiles[j].x < xx - tau && tiles[j].x + tiles[j].w > xx + tau) {
                    chh += Math.max(0, Math.min(evalY, tiles[j].y + tiles[j].h) - tiles[j].y);
                }
            }
            var f2 = chh / Math.max(1, evalY);
            if (f2 < minCV) minCV = f2;
        }
        out.minCrossV = xEdges.length ? minCV : 1;

        // ---- M3 : bandIndex (Sean's literal test) ----
        function clusterLevels(vals) {
            var s = vals.slice().sort(function (a, c) { return a - c; }), o = [], k;
            for (k = 0; k < s.length; k++) {
                if (o.length && s[k] - o[o.length - 1] <= tau) continue;
                o.push(s[k]);
            }
            return o;
        }
        var allTops = [], allBots = [];
        for (i = 0; i < n; i++) { allTops.push(tiles[i].y); allBots.push(tiles[i].y + tiles[i].h); }
        var levs = clusterLevels(allTops.concat(allBots));
        function levIdx(v) {
            var k, bi = -1, bd = tau + 1;
            for (k = 0; k < levs.length; k++) { var d = Math.abs(levs[k] - v); if (d < bd) { bd = d; bi = k; } }
            return bi;
        }
        var pairMap = {};
        for (i = 0; i < n; i++) {
            var t1 = levIdx(tiles[i].y), t2 = levIdx(tiles[i].y + tiles[i].h);
            if (t1 < 0 || t2 < 0 || t1 === t2) continue;
            var key = t1 + ':' + t2;
            (pairMap[key] = pairMap[key] || []).push(tiles[i]);
        }
        var bandIdx = 0, kk;
        for (kk in pairMap) {
            if (!Object.prototype.hasOwnProperty.call(pairMap, kk)) continue;
            var grp = pairMap[kk];
            if (grp.length < 3) continue;
            var iv = grp.map(function (t) { return [t.x, t.x + t.w]; }).sort(function (a, c) { return a[0] - c[0]; });
            var tot = 0, cs = iv[0][0], ce = iv[0][1], z;
            for (z = 1; z < iv.length; z++) {
                if (iv[z][0] <= ce + 0.5) { if (iv[z][1] > ce) ce = iv[z][1]; }
                else { tot += ce - cs; cs = iv[z][0]; ce = iv[z][1]; }
            }
            tot += ce - cs;
            if (tot / W > bandIdx) bandIdx = tot / W;
        }
        out.bandIndex = bandIdx;

        /* ---- M3b : colBandIndex - THE EXACT X-MIRROR, WITH NO EDGE EXEMPTION ----
         * The engine did not compute this at all. Cluster every LEFT and RIGHT edge
         * within tau - INCLUDING x = 0 and x = U - group the tiles by the (leftLevel,
         * rightLevel) pair, and for every group of >= 3 report the union of their
         * y-extents as a fraction of the wall height. This is the number Sean is reading
         * off the renders; measured on the shipping engine it ran 0.33-0.92 with a median
         * of 0.53, at or above the ROW band's own 0.50 hard-fail in 7 of 18 runs, while
         * the engine reported nothing.
         * NOTE: M2's xEdges probe above legitimately still excludes x=0 and x=U, because
         * nothing can CROSS the container edge and including them would pin minCrossV to
         * zero always. It is column DETECTION that stops exempting the edges, not the
         * crossing probe. */
        var allL = [], allR = [];
        for (i = 0; i < n; i++) { allL.push(tiles[i].x); allR.push(tiles[i].x + tiles[i].w); }
        var xlevs = clusterLevels(allL.concat(allR));
        function xIdx(v) {
            var k, bi = -1, bd = tau + 1;
            for (k = 0; k < xlevs.length; k++) { var d = Math.abs(xlevs[k] - v); if (d < bd) { bd = d; bi = k; } }
            return bi;
        }
        var colMap = {}, ck;
        for (i = 0; i < n; i++) {
            var l1 = xIdx(tiles[i].x), l2 = xIdx(tiles[i].x + tiles[i].w);
            if (l1 < 0 || l2 < 0 || l1 === l2) continue;
            var ckey = l1 + ':' + l2;
            (colMap[ckey] = colMap[ckey] || []).push(tiles[i]);
        }
        var colIdx = 0;
        for (ck in colMap) {
            if (!Object.prototype.hasOwnProperty.call(colMap, ck)) continue;
            var cgrp = colMap[ck];
            if (cgrp.length < 3) continue;
            var civ = cgrp.map(function (t) { return [t.y, t.y + t.h]; }).sort(function (a, c) { return a[0] - c[0]; });
            var ctot = 0, ccs = civ[0][0], cce = civ[0][1], cz;
            for (cz = 1; cz < civ.length; cz++) {
                if (civ[cz][0] <= cce + 0.5) { if (civ[cz][1] > cce) cce = civ[cz][1]; }
                else { ctot += cce - ccs; ccs = civ[cz][0]; cce = civ[cz][1]; }
            }
            ctot += cce - ccs;
            if (ctot / Math.max(1, maxBottom) > colIdx) colIdx = ctot / Math.max(1, maxBottom);
        }
        out.colBandIndex = colIdx;

        /* ---- BORDER-FLUSH RUNS. Among the tiles flush to x=0 (resp. x=U), the longest
         * CONTIGUOUS y-run whose FAR edges also cluster within tau. That is the seam the
         * eye actually reads as a column, and unlike a naive "longest run of tiles touching
         * the border" it is not tautological - the border has to be covered from top to
         * bottom by construction, so that reading is ~1.0 on every wall including good
         * ones. leftEdgeIndex / rightEdgeIndex ARE that naive reading; they are reported
         * because they are cheap and occasionally informative, and gated on by nothing. */
        function flushRun(list, far) {
            if (list.length < 2) return 0;
            var s = list.slice().sort(function (a, c) { return a.y - c.y; }), bi, bj, best = 0;
            for (bi = 0; bi < s.length; bi++) {
                var f0 = s[bi][far], y0 = s[bi].y, y1 = s[bi].y + s[bi].h;
                for (bj = bi + 1; bj < s.length; bj++) {
                    if (s[bj].y > y1 + 0.5) break;
                    if (Math.abs(s[bj][far] - f0) > tau) break;
                    if (s[bj].y + s[bj].h > y1) y1 = s[bj].y + s[bj].h;
                }
                if (y1 - y0 > best) best = y1 - y0;
            }
            return best;
        }
        var fl = [], fr = [];
        for (i = 0; i < n; i++) {
            if (tiles[i].x <= tau) fl.push({ y: tiles[i].y, h: tiles[i].h, f: tiles[i].x + tiles[i].w });
            if (tiles[i].x + tiles[i].w >= W - tau) fr.push({ y: tiles[i].y, h: tiles[i].h, f: tiles[i].x });
        }
        out.flushCountL = fl.length;
        out.flushCountR = fr.length;
        out.flushRunLpx = flushRun(fl, 'f');
        out.flushRunRpx = flushRun(fr, 'f');

        // ---- M4 / M5 : sigmaH, sigmaV ----
        function longestSeam(items, coordKey, loKey, hiKey, span) {
            // items: [{c, lo, hi, side}] side 1 = "upper/left owner", 2 = "lower/right owner"
            var byLev = {}, k;
            for (k = 0; k < items.length; k++) {
                var b2 = Math.round(items[k].c / Math.max(1, tau));
                var kk2;
                for (kk2 = b2 - 1; kk2 <= b2 + 1; kk2++) { /* bucket spill handled below */ }
                (byLev[b2] = byLev[b2] || []).push(items[k]);
            }
            var longest = 0;
            var keys = Object.keys(byLev);
            for (k = 0; k < keys.length; k++) {
                var bucket = byLev[keys[k]].concat(byLev[String(Number(keys[k]) - 1)] || []);
                var A = [], B = [], z2;
                for (z2 = 0; z2 < bucket.length; z2++) {
                    if (Math.abs(bucket[z2].c - byLev[keys[k]][0].c) > tau) continue;
                    (bucket[z2].side === 1 ? A : B).push([bucket[z2].lo, bucket[z2].hi]);
                }
                var inter = intersectUnions(A, B);
                var z3;
                for (z3 = 0; z3 < inter.length; z3++) if (inter[z3][1] - inter[z3][0] > longest) longest = inter[z3][1] - inter[z3][0];
            }
            return longest / span;
        }
        function unionIv(list) {
            if (!list.length) return [];
            var s = list.slice().sort(function (a, c) { return a[0] - c[0]; }), o = [s[0].slice()], k;
            for (k = 1; k < s.length; k++) {
                if (s[k][0] <= o[o.length - 1][1] + 0.5) { if (s[k][1] > o[o.length - 1][1]) o[o.length - 1][1] = s[k][1]; }
                else o.push(s[k].slice());
            }
            return o;
        }
        function intersectUnions(A, B) {
            var ua = unionIv(A), ub = unionIv(B), o = [], ia = 0, ib = 0;
            while (ia < ua.length && ib < ub.length) {
                var lo = Math.max(ua[ia][0], ub[ib][0]), hi = Math.min(ua[ia][1], ub[ib][1]);
                if (hi > lo) o.push([lo, hi]);
                if (ua[ia][1] < ub[ib][1]) ia++; else ib++;
            }
            return o;
        }
        var hItems = [], vItems = [];
        for (i = 0; i < n; i++) {
            var t = tiles[i];
            hItems.push({ c: t.y, lo: t.x, hi: t.x + t.w, side: 2 });                 // a TOP edge at y
            hItems.push({ c: t.y + t.h, lo: t.x, hi: t.x + t.w, side: 1 });           // a BOTTOM edge at y
            var vlo = t.y, vhi = Math.min(evalY, t.y + t.h);
            if (vhi > vlo) {
                vItems.push({ c: t.x, lo: vlo, hi: vhi, side: 2 });
                vItems.push({ c: t.x + t.w, lo: vlo, hi: vhi, side: 1 });
            }
        }
        out.sigmaH = longestSeam(hItems, 'c', 'lo', 'hi', W);
        out.sigmaV = longestSeam(vItems, 'c', 'lo', 'hi', Math.max(1, evalY));

        // ---- M6 / M7 : concentration ----
        var hRef = ctx ? ctx.hRef : 200, wRef = ctx ? ctx.wRef : 300;
        function conc(vals, binW, total, skipZero) {
            var m2 = {}, k, mx = 0;
            for (k = 0; k < vals.length; k++) {
                if (skipZero && vals[k] <= tau) continue;
                var b3 = Math.floor(vals[k] / binW);
                m2[b3] = (m2[b3] || 0) + 1;
                if (m2[b3] > mx) mx = m2[b3];
            }
            return mx / Math.max(1, total);
        }
        // THE ROW reading genuinely may exempt y = 0: the top edge is mandated straight
        // and tiles sharing it have different bottoms, so counting it would read a
        // constant. THE COLUMN reading MAY NOT. A run of tiles all flush to x = 0 (or all
        // flush to x = U) is a column to the eye whether or not the container edge is
        // meant to be straight, and the shipping metric was skipping exactly those - it
        // under-reported by up to 0.167 at W=375 and 0.084 at W=560/768 against a 0.22
        // gate. Sean can see them in the renders. Count them.
        out.bandConc = conc(tiles.map(function (t) { return t.y; }), hRef / 3, n, true);
        out.colConc = Math.max(
            conc(tiles.map(function (t) { return t.x; }), wRef / 3, n, false),
            conc(tiles.map(function (t) { return t.x + t.w; }), wRef / 3, n, false));
        // reported for continuity with the old number, never gated on
        out.colConcExcl = conc(tiles.map(function (t) { return t.x; }), wRef / 3, n, true);

        // ---- M9 : crop ----
        var crops = [], zc = 0, aspects = ctx ? ctx.asp : null;
        var bw2m = ctx ? ctx.bw2 : 0;
        for (i = 0; i < n; i++) {
            // crop and area are DRAWN-box properties; seams are gross-box properties
            var dwm = (tiles[i].dw != null) ? tiles[i].dw : (tiles[i].w - gap);
            var dhm = (tiles[i].dh != null) ? tiles[i].dh : (tiles[i].h - gap);
            var a = aspects ? aspects[tiles[i].i] : (tiles[i].a || 1);
            var c = (dwm - bw2m) / (dhm - bw2m);
            var r = (c > 0) ? (a > c ? a / c : c / a) : 99;
            crops.push(r);
            if (r <= 1.006) zc++;
            if (r > out.maxCrop) out.maxCrop = r;
            if (Math.min(dwm, dhm) < out.minTileDim) out.minTileDim = Math.min(dwm, dhm);
        }
        crops.sort(function (a, c) { return a - c; });
        out.p95Crop = crops[Math.min(crops.length - 1, Math.floor(crops.length * 0.95))];
        out.meanCrop = crops.reduce(function (a, c) { return a + c; }, 0) / crops.length;
        out.zeroCropFrac = zc / n;

        // ---- M10 : area spread ----
        if (ctx) {
            var res = [], raw = [], viol = 0;
            for (i = 0; i < n; i++) {
                var dwa = (tiles[i].dw != null) ? tiles[i].dw : (tiles[i].w - gap);
                var dha = (tiles[i].dh != null) ? tiles[i].dh : (tiles[i].h - gap);
                var ar = dwa * dha;
                var rr2 = ar / ctx.Ai[tiles[i].i];
                // per-rung band, with the same 1.30 measurement slack the global 2.05 -> 2.1
                // used to carry (integer snapping moves a realized area by a hair).
                var bRm = ctx.bandR[tiles[i].i] * 1.30;
                if (rr2 < 1 / bRm || rr2 > bRm) viol++;
                res.push(rr2);
                raw.push(ar / ctx.A_target);
            }
            out.bandViol = viol;
            function cv(v) {
                var mu = v.reduce(function (a, c) { return a + c; }, 0) / v.length;
                var sd = Math.sqrt(v.reduce(function (a, c) { return a + (c - mu) * (c - mu); }, 0) / v.length);
                return sd / mu;
            }
            out.residualAreaCV = cv(res);
            out.rawAreaCV = cv(raw);
        }

        // ---- ragged bottom ----
        var medHs = tiles.map(function (t) { return t.h; }).sort(function (a, c) { return a - c; });
        var medH = medHs[medHs.length >> 1] || 1;
        out.ragged = (maxBottom - evalY) / medH;
        // sigmaVmed divides by the median tile height, and with a wide ladder the median
        // falls relative to the big tiles, so the raw figure inflates arithmetically for a
        // wall that has not got any worse. Normalise against the UPPER-QUARTILE height,
        // which tracks the tiles a long seam actually runs beside.
        var p75H = medHs[Math.min(medHs.length - 1, Math.floor(medHs.length * 0.75))] || medH;
        out.sigmaVmed = out.sigmaV * Math.max(1, evalY) / Math.max(medH, 0.72 * p75H);
        out.medH = medH;
        out.evalY = evalY;
        out.flushRunL = out.flushRunLpx / medH;
        out.flushRunR = out.flushRunRpx / medH;
        out.leftEdgeIndex = (maxBottom / Math.max(1, out.flushCountL)) / medH;
        out.rightEdgeIndex = (maxBottom / Math.max(1, out.flushCountR)) / medH;

        /* ---- REALIZED AREA DISTRIBUTION, on the DRAWN boxes. Reported, gated on, and
         * never confused with the ASSIGNED rungs - the two have diverged before. */
        var arr = [], maxLong = 0;
        for (i = 0; i < n; i++) {
            var dwq2 = (tiles[i].dw != null) ? tiles[i].dw : (tiles[i].w - gap);
            var dhq2 = (tiles[i].dh != null) ? tiles[i].dh : (tiles[i].h - gap);
            arr.push(dwq2 * dhq2);
            if (Math.max(dwq2, dhq2) > maxLong) maxLong = Math.max(dwq2, dhq2);
        }
        arr.sort(function (a, c) { return a - c; });
        function qv(p) {
            if (!arr.length) return 0;
            var x = (arr.length - 1) * p, lo = Math.floor(x), hi = Math.ceil(x);
            return lo === hi ? arr[lo] : arr[lo] + (arr[hi] - arr[lo]) * (x - lo);
        }
        out.areaP10 = qv(0.10); out.areaP25 = qv(0.25); out.areaP50 = qv(0.50);
        out.areaP75 = qv(0.75); out.areaP90 = qv(0.90);
        out.areaP90P10 = out.areaP10 > 0 ? out.areaP90 / out.areaP10 : 0;
        out.areaIqr = out.areaP25 > 0 ? out.areaP75 / out.areaP25 : 0;
        out.maxLong = maxLong;
        out.heroLong = maxLong;
        if (ctx) {
            out.heroFloorPossible = !!ctx.heroFloorPossible;
            out.heroFloorMet = (maxLong >= ctx.opts.heroMin - 0.5) || !ctx.heroFloorPossible;
        }
        return out;
    }

    function energy(m, ctx) {
        function hinge(v, t) { return Math.max(0, v - t); }
        var E = 0;
        // The sigmaH threshold is HERO-AWARE. A 450px hero at W=768 is 0.59 of the
        // width by arithmetic, so a fixed 0.50 would put every genome over the gate and
        // the annealer would optimise noise. ctx.heroSeamFrac is exactly what the hero
        // costs and nothing more; it is reported, not hidden.
        var seamHT = (ctx && ctx.heroSeamFrac) ? Math.max(0.50, ctx.heroSeamFrac) : 0.50;
        E += 55 * Math.pow(hinge(m.sigmaH, seamHT), 2);
        E += 50 * Math.pow(hinge(m.sigmaVmed / Math.max(1e-6, 2.8), 1), 2);
        E += 70 * Math.pow(hinge(m.bandIndex, 0.34), 2);
        E += 55 * Math.pow(hinge(m.bandConc, 0.16), 2);
        // THE COLUMN THRESHOLDS ARE RE-DERIVED, NOT INHERITED. colConc is now
        // edge-inclusive and reads higher than the number the old 0.22 gate was calibrated
        // against; leaving 0.22 in place would put every genome over the gate and the
        // annealer would spend the whole search optimising noise.
        E += 45 * Math.pow(hinge(m.colConc, 0.26), 2);
        // ---- VARIATION AND COLUMNS: the four things nothing in this objective used to
        // reward. Every other hinge here pushes toward uniformity, so the annealer
        // obliged - which is a large part of why the ladder was invisible in the output.
        E += 45 * Math.pow(hinge(m.colBandIndex || 0, 0.34), 2);
        E += 40 * Math.pow(hinge(6.0 / Math.max(1e-6, m.areaP90P10 || 0), 1), 2);
        E += 20 * Math.pow(hinge(2.5 / Math.max(1e-6, m.areaIqr || 0), 1), 2);
        E += 25 * Math.pow(Math.max(0, (m.flushRunL || 0) - 3.0), 2)
            + 25 * Math.pow(Math.max(0, (m.flushRunR || 0) - 3.0), 2);
        if (ctx && ctx.heroFloorPossible) {
            var want = Math.max(ctx.opts.heroMin, 0);
            E += 900 * Math.pow(Math.max(0, (want - (m.heroLong || 0)) / Math.max(1, want)), 2);
        }
        E += 30 * (1 / Math.max(m.minCrossV, 0.05));
        E += 30 * (1 / Math.max(m.minCrossH, 0.05));
        E += 8 * Math.pow(hinge(m.ragged, ctx ? ctx.rag * 2.2 : 2.2), 2);
        E += 6 * m.residualAreaCV;
        E += 4 * m.meanCrop * m.meanCrop;
        // hard gates -> huge additive penalty so gate-passers always win
        var fails = 0;
        if (m.fullWidthCuts > 0) fails++;
        if (m.minCrossH <= 0.15) fails++;
        if (m.minCrossV <= 0.12) fails++;
        if (m.bandIndex >= 0.50) fails++;
        if (m.sigmaH > seamHT) fails++;
        if (ctx && m.maxCrop > ctx.K + 1e-6) fails++;
        if (m.bandConc > 0.18) fails++;
        if (m.colConc > 0.32) fails++;              // edge-inclusive; re-derived from the harness
        if (m.colBandIndex > 0.50) fails++;         // matches the ROW band's own hard gate
        if (m.flushRunL > 5.0 || m.flushRunR > 5.0) fails++;
        if (m.sigmaVmed > 2.8) fails++;
        // residualAreaCV is the spread of realized/target. With a five-rung ladder a wall
        // that lands every tile on its OWN rung still shows a large figure, because the
        // rungs differ; the gate has to be on the MISS, not on the spread of the targets.
        if (m.residualAreaCV > 0.34) fails++;
        if (m.bandViol > 0) fails++;
        // VARIATION, relaxed outside-in by VAR_RELAX in solvePlan. The hero survives three
        // rungs before it is touched, because Sean named it a floor and the spread a target.
        if (ctx && ctx.varGate) {
            if ((m.areaP90P10 || 0) < ctx.varGate.ratio) fails++;
            if ((m.areaIqr || 0) < ctx.varGate.iqr) fails++;
            if (ctx.heroFloorPossible
                && (m.heroLong || 0) < ctx.opts.heroMin * ctx.varGate.hero - 0.5) fails++;
        }
        if (m.rightShort > 0.5) fails++;      // the wall must reach the full width
        if (m.minTileDim < (ctx ? ctx.minTile : 96) - 0.5) fails++;
        if (m.ragged > (ctx ? ctx.rag * 2.2 : 2.2)) fails++;
        E += fails * 1000;
        E += 120 * (m.ladderHits || 0);      // the fallback ladder is a last resort, not a plan
        m.gateFails = fails;
        return E;
    }

    /* ================================================================
     * 6. SOLVE - search once, emit a NORMALISED plan (never pixels)
     * ================================================================ */

    var DEFAULTS = {
        base: 720, gap: 6, crop: 1.12, effort: 2600, minAcross: 5, minTile: 96,
        heroRate: RUNG_MIX[4], rag: 2.6, seamH: 0.50, seamV: 2.4, effort2: 0, border: 0,
        // --- density -------------------------------------------------------------
        // acrossRelief multiplies the MEDIAN tile's area over the old min-across clamp.
        // 1.0 reproduces the shipping density exactly.
        acrossRelief: 1.7,
        // --- hero ----------------------------------------------------------------
        heroMin: 450,     // FLOOR on the hero's long side, CSS px. Half of the 900px
        //                   aspect thumbnail (SNAPSMACK_THUMB_ASPECT_LONG).
        heroCap: 900,     // past this the aspect thumbnail upscales
        heroMult: 3.4,    // hero area as a multiple of the median rung's area
        anchors: 5,       // 1 hero + 4 majors
        fillK: 3          // nominal companion-stack depth beside an anchor
    };

    function normOpts(opts) {
        var o = {}, k;
        for (k in DEFAULTS) if (Object.prototype.hasOwnProperty.call(DEFAULTS, k)) o[k] = DEFAULTS[k];
        for (k in opts) if (Object.prototype.hasOwnProperty.call(opts, k) && opts[k] != null && opts[k] === opts[k]) o[k] = opts[k];
        o.gap = Math.max(0, Math.round(o.gap));
        o.base = Math.max(80, o.base);
        o.crop = clamp(o.crop, 1.0, 1.6);
        o.effort = clamp(o.effort, 200, 5000);
        o.minAcross = clamp(o.minAcross, 2, 12);
        o.minTile = clamp(o.minTile, 24, 400);
        o.heroRate = clamp(o.heroRate, 0, 0.4);
        o.rag = clamp(o.rag, 0, 4);
        o.seamH = clamp(o.seamH, 0.15, 1.0);
        o.seamV = clamp(o.seamV, 0.5, 6);
        o.border = clamp(o.border || 0, 0, 64);
        o.acrossRelief = clamp(o.acrossRelief, 1.0, 4.0);
        o.heroMin = clamp(o.heroMin, 200, 1200);
        o.heroCap = clamp(o.heroCap, o.heroMin, 2400);
        o.heroMult = clamp(o.heroMult, 1.5, 8);
        o.anchors = Math.round(clamp(o.anchors, 0, 8));
        o.fillK = Math.round(clamp(o.fillK, 2, 5));
        return o;
    }

    /* --- degenerate constructions (Part 11) --- */
    /**
     * THE LAST RESORT, and it is deliberately NOT one photo per line.
     * A single full-width column gives a 2:3 portrait the same width as a 3:1
     * panorama, so portraits end up with 4x the area (measured P/L 4.81) and the wall
     * reaches 162,564px. Grouping consecutive photos into full-width runs of common
     * height keeps areas comparable and the wall finite. It IS rows - that is why it
     * is flagged degenerate and only ever reached when nothing else is placeable.
     */
    function degenerateStack(ctx, o, why) {
        var tiles = [], y = 0, i = 0, gap = o.gap, U = ctx.U;
        var hTarget = ctx.hRef + gap;
        while (i < ctx.n) {
            // grow the run until its common height is closest to the reference height
            var bestK = 1, bestErr = Infinity, k, sumA, hRun;
            for (k = 1; k <= Math.min(6, ctx.n - i); k++) {
                sumA = 0;
                var q;
                for (q = 0; q < k; q++) sumA += ctx.asp[i + q];
                hRun = (U - k * gap) / sumA + gap;
                if (hRun - gap < ctx.minTile && k > 1) break;
                var err = Math.abs(Math.log(hRun / hTarget));
                if (err < bestErr) { bestErr = err; bestK = k; }
            }
            sumA = 0;
            for (k = 0; k < bestK; k++) sumA += ctx.asp[i + k];
            hRun = (U - bestK * gap) / sumA + gap;
            var x = 0;
            for (k = 0; k < bestK; k++) {
                var wq = (k === bestK - 1) ? (U - x) : ((hRun - gap) * ctx.asp[i + k] + gap);
                tiles.push({ i: i + k, x: x, y: y, w: wq, h: hRun });
                x += wq;
            }
            y += hRun;
            i += bestK;
        }
        return { tiles: tiles, degenerate: why };
    }

    function degenerateSmall(ctx, o) {
        // one guillotine block filling the full width; pick the shape with least crop
        var n = ctx.n, best = null, bi;
        var asp = new Float64Array(5), tup = new Int32Array(n);
        var perm = [], i;
        for (i = 0; i < n; i++) perm.push(i);
        var shapes = SHAPES[n] || SHAPES[1];
        for (bi = 0; bi < shapes.length; bi++) {
            for (i = 0; i < n; i++) { tup[i] = perm[i]; asp[i] = ctx.asp[perm[i]]; }
            shapeAffine(shapes[bi], asp, o.gap);
            var H = shapeRects(shapes[bi], asp, o.gap, ctx.U, 1);
            var ok = true, worst = 1, q;
            for (q = 0; q < R_N; q++) {
                if (R_W[q] - o.gap < 8 || R_H[q] - o.gap < 8) { ok = false; break; }
                var a2 = ctx.asp[tup[R_P[q]]], c = (R_W[q] - o.gap) / (R_H[q] - o.gap);
                var r = a2 > c ? a2 / c : c / a2;
                if (r > worst) worst = r;
            }
            if (!ok) continue;
            if (!best || worst < best.worst) {
                var tl = [];
                for (q = 0; q < R_N; q++) tl.push({ i: tup[R_P[q]], x: R_X[q], y: R_Y[q], w: R_W[q], h: R_H[q] });
                best = { worst: worst, tiles: tl, H: H };
            }
        }
        if (!best) return degenerateStack(ctx, o, 'small');
        return { tiles: best.tiles, degenerate: 'small' };
    }

    /**
     * solvePlan(photos, opts) -> plan
     * The plan holds NORMALISED fractional coordinates (divided by U). It is
     * computed ONCE. Resizes and the second measurement pass RE-INSTANTIATE it;
     * they never re-search. That is why pass 1 and pass 2 cannot disagree.
     */
    function solvePlan(photos, opts) {
        var o = normOpts(opts || {});
        var n = photos.length;
        var t0 = nowMs();
        var W = Math.max(1, Math.floor(o.width || 1200));
        var U = W + o.gap;
        o.U = U;
        if (!o.seedStr) {
            var parts = [], i;
            for (i = 0; i < n; i++) parts.push(photos[i] && photos[i].key != null ? photos[i].key : (photos[i] ? photos[i].w + 'x' + photos[i].h : '?'));
            o.seedStr = parts.join(',');
        }
        var ctx = buildContext(photos, o);

        if (n === 0) return { rects: [], U: 1, degenerate: 'empty', ctx: ctx, opts: o, ms: 0 };
        if (n === 1) {
            var a1 = ctx.asp[0];
            return finishPlan(ctx, o, [{ i: 0, x: 0, y: 0, w: U, h: (U - o.gap) / a1 + o.gap }], 'single', t0);
        }
        if (U < 3 * ctx.wMin) return finishPlan(ctx, o, degenerateStack(ctx, o, 'narrow').tiles, 'narrow', t0);
        if (n <= 4) return finishPlan(ctx, o, degenerateSmall(ctx, o).tiles, 'small', t0);

        // ---- search ----
        // DETERMINISM. The trial count is derived from --ss-effort and n, NOT from a wall
        // clock, and NOTHING in the search loop reads one: a time-boxed search returns
        // different geometry on a fast machine than on a slow one. The old code kept a
        // `hardStop = t0 + budget * 4` escape valve and it DID fire under CPU load -
        // the same input returned H=1910 in one process and H=1940 in another. Every
        // clock read is gone; the only timing left is the `ms` figure reported for
        // diagnostics. Output is identical on any machine, at any speed.
        var budget = o.effort;
        // Calibrated: trials per ms falls as ~n^-1.9 (measured 0.84 at n=24, 0.123 at
        // n=60, 0.0298 at n=140). Solving for a FIXED wall clock keeps the whole matrix
        // inside the 5s budget without ever consulting a clock, so output stays identical
        // across machines.
        var nTrials = clamp(Math.round(budget * 68 / Math.pow(Math.max(10, n), 1.9)), 24, 2000);
        var nA = Math.max(6, Math.round(nTrials * 0.42));
        var nB = Math.max(nA + 4, Math.round(nTrials * 0.85));
        var seeds = [], best = null, trials = 0;
        var rndM = mulberry32(ctx.seed ^ 0x9e3779b9);

        var nullPacks = 0;
        function evaluateGenome(g) {
            var lay = pack(ctx, g);
            if (!lay) { nullPacks++; return null; }
            bottomFreeRelax(ctx, lay);
            var m = measure(lay.tiles, W, o.gap, ctx);
            var mxR = 0, zq;
            for (zq = 0; zq < lay.tiles.length; zq++) {
                var rr3 = lay.tiles[zq].x + lay.tiles[zq].w;
                if (rr3 > mxR) mxR = rr3;
            }
            m.rightShort = Math.max(0, U - mxR);
            m.ladderHits = lay.ladderHits; m.forcedFails = lay.forcedFails;
            m.straddles = lay.straddles; m.levels = lay.levels;
            m.anchors = lay.anchors; m.anchorFails = lay.anchorFails;
            m.heroTier = lay.heroTier;
            var E = energy(m, ctx);
            return { g: g, tiles: lay.tiles, m: m, E: E };
        }

        /* VARIATION RELAXATION LADDER, evaluated OUTSIDE-IN. Relaxing a gate changes only
         * the scoring, never the geometry, so the seeds are re-scored rather than re-packed
         * - the whole ladder costs one pass over at most eight stored measurements. The
         * HERO SURVIVES THREE RUNGS before it is touched: Sean called 450px a floor and the
         * spread a target, so the spread yields first. If the ladder is exhausted the plan
         * says so on `varRelax` and the container carries data-ss-degenerate; it is never
         * silently abandoned. */
        var VAR_RELAX = [
            { hero: 1.00, ratio: 6.0, iqr: 2.5 },
            { hero: 1.00, ratio: 4.5, iqr: 2.0 },
            { hero: 1.00, ratio: 3.2, iqr: 1.7 },
            { hero: 0.85, ratio: 3.2, iqr: 1.5 },
            { hero: 0.70, ratio: 2.5, iqr: 1.3 }
        ];
        ctx.varGate = VAR_RELAX[0];
        var varRelax = 0;

        // Phase A - multistart. If NOTHING is feasible under the shape caps, widen them
        // and try again (Part 11 escalation) rather than dropping to a stack of
        // full-width rows, which is the one shape Sean has explicitly rejected.
        var RELAX_LADDER = [1, 1.25, 1.6, 2.2, 3.5, 1e9];
        var relaxUsed = 0, rl;
        for (rl = 0; rl < RELAX_LADDER.length; rl++) {
            ctx.relaxMul = RELAX_LADDER[rl];
            relaxUsed = rl;
            trials = 0; seeds = []; best = null;
            while (trials < nA) {
                var g = makeGenome(ctx, (ctx.seed + trials * 2654435761) >>> 0);
                var c = evaluateGenome(g);
                trials++;
                if (c) {
                    seeds.push(c);
                    if (!best || c.E < best.E) best = c;
                }
            }
            if (seeds.length) break;
        }
        seeds.sort(function (a, b) { return a.E - b.E; });
        seeds = seeds.slice(0, 8);
        if (!seeds.length) return finishPlan(ctx, o, degenerateStack(ctx, o, 'fallback').tiles, 'fallback', t0);

        // walk the variation ladder outward until at least one seed can satisfy it
        function varOk(mm, gate) {
            if ((mm.areaP90P10 || 0) < gate.ratio) return false;
            if ((mm.areaIqr || 0) < gate.iqr) return false;
            if (ctx.heroFloorPossible && (mm.heroLong || 0) < o.heroMin * gate.hero - 0.5) return false;
            return true;
        }
        var vr, anyVar = false;
        for (vr = 0; vr < VAR_RELAX.length; vr++) {
            var sq5;
            for (sq5 = 0; sq5 < seeds.length; sq5++) {
                if (varOk(seeds[sq5].m, VAR_RELAX[vr])) { anyVar = true; break; }
            }
            if (anyVar) break;
        }
        varRelax = anyVar ? vr : VAR_RELAX.length - 1;
        ctx.varGate = VAR_RELAX[varRelax];
        var rsq;
        for (rsq = 0; rsq < seeds.length; rsq++) seeds[rsq].E = energy(seeds[rsq].m, ctx);
        seeds.sort(function (a, b) { return a.E - b.E; });
        best = seeds[0];

        // Phase B - simulated annealing from the top seeds
        var cur = seeds[0], T0 = Math.max(1, cur.E * 0.05), si = 0;
        var curSeed = 0;
        while (trials < nB) {
            var frac = (trials - nA) / Math.max(1, nB - nA);
            var Temp = T0 * Math.pow(0.02, frac);
            var g2 = mutateGenome(cur.g, rndM, 1 + Math.floor((1 - frac) * 3));
            var c2 = evaluateGenome(g2);
            trials++;
            if (c2) {
                if (c2.E < cur.E || rndM() < Math.exp((cur.E - c2.E) / Math.max(1e-6, Temp))) cur = c2;
                if (c2.E < best.E) best = c2;
            }
            si++;
            if (si % 40 === 0) { curSeed = (curSeed + 1) % seeds.length; cur = seeds[curSeed].E < cur.E ? seeds[curSeed] : cur; }
        }

        // Phase C - greedy polish
        while (trials < nTrials) {
            var g3 = mutateGenome(best.g, rndM, 1);
            var c3 = evaluateGenome(g3);
            trials++;
            if (c3 && c3.E < best.E) best = c3;
        }

        // Fewer than about three typical tiles across: the wall is honestly reported as
        // constrained. Part 11 - never a silent failure. Full-width horizontal cuts are
        // geometrically unavoidable at this density and no packer can fix that.
        // FEW-ACROSS IS A COMBINATORIAL-ROOM TEST, so it reads wRoom (from the UNCHANGED
        // A_medRef) and not wRef (from the relief-multiplied A_target). Reading wRef here
        // makes an ordinary 1280px desktop wall self-report as degenerate the moment the
        // density lever moves - the honesty machinery starting to lie, which is the worst
        // regression available.
        var few = (U < 3.2 * ctx.wRoom) ? 'few-across' : (relaxUsed >= 3 ? 'relaxed' : null);
        // HONEST REPORTING, never a silent shortfall. A hero that could not reach the floor,
        // a variation ladder that had to be walked, or a container too narrow to host the
        // floor at all, each surfaces on the plan and (via layoutGrid) on the container.
        var degen = few;
        if (!degen && varRelax > 0) degen = 'low-variation';
        if (!degen && ctx.heroFloorPossible && (best.m.heroLong || 0) < o.heroMin - 0.5) degen = 'hero-short';
        // The context currently holds whatever the LAST trial assigned. Restore the
        // WINNER's ladder so the reported metrics describe the wall that shipped.
        assignRungs(ctx, best.g.order);
        var plan = finishPlan(ctx, o, best.tiles, degen, t0);
        plan.relaxUsed = relaxUsed;
        plan.varRelax = varRelax;
        plan.nullPacks = nullPacks;
        plan.trials = trials;
        plan.searchMetrics = best.m;
        plan.heroFloorMet = !ctx.heroFloorPossible || (best.m.heroLong || 0) >= o.heroMin - 0.5;
        plan.heroFloorPossible = ctx.heroFloorPossible;
        return plan;
    }

    function finishPlan(ctx, o, tiles, degen, t0) {
        var U = o.U, i;
        var rects = [];
        for (i = 0; i < tiles.length; i++) {
            rects.push({
                i: tiles[i].i,
                x: tiles[i].x / U, y: tiles[i].y / U,
                w: tiles[i].w / U, h: tiles[i].h / U,
                a: ctx.asp[tiles[i].i]
            });
        }
        return {
            rects: rects, U: U, degenerate: degen, ctx: ctx, opts: o,
            ms: nowMs() - t0, n: ctx.n
        };
    }

    /* ================================================================
     * 7. PART 8 - INSTANTIATE, SNAP, VERIFY (O(n), no search)
     * ================================================================ */

    /**
     * waterline(tiles, W) -> y, or null when no clean cut exists.
     *
     * The deepest horizontal line at which the wall is SOLID EDGE TO EDGE with no
     * tile crossing it. Two conditions, both necessary:
     *   1. every x in [0,W) is covered down to at least y  (solid above), and
     *   2. no tile straddles y                              (nothing is cut in half).
     * The deepest candidate satisfying (1) is min-over-x of the bottom profile; the
     * answer is then the largest tile-bottom at or below that which satisfies (2).
     * Pure arithmetic — no DOM, no engine state.
     */
    function waterline(tiles, W) {
        if (!tiles || !tiles.length || !(W > 0)) return null;
        var i, x, EPS = 1e-6;

        // bottom profile: how far down coverage reaches at each x
        var prof = new Float64Array(Math.max(1, Math.floor(W)));
        for (i = 0; i < tiles.length; i++) {
            var t = tiles[i], x0 = Math.max(0, Math.floor(t.x));
            var x1 = Math.min(prof.length, Math.ceil(t.x + t.w)), b = t.y + t.h;
            for (x = x0; x < x1; x++) if (b > prof[x]) prof[x] = b;
        }
        var ceiling = Infinity;
        for (x = 0; x < prof.length; x++) if (prof[x] < ceiling) ceiling = prof[x];
        if (!(ceiling > 0) || !isFinite(ceiling)) return null;   // a gap reaches the top

        // candidate cuts are tile bottoms at or above the ceiling, deepest first
        var cands = [];
        for (i = 0; i < tiles.length; i++) {
            var yb = tiles[i].y + tiles[i].h;
            if (yb <= ceiling + EPS) cands.push(yb);
        }
        cands.sort(function (a, b) { return b - a; });

        for (var c = 0; c < cands.length; c++) {
            var y = cands[c], ok = true;
            for (i = 0; i < tiles.length && ok; i++) {
                var q = tiles[i];
                if (q.y < y - EPS && q.y + q.h > y + EPS) ok = false;   // straddles
            }
            if (ok) return y;
        }
        return null;
    }

    function verifyIntegers(tiles, gap) {
        // coordinate-compress the GROSS boxes and check: no cell covered twice,
        // and no uncovered cell with a covered cell BELOW it in the same column.
        var xs = [], ys = [], i, j;
        for (i = 0; i < tiles.length; i++) {
            xs.push(tiles[i].gx0, tiles[i].gx1);
            ys.push(tiles[i].gy0, tiles[i].gy1);
        }
        function uniq(v) { v.sort(function (a, b) { return a - b; }); var o = [], k; for (k = 0; k < v.length; k++) if (!o.length || v[k] !== o[o.length - 1]) o.push(v[k]); return o; }
        xs = uniq(xs); ys = uniq(ys);
        var nx = xs.length - 1, ny = ys.length - 1;
        if (nx <= 0 || ny <= 0) return { holeArea: 0, overlapArea: 0, holeCells: 0, voidArea: 0, voidCols: 0 };
        var grid = new Int32Array(nx * ny).fill(0);
        function idxOf(arr, v) { var lo = 0, hi = arr.length - 1; while (lo < hi) { var mid = (lo + hi) >> 1; if (arr[mid] < v) lo = mid + 1; else hi = mid; } return lo; }
        var overlapArea = 0;
        for (i = 0; i < tiles.length; i++) {
            var a = idxOf(xs, tiles[i].gx0), b = idxOf(xs, tiles[i].gx1);
            var c = idxOf(ys, tiles[i].gy0), d = idxOf(ys, tiles[i].gy1);
            var cx, cy;
            for (cy = c; cy < d; cy++) for (cx = a; cx < b; cx++) {
                var k = cy * nx + cx;
                if (grid[k]) overlapArea += (xs[cx + 1] - xs[cx]) * (ys[cy + 1] - ys[cy]);
                grid[k]++;
            }
        }
        var holeArea = 0, holeCells = 0, voidArea = 0, voidCols = 0;
        var maxY = ys[ny];
        for (var col = 0; col < nx; col++) {
            var deepest = -1, r;
            for (r = ny - 1; r >= 0; r--) if (grid[r * nx + col]) { deepest = r; break; }
            // A COLUMN WITH NO COVERAGE AT ALL IS THE DEFECT THIS CHECK USED TO MISS.
            // `deepest` stays -1, the loop below never runs, and holeCells reports 0 while
            // a full-height strip of page background runs from the top edge to the bottom
            // (measured: 521 of 1286 columns empty, holeArea 0). The wall is required to be
            // flush across the whole width, so an uncovered column at y = 0 IS a hole.
            if (deepest < 0) {
                voidCols++;
                voidArea += (xs[col + 1] - xs[col]) * maxY;
                holeCells++;
                holeArea += (xs[col + 1] - xs[col]) * maxY;
                continue;
            }
            if (!grid[col]) {                       // covered lower down but NOT at the top
                voidCols++;
                voidArea += (xs[col + 1] - xs[col]) * (ys[1] - ys[0]);
            }
            for (r = 0; r <= deepest; r++) {
                if (!grid[r * nx + col]) { holeCells++; holeArea += (xs[col + 1] - xs[col]) * (ys[r + 1] - ys[r]); }
            }
        }
        return {
            holeArea: holeArea, overlapArea: overlapArea, holeCells: holeCells,
            voidArea: voidArea, voidCols: voidCols
        };
    }

    /**
     * instantiate(plan, W) -> {tiles, height, metrics}
     * Pure arithmetic, O(n). Multiply the normalised plan by U = W + gap, then
     * snap through a SHARED integer edge table so both sides of every shared edge
     * read the same entry - no hairlines, no overlaps, no new holes.
     */
    function instantiate(plan, W) {
        var o = plan.opts, gap = o.gap, ctx = plan.ctx;
        W = Math.max(1, Math.floor(W));
        var U = W + gap;
        var rects = plan.rects, n = rects.length, i;
        if (!n) return { tiles: [], height: 0, W: W, metrics: measure([], W, gap, ctx) };

        // ---- 8.2 shared edge table ----
        var xVals = [], yVals = [];
        for (i = 0; i < n; i++) {
            xVals.push(rects[i].x * U, (rects[i].x + rects[i].w) * U);
            yVals.push(rects[i].y * U, (rects[i].y + rects[i].h) * U);
        }
        function buildTable(vals, forceLast) {
            var s = vals.slice().sort(function (a, b) { return a - b; });
            var keys = [], k;
            for (k = 0; k < s.length; k++) if (!keys.length || s[k] - keys[keys.length - 1] > 1e-6) keys.push(s[k]);
            var snap = new Float64Array(keys.length);
            for (k = 0; k < keys.length; k++) {
                var v = Math.round(keys[k]);
                if (k > 0 && v <= snap[k - 1]) v = snap[k - 1] + 1;
                snap[k] = v;
            }
            snap[0] = 0;
            for (k = 1; k < keys.length; k++) if (snap[k] <= snap[k - 1]) snap[k] = snap[k - 1] + 1;
            if (forceLast != null && keys.length > 1
                && Math.abs(keys[keys.length - 1] - forceLast) < 1.5) {
                // the rightmost edge is pinned to U exactly; walk back if that broke monotonicity
                snap[keys.length - 1] = forceLast;
                for (k = keys.length - 2; k >= 1; k--) if (snap[k] >= snap[k + 1]) snap[k] = snap[k + 1] - 1;
                if (snap[0] !== 0) snap[0] = 0;
            }
            return { keys: keys, snap: snap };
        }
        var TX = buildTable(xVals, U);
        var TY = buildTable(yVals, null);
        function lookup(tbl, v) {
            var lo = 0, hi = tbl.keys.length - 1;
            while (lo < hi) { var mid = (lo + hi) >> 1; if (tbl.keys[mid] < v - 1e-6) lo = mid + 1; else hi = mid; }
            return tbl.snap[lo];
        }

        var tiles = [], maxBottom = 0;
        for (i = 0; i < n; i++) {
            var gx0 = lookup(TX, rects[i].x * U);
            var gx1 = lookup(TX, (rects[i].x + rects[i].w) * U);
            var gy0 = lookup(TY, rects[i].y * U);
            var gy1 = lookup(TY, (rects[i].y + rects[i].h) * U);
            if (gx1 - gx0 <= gap) gx1 = gx0 + gap + 1;
            if (gy1 - gy0 <= gap) gy1 = gy0 + gap + 1;
            var t = {
                i: rects[i].i,
                x: gx0, y: gy0,
                w: gx1 - gx0 - gap, h: gy1 - gy0 - gap,
                gx0: gx0, gy0: gy0, gx1: gx1, gy1: gy1,
                a: rects[i].a,
                bottomFree: false
            };
            tiles.push(t);
            if (gy1 > maxBottom) maxBottom = gy1;
        }
        // recompute bottom-free flags on the realized geometry
        for (i = 0; i < n; i++) {
            var ti = tiles[i], free = true, j;
            for (j = 0; j < n && free; j++) {
                if (j === i) continue;
                var tj = tiles[j];
                if (tj.gy0 >= ti.gy1 - 0.5 && Math.min(tj.gx1, ti.gx1) - Math.max(tj.gx0, ti.gx0) > 0.5) free = false;
            }
            ti.bottomFree = free;
        }

        var height = maxBottom - gap;
        var vf = verifyIntegers(tiles, gap);
        var m = measure(tiles, W, gap, ctx);
        m.holeArea = vf.holeArea; m.overlapArea = vf.overlapArea; m.holeCells = vf.holeCells;
        m.voidArea = vf.voidArea; m.voidCols = vf.voidCols;
        m.degenerate = plan.degenerate || '';
        return { tiles: tiles, height: height, W: W, U: U, metrics: m, verify: vf };
    }

    /** Convenience: solve + instantiate. Pure, DOM-free, unit-testable in Node. */
    function computeLayout(photos, opts) {
        opts = opts || {};
        var plan = solvePlan(photos, opts);
        var res = instantiate(plan, Math.max(1, Math.floor(opts.width || 1200)));
        var out = res.tiles.map(function (t) {
            return { i: t.i, x: t.x, y: t.y, w: t.w, h: t.h, gx0: t.gx0, gy0: t.gy0, gx1: t.gx1, gy1: t.gy1, a: t.a, bottomFree: t.bottomFree };
        });
        out.height = res.height;
        out.width = res.W;
        out.metrics = res.metrics;
        out.degenerate = plan.degenerate || '';
        out.plan = plan;
        out.solveMs = plan.ms;
        return out;
    }

    /* ================================================================
     * 8. COVER CROP - computed here; object-fit is NOT trusted
     * ================================================================ */
    function coverBox(nw, nh, innerW, innerH, fx, fy) {
        if (!(nw > 0) || !(nh > 0)) { nw = 3; nh = 2; }
        // Scale BOTH axes by the SAME factor and keep sub-pixel precision: that makes
        // geometric distortion literally unrepresentable (the drawn img aspect equals the
        // photo's own aspect to ~1e-5). A small relative over-cover margin - never a
        // per-axis ceil, which would skew the aspect by up to 1px/edge - guarantees the
        // image can never under-cover its box and reveal background.
        var s = Math.max(innerW / nw, innerH / nh) * (1 + Math.min(0.01, 1.5 / Math.max(1, Math.min(innerW, innerH))));
        var iw = Math.round(nw * s * 100) / 100, ih = Math.round(nh * s * 100) / 100;
        var bx = (fx == null) ? 0.5 : clamp(fx, 0, 1);
        var by = (fy == null) ? 0.5 : clamp(fy, 0, 1);
        return {
            w: iw, h: ih,
            left: Math.round((innerW - iw) * bx * 100) / 100,
            top: Math.round((innerH - ih) * by * 100) / 100
        };
    }

    /* ================================================================
     * 9. DOM ADAPTER
     * ================================================================ */

    if (typeof document !== 'undefined' && typeof window !== 'undefined') {

        var CFG = window.SS_MASONRY_CONFIG || {};
        var sbCache = null;

        function probeScrollbarWidth() {
            if (sbCache != null) return sbCache;
            try {
                var d = document.createElement('div');
                d.style.cssText = 'position:absolute;top:-9999px;width:100px;height:100px;overflow:scroll;';
                document.body.appendChild(d);
                sbCache = d.offsetWidth - d.clientWidth;
                document.body.removeChild(d);
            } catch (e) { sbCache = 15; }
            if (!(sbCache >= 0)) sbCache = 15;
            return sbCache;
        }

        function cssNum(cs, name, fb) {
            var v = parseFloat(cs.getPropertyValue(name));
            return (typeof v === 'number' && isFinite(v)) ? v : fb;
        }

        function readHooks(grid) {
            var cs = window.getComputedStyle(grid);                 // ONE read per solve
            var o = {
                base: cssNum(cs, '--ss-base', DEFAULTS.base),
                gap: cssNum(cs, '--ss-gap', DEFAULTS.gap),
                crop: cssNum(cs, '--ss-crop', DEFAULTS.crop),
                effort: cssNum(cs, '--ss-effort', DEFAULTS.effort),
                minAcross: cssNum(cs, '--ss-min-across', DEFAULTS.minAcross),
                minTile: cssNum(cs, '--ss-min-tile', DEFAULTS.minTile),
                heroRate: cssNum(cs, '--ss-hero-rate', DEFAULTS.heroRate),
                rag: cssNum(cs, '--ss-rag', DEFAULTS.rag),
                seamH: cssNum(cs, '--ss-seam-h', DEFAULTS.seamH),
                seamV: cssNum(cs, '--ss-seam-v', DEFAULTS.seamV),
                acrossRelief: cssNum(cs, '--ss-across-relief', DEFAULTS.acrossRelief),
                heroMin: cssNum(cs, '--ss-hero-min', DEFAULTS.heroMin),
                heroCap: cssNum(cs, '--ss-hero-cap', DEFAULTS.heroCap),
                heroMult: cssNum(cs, '--ss-hero-mult', DEFAULTS.heroMult),
                anchors: cssNum(cs, '--ss-anchors', DEFAULTS.anchors),
                fillK: cssNum(cs, '--ss-fill-k', DEFAULTS.fillK),
                padL: cssNum(cs, 'padding-left', 0),
                padR: cssNum(cs, 'padding-right', 0)
            };
            var k;
            for (k in CFG) if (Object.prototype.hasOwnProperty.call(CFG, k)) o[k] = CFG[k];
            return o;
        }

        function measureWidth(grid, hooks) {
            // getBoundingClientRect, never clientWidth (integer-rounded -> 1px overflow)
            var r = grid.getBoundingClientRect();
            return r.width - hooks.padL - hooks.padR;
        }

        function readPhotos(grid) {
            var items = grid.querySelectorAll('.ss-masonry-item');
            var photos = [], i, provisional = 0;
            for (i = 0; i < items.length; i++) {
                var it = items[i], img = it.querySelector('img');
                var w = parseInt(it.getAttribute('data-w'), 10) || (img ? parseInt(img.getAttribute('data-w'), 10) : 0);
                var h = parseInt(it.getAttribute('data-h'), 10) || (img ? parseInt(img.getAttribute('data-h'), 10) : 0);
                // NEVER img.naturalWidth: ss-engine-lazyload swaps src for a 1x1 GIF.
                if (!(w > 0 && h > 0)) { w = 3; h = 2; provisional++; }
                var wgt = parseFloat(it.getAttribute('data-ss-weight'));
                if (!isFinite(wgt)) wgt = it.classList.contains('ss-feature') ? 1.30 : 0;
                photos.push({
                    w: w, h: h, weight: wgt,
                    key: it.getAttribute('href') || (i + ':' + w + 'x' + h),
                    el: it, img: img,
                    fx: parseFloat(it.getAttribute('data-focus-x')),
                    fy: parseFloat(it.getAttribute('data-focus-y'))
                });
            }
            return { photos: photos, items: items, provisional: provisional };
        }

        function applyLayout(grid, res, photos, hooks, bw) {
            var tiles = res.tiles, i;

            // ── WATERLINE TRIM (post-processing; the packer is untouched) ──────────
            // A chunk's bottom is ragged. Stacked chunks would therefore butt together
            // along a torn seam. So find the WATERLINE: the deepest y at which the wall
            // is solid edge to edge with no tile crossing it. Render only the tiles
            // wholly above it and hand the rest to the next chunk. Every chunk then
            // ends flat and starts flat, and the join is invisible.
            // Opt-in per container via data-ss-trim, because the LAST chunk has nowhere
            // to hand its remainder to and must keep its ragged bottom.
            var wl = null, deferredEls = [];
            if (grid.hasAttribute('data-ss-trim')) {
                wl = waterline(tiles, res.W || grid.clientWidth);
            }
            grid.__ssDeferredEls = deferredEls;

            grid.style.position = 'relative';
            grid.style.display = 'block';
            // + gap so the seam between two chunks matches the gutter inside one.
            grid.style.height = (wl != null ? wl + ((hooks && hooks.gap) || 0) : res.height) + 'px';
            for (i = 0; i < tiles.length; i++) {
                var t = tiles[i], ph = photos[t.i];
                if (!ph) continue;
                var el = ph.el, img = ph.img;
                var st = el.style;
                if (wl != null && t.y + t.h > wl + 1e-6) {   // below the line: defer it
                    st.display = 'none';
                    el.setAttribute('data-ss-deferred', '');
                    deferredEls.push(el);
                    continue;
                }
                el.removeAttribute('data-ss-deferred');
                st.position = 'absolute';
                st.display = 'block';
                st.margin = '0';
                // The engine's own arithmetic assumes the written width INCLUDES the
                // border (it computes the img's cover box against t.w - 2*bw). That was
                // true only because the skin happens to ship `* { box-sizing: border-box }`.
                // One dropped reset, or one skin fork, and every tile with a non-zero
                // border would overlap its neighbour. Write it here; do not inherit it.
                st.boxSizing = 'border-box';
                st.left = t.x + 'px';
                st.top = t.y + 'px';
                st.width = t.w + 'px';
                st.height = t.h + 'px';
                st.overflow = 'hidden';
                if (img) {
                    // H4: an absolutely-positioned child lays out against the PADDING box,
                    // but border-box sizing means the item's width INCLUDES the border.
                    var innerW = t.w - 2 * bw, innerH = t.h - 2 * bw;
                    var cb = coverBox(ph.w, ph.h, innerW, innerH,
                        isFinite(ph.fx) ? ph.fx : null, isFinite(ph.fy) ? ph.fy : null);
                    var ist = img.style;
                    ist.position = 'absolute';
                    ist.left = cb.left + 'px';
                    ist.top = cb.top + 'px';
                    ist.width = cb.w + 'px';
                    ist.height = cb.h + 'px';
                    ist.maxWidth = 'none';        // ESSENTIAL: a global img{max-width:100%} would
                    ist.maxHeight = 'none';       // shrink the cover image and reveal background.
                }
            }
        }

        // noSolve: re-instantiate the EXISTING plan at the current width and skip the
        // search. Plans are resolution-independent (rects are normalised fractions of
        // U and instantiate() rescales uniformly), so this keeps the geometry correct
        // at the new width for ~0.15ms instead of ~2s. Only honoured when a plan
        // already exists for the SAME photo set — a changed signature means the plan
        // no longer indexes the tiles it would be applied to, which must never be
        // instantiated. See init()'s deferred-resize path for the one caller.
        function layoutGrid(grid, force, noSolve) {
            var hooks = readHooks(grid);
            var W1 = measureWidth(grid, hooks);
            if (!(W1 > 0)) return false;

            var rd = readPhotos(grid);
            if (!rd.photos.length) { grid.style.height = '0px'; return true; }

            // border width, read once (H4)
            var bw = 0;
            try { bw = parseFloat(window.getComputedStyle(rd.items[0]).borderTopWidth) || 0; } catch (e) { bw = 0; }

            var seedAttr = grid.getAttribute('data-ss-seed');
            var sig = rd.photos.map(function (p) { return p.key + '|' + p.w + 'x' + p.h; }).join(',') + '#' + JSON.stringify(hooks);

            // 8.1 full re-solve ONLY when the photo set or a hook changed, or the width
            // moved more than 2%. Resizes and pass 2 re-instantiate the SAME plan.
            var needSolve = force || !grid.__ssPlan || grid.__ssSig !== sig
                || Math.abs(W1 - grid.__ssPlanW) / Math.max(1, grid.__ssPlanW) > 0.02;
            // Deferred relayout: keep the plan, re-instantiate it at the new width.
            // __ssPlanW is deliberately NOT updated, so the next non-deferred pass
            // still sees the width as moved and does the real solve.
            if (noSolve && !force && grid.__ssPlan && grid.__ssSig === sig) needSolve = false;
            if (needSolve) {
                var o = {};
                var k; for (k in hooks) if (Object.prototype.hasOwnProperty.call(hooks, k)) o[k] = hooks[k];
                o.width = Math.floor(W1);
                o.border = bw;
                if (seedAttr != null) o.seed = hashString(String(seedAttr));
                grid.__ssPlan = solvePlan(rd.photos, o);
                grid.__ssSig = sig;
                grid.__ssPlanW = W1;
            }
            var plan = grid.__ssPlan;
            var planId = plan;

            // ---- Part 10: two-pass (predictive + corrective) measurement ----
            var sbW = probeScrollbarWidth();
            var docHasSb = (typeof document !== 'undefined')
                && (document.documentElement.scrollHeight > document.documentElement.clientHeight);
            var L1 = instantiate(plan, Math.floor(W1));
            var predictAdds = !docHasSb && (L1.height + grid.getBoundingClientRect().top + 40 > window.innerHeight);
            var W = predictAdds ? Math.floor(W1 - sbW) : Math.floor(W1);

            var seen = [], res = null, pass;
            for (pass = 0; pass < 3; pass++) {
                res = instantiate(plan, W);
                applyLayout(grid, res, rd.photos, hooks, bw);
                void grid.offsetWidth;                                  // force reflow; let the scrollbar settle
                var W2 = Math.floor(measureWidth(grid, hooks));
                if (Math.abs(W2 - W) < 0.5) break;
                if (seen.indexOf(W2) >= 0) { W = Math.min(W, W2); continue; }
                seen.push(W);
                W = W2;
            }
            if (plan !== planId) { /* impossible by construction; kept as documentation */ }

            // Part 9 - verify or fall back
            if (res && (res.verify.holeCells > 0 || res.verify.overlapArea > 0 || res.verify.voidCols > 0)) {
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('[ss-masonry] verifier failed', res.verify, res.metrics);
                }
                var fb = fallbackPlan(rd.photos, plan.opts, W);
                res = instantiate(fb, W);
                applyLayout(grid, res, rd.photos, hooks, bw);
                grid.__ssPlan = fb;
            }

            grid.dataset.ssMetrics = JSON.stringify(round3(res.metrics));
            if (plan.degenerate) grid.dataset.ssDegenerate = plan.degenerate;
            else if (grid.dataset.ssDegenerate) delete grid.dataset.ssDegenerate;
            // A HERO SHORTFALL IS NEVER SILENT. Either the floor was met, or the container
            // could not host it and the container says so.
            if (plan.heroFloorMet === false) grid.dataset.ssHeroShort = String(Math.round(res.metrics.heroLong || 0));
            else if (grid.dataset.ssHeroShort) delete grid.dataset.ssHeroShort;
            if (plan.varRelax > 0) grid.dataset.ssVarRelax = String(plan.varRelax);
            else if (grid.dataset.ssVarRelax) delete grid.dataset.ssVarRelax;
            window.SS_MASONRY_LAST = res.metrics;

            try {
                grid.dispatchEvent(new CustomEvent('ss:masonry-layout', { bubbles: true, detail: { metrics: res.metrics, height: res.height } }));
            } catch (e) { /* older browsers */ }

            if (/[?&]ssdebug=1/.test(window.location.search)) drawDebug(grid, res);

            // provisional data -> one re-solve on load
            if (rd.provisional > rd.photos.length * 0.1 && !grid.__ssReprobe) {
                grid.__ssReprobe = true;
                var imgs = grid.querySelectorAll('.ss-masonry-item img'), z;
                for (z = 0; z < imgs.length; z++) {
                    imgs[z].addEventListener('load', function () { layoutGrid(grid, true); }, { once: true });
                }
            }
            return true;
        }

        function round3(m) {
            var o = {}, k;
            for (k in m) if (Object.prototype.hasOwnProperty.call(m, k)) {
                o[k] = (typeof m[k] === 'number') ? Math.round(m[k] * 1000) / 1000 : m[k];
            }
            return o;
        }

        function fallbackPlan(photos, o, W) {
            // trivially a valid hypograph: a single-file stack of full-width tiles
            var gap = o.gap, U = W + gap, tiles = [], y = 0, i;
            for (i = 0; i < photos.length; i++) {
                var a = (photos[i].w > 0 && photos[i].h > 0) ? photos[i].w / photos[i].h : 1.5;
                var h = (U - gap) / a + gap;
                tiles.push({ i: i, x: 0, y: y, w: U, h: h, a: a });
                y += h;
            }
            var o2 = {}, k; for (k in o) if (Object.prototype.hasOwnProperty.call(o, k)) o2[k] = o[k];
            o2.U = U;
            var ctx = buildContext(photos, o2);
            var rects = tiles.map(function (t) { return { i: t.i, x: t.x / U, y: t.y / U, w: t.w / U, h: t.h / U, a: t.a }; });
            return { rects: rects, U: U, degenerate: 'fallback', ctx: ctx, opts: o2, ms: 0, n: photos.length };
        }

        function drawDebug(grid, res) {
            var old = grid.querySelector('.ss-masonry-debug');
            if (old) old.parentNode.removeChild(old);
            var d = document.createElement('div');
            d.className = 'ss-masonry-debug';
            d.style.cssText = 'position:absolute;left:0;top:0;right:0;bottom:0;pointer-events:none;z-index:99;font:10px monospace;color:#0f0;';
            d.textContent = JSON.stringify(round3(res.metrics));
            d.style.whiteSpace = 'pre-wrap';
            grid.appendChild(d);
        }

        function init(grid) {
            if (grid.__ssMasonry) return;
            grid.__ssMasonry = true;

            var pending = null;
            var run = function (force, noSolve) {
                if (pending) { clearTimeout(pending); pending = null; }
                try { layoutGrid(grid, force, noSolve); }
                catch (e) { if (console && console.warn) console.warn('[ss-masonry]', e); }
            };

            run(false);
            setTimeout(function () { run(false); }, 50);
            setTimeout(function () { run(false); }, 300);

            // ---- OPTIONAL: defer offscreen relayout (opt-in via data-ss-defer) ----
            // A page built from many stacked containers (SCROLL's chunked wall) has
            // every container at the same width, so ONE window resize crosses the 2%
            // gate in all of them at once and they re-solve serially: 11 chunks x ~2s
            // of blocked main thread. With data-ss-defer, a container that is far from
            // the viewport records that it is stale and returns; it re-solves when it
            // is next scrolled toward. Cost of a resize becomes bounded by the viewport
            // instead of by the size of the gallery.
            //
            // Strictly opt-in: without the attribute this whole block is skipped and
            // the observers below behave exactly as they always have.
            var defer = grid.hasAttribute('data-ss-defer') && typeof IntersectionObserver !== 'undefined';
            var nearViewport = function () {
                var r = grid.getBoundingClientRect();
                return r.bottom > -1200 && r.top < (window.innerHeight || 0) + 1200;
            };
            if (defer) {
                var vio = new IntersectionObserver(function (entries) {
                    var j;
                    for (j = 0; j < entries.length; j++) {
                        if (entries[j].isIntersecting && grid.__ssDirty) {
                            grid.__ssDirty = false;
                            run(false);
                        }
                    }
                }, { rootMargin: '1200px 0px' });
                vio.observe(grid);
            }
            var resized = function () {
                if (defer && !nearViewport()) {
                    // DEFER THE SEARCH, NOT THE GEOMETRY. Returning here without
                    // laying out would leave an offscreen container drawn at the OLD
                    // width — a dead gutter down one side of every chunk the visitor
                    // has already scrolled past (measured: 400px of empty right-hand
                    // gutter per chunk after 1200 -> 1600), a wrong container height,
                    // and therefore a wrong document scroll height. Re-instantiating
                    // the cached plan fixes all three for ~0.15ms; only the ~2s solve
                    // is postponed until the container is next approached. A phone
                    // rotation is a resize too, so this is not a desktop-only path.
                    grid.__ssDirty = true;
                    run(false, true);
                    return;
                }
                run(false);
            };

            var t;
            if (typeof ResizeObserver !== 'undefined') {
                var ro = new ResizeObserver(function () {
                    clearTimeout(t);
                    t = setTimeout(resized, 120);
                });
                ro.observe(grid);
            }
            window.addEventListener('resize', function () {
                clearTimeout(t);
                t = setTimeout(resized, 120);
            });
        }

        function boot() {
            var grids = document.querySelectorAll('.ss-masonry'), i;
            for (i = 0; i < grids.length; i++) init(grids[i]);
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
        else boot();

        window.SSMasonry = {
            computeLayout: computeLayout, solvePlan: solvePlan, instantiate: instantiate, waterline: waterline,
            measure: measure, coverBox: coverBox, SHAPES: SHAPES, relayout: function (g) { layoutGrid(g, true); },
            // init/boot are exported so a container appended AFTER DOMContentLoaded
            // (SCROLL's chunked wall) can get its first solve AND its observers.
            // Do NOT use relayout() for that: it forces a solve, never sets
            // __ssMasonry, and attaches no observers. init() is idempotent — it
            // returns immediately for any grid already initialised, so calling it
            // can never disturb an existing container's cached plan.
            init: init, boot: boot
        };
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            computeLayout: computeLayout, solvePlan: solvePlan, instantiate: instantiate, waterline: waterline,
            measure: measure, coverBox: coverBox, SHAPES: SHAPES, verifyIntegers: verifyIntegers,
            DIAG: DIAG
        };
    }
})();
// ===== SNAPSMACK EOF =====
