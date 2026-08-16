<!--
  SNAPSMACK_EOF_HEADER
  Last non-empty line of this file MUST be the canonical .md EOF marker:
  an HTML comment containing five equals, space, 'SNAPSMACK EOF', space, five equals.
  Missing or different = truncated/corrupted. Restore before saving.
-->

# PhotoFri Weekly Challenge Scheduler - Specification

**Status:** Proposed  
**Date:** 2026-08-16  
**Applies to:** SnapSmack FEDISTRUCTURE photo-challenge profile, COLD SNAP, and SHOTS FIRED

## 1. Decision

PhotoFri should use:

- one permanent discovery tag, `#PhotoFri`;
- one unique required prompt tag for each challenge week;
- an automatically calculated worldwide-Friday submission window;
- an advance queue of weekly challenge records;
- ordinary future-dated SnapSmack posts for prompt publication;
- COLD SNAP for offline prompt creation and upload;
- SHOTS FIRED for seeing and moving scheduled prompt posts;
- a moderation gate before participant entries are boosted.

The operator should be able to prepare at least 52 weeks ahead. No weekly login, tag swap, or manual opening/closing action should be required.

## 2. Why two hashtags

`#PhotoFri` is the permanent community and discovery tag. It tells people and search systems what the project is.

The weekly tag identifies the answer to a specific prompt. It must be unique enough that an old post cannot accidentally qualify for a later prompt. Recommended format:

`#PhotoFri2026W36Reflections`

The year and ISO week are authoritative; the readable slug is optional. The interface generates the tag from the selected target Friday and prompt title, then allows editing before the week is saved. Once a submission window opens, changing its required tag requires an explicit warning and confirmation.

Qualifying entries must contain the weekly tag. `#PhotoFri` is recommended on prompt and participant posts but is not required for qualification.

## 3. Automatic global window

The operator selects a target Friday, not start and end timestamps.

The server calculates:

- opens Thursday 10:00 UTC;
- closes Saturday 12:00 UTC;
- total duration 50 hours;
- week identity from the target Friday's ISO year and week.

This spans Friday from UTC+14 through UTC-12. The site's display timezone affects labels only. Window calculations and stored timestamps are UTC.

Do not offer arbitrary start/end fields in the normal interface. An advanced override would make the rules harder to understand and should not exist in version 1.

## 4. Weekly challenge record

Add `pc_challenge_weeks` as the source of truth:

| Field | Purpose |
|---|---|
| `id` | Internal primary key. |
| `week_key` | Unique ISO key such as `2026-W36`. |
| `target_friday` | Human-manageable calendar date. |
| `window_start_utc` | Calculated Thursday 10:00 UTC. |
| `window_end_utc` | Calculated Saturday 12:00 UTC. |
| `prompt_title` | Prompt name. |
| `prompt_text` | Canonical instructions/description. |
| `challenge_tag` | Unique normalized required tag without `#`. |
| `community_tag` | Defaults to `photofri`. |
| `prompt_content_kind` | `image` or `post`. |
| `prompt_content_id` | Linked ordinary SnapSmack content unit. |
| `entry_policy` | Versioned policy snapshot; initially `one_image_v1`. |
| `boost_policy` | `review`, `grace`, or `off`; default `review`. |
| `state` | `draft`, `ready`, `open`, `closed`, `archived`, or `cancelled`. |
| `created_at`, `updated_at` | Audit timestamps. |

Constraints:

- unique `week_key`;
- unique `challenge_tag`;
- one linked prompt content unit cannot belong to two active weeks;
- calculated window end must be after start;
- a week cannot become `ready` without title, tag, valid window, and linked scheduled prompt;
- opening, closing, and current-week selection are derived from UTC time, not dependent on a state-flipping cron job.

The existing single `snap_settings.photochallenge_tag` becomes a compatibility fallback only. Migration creates one current-week record from it, then all qualification reads the weekly table.

## 5. Prompt scheduling - reuse SnapSmack

A prompt is an ordinary SnapSmack post with a future publication date. SnapSmack already hides future-dated published content until its date arrives, and `sv_sweep_new_posts()` federates scheduled content after the date passes on the next SMACKVERSE cron run.

Do not build a second prompt-post queue.

Recommended workflow:

