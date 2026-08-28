<?php
/** Compatibility route: the Feed is a CMS page owned by the active skin. */
header('Location: /page.php?slug=feed', true, 302);
exit;
// ===== SNAPSMACK EOF =====
