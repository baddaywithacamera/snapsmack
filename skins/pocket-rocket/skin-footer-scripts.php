<?php
/**
 * SNAPSMACK - Footer scripts for the pocket-rocket skin
 * Alpha v0.7.3
 *
 * Drawer toggle logic. No hotkey engines, no lightbox, no wall physics.
 * Just two drawers that open and close.
 */
?>
<script>
function poToggleDrawer(which) {
    var info = document.getElementById('po-info-drawer');
    var signals = document.getElementById('po-signals-drawer');
    var btns = document.querySelectorAll('.po-drawer-btn');

    if (which === 'info' && info) {
        var opening = !info.classList.contains('open');
        info.classList.toggle('open');
        if (signals) signals.classList.remove('open');
        btns[0].classList.toggle('active', opening);
        if (btns[1]) btns[1].classList.remove('active');
    }
    if (which === 'signals' && signals) {
        var opening = !signals.classList.contains('open');
        signals.classList.toggle('open');
        if (info) info.classList.remove('open');
        if (btns[1]) btns[1].classList.toggle('active', opening);
        btns[0].classList.remove('active');
    }
}
</script>