1. Compose the prompt post offline in COLD SNAP, including title, copy, image, ALT text, `#PhotoFri`, and the generated weekly tag.
2. Give it a future `post_date` and sync it to photofri.day.
3. Link that scheduled SnapSmack content unit to the matching weekly challenge record.
4. Review all future prompt posts in SHOTS FIRED.
5. Move a prompt in SHOTS FIRED if necessary; the linked post's `img_date`/publication timestamp remains the only prompt-publication clock.

The weekly challenge row should not duplicate `prompt_publish_at`. Its prompt publication time is read from the linked post. This prevents SHOTS FIRED from moving a post while a second scheduler retains a stale date.

The default prompt-publication time is configurable as a lead offset, recommended **seven days before the target Friday at 12:00 UTC**. The submission window remains the calculated global Friday window regardless of when the prompt is announced.

## 6. COLD SNAP integration

COLD SNAP already stores drafts locally and supports future `post_date`. Preserve that offline-first model.

Version 1 requires no new upload engine. Add a PhotoFri helper to the solo composer:

- `PHOTOFRI PROMPT` toggle;
- target-Friday picker;
- prompt-title field;
- generated weekly tag preview/edit;
- automatic insertion of `#PhotoFri` and the weekly tag;
- suggested publication date from the configured lead offset;
- validation that ALT text, prompt title, and both tags are present;
- a local sidecar containing `week_key`, target Friday, prompt title, weekly tag, and draft ID.

When online, sync the ordinary post first. After positive server verification returns the content ID, create or update the weekly challenge record through a narrowly scoped challenge-schedule API. If the second step fails, keep the local draft in `needs_challenge_link` state and retry safely. Never report the week as ready until both the post and week link are verified.

COLD SNAP must allow preparing many prompts while fully offline. Collision checks against existing server weeks occur at sync time; conflicts require operator resolution and must not silently overwrite a week.

## 7. SHOTS FIRED integration

SHOTS FIRED is the fleet-wide schedule board and remains the owner of schedule visibility and date movement. Its server routes now exist:

- `GET smack-schedule.php?action=list`
- `POST smack-schedule.php?action=set_date`

Extend its display, not its responsibility:

- identify linked PhotoFri prompt posts;
- show a `PHOTOFRI 2026-W36` badge, prompt title, weekly tag, target Friday, and submission window;
- warn when a prompt is scheduled after its submission window opens;
- warn about duplicate/missing weeks, duplicate tags, unlinked prompt drafts, and a gap in the next configured weeks;
- offer filters for PhotoFri prompts and “weeks needing attention”;
- retain the existing large MOVE action for rescheduling.

Moving the linked prompt changes only the prompt publication time. It must not move the target Friday or submission window. A separate explicit `MOVE CHALLENGE WEEK` action in the PhotoFri admin may change the target Friday, recalculate the global window, regenerate the suggested tag, and require conflict review.

PhotoFri FEDISTRUCTURE publishes single-image prompt posts only. Carousels and grouped posts are outside this profile and must not be offered by its prompt composer. SHOTS FIRED's existing image-row schedule model therefore matches PhotoFri's needs; no carousel normalization work is required for this feature.

Remote participants may still post carousels from Mastodon-compatible software. Do not silently select the first or cover attachment: that creates an unclear judging rule, and ActivityPub Announce redistributes the complete remote object rather than one selected attachment. A tagged post with more than one image is recorded as `ineligible_multiple_images`, excluded from the public challenge board, ranking, and boost queue, and exposed in the operator eligibility view with the reason `PhotoFri entries require exactly one image`. Where a safe, non-spammy participant-notification mechanism is enabled, send that explanation at most once for the object; otherwise provide it in the published rules and operator UI only.

## 8. Server selection and boosting

On inbound Create:

1. Normalize the post's published timestamp and tags.
2. Find exactly one non-cancelled weekly record whose UTC window contains the timestamp.
3. Require an active participant and the record's exact weekly tag.
4. Require an original post, exactly one image, and the per-actor entry limit. A multi-image object is recorded as ineligible and is never partially selected or announced.
5. Create a durable `pc_entries` row tied to `challenge_week_id`.
6. Set moderation state according to policy; default `pending`.
7. Do not boost until the entry becomes eligible under the week's boost policy.

