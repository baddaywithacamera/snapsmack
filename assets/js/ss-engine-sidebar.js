/**
 * SNAPSMACK - Sidebar Accordion Engine
 * Alpha v0.7.1
 *
 * Pure accordion behaviour for the admin sidebar navigation.
 * One section open at a time; clicking a toggle slides the old
 * section closed and the new one open. PHP sets the initial .open
 * class based on which section contains the current page.
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

})();
