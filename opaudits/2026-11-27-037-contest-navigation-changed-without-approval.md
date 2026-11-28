# OPAUDIT 037 — Contest navigation changed without approval

<!-- SNAPSMACK_EOF_HEADER
The last non-empty line must be the canonical SNAPSMACK EOF marker.
-->

## What happened

SS-1589 covered admin navigation cleanup. The approved scope included renaming the Fediverse section, standardizing several labels, adding the missing Pixelfed link and correcting the active section map. Codex additionally moved **Contest & Feed** out of **Challenge Me** and renamed it **Photo Challenge** in **Set Up by the Feds**. Sean did not request that move or rename. Commit `6fe29d848831d1fd699a68ff08f8bbb4ef16a22c` first shipped the change in `0.7.1005D`.

The admin page remained at `smack-photochallenge.php`. On November 27 Sean reported that **Challenge Me** showed only the queue composer and queued posts. A read-only inspection of the live sidebar confirmed the third link had moved. The install time of that particular sidebar file was not established.

## Why the review missed it

Codex reported that all previous routes remained linked. That check counted destinations and missed a change to where an existing task appeared. The Photo Challenge regression test was then changed to require the new location, turning the unintended change into an expected result.

## Correction and follow-up

Sean asked to return **Contest & Feed** to **Challenge Me**. The correction restores the original label and position, opens **Challenge Me** when the page is active, and checks that the Fediverse section has no duplicate link. Codex reported the cause to Brain Trust under SS-1589.

For future navigation cleanup, compare each affected task's section and label to the baseline. Record each intended move or rename against the owner's request. Include the active section for the destination page in the rendered comparison.

The correction is staged in source. Live deployment and verification are open. The installed sidebar is tracked by SMACKBACK.

<!-- ===== SNAPSMACK EOF ===== -->
