/**
 * SNAPSMACK - Sidebar Accordion Engine
 *
 * Pure accordion behaviour for the admin sidebar navigation.
 * One section open at a time; clicking a toggle slides the old
 * section closed and the new one open. PHP sets the initial .open
 * class based on which section contains the current page.
 *
 * Also the narrow-screen drawer: .sidebar-toggle opens the sidebar and its
 * overlay, and a click on .sidebar-overlay closes both. That used to be two
 * onclick= attributes in core/admin-header.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    function init() {
        var sections = document.querySelectorAll('.nav-section');
        if (!sections.length) return;

        for (var i = 0; i < sections.length; i++) {
            (function (section) {
                var toggle = section.querySelector('.nav-section-toggle');
                if (!toggle) return;

                toggle.addEventListener('click', function () {
                    var isOpen = section.classList.contains('open');

                    // Close all sections first
                    for (var j = 0; j < sections.length; j++) {
                        sections[j].classList.remove('open');
                    }

                    // If it wasn't open, open it (toggle behaviour)
                    if (!isOpen) {
                        section.classList.add('open');
                    }
                });
            })(sections[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }


    // ---- narrow-screen drawer ---------------------------------------------
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        var bar = document.querySelector('.sidebar');
        var veil = document.querySelector('.sidebar-overlay');

        if (e.target.closest('.sidebar-toggle')) {
            if (bar)  bar.classList.toggle('open');
            if (veil) veil.classList.toggle('open');
            return;
        }
        if (e.target.closest('.sidebar-overlay')) {
            if (bar)  bar.classList.remove('open');
            if (veil) veil.classList.remove('open');
        }
    });

})();
// ===== SNAPSMACK EOF =====
