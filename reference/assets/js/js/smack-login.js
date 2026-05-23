/**
 * SNAPSMACK - Login page tab switcher
 *
 * Handles the tab strip on login.php and smack-2fa-verify.php.
 * Tabs are indexed by their panel ID suffix (e.g. "password", "recovery", "totp").
 */
(function () {
    'use strict';

    window.switchTab = function (tab) {
        document.querySelectorAll('.login-tab').forEach(function (t) {
            t.classList.remove('active');
        });
        document.querySelectorAll('.login-panel').forEach(function (p) {
            p.classList.remove('active');
        });
        var panel = document.getElementById('panel-' + tab);
        if (panel) {
            panel.classList.add('active');
            // Focus the first input in the newly active panel
            var first = panel.querySelector('input');
            if (first) first.focus();
        }
        // Activate the tab button that matches the clicked tab name
        var btn = document.querySelector('[data-tab="' + tab + '"]');
        if (btn) btn.classList.add('active');
    };
}());
