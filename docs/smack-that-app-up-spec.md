<!--
  SNAPSMACK_EOF_HEADER
      <!-- ===== SNAPSMACK EOF ===== -->
  Last non-empty line of this file MUST match the marker above.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# SMACK THAT APP UP

## Product specification

Status: planned for open beta  
Last updated: 2026-08-12

## 1. Product definition

SMACK THAT APP UP is SnapSmack's existing web posting interface made responsive from phone size through tablet size and delivered as an installable Progressive Web App (PWA).

It is **not** a mobile equivalent of the Windows/Linux companion applications. It does not reproduce batch migration, archive repair, fleet management, bulk AI enrichment, backup, or other desktop-tool workflows.

The PWA presents one coherent mobile experience:

- Public, signed-out side: PHOTOGRAM.
- Private, signed-in side: a classic-Instagram-style posting interface.
- Same site, same installation, same content, and the existing SnapSmack authentication and publishing machinery.

Public and private code must retain a clear permission boundary even though they share a visual language and installable shell.

## 2. Goals

- Make ordinary SnapSmack posting practical on phones and tablets.
- Preserve PHOTOGRAM as the public mobile presentation.
- Reuse the existing web forms, authentication, image-ingestion pipeline, and database models.
- Support all three SnapSmack installation modes without allowing users to switch modes.
- Feel like the classic Instagram application: direct, photograph-first, familiar, and uncluttered.
- Work in a normal browser and when installed to the home screen.
- Support modern Android devices and Apple Safari/PWAs honestly, without promising capabilities iOS does not reliably provide.

## 3. Non-goals

- No replacement for SMACK YOUR BATCH UP or other desktop companion tools.
- No large batch-processing workflow.
- No migration from Instagram, Flickr, old sites, drives, or exports.
- No archive-wide repair or management suite.
- No complete offline publishing system.
- No unattended/background upload guarantee.
- No separate mobile content model or duplicate posting backend.
- No mode selector inside the PWA.
- No app-store-native application in the initial release.

## 4. Mode awareness

The server already knows the installation mode. The PWA must read that authoritative value and load only the matching composer.

### SMACKONEOUT

- Select or photograph one image.
- Preview and correct orientation.
- Enter the existing title, caption/description, alt text, tags, category, date, and publication status supported by the web form.
- Use the current single-image ingestion and publishing pipeline.

### GRAMOFSMACK

- Select one or several images.
- Reorder carousel images by touch.
- Choose the cover image.
- Preserve existing carousel and trigram behaviour.
- Enter caption, hashtags/tags, alt text, date, and publication status.
- Preview the resulting three-column grid before publishing where practical.
- Use the current gram/carousel ingestion and publishing pipeline.

### SMACKTALK

- Provide a responsive version of the existing long-form editor.
- Support text, headings, inline photographs, captions, cover image, ordering, draft, preview, and publish.
- On phones, use a single-column editor with explicit block controls.
- On tablets, use the additional width for a media/editor or editor/preview split where useful.
- Do not invent a new document format; save through the existing long-form pipeline.

## 5. Responsive behaviour

The supported design range begins at narrow phone width and extends through landscape tablet width.

### Phones

- Single-column workflow.
- Large touch targets and controls reachable with one hand where reasonable.
- Fixed or sticky bottom action bar only when it does not collide with the keyboard or iOS safe areas.
- No horizontally scrolling forms.
- Media previews sized to the viewport.
- Reordering must work without relying on tiny drag handles.

### Tablets

- Portrait and landscape layouts.
- Use two panes when they materially improve the task: media plus fields, media tray plus editor, or editor plus preview.
- Retain touch-first controls; do not assume mouse input.
- Avoid merely stretching the phone column across the screen.

### Shared requirements

- Respect `env(safe-area-inset-*)`.
- Remain usable with the on-screen keyboard open.
- Preserve entered text and selected media across orientation changes where browser storage permits.
- Meet reasonable accessible-label, focus, contrast, and touch-target requirements.

## 6. PWA shell

- Web app manifest with SMACK THAT APP UP name, icons, colours, scope, and standalone display mode.
- Service worker with conservative caching.
- Installable from supporting browsers.
- Correct standalone navigation and return paths.
- Offline fallback explaining that publishing requires a connection.
- Versioned cache invalidation during SnapSmack updates.
- Never cache authenticated HTML, credentials, CSRF tokens, unpublished post data, or private media in a shared/public cache.

Basic local protection may preserve form text against an accidental refresh or app closure. It is not a promised durable offline media queue.

## 7. Authentication and security