Recommended default is `review`: an administrator approves an entry before announcement. `grace` may automatically approve after a configurable delay only when there are no reports or safety holds. `off` never boosts automatically.

The current immediate `pc_maybe_boost_entry()` behavior must not remain the default for public submissions.

Every outbound Announce is recorded by entry and week. Hiding or rejecting an already announced entry enqueues a signed Undo(Announce) and exposes delivery/retry status.

## 9. Queue administration

Add a `FUTURE WEEKS` section to the PhotoFri admin:

- agenda view grouped by month;
- bulk creation of consecutive Fridays;
- duplicate-last-week action that copies policy but requires a new prompt and tag;
- readiness indicator for prompt linked, prompt scheduled, unique tag, moderation policy, and cron health;
- edit draft week;
- cancel week without deleting history;
- preview prompt, board label, and global opening/closing times;
- export/import JSON for backup and offline transfer.

Show a persistent coverage warning such as `12 READY / NEXT GAP: 2026-W49`. A configurable minimum runway, recommended eight ready weeks, raises an admin warning but does not fabricate content.

## 10. Failure behavior

- No configured week: board says no challenge scheduled; ingest does not qualify or boost posts.
- Duplicate matching weeks: fail closed, boost nothing, alert the operator.
- Prompt publication cron stale: show a critical readiness warning before the scheduled time.
- Prompt post missing/deleted: week becomes not ready; do not substitute another post.
- Prompt federates late: keep the submission window unchanged and report lateness.
- Challenge schedule API sync fails: preserve the offline sidecar and retry; never duplicate the post.
- Operator is away: time-derived week selection continues without a login or state transition.

## 11. API additions

Create a narrow authenticated endpoint, separate from the generic posting API:

- `GET challenge/weeks?from=&to=` - list week definitions and readiness;
- `POST challenge/weeks/upsert` - create/update a draft or ready future week;
- `POST challenge/weeks/cancel` - cancel a future week;
- `POST challenge/weeks/link_prompt` - link a positively verified SnapSmack content unit;
- `GET challenge/weeks/by_content?id=&kind=` - allow SHOTS FIRED to decorate scheduled posts.

Use a dedicated challenge-management scope or an explicitly expanded SYBU scope. Require authenticated keys, installation-mode gate, strict field validation, unique constraints, idempotency keys, and an audit log. Do not expose participant moderation through the scheduling key.

## 12. Acceptance tests

1. Creating 52 consecutive Fridays produces 52 unique UTC windows without gaps or overlaps.
2. DST changes in the display timezone do not change the UTC qualification window.
3. ISO year boundaries produce correct unique week keys and tags.
4. A post one second before open fails; at open succeeds; one second before close succeeds; at close fails.
5. The correct weekly tag qualifies only its own week.
6. An old weekly tag cannot qualify during a later window.
7. COLD SNAP can create several prompt drafts offline and sync them idempotently.
8. SHOTS FIRED shows all linked future prompts and moves the prompt publication time without moving the challenge window.
9. A scheduled prompt becomes public at its date and federates on the next healthy SMACKVERSE sweep.
10. Manual SMACKVERSE push mode creates an explicit readiness failure because scheduled prompts will not auto-federate.
11. Missing cron, missing prompt, duplicate week, and duplicate tag all fail closed with operator-visible warnings.
12. Entries remain pending and unboosted until moderation policy permits announcement.
13. Hiding an announced entry sends and tracks Undo(Announce).
14. Leaving the challenge undoes PhotoFri's follow-back and stops intentional ingestion.

## 13. Delivery order

1. Implement the moderation and Flag requirements from SECAUDIT 049.
2. Add weekly challenge and entry tables with migration from the single active tag.
3. Change qualification to select the correct week by UTC timestamp.
4. Add the future-weeks admin and readiness checks.
5. Add the challenge-schedule API and COLD SNAP sidecar/link workflow.
6. Decorate and validate PhotoFri items in SHOTS FIRED.
7. Enforce the FEDISTRUCTURE single-image rule in the prompt composer, schedule-link API, and participant-entry qualification.
8. Run controlled federation tests with dedicated accounts before the public submission window.

<!-- ===== SNAPSMACK EOF ===== -->
