# CMS Help and Contact Follow-up

Recorded 2026-10-07. This is a follow-up list, not authorization to begin the work tonight.

## 1. Reconcile the CMS help system

- Inventory every current admin screen and workflow against the CMS help topics.
- Classify each topic as current, incomplete, obsolete, duplicated, or missing.
- Resolve the duplicate `cron` and `blogger-flogger` topic definitions so content is not silently overwritten.
- Correct known stale claims, including the five-skin inventory and missing `[pullquote]` documentation.
- Reconcile security, recovery, updating, federation, release-gate, and skin-authority guidance against the current implementation before treating the manual as authoritative.
- Add a regression gate that detects duplicate topic keys and material admin screens with no mapped help coverage.

## 2. Restore contextual help for every skin

- Recover the useful content from the 26 pre-strip skin `help.php` files through Git history.
- Review every recovered topic against the current skin manifest and behaviour; do not blindly restore stale prose.
- Add help for the remaining current skins so all 30 managed skins have contextual documentation for their custom behaviour and controls.
- Keep the security boundary intact: skins provide bounded declarative help data; the CMS validates, sanitizes, indexes, and renders it. Do not restore skin database access or executable skin-side help logic.
- Confirm that help appears only for the active skin and that malformed skin help fails closed without breaking the CMS manual.
- Add fleet coverage that fails when a managed skin ships custom controls without valid contextual help.

## 3. Test the public contact form end to end

- Submit a valid message through the real public form and confirm it reaches the configured recipient with the expected sender, reply-to address, subject, and body.
- Verify required fields, invalid email handling, length limits, Unicode, and safe treatment of HTML and header-injection characters.
- Verify spam and abuse controls, including honeypot/rate limiting or whatever protection the current implementation declares.
- Verify success, validation-error, delivery-failure, and mail-transport-unavailable responses without leaking configuration, credentials, paths, or stack details.
- Confirm keyboard use, labels, focus/error presentation, and screen-reader-readable status messages.
- Confirm privacy behaviour: no unnecessary retention or logging of message contents or personal information.
- Add automated regression coverage where deterministic, and record the live-mail delivery check separately because it depends on external mail infrastructure.

## Done means

- The CMS manual accurately describes the current release.
- Every managed skin contributes safe, current contextual help for its custom functions.
- The contact form has both automated coverage and a recorded successful live delivery test.

<!-- ===== SNAPSMACK EOF ===== -->
