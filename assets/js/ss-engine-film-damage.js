/**
 * SNAPSMACK - Chaplin Skin / Film Damage Engine
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Full-viewport canvas overlay simulating aged cinema film:
 * vertical scratches, dust spots, hair/dirt, and gate weave.
 * Runs via requestAnimationFrame. Pointer-events: none.
 * Blended with mix-blend-mode: screen so it reads as light damage.
 *
 * Activated by: window.ChaplinFilmDamage.init(options)
 * Controlled by skin-header.php via chap_film_damage setting.
 */

(function (global) {
    'use strict';

    // ── Default configuration ────────────────────────────────────────────────
    var DEFAULTS = {
        intensity:      5,      // 1–10 overall damage intensity scale
        scratches:      true,   // vertical scratch lines
        dust:           true,   // dust spots
        hair:           true,   // hair / lint strands
        weave:          true,   // gate weave (horizontal jitter)
        blendMode:      'screen',
        opacity:        0.55,   // base canvas opacity
        fps:            24,     // target framerate — 24fps feels cinematic
    };

    // ── State ────────────────────────────────────────────────────────────────
    var canvas, ctx, raf, lastTime = 0, running = false;
    var W = 0, H = 0;
    var cfg = {};

    // Active element pools
    var scratches = [];
    var dustSpots  = [];
    var hairs      = [];
    var weaveOffset = 0, weaveTarget = 0, weaveVel = 0;

    // ── Utility ──────────────────────────────────────────────────────────────
    function rand(min, max) { return min + Math.random() * (max - min); }
    function randInt(min, max) { return Math.floor(rand(min, max + 1)); }
    function chance(p) { return Math.random() < p; }

    // ── Element factories ────────────────────────────────────────────────────

    function makeScratch() {
        var x = rand(0, W);
        var len = rand(H * 0.15, H * 0.95);
        var y = rand(-H * 0.1, H - len);
        return {
            x:       x,
            y:       y,
            len:     len,
            width:   rand(0.4, 1.2),
            alpha:   0,
            alphaMax: rand(0.25, 0.65) * (cfg.intensity / 10),
            life:    0,
            maxLife: randInt(2, 12),   // frames
            fadeIn:  randInt(1, 3),
            fadeOut: randInt(1, 4),
        };
    }

    function makeDust() {
        return {
            x:       rand(0, W),
            y:       rand(0, H),
            r:       rand(0.5, 2.5),
            alpha:   0,
            alphaMax: rand(0.3, 0.7) * (cfg.intensity / 10),
            life:    0,
            maxLife: randInt(3, 20),
            fadeIn:  randInt(1, 3),
            fadeOut: randInt(2, 5),
        };
    }

    function makeHair() {
        // A hair is a short irregular polyline
        var x0 = rand(0, W);
        var y0 = rand(0, H);
        var points = [{ x: x0, y: y0 }];
        var segCount = randInt(3, 8);
        for (var i = 0; i < segCount; i++) {
            var prev = points[points.length - 1];
            points.push({
                x: prev.x + rand(-12, 12),
                y: prev.y + rand(-8,  8),
            });
        }
        return {
            points:  points,
            width:   rand(0.4, 0.9),
            alpha:   0,
            alphaMax: rand(0.2, 0.5) * (cfg.intensity / 10),
            life:    0,
            maxLife: randInt(6, 30),
            fadeIn:  randInt(2, 5),
            fadeOut: randInt(3, 7),
        };
    }

    // ── Per-element alpha lifecycle ──────────────────────────────────────────

    function tickAlpha(el) {
        el.life++;
        if (el.life <= el.fadeIn) {
            el.alpha = el.alphaMax * (el.life / el.fadeIn);
        } else if (el.life >= el.maxLife - el.fadeOut) {
            var remaining = el.maxLife - el.life;
            el.alpha = el.alphaMax * Math.max(0, remaining / el.fadeOut);
        } else {
            el.alpha = el.alphaMax;
        }
        return el.life < el.maxLife;
    }

    // ── Draw routines ────────────────────────────────────────────────────────

    function drawScratch(s) {
        ctx.save();
        ctx.globalAlpha = s.alpha;
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth   = s.width;
        ctx.shadowColor = '#ffffff';
        ctx.shadowBlur  = 1;
        ctx.beginPath();
        // Slightly wobbly — add a tiny midpoint offset
        var midX = s.x + rand(-0.8, 0.8);
        ctx.moveTo(s.x, s.y);
        ctx.quadraticCurveTo(midX, s.y + s.len * 0.5, s.x + rand(-0.5, 0.5), s.y + s.len);
        ctx.stroke();
        ctx.restore();
    }

    function drawDust(d) {
        ctx.save();
        ctx.globalAlpha = d.alpha;
        ctx.fillStyle   = '#ffffff';
        ctx.shadowColor = '#ffffff';
        ctx.shadowBlur  = d.r * 1.5;
        ctx.beginPath();
        ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
    }

    function drawHair(h) {
        if (h.points.length < 2) return;
        ctx.save();
        ctx.globalAlpha = h.alpha;
        ctx.strokeStyle = '#cccccc';
        ctx.lineWidth   = h.width;
        ctx.lineJoin    = 'round';
        ctx.lineCap     = 'round';
        ctx.beginPath();
        ctx.moveTo(h.points[0].x, h.points[0].y);
        for (var i = 1; i < h.points.length; i++) {
            ctx.lineTo(h.points[i].x, h.points[i].y);
        }
        ctx.stroke();
        ctx.restore();
    }

    // ── Gate weave ───────────────────────────────────────────────────────────

    function tickWeave() {
        // Drift toward a random target, occasionally snap to new target
        if (chance(0.04)) {
            weaveTarget = rand(-1.8, 1.8) * (cfg.intensity / 10);
        }
        weaveVel  += (weaveTarget - weaveOffset) * 0.12;
        weaveVel  *= 0.7;
        weaveOffset += weaveVel;
    }

    // ── Spawn logic ──────────────────────────────────────────────────────────

    function spawnPass(frame) {
        var scale = cfg.intensity / 10;

        // Scratches: low probability each frame, more likely on "reel change" frames
        if (cfg.scratches) {
            var scratchP = 0.04 * scale;
            if (chance(scratchP)) scratches.push(makeScratch());
            // Occasional burst (splice / reel join)
            if (chance(0.003 * scale)) {
                var burst = randInt(2, 5);
                for (var i = 0; i < burst; i++) scratches.push(makeScratch());
            }
        }

        // Dust: semi-persistent, spawn a few per frame at low intensity
        if (cfg.dust) {
            var dustTarget = Math.round(scale * 6);
            if (dustSpots.length < dustTarget && chance(0.4)) {
                dustSpots.push(makeDust());
            }
        }

        // Hair: rare, slow
        if (cfg.hair) {
            if (hairs.length < Math.round(scale * 2) && chance(0.02 * scale)) {
                hairs.push(makeHair());
            }
        }
    }

    // ── Main loop ────────────────────────────────────────────────────────────

    function loop(ts) {
        if (!running) return;

        var interval = 1000 / cfg.fps;
        if (ts - lastTime < interval) {
            raf = requestAnimationFrame(loop);
            return;
        }
        lastTime = ts;

        // Clear
        ctx.clearRect(0, 0, W, H);

        // Gate weave — translate entire canvas
        if (cfg.weave) {
            tickWeave();
            ctx.save();
            ctx.translate(weaveOffset, 0);
        }

        // Spawn new elements
        spawnPass(ts);

        // Tick & draw scratches
        scratches = scratches.filter(function (s) {
            var alive = tickAlpha(s);
            if (alive) drawScratch(s);
            return alive;
        });

        // Tick & draw dust
        dustSpots = dustSpots.filter(function (d) {
            var alive = tickAlpha(d);
            if (alive) drawDust(d);
            return alive;
        });

        // Tick & draw hair
        hairs = hairs.filter(function (h) {
            var alive = tickAlpha(h);
            if (alive) drawHair(h);
            return alive;
        });

        if (cfg.weave) ctx.restore();

        raf = requestAnimationFrame(loop);
    }

    // ── Resize handler ───────────────────────────────────────────────────────

    function resize() {
        W = canvas.width  = window.innerWidth;
        H = canvas.height = window.innerHeight;
    }

    // ── Public API ───────────────────────────────────────────────────────────

    var ChaplinFilmDamage = {

        init: function (options) {
            cfg = Object.assign({}, DEFAULTS, options || {});

            // Build canvas
            canvas = document.createElement('canvas');
            canvas.id = 'chap-film-damage';
            canvas.style.cssText = [
                'position:fixed',
                'inset:0',
                'width:100%',
                'height:100%',
                'pointer-events:none',
                'z-index:9999',
                'mix-blend-mode:' + cfg.blendMode,
                'opacity:' + cfg.opacity,
            ].join(';');

            document.body.appendChild(canvas);
            ctx = canvas.getContext('2d');

            resize();
            window.addEventListener('resize', resize);

            running = true;
            raf = requestAnimationFrame(loop);
        },

        stop: function () {
            running = false;
            if (raf) cancelAnimationFrame(raf);
            if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
        },

        setIntensity: function (val) {
            cfg.intensity = Math.max(1, Math.min(10, val));
        },
    };

    global.ChaplinFilmDamage = ChaplinFilmDamage;

}(window));
// ===== SNAPSMACK EOF =====
