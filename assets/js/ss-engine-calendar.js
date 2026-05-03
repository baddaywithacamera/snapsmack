/**
 * SNAPSMACK - Archive Calendar Engine
 *
 * Archive-integrated sliding calendar panel. Activates automatically when the
 * archive layout is 'croppedwithcalendar' (body class archive-layout-croppedwithcalendar).
 * Slides in from the right; slides out before navigating to any other layout.
 *
 * Supports single-day navigation and two-click date-range selection.
 * Month count is computed from available viewport height on open.
 * Colours inherit from the active skin's CSS custom properties.
 *
 * Activated by: require_scripts[] = 'smack-calendar' in skin manifest.
 * Triggered by: archive layout toggle (layout=croppedwithcalendar).
 *
 * Date selection UX:
 *   First click on a day  → enters range-start mode (day highlighted)
 *   Second click same day → single-day navigate (?date=YYYY-MM-DD)
 *   Second click other day → range navigate (?from=DATE&to=DATE)
 *   ESC while in range-start mode → cancel, return to browsing
 *
 * SMACK_CONFIG.calendar keys (emitted by core/meta.php):
 *   endpoint — URL of api-calendar.php
 */

(function () {
    'use strict';

    // ── Config ─────────────────────────────────────────────────────────────

    var cfg      = (window.SMACK_CONFIG && window.SMACK_CONFIG.calendar) || {};
    var ENDPOINT = cfg.endpoint || '/api-calendar.php';

    // Height of one rendered month block (heading + day-of-week row + grid + margin).
    // Used to compute how many months fit in the viewport.
    var MONTH_BLOCK_H = 215;
    var PANEL_CHROME  = 120; // header + nav + padding

    // ── State ──────────────────────────────────────────────────────────────

    var panel       = null;
    var calBody     = null;
    var rangeHint   = null;
    var isOpen      = false;
    var isLoading   = false;
    var monthOffset = 0;
    var computedMonths = 1;

    // Range-selection state
    var rangeStart  = null; // 'YYYY-MM-DD' or null
    var allDayCells = [];   // flat list of rendered {dateKey, el} for hover preview

    // ── DOM construction ───────────────────────────────────────────────────

    function buildPanel() {
        panel = document.createElement('div');
        panel.id = 'smack-cal-panel';
        panel.setAttribute('aria-label', 'Archive Calendar');
        panel.setAttribute('role', 'complementary');
        panel.classList.add('smack-cal--right');

        // Header
        var header = document.createElement('div');
        header.className = 'smack-cal-header';

        var title = document.createElement('span');
        title.className = 'smack-cal-title';
        title.textContent = 'Browse by Date';
        header.appendChild(title);

        var closeBtn = document.createElement('button');
        closeBtn.className = 'smack-cal-close';
        closeBtn.setAttribute('aria-label', 'Close calendar');
        closeBtn.innerHTML = '&#x2715;';
        closeBtn.addEventListener('click', function () {
            // Navigate to cropped layout (removes calendar)
            var fallback = findFallbackLayoutLink();
            slideOutThen(function () {
                window.location.href = fallback;
            });
        });
        header.appendChild(closeBtn);
        panel.appendChild(header);

        // Range hint bar (hidden until range-start is set)
        rangeHint = document.createElement('div');
        rangeHint.className = 'smack-cal-range-hint';
        rangeHint.style.display = 'none';
        panel.appendChild(rangeHint);

        // Calendar body
        calBody = document.createElement('div');
        calBody.className = 'smack-cal-months';
        panel.appendChild(calBody);

        // Navigation
        var navRow = document.createElement('div');
        navRow.className = 'smack-cal-nav';

        var prevBtn = document.createElement('button');
        prevBtn.className = 'smack-cal-nav-btn';
        prevBtn.innerHTML = '&larr; Earlier';
        prevBtn.addEventListener('click', function () { monthOffset--; loadData(); });

        var nextBtn = document.createElement('button');
        nextBtn.id = 'smack-cal-next';
        nextBtn.className = 'smack-cal-nav-btn smack-cal-nav-next';
        nextBtn.innerHTML = 'Later &rarr;';
        nextBtn.addEventListener('click', function () {
            if (monthOffset < 0) { monthOffset++; loadData(); }
        });

        navRow.appendChild(prevBtn);
        navRow.appendChild(nextBtn);
        panel.appendChild(navRow);

        document.body.appendChild(panel);
    }

    // ── Viewport height → month count ──────────────────────────────────────

    function computeMonthCount() {
        var available = window.innerHeight - PANEL_CHROME;
        return Math.max(1, Math.floor(available / MONTH_BLOCK_H));
    }

    // ── AJAX ───────────────────────────────────────────────────────────────

    function loadData() {
        if (isLoading) return;
        isLoading = true;
        calBody.innerHTML = '<p class="smack-cal-loading-msg">Loading…</p>';

        computedMonths = computeMonthCount();

        var url = ENDPOINT
            + '?offset='  + encodeURIComponent(monthOffset)
            + '&months='  + encodeURIComponent(computedMonths)
            + '&count=5';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isLoading = false;
            if (xhr.status === 200) {
                try { renderCalendar(JSON.parse(xhr.responseText)); }
                catch (e) { calBody.innerHTML = '<p class="smack-cal-error">Parse error.</p>'; }
            } else {
                calBody.innerHTML = '<p class="smack-cal-error">Could not load calendar.</p>';
            }
        };
        xhr.send();

        // Disable next button when at current month
        var nextBtn = document.getElementById('smack-cal-next');
        if (nextBtn) nextBtn.disabled = (monthOffset >= 0);
    }

    // ── Render ─────────────────────────────────────────────────────────────

    function renderCalendar(data) {
        calBody.innerHTML = '';
        allDayCells = [];

        if (!data || !data.months || !data.months.length) {
            calBody.innerHTML = '<p class="smack-cal-error">No data.</p>';
            return;
        }

        data.months.forEach(function (monthData) {
            calBody.appendChild(buildMonthBlock(monthData, data.base_url));
        });

        updateRangeHighlights();
    }

    function buildMonthBlock(monthData, baseUrl) {
        var wrap = document.createElement('div');
        wrap.className = 'smack-cal-month';

        var heading = document.createElement('div');
        heading.className = 'smack-cal-month-name';
        heading.textContent = monthData.name + ' ' + monthData.year;
        wrap.appendChild(heading);

        var grid = document.createElement('div');
        grid.className = 'smack-cal-grid';

        // Day-of-week headers Mon→Sun
        ['Mo','Tu','We','Th','Fr','Sa','Su'].forEach(function (d) {
            var hdr = document.createElement('div');
            hdr.className = 'smack-cal-dow';
            hdr.textContent = d;
            grid.appendChild(hdr);
        });

        var firstDate   = new Date(monthData.year, monthData.month - 1, 1);
        var startOffset = (firstDate.getDay() + 6) % 7;
        var daysInMonth = new Date(monthData.year, monthData.month, 0).getDate();

        var today    = new Date();
        var todayKey = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

        // Empty cells before day 1
        for (var i = 0; i < startOffset; i++) {
            var empty = document.createElement('div');
            empty.className = 'smack-cal-day smack-cal-day--empty';
            grid.appendChild(empty);
        }

        for (var d = 1; d <= daysInMonth; d++) {
            var dateKey = monthData.year + '-' + pad(monthData.month) + '-' + pad(d);
            var count   = monthData.days[dateKey] || 0;

            var cell = document.createElement('div');
            cell.className = 'smack-cal-day';
            if (dateKey === todayKey)  cell.classList.add('smack-cal-day--today');
            if (count > 0)             cell.classList.add('smack-cal-day--has-post');

            cell.dataset.date  = dateKey;
            cell.dataset.count = count;
            cell.textContent   = d;

            if (count > 0) {
                cell.title = count + (count === 1 ? ' photo' : ' photos');
            }

            cell.addEventListener('click', function () { onDayClick(this); });
            cell.addEventListener('mouseenter', function () { onDayHover(this.dataset.date); });

            grid.appendChild(cell);
            allDayCells.push({ dateKey: dateKey, el: cell });
        }

        wrap.appendChild(grid);
        return wrap;
    }

    // ── Day interaction ────────────────────────────────────────────────────

    function onDayClick(cell) {
        var dateKey = cell.dataset.date;

        if (!rangeStart) {
            // First click — enter range-start mode
            rangeStart = dateKey;
            showRangeHint('Now click an end date — or click the same date for a single day.');
            updateRangeHighlights();
            calBody.classList.add('smack-cal-range-mode');
        } else if (dateKey === rangeStart) {
            // Clicked same cell — single day navigation
            var url = buildArchiveUrl({ date: dateKey });
            navigateTo(url);
        } else {
            // Second click — range navigation
            var d1 = rangeStart < dateKey ? rangeStart : dateKey;
            var d2 = rangeStart < dateKey ? dateKey : rangeStart;
            var url = buildArchiveUrl({ from: d1, to: d2 });
            navigateTo(url);
        }
    }

    function onDayHover(dateKey) {
        if (!rangeStart) return;
        // Preview range between rangeStart and hovered date
        var lo = rangeStart < dateKey ? rangeStart : dateKey;
        var hi = rangeStart < dateKey ? dateKey : rangeStart;
        allDayCells.forEach(function (c) {
            if (c.dateKey >= lo && c.dateKey <= hi) {
                c.el.classList.add('smack-cal-day--in-range-preview');
            } else {
                c.el.classList.remove('smack-cal-day--in-range-preview');
            }
        });
    }

    function updateRangeHighlights() {
        allDayCells.forEach(function (c) {
            c.el.classList.remove('smack-cal-day--range-start', 'smack-cal-day--in-range-preview');
            if (c.dateKey === rangeStart) {
                c.el.classList.add('smack-cal-day--range-start');
            }
        });
    }

    function clearRange() {
        rangeStart = null;
        rangeHint.style.display = 'none';
        calBody.classList.remove('smack-cal-range-mode');
        allDayCells.forEach(function (c) {
            c.el.classList.remove(
                'smack-cal-day--range-start',
                'smack-cal-day--in-range-preview'
            );
        });
    }

    function showRangeHint(msg) {
        rangeHint.textContent = msg;
        rangeHint.style.display = 'block';
    }

    // ── URL building ───────────────────────────────────────────────────────

    function buildArchiveUrl(params) {
        // Preserve layout=croppedwithcalendar so the panel stays open
        var base = window.location.pathname;
        var parts = ['layout=croppedwithcalendar'];
        if (params.date) parts.push('date=' + encodeURIComponent(params.date));
        if (params.from) parts.push('from=' + encodeURIComponent(params.from));
        if (params.to)   parts.push('to='   + encodeURIComponent(params.to));
        return base + '?' + parts.join('&');
    }

    function navigateTo(url) {
        window.location.href = url;
    }

    // ── Open / Close ───────────────────────────────────────────────────────

    function open() {
        if (isOpen) return;
        isOpen = true;
        // Force reflow then add open class for CSS transition
        panel.getBoundingClientRect();
        panel.classList.add('smack-cal-panel--open');
        loadData();
    }

    function slideOutThen(cb) {
        panel.classList.remove('smack-cal-panel--open');
        setTimeout(cb, 340);
    }

    // Find the URL of the first non-calendar layout toggle link
    function findFallbackLayoutLink() {
        var links = document.querySelectorAll('.archive-layout-toggle a[data-layout]');
        for (var i = 0; i < links.length; i++) {
            if (links[i].dataset.layout !== 'croppedwithcalendar') {
                return links[i].href;
            }
        }
        return 'archive.php';
    }

    // ── Keyboard ───────────────────────────────────────────────────────────

    function onKeydown(e) {
        if (e.key === 'Escape') {
            if (rangeStart) {
                clearRange();
            }
        }
    }

    // ── Wire layout toggle links to slide-out animation ───────────────────

    function wireLayoutLinks() {
        var links = document.querySelectorAll('.archive-layout-toggle a[data-layout]');
        links.forEach(function (link) {
            if (link.dataset.layout === 'croppedwithcalendar') return;
            link.addEventListener('click', function (e) {
                if (!isOpen) return;
                e.preventDefault();
                var href = this.href;
                slideOutThen(function () {
                    window.location.href = href;
                });
            });
        });
    }

    // ── Util ───────────────────────────────────────────────────────────────

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    // ── Init ───────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        var isCalLayout = document.body.classList.contains('archive-layout-croppedwithcalendar');
        if (!isCalLayout) return; // Only activate on calendar layout

        buildPanel();
        wireLayoutLinks();
        open();

        document.addEventListener('keydown', onKeydown);

        window.addEventListener('resize', function () {
            if (!isOpen || isLoading) return;
            var newCount = computeMonthCount();
            if (newCount !== computedMonths) loadData();
        });

        // Public API
        window.smackCalendar = {
            open:       open,
            clearRange: clearRange,
        };
    });

}());
