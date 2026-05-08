<?php
/**
 * SNAPSMACK - Admin Dashboard Footer
 *
 * Closes the admin HTML structure with credits line. Photoblog mode
 * acknowledges PixelPost, GreyMatter, and Noah Grey; Carousel mode
 * credits Rick McGinnis. Outputs the closing </body> and </html> tags.
 *
 * Also flushes the output buffer started in admin-header.php and
 * auto-injects a CSRF hidden field into every <form method="POST">
 * so individual admin pages don't have to call csrf_field() themselves.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



?>
<footer id="admin-universal-footer">
    <div class="footer-left">
        <?php if (($settings['site_mode'] ?? 'photoblog') === 'carousel'): ?>
        THE GRID WAS CREATED JUST FOR TORONTO PHOTOGRAPHER <a href="https://rickmcginnis.com/" target="_blank" rel="noopener">RICK MCGINNIS</a>. VISIT HIM AND LOVE HIS WORK LIKE WE DO.
        <?php else: ?>
        A RE-IMAGINING OF <a href="https://en.wikipedia.org/wiki/Pixelpost" target="_blank">PIXELPOST</a>,
        INSPIRED BY <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank">GREYMATTER</a>
        FROM <a href="https://manmadeghost.com/" target="_blank">NOAH GREY</a>.
        <?php endif; ?>
    </div>

    <div class="footer-right">
        SNAPSMACK <?php echo strtoupper(defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : 'Alpha 0.7'); ?> &copy; <?php echo date("Y"); ?>
    </div>
</footer>

<script src="assets/js/ss-engine-sidebar.js"></script>
<script src="assets/js/ss-engine-updater.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/ss-engine-admin-csrf.js?v=<?php echo time(); ?>"></script>
</body>
</html>
<?php
// ── CSRF auto-injection ──────────────────────────────────────────────────────
// admin-header.php started an ob_start(). Pull the buffered HTML, inject a
// hidden csrf_token field into every <form method="POST"> tag, then flush.
// This means every admin form gets CSRF protection without per-page changes.
if (function_exists('csrf_token')) {
    $__html = ob_get_clean();
    $__token = htmlspecialchars(csrf_token(), ENT_QUOTES);
    $__field = '<input type="hidden" name="csrf_token" value="' . $__token . '">';
    $__html = preg_replace_callback(
        '/<form\b([^>]*)>/i',
        function ($m) use ($__field) {
            // Only inject when this form posts. Match method="post" with any
            // quoting style (or unquoted) and case-insensitively.
            if (preg_match('/\bmethod\s*=\s*["\']?post["\']?/i', $m[1])) {
                return $m[0] . $__field;
            }
            return $m[0];
        },
        $__html
    );
    echo $__html;
}
// ===== SNAPSMACK EOF =====
