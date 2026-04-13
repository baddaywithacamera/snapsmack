/**
 * SNAPSMACK - Carousel View Controller
 *
 * Initialises SnapSlider on the single-post carousel view and updates
 * the EXIF panel when slides change. Used by The Grid layout.php.
 * Replaces inline <script> block.
 *
 * Reads configuration from data attributes on the carousel container:
 *   data-speed      — transition speed in ms (default 400)
 *   data-loop       — "true"/"false" (default false)
 *
 * Depends on DOM elements:
 *   #tg-carousel     — carousel wrapper (SnapSlider container)
 *   #tg-exif-panel   — EXIF metadata display panel
 */
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('tg-carousel');
    if (!container || typeof SnapSlider === 'undefined') return;

    var speed = parseInt(container.getAttribute('data-speed'), 10) || 400;
    var loop  = container.getAttribute('data-loop') === 'true';

    var slider = new SnapSlider({
        container: container,
        speed:     speed,
        loop:      loop
    });

    // Update EXIF panel on slide change
    container.addEventListener('snapslider:slidechange', function (e) {
        var exif  = e.detail.exif || {};
        var panel = document.getElementById('tg-exif-panel');
        if (!panel) return;

        // Clear existing items
        panel.innerHTML = '';

        var fields = {
            camera: 'Camera', lens: 'Lens', focal: 'Focal', film: 'Film',
            iso: 'ISO', aperture: 'Aperture', shutter: 'Shutter', flash: 'Flash'
        };

        Object.keys(fields).forEach(function (key) {
            var val = (exif[key] || '').trim();
            if (!val) return;
            var item  = document.createElement('div');
            item.className = 'tg-exif-item';
            item.setAttribute('data-exif-key', key);
            var lbl   = document.createElement('span');
            lbl.className = 'tg-exif-label';
            lbl.textContent = fields[key];
            var valEl = document.createElement('span');
            valEl.className = 'tg-exif-value';
            valEl.textContent = val;
            item.appendChild(lbl);
            item.appendChild(valEl);
            panel.appendChild(item);
        });
    });
});
