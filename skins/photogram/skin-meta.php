<?php
/**
 * SNAPSMACK - Photogram Skin Meta
 * Alpha v0.7.3
 *
 * Delegates to core meta.php for <head> content, viewport, and font loading.
 */
include(dirname(__DIR__, 2) . '/core/meta.php');
?>
<!-- ── Eruda mobile console (debug) ──────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/eruda"></script>
<script>
    eruda.init();
    /* Push button above Photogram's fixed nav bar */
    eruda.position({ x: window.innerWidth - 50, y: window.innerHeight - 70 });
</script>
<?php
