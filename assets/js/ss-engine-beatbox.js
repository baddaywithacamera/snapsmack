/**
 * SNAPSMACK — BEATBOX shared audio engine (Layer 0 / data bus)
 * ss-engine-beatbox.js
 *
 * The GRAMOFSMACK BEATBOX skin's audio core (spec: _spec/beatbox-skin-spec-v0_3.docx).
 * Owns the player UI, the playlist, MP3 loading, sessionStorage persistence, the
 * photosensitivity interstitial, and — critically — the SINGLE shared Web Audio
 * AnalyserNode. It is the one data bus for all three visual layers:
 *
 *   ss-engine-beatbox-viz.js  → Layer 1 (LED EQ tile borders) + Layer 2 (canvas viz)
 *   ss-engine-beatbox-bg.js   → Layer 3 (ORGANIZED MAYHEM BEATBOX collage)
 *
 * No layer creates its own AnalyserNode. They read band amplitudes and waveform
 * data from window.SnapBeatbox and render on the 'beatbox:frame' event this engine
 * dispatches each animation frame WHILE AUDIO IS PLAYING (so their rAF work
 * naturally pauses when audio pauses — no idle CPU burn, per spec build notes).
 *
 * AudioContext is NOT created until Play is pressed (browser autoplay requirement,
 * non-negotiable per spec).
 *
 * DATA CONTRACT — mount on a carrier carrying [data-beatbox] plus:
 *   data-bb-playlist        JSON [ {id,title,artist,src,duration}, ... ]  (ordered)
 *   data-bb-artist          site-wide artist / band name (player bar)
 *   data-bb-band-hz         JSON [[lo,hi] x5]  highs→bass  (spec defaults if absent)
 *   data-bb-intensity       default global intensity 0..10                       [3]
 *   data-bb-intensity-cap   owner max cap 0..10                                  [10]
 *   data-bb-loop            "1"/"0" loop playlist                                 [1]
 *   data-bb-bg-mode         off | viz | collage | both                        [off]
 *   data-bb-react           simultaneous | ripple                     [simultaneous]
 *   data-bb-warn            "1"/"0" show photosensitivity interstitial            [1]
 *
 * Player bar markup (skin template — NO inline JS): an element [data-beatbox-bar]
 * containing controls tagged data-bb="play|prev|next|prog|vol|intensity|bgmode|react".
 * Interstitial markup: [data-beatbox-warn] with data-bb="warn-continue|warn-off".
 * Everything degrades gracefully if a control is absent.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';
    if (window.__ssBeatboxEngine) return;
    window.__ssBeatboxEngine = true;

    function fault(where, err) {
        try { if (window.console && console.error) console.error('[beatbox] ' + where, err); } catch (e) {}
    }

    var SS_KEY = 'beatbox_state';       // sessionStorage: position/vol/intensity/mode
    var WARN_KEY = 'beatbox_warned';    // sessionStorage: interstitial shown once/session

    // Spec default Hz band ranges: highs, hi-mid, mid, lo-mid, bass.
    var DEFAULT_HZ = [[4000, 20000], [800, 4000], [250, 800], [80, 250], [20, 80]];

    var carrier = null;

    // ── shared bus (public) ────────────────────────────────────────────────
    var Bus = window.SnapBeatbox = {
        ready: false,
        playing: false,
        bands: [0, 0, 0, 0, 0],      // 0=highs … 4=bass  (matches row % 5 mapping)
        prevBands: [0, 0, 0, 0, 0],
        freq: null,                  // Uint8Array frequency data
        wave: null,                  // Uint8Array time-domain data
        intensity: 0.3,              // 0..1 (scaled from 0..10, capped)
        settings: {},                // parsed carrier config
        getBands: function () { return Bus.bands; },
        getWave: function () { return Bus.wave; }
    };

    function A(name, dflt) {
        var v = carrier && carrier.getAttribute ? carrier.getAttribute(name) : null;
        return v == null ? dflt : v;
    }
    function jsonAttr(name, dflt) {
        try { var v = A(name, null); return v == null ? dflt : (JSON.parse(v) || dflt); }
        catch (e) { return dflt; }
    }
    function clamp(v, lo, hi) { return v < lo ? lo : (v > hi ? hi : v); }

    // ── engine state ────────────────────────────────────────────────────────
    var ctx = null, analyser = null, master = null, media = null, mediaSrc = null;
    var bins = [];                 // [lo,hi] fft-bin index per band
    var playlist = [], idx = 0, loop = true;
    var intensityCap = 1, rafId = 0, running = false;

    function readSettings() {
        carrier = document.querySelector('[data-beatbox]');
        if (!carrier) return false;
        playlist = jsonAttr('data-bb-playlist', []) || [];
        loop = A('data-bb-loop', '1') !== '0';
        var hz = jsonAttr('data-bb-band-hz', DEFAULT_HZ);
        var cap = clamp(parseFloat(A('data-bb-intensity-cap', 10)) || 10, 0, 10);
        var inten = clamp(parseFloat(A('data-bb-intensity', 3)) || 0, 0, cap);
        intensityCap = cap / 10;
        Bus.intensity = inten / 10;
        Bus.settings = {
            hz: hz,
            artist: A('data-bb-artist', ''),
            bgMode: A('data-bb-bg-mode', 'off'),
            react: A('data-bb-react', 'simultaneous'),
            palette: A('data-bb-palette', 'classic')
        };
        // prefers-reduced-motion forces intensity to 0 (audio unaffected) — spec.
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) {
            Bus.intensity = 0;
        }
        applyIntensityVar();
        return true;
    }

    function applyIntensityVar() {
        if (carrier) carrier.style.setProperty('--bb-intensity', Bus.intensity.toFixed(3));
        document.documentElement.style.setProperty('--bb-intensity', Bus.intensity.toFixed(3));
    }

    // ── audio graph (lazy — only on first Play) ──────────────────────────────
    function ensureAudio() {
        if (ctx) return;
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) { fault('audio', 'No Web Audio API'); return; }
        ctx = new AC();
        analyser = ctx.createAnalyser();
        analyser.fftSize = 2048;
        analyser.smoothingTimeConstant = 0.75;
        Bus.freq = new Uint8Array(analyser.frequencyBinCount);
        Bus.wave = new Uint8Array(analyser.fftSize);
        master = ctx.createGain();
        master.gain.value = 0.9;
        master.connect(analyser);
        analyser.connect(ctx.destination);
        computeBins();
        Bus.ready = true;
    }

    function computeBins() {
        var nyq = ctx.sampleRate / 2, n = analyser.frequencyBinCount, hz = Bus.settings.hz || DEFAULT_HZ;
        bins = hz.map(function (r) {
            return [Math.max(1, Math.floor(r[0] / nyq * n)), Math.min(n - 1, Math.ceil(r[1] / nyq * n))];
        });
    }

    function computeBands() {
        analyser.getByteFrequencyData(Bus.freq);
        analyser.getByteTimeDomainData(Bus.wave);
        for (var b = 0; b < 5; b++) {
            var lo = bins[b][0], hi = bins[b][1], sum = 0, c = 0;
            for (var i = lo; i <= hi; i++) { sum += Bus.freq[i]; c++; }
            var v = (c ? sum / c : 0) / 255;
            v = Math.pow(v, 0.72);   // perceptual lift for sparse high bands
            Bus.prevBands[b] = Bus.bands[b];
            Bus.bands[b] = v;
        }
    }

    // ── playback ──────────────────────────────────────────────────────────────
    function currentTrack() { return playlist.length ? playlist[idx % playlist.length] : null; }

    function loadTrack(i, autoplay) {
        if (!playlist.length) return;
        idx = (i % playlist.length + playlist.length) % playlist.length;
        var t = currentTrack();
        if (!media) {
            media = new Audio();
            media.crossOrigin = 'anonymous';
            media.preload = 'metadata';
            media.addEventListener('ended', onEnded);
            media.addEventListener('timeupdate', onTime);
        }
        media.src = t.src;
        emitMeta(t);
        if (autoplay) media.play().then(afterPlay).catch(function (e) { fault('play', e); });
    }

    function afterPlay() {
        ensureAudio();
        if (ctx && ctx.state === 'suspended') ctx.resume();
        // wire the media element into the analyser exactly once
        if (ctx && media && !mediaSrc) {
            try { mediaSrc = ctx.createMediaElementSource(media); mediaSrc.connect(master); }
            catch (e) { fault('wire', e); }
        }
        Bus.playing = true;
        setPlayIcon(true);
        startLoop();
        dispatch('beatbox:play');
    }

    function play() {
        if (!playlist.length) return;
        if (!media || !media.src) { loadTrack(idx, true); return; }
        media.play().then(afterPlay).catch(function (e) { fault('play', e); });
    }
    function pause() {
        if (media) media.pause();
        Bus.playing = false;
        setPlayIcon(false);
        dispatch('beatbox:pause');
        // one final zeroed frame so Layer 1 settles to dim inactive state
        Bus.bands = [0, 0, 0, 0, 0];
        dispatch('beatbox:frame');
    }
    function toggle() { Bus.playing ? pause() : play(); }
    function next() { loadTrack(idx + 1, true); }
    function prev() { if (media && media.currentTime > 3) { media.currentTime = 0; return; } loadTrack(idx - 1, true); }

    function onEnded() {
        if (idx + 1 >= playlist.length && !loop) { pause(); return; }  // clean cut, no crossfade
        loadTrack(idx + 1, true);
    }

    // ── render loop (drives the shared frame event) ──────────────────────────
    function startLoop() {
        if (running) return;
        running = true;
        (function frame() {
            if (!Bus.playing) { running = false; return; } // pauses with audio — no idle burn
            computeBands();
            applyIntensityVar();
            dispatch('beatbox:frame');
            rafId = requestAnimationFrame(frame);
        })();
    }

    // ── UI wiring ─────────────────────────────────────────────────────────────
    var els = {};
    function bindBar() {
        var bar = document.querySelector('[data-beatbox-bar]');
        if (!bar) return;
        function pick(k) { return bar.querySelector('[data-bb="' + k + '"]'); }
        els.play = pick('play'); els.prev = pick('prev'); els.next = pick('next');
        els.prog = pick('prog'); els.progfill = pick('progfill'); els.time = pick('time');
        els.vol = pick('vol'); els.intensity = pick('intensity');
        els.bgmode = pick('bgmode'); els.react = pick('react');
        els.title = pick('title'); els.artist = pick('artist');

        if (els.play) els.play.addEventListener('click', toggle);
        if (els.prev) els.prev.addEventListener('click', prev);
        if (els.next) els.next.addEventListener('click', next);
        if (els.vol) els.vol.addEventListener('input', function (e) {
            if (media) media.volume = clamp(parseFloat(e.target.value), 0, 1); saveState();
        });
        if (els.intensity) els.intensity.addEventListener('input', function (e) {
            var v = clamp(parseFloat(e.target.value) / 10, 0, intensityCap);
            Bus.intensity = v; applyIntensityVar(); saveState();
        });
        if (els.bgmode) els.bgmode.addEventListener('change', function (e) {
            Bus.settings.bgMode = e.target.value; dispatch('beatbox:settings'); saveState();
        });
        if (els.react) els.react.addEventListener('change', function (e) {
            Bus.settings.react = e.target.value; dispatch('beatbox:settings'); saveState();
        });
        if (els.prog) els.prog.addEventListener('click', function (e) {
            if (!media || !media.duration) return;
            var r = els.prog.getBoundingClientRect();
            media.currentTime = clamp((e.clientX - r.left) / r.width, 0, 1) * media.duration;
        });
    }

    function emitMeta(t) {
        if (els.title) els.title.textContent = (t.title || 'Untitled').toUpperCase();
        if (els.artist) els.artist.textContent = t.artist || Bus.settings.artist || '';
    }
    function setPlayIcon(on) { if (els.play) els.play.setAttribute('data-playing', on ? '1' : '0'); }
    function onTime() {
        if (!media || !media.duration) return;
        var pct = media.currentTime / media.duration;
        if (els.progfill) els.progfill.style.width = (pct * 100) + '%';
        if (els.time) {
            var s = Math.floor(media.currentTime);
            els.time.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
        }
        if ((Math.floor(media.currentTime) % 4) === 0) saveState();
    }

    // ── persistence (sessionStorage — survives page nav, spec) ───────────────
    function saveState() {
        try {
            sessionStorage.setItem(SS_KEY, JSON.stringify({
                idx: idx,
                t: media ? media.currentTime : 0,
                vol: media ? media.volume : 1,
                inten: Bus.intensity,
                bg: Bus.settings.bgMode,
                react: Bus.settings.react
            }));
        } catch (e) {}
    }
    function restoreState() {
        var st; try { st = JSON.parse(sessionStorage.getItem(SS_KEY) || 'null'); } catch (e) { st = null; }
        if (!st) { if (playlist.length) loadTrack(0, false); return; }
        idx = st.idx || 0;
        if (typeof st.inten === 'number') { Bus.intensity = clamp(st.inten, 0, intensityCap); applyIntensityVar(); if (els.intensity) els.intensity.value = Math.round(Bus.intensity * 10); }
        if (st.bg) { Bus.settings.bgMode = st.bg; if (els.bgmode) els.bgmode.value = st.bg; }
        if (st.react) { Bus.settings.react = st.react; if (els.react) els.react.value = st.react; }
        if (playlist.length) {
            loadTrack(idx, false);
            if (media && st.t) { var seek = function () { try { media.currentTime = st.t; } catch (e) {} media.removeEventListener('loadedmetadata', seek); }; media.addEventListener('loadedmetadata', seek); }
            if (media && typeof st.vol === 'number') media.volume = st.vol;
        }
        dispatch('beatbox:settings');
    }

    // ── photosensitivity interstitial (DOMContentLoaded, once/session) ───────
    function interstitial() {
        if (A('data-bb-warn', '1') === '0') return;
        var shown; try { shown = sessionStorage.getItem(WARN_KEY); } catch (e) { shown = null; }
        if (shown) return;
        var modal = document.querySelector('[data-beatbox-warn]');
        if (!modal) return;                 // skin provides the markup
        modal.classList.add('bb-warn-show');
        var cont = modal.querySelector('[data-bb="warn-continue"]');
        var off = modal.querySelector('[data-bb="warn-off"]');
        function dismiss() { modal.classList.remove('bb-warn-show'); try { sessionStorage.setItem(WARN_KEY, '1'); } catch (e) {} }
        if (cont) cont.addEventListener('click', dismiss);
        if (off) off.addEventListener('click', function () {
            Bus.intensity = 0; applyIntensityVar();
            if (els.intensity) els.intensity.value = 0;
            Bus.settings.bgMode = 'off'; if (els.bgmode) els.bgmode.value = 'off';
            dispatch('beatbox:settings'); dismiss();
        });
    }

    // ── events ────────────────────────────────────────────────────────────────
    function dispatch(name) {
        try { document.dispatchEvent(new CustomEvent(name)); } catch (e) { fault('dispatch', e); }
    }

    // ── boot ────────────────────────────────────────────────────────────────
    function start() {
        if (!readSettings()) return;        // no [data-beatbox] carrier → not a BEATBOX page
        bindBar();
        if (els.intensity) els.intensity.value = Math.round(Bus.intensity * 10);
        restoreState();
        interstitial();
        dispatch('beatbox:settings');       // let viz/bg pick up initial modes
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();

})();
// ===== SNAPSMACK EOF =====