- Reuse SnapSmack's existing session authentication, CSRF protection, password flow, and mandatory 2FA.
- Do not require owners to copy API keys into the PWA.
- Do not create a separate PWA user database or authentication system.
- Signed-out users see PHOTOGRAM only.
- Signed-in authorized owners gain access to the composer.
- Posting endpoints continue to enforce authorization, CSRF, mode rules, upload limits, file validation, and server-side data validation.
- Logging out must remove private local state that should not remain on the device.
- Service-worker and browser-cache behaviour require a focused security review before open beta.

## 8. Media handling

- Accept camera capture and photo-library selection through browser file inputs.
- Support formats already accepted by SnapSmack.
- Explicitly test iPhone HEIC/HEIF handling and EXIF orientation.
- Show upload progress and a clear failure state.
- Prevent double submission and duplicate posts when a request is retried.
- Warn before leaving a composer with unsaved work.
- If the browser or operating system suspends an upload, report failure honestly and allow the user to retry.

## 9. Apple support

Target Safari on a physical iPhone 13 for initial Apple validation, followed by iPad-size responsive testing where available.

Expected capabilities:

- Add to Home Screen installation through Safari.
- Standalone display.
- Camera and photo-library selection.
- Normal authenticated online posting.
- Service-worker caching and a basic offline fallback.
- Limited local draft preservation.

Do not promise:

- Reliable background uploads after the app is switched away from or the screen locks.
- Permanent retention of large local drafts; iOS may evict website data.
- Android-equivalent background sync, file handling, or share-target behaviour.

Initial public wording:

> Works on modern iPhone, iPad and Android devices. Keep the app open while photographs are uploading; background behaviour depends on the operating system.

## 10. Interface flow

1. Visitor opens the installed PWA or mobile site.
2. Signed-out state displays PHOTOGRAM.
3. Owner signs in using the existing SnapSmack login and 2FA flow.
4. A prominent create control opens the composer appropriate to the installed mode.
5. Owner selects media, completes the mode-specific fields, previews, and publishes or saves a draft.
6. Successful publication returns to the public PHOTOGRAM representation of the new work or offers an explicit view-post action.

The interface should borrow the useful clarity of classic Instagram without copying protected brand assets or pretending to be Instagram.

## 11. Implementation approach

### Phase 1: foundation

- Add manifest, icons, service worker, installability, standalone navigation, and offline fallback.
- Make PHOTOGRAM safe and coherent inside the PWA shell.
- Establish signed-out and authenticated navigation states.

### Phase 2: responsive composers

- Refactor the existing three posting pages so their processing remains unchanged while their forms can render through responsive PWA-oriented templates/components.
- Implement SMACKONEOUT first, then GRAMOFSMACK, then SMACKTALK.
- Avoid duplicating server-side write logic.

### Phase 3: mobile resilience

- Form-text recovery.
- Upload progress, retry, duplicate prevention, orientation handling, and leave-page warnings.
- Phone and tablet polish.

### Phase 4: verification

- Test all three modes on desktop responsive emulation, Android-class browser behaviour, and the physical iPhone 13.
- Test Safari browser mode and installed home-screen mode.
- Complete focused security and cache review.
- Release to closed testers before open beta.

## 12. Acceptance criteria

- The PWA installs and launches in standalone mode on supported devices.
- Signed-out users receive the full PHOTOGRAM public experience.
- An authenticated owner can create, draft, preview, and publish the correct post type for each of the three modes.
- The app never offers a composer incompatible with the installed mode.
- Existing desktop posting continues to work.
- Posts created through the PWA are indistinguishable in storage and public rendering from posts created through the existing web admin.
- Phone layouts have no horizontal overflow and remain usable with the keyboard open.
- Tablet layouts use available space intentionally.
- iPhone camera/library selection, HEIC orientation, login, 2FA, publishing, and session persistence are tested on the physical iPhone 13.
- Private/authenticated content is not exposed through public service-worker caches.
- Interrupted or repeated submissions do not silently create duplicate posts.

## 13. Open decisions

- Exact create-button placement in PHOTOGRAM.
- Whether the installed app launches to the public feed or remembers the owner's last authenticated screen.
- How much form state is retained locally, and for how long.
- Whether SMACKTALK receives a phone-first block editor treatment in the first beta or a responsive adaptation of the current editor.
- Final app icon and splash-screen treatment.

## 14. Product copy

Name: **SMACK THAT APP UP**

Short definition:

> SnapSmack's phone-and-tablet posting interface. PHOTOGRAM out front; classic-Insta-style posting when the owner signs in.

FAQ commitment:

> Eventually, yes. A Progressive Web App (PWA) is in the works for open beta.


<!-- ===== SNAPSMACK EOF ===== -->
