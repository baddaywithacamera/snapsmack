# SnapSmack Deferred Work

These items were deliberately deferred on October 8, 2026. They are not release claims.

## Federation during breach lockdown

- Audit and define consistent ActivityPub behaviour during SMACKBACK breach lockdown, separately from ordinary maintenance. Source inspection on 2026-10-27: the public maintenance gate blocks anonymous HTML, but ordinary federation endpoints and CLI delivery do not apply that gate. `fediverse.php` inbox and `cron-fediverse.php` suspend only the `smackcast` distribution profile; shared delivery/webcron paths need the same review. This is not a live lockdown test.
- Owner decision (2026-10-27): breach disables ALL federation across all modes and entry points. No incoming federation mail may be accepted, parsed, queued, or processed; no signature-verification actor fetches, automatic protocol replies, outgoing signed deliveries, relay forwarding, or discovery/actor/outbox/object documents. Reject at the earliest practical boundary before federation processing with a fixed minimal temporary-unavailable response, independent of request content. This protects peers from a compromised install being used to stage an attack, including through responses to inbound requests. Ordinary maintenance remains a separate policy.
- Preserve existing queued work in a suspended state; review recovery/quarantine handling so clearing lockdown does not automatically send potentially compromised activities. Rejected incoming requests must not be acknowledged as accepted or silently stored for later processing.
- Verify ordinary sites and SMACKCAST through every HTTP and worker entry point. Assert that locked-down requests invoke no message parser, signature processing, remote fetch, queue mutation, or federation response generator, and that workers send nothing. Test fixed rejection responses and controlled recovery. This is pending implementation and runtime verification, not a claim that a compromised application can enforce its own integrity.

## Blog post total in TILEZ sidebar

- Owner request (2026-10-27, baddaywithacamera.ca screenshot): show a POSTS row immediately above PHOTOS in the post metadata sidebar, using the existing row styling. POSTS is the total number of published posts across this blog; PHOTOS remains the current post's photo count. Exclude drafts, future scheduled posts, static pages and media-library entries.
- Supply the count through the CMS view data as a reusable capability; no database queries or application logic in the skin. Keep this requested addition separate from historical-presentation restoration so it does not redefine the restoration baseline. Pending implementation.

## CMS help

- Audit the CMS help system against the current interface and remove or update stale guidance.
- Verify every skin with custom controls contributes its own current, skin-specific help content.
- Add a regression that inventories custom skin functions without matching help coverage.

## Public contact

- Test the snapsmack.ca contact form end to end: validation, delivery, reply address, failure handling, spam controls, and confirmation copy.
- Record a watched-working result; a successful HTTP response alone is not proof of mail delivery.

## TELEGRAM mobile skin

- Reconcile the last completed TELEGRAM work with the current phone presentation.
- Test its landing, post, static page, navigation, search, comments, and editor-facing options at representative phone widths.
- Separate genuine TELEGRAM work from the earlier ALFRED/TILEZ shared ancestry before changing selectors.

## Font catalogue

- Reconcile every font offered by skin controls with a font that SnapSmack can actually deliver through its bounded same-origin provider.
- Do not leave selectable catalogue entries that silently fall back in the browser.
- Preserve licensing records and avoid third-party browser requests when adding faces.

