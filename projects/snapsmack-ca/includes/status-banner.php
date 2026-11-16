<?php
/**
 * SNAPSMACK.CA — Shared public-site status banner.
 *
 * Set the switch below to false when the closed beta opens. Every public page
 * that uses the shared header will lose the banner in that one change.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$ss_show_closed_beta_banner = true;

if ($ss_show_closed_beta_banner):
?>
<aside class="beta-delay" aria-label="Closed beta delay notice">
    <div class="wrap beta-delay-inner">
        <p class="beta-delay-copy"><strong>Closed beta is running late.</strong> It opens when the software is ready.</p>
        <a class="beta-delay-link" href="wotcha.php#closed-beta-delay">Read why &rarr;</a>
    </div>
</aside>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
