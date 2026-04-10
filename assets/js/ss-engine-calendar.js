/**
 * SNAPSMACK - Archive Calendar Engine
 * Alpha v0.7.9f
 *
 * Renders a fixed sidebar calendar panel that slides in from the left or right.
 * Days with published images are highlighted as clickable links into the archive.
 * Below the calendar, a configurable list of recent post titles is shown.
 * Month navigation loads new data via AJAX without a page reload.
 *
 * Activated by: require_scripts[] = 'smack-calendar' in skin manifest.
 * Configured via: window.SMACK_CONFIG.calendar (emitted by core/meta.php).
 *
 * DOM requirements:
 *   #smack-cal-trigger  — button/link that opens/closes the panel
 *   (panel is created and injected by this engine)
 *
 * SMACK_CONFIG.calendar keys:
 *   side        — 'left' | 'right'  (default: 'left')
 *   months      — integer 1-3       (how many months to show, default: 1)
 *   postCount   — integer 5-20      (recent post list length, default: 10)
 *   endpoint    — string URL        (AJAX endpoint, default: '/api-calendar.php')
 */

(function () {
    'use strict';

    var cfg = (window.SMACK_CONFIG && window.SMACK_CONFIG.calendar) || {};
    var SIDE       = cfg.side      || 'left';
    var MONTHS     = Math.min(3, Math.max(1, parseInt(cfg.months,    10) || 1));
    var POST_COUNT = Math.min(20, Math.max(5, parseInt(cfg.postCount, 10) || 10));
    var ENDPOINT   = cfg.endpoint || '/api-calendar.php';

    // State
    var isOpen    = false;
    var isLoading = false;
    var panel     = null;
    var overlay   = null;
    var calBody   = null;
    var postList  = null;

    // Current displayed month offset from today (0 = current month)
    var monthOffset = 0;

    // ─────────────────────────────────────────────────────────────────────────
    // DOM CONSTRUCTION
    // ─────────────────────────────────────────────────────────────────────────

    function buildPanel() {
        // Overlay (click-outside-to-close)
        overlay = document.createElement('div');
        overlay.id = 'smack-cal-overlay';
        overlay.addEventListener('click', close);
        document.body.appendChild(overlay);

        // Panel
        panel = document.createElement('div');
        panel.id = 'smack-cal-panel';
        panel.setAttribute('aria-label', 'Archive Calendar');
        panel.setAttribute('role', 'complementary');
        panel.classList.add('smack-cal--' + SIDE);

        // Header row: title + close button
        var header = document.createElement('div');
        header.className = 'smack-cal-header';

        var title = document.createElement('span');
        title.className = 'smack-cal-title';
        title.textContent = 'Archive';
        header.appendChild(title);

        var closeBtn = document.createElement('button');
        closeBtn.className = 'smack-cal-close';
        closeBtn.setAttribute('aria-label', 'Close calendar');
        closeBtn.textContent = '✕';
        closeBtn.addEventListener('click', close);
        header.appendChild(closeBtn);

        panel.appendChild(header);

        // Calendar body (months rendered here)
        calBody = document.createElement('div');
        calBody.className = 'smack-cal-months';
        panel.appendChild(calBody);

        // Recent posts section
        var postSection = document.createElement('div');
        postSection.className = 'smack-cal-posts';

        var postLabel = document.createElement('div');
        postLabel.className = 'smack-cal-section-label';
        postLabel.textContent = 'Recent Posts';
        postSection.appendChild(postLabel);

        postList = document.createElement('ul');
        postList.className = 'smack-cal-post-list';
        postSection.appendChild(postList);

        panel.appendChild(postSection);

        document.body.appendChild(panel);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DATA / AJAX
    // ─────────────────────────────────────────────────────────────────────────

    function fetchData(offset, cb) {
        if (isLoading) return;
        isLoading = true;
        calBody.classList.add('smack-cal-loading');

        var url = ENDPOINT
            + '?offset=' + encodeURIComponent(offset)
            + '&months=' + encodeURIComponent(MONTHS)
            + '&count='  + encodeURIComponent(POST_COUNT);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            isLoading = false;
            calBody.classList.remove('smack-cal-loading');
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    cb(null, data);
                } catch (e) {
                    cb('Parse error');
                }
            } else {
                cb('HTTP ' + xhr.status);
            }
        };
        xhr.send();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RENDER
    // ─────────────────────────────────────────────────────────────────────────

    function renderCalendar(data) {
        calBody.innerHTML = '';

        if (!data || !data.months) return;

        data.months.forEach(function (monthData) {
            calBody.appendChild(buildMonthBlock(monthData, data.base_url));
        });

        // Navigation row (previous / next month)
        var navRow = document.createElement('div');
        navRow.className = 'smack-cal-nav';

        var prevBtn = document.createElement('button');
        prevBtn.className = 'smack-cal-nav-btn';
        prevBtn.textContent = '← Earlier';
        prevBtn.addEventListener('click', function () {
            monthOffset--;
            loadData();
        });

        var nextBtn = document.createElement('button');
        nextBtn.className = 'smack-cal-nav-btn smack-cal-nav-next';
        nextBtn.textContent = 'Later →';
        nextBtn.disabled = (monthOffset >= 0);
        nextBtn.addEventListener('click', function () {
            if (monthOffset < 0) {
                monthOffset++;
                loadData();
            }
        });

        navRow.appendChild(prevBtn);
        navRow.appendChild(nextBtn);
        calBody.appendChild(navRow);

        // Recent posts
        renderPosts(data.recent_posts || [], data.base_url);
    }

    function buildMonthBlock(monthData, baseUrl) {
        // monthData: { year, month (1-12), name, days: { "YYYY-MM-DD": count, ... } }
        var wrap = document.createElement('div');
        wrap.className = 'smack-cal-month';

        var heading = document.createElement('div');
        heading.className = 'smack-cal-month-name';
        heading.textContent = monthData.name + ' ' + monthData.year;
        wrap.appendChild(heading);

        var grid = document.createElement('div');
        grid.className = 'smack-cal-grid';

        // Day-of-week headers (Mon-Sun)
        var dayNames = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        dayNames.forEach(function (d) {
            var hdr = document.createElement('div');
            hdr.className = 'smack-cal-dow';
            hdr.textContent = d;
            grid.appendChild(hdr);
        });

        // First day of the month (JS: 0=Sun..6=Sat → convert to Mon=0 offset)
        var firstDate = new Date(monthData.year, monthData.month - 1, 1);
        var startOffset = (firstDate.getDay() + 6) % 7; // 0=Mon
        var daysInMonth = new Date(monthData.year, monthData.month, 0).getDate();

        // Empty cells before day 1
        for (var i = 0; i < startOffset; i++) {
            var empty = document.createElement('div');
            empty.className = 'smack-cal-day smack-cal-day--empty';
            grid.appendChild(empty);
        }

        var today = new Date();
        var todayKey = today.getFullYear() + '-'
            + pad(today.getMonth() + 1) + '-'
            + pad(today.getDate());

        for (var d = 1; d <= daysInMonth; d++) {
            var dateKey = monthData.year + '-'
                + pad(monthData.month) + '-'
                + pad(d);

            var cell = document.createElement('div');
            cell.className = 'smack-cal-day';

            if (dateKey === todayKey) cell.classList.add('smack-cal-day--today');

            var count = monthData.days[dateKey] || 0;
            if (count > 0) {
                cell.classList.add('smack-cal-day--has-post');
                var link = document.createElement('a');
                link.href = baseUrl + '?date=' + encodeURIComponent(dateKey);
                link.title = count + (count === 1 ? ' post' : ' posts');
                link.textContent = d;
                cell.appendChild(link);
            } else {
                cell.textContent = d;
            }

            grid.appendChild(cell);
        }

        wrap.appendChild(grid);
        return wrap;
    }

    function renderPosts(posts, baseUrl) {
        postList.innerHTML = '';
        if (!posts.length) {
            var li = document.createElement('li');
            li.className = 'smack-cal-post-empty';
            li.textContent = 'No recent posts.';
            postList.appendChild(li);
            return;
        }
        posts.forEach(function (post) {
            var li = document.createElement('li');
            var a  = document.createElement('a');
            a.href        = post.url;
            a.textContent = post.title;
            a.title       = post.date;
            li.appendChild(a);
            postList.appendChild(li);
        });
    }

    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPEN / CLOSE
    // ─────────────────────────────────────────────────────────────────────────

    function open() {
        if (isOpen) return;
        isOpen = true;
        panel.classList.add('smack-cal-panel--open');
        overlay.classList.add('smack-cal-overlay--visible');
        document.body.classList.add('smack-cal-body-lock');
        loadData();
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        panel.classList.remove('smack-cal-panel--open');
        overlay.classList.remove('smack-cal-overlay--visible');
        document.body.classList.remove('smack-cal-body-lock');
    }

    function toggle() {
        isOpen ? close() : open();
    }

    function loadData() {
        fetchData(monthOffset, function (err, data) {
            if (err) {
                calBody.innerHTML = '<p class="smack-cal-error">Could not load calendar.</p>';
                return;
            }
            renderCalendar(data);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INIT
    // ─────────────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        buildPanel();

        var trigger = document.getElementById('smack-cal-trigger');
        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                toggle();
            });
        }

        // Keyboard: Escape closes
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) close();
        });

        // Expose API for skins that want to control the panel directly
        window.smackCalendar = { open: open, close: close, toggle: toggle };
    });

}());
