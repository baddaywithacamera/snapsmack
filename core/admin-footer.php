<?php
/**
 * SNAPSMACK - Admin Dashboard Footer
 * Alpha v0.7.1
 *
 * Closes the admin HTML structure with credits acknowledging PixelPost,
 * GreyMatter, and Noah Grey. Outputs the closing </body> and </html> tags.
 */
?>
<footer id="admin-universal-footer">
    <div class="footer-left">
        A RE-IMAGINING OF <a href="https://en.wikipedia.org/wiki/Pixelpost" target="_blank">PIXELPOST</a>,
        INSPIRED BY <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank">GREYMATTER</a>
        FROM <a href="https://manmadeghost.com/" target="_blank">NOAH GREY</a>.
    </div>

    <div class="footer-right">
        SNAPSMACK <?php echo strtoupper(defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : 'Alpha 0.7'); ?> &copy; <?php echo date("Y"); ?>
    </div>
</footer>

<script src="assets/js/ss-engine-sidebar.js"></script>
</body>
</html>
