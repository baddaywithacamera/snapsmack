/**
 * SNAPSMACK - ss-engine-admin-chrome
 *
 * Moved verbatim out of a <script> block in core/admin-header.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if(localStorage.getItem('smackback_alarm_silenced')==='1'){document.body.classList.add('alarm-silenced');var b=document.getElementById('smackback-silence-btn');if(b)b.style.display='none';}

// ===== SNAPSMACK EOF =====
