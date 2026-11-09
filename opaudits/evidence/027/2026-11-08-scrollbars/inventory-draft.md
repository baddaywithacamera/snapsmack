# Scrollbar rollout inventory — draft

Thirty default archive fixtures were inspected in Chromium at 1920. This is not installed-site verification or all-variant coverage. Layered-background recommendations below deliberately retain native thumbs until their actual scroll surfaces and variants have been rendered; transparent root colours are not evidence of the visible surface. Primary scroll surfaces require per-skin selection; nested scroll controls must retain their existing treatment.

Phase one: shared opt-in CSS plus NEW HORIZON only (one skin version affected). Phase two: the other 29 skins remain unchanged pending review of colours and packaging load.

| Skin | Observed default page background | Existing scrollbar rules | Inner scroll areas | Proposed thumb / hover | Contrast |
|---|---|---|---:|---|---|
| 50-shades-of-noah-grey | transparent/layered; underlying surface NOT CHECKED | no | 1 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| 52-card-pickup | rgb(245, 240, 232) | no | 0 | #bbbbbb / #999999 | 1.69:1 (proposal only) |
| alfred | rgb(29, 29, 29) | no | 0 | #3b3b3b / #555555 | 1.50:1 (proposal only) |
| aurora | rgb(0, 0, 0) | no | 0 | #3b3b3b / #555555 | 1.87:1 (proposal only) |
| chaplin | rgb(10, 10, 10) | yes | 1 | #3b3b3b / #555555 | 1.77:1 (proposal only) |
| full-monty | rgb(9, 9, 9) | no | 0 | #3b3b3b / #555555 | 1.78:1 (proposal only) |
| galleria | rgb(245, 240, 235); texture present | yes | 0 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| game-on | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| glide | rgb(11, 11, 11) | yes | 0 | #3b3b3b / #555555 | 1.76:1 (proposal only) |
| heuristic | rgb(3, 3, 5) | no | 0 | #3b3b3b / #555555 | 1.84:1 (proposal only) |
| hip-to-be-square | rgb(31, 56, 69); texture present | yes | 0 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| impact-printer | transparent/layered; underlying surface NOT CHECKED | no | 1 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| instant-camera | transparent/layered; underlying surface NOT CHECKED | no | 0 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| jive-turkey | rgb(0, 0, 0) | no | 0 | #3b3b3b / #555555 | 1.87:1 (proposal only) |
| new-horizon | dark #000000 / light #f2f2f2, variant proof | opt-in in pending 1.3.36 | 1 | dark #3b3b3b / #555555; light #bbbbbb / #999999 | Chromium PASS; Firefox NOT CHECKED |
| onyx | rgb(17, 17, 17) | no | 1 | #3b3b3b / #555555 | 1.69:1 (proposal only) |
| parade | rgb(255, 247, 237) | no | 0 | #bbbbbb / #999999 | 1.81:1 (proposal only) |
| photogram | rgb(239, 239, 239) | no | 1 | #bbbbbb / #999999 | 1.67:1 (proposal only) |
| rational-geo | rgb(10, 10, 10) | no | 0 | #3b3b3b / #555555 | 1.77:1 (proposal only) |
| scroll | rgb(255, 255, 255) | no | 1 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| show-n-tell | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| slickr | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| sliders | transparent/layered; underlying surface NOT CHECKED | no | 0 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| stanley | rgb(213, 214, 215) | no | 0 | #bbbbbb / #999999 | 1.32:1 (proposal only) |
| sudden-impact | transparent/layered; underlying surface NOT CHECKED; texture present | no | 0 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| telegram | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| the-grid | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| tilez | rgb(255, 255, 255) | yes | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| true-grit | rgb(42, 42, 42); texture present | no | 2 | Retain existing/native thumb; defer uniform colour | NOT CHECKED — rendered variants needed |
| writing-with-impact | rgb(207, 202, 187) | no | 0 | #bbbbbb / #999999 | 1.17:1 (proposal only) |

NEW HORIZON variant-specific proposal: dark #3b3b3b / hover #555555 on #000000; light #bbbbbb / hover #999999 on #f2f2f2. Transparent track, 12px draggable hit area in Chromium; Firefox uses normal native width. Local Chromium tests cover 1920/1440/1024, wheel scrolling, thumb dragging, and native nested controls. Firefox runtime unavailable: NOT CHECKED. These are scrollbar-only fixture checks, not full page parity.

## Layered-background recommendations

No additional skin is enabled by this review. The recommendation for each unresolved family is concrete: preserve its existing scrollbar (native where none is defined), rather than introducing a flat thumb chosen from an ancestor colour. Primary surface and nested controls still need per-page checks before an opt-in changes.

| Skin | Recommendation and reason | Rollout verification |
|---|---|---|
| 50-shades-of-noah-grey | Native primary thumb; one nested scroll area and transparent roots make root-only colour sampling insufficient. Identify the actual primary container first. | NOT CHECKED |
| impact-printer | Native primary thumb; preserve the paper/editor appearance and its nested scroller. Sample the paper background at the scrollbar edge before proposing a colour. | NOT CHECKED |
| instant-camera | Native primary thumb across treatment choices; the white base declared in the stylesheet does not establish the visible owner-selected treatment. | NOT CHECKED |
| sliders | Native primary thumb; image-backed moving bands cannot be represented by one sampled root colour. Keep overlay panels independently styled. | NOT CHECKED |
| sudden-impact | Native primary thumb; inspect texture and treatment variants at the actual gutter before choosing a uniform thumb. | NOT CHECKED |
| galleria | Keep its existing scrollbar; textured cream wall needs actual edge samples, and its current rules should not be overwritten globally. | NOT CHECKED |
| hip-to-be-square | Keep its existing scrollbar; textured blue wall and framed content use different surfaces. Choose the primary wall gutter only after render review. | NOT CHECKED |
| true-grit | Native primary thumb; wall, overlay and page backgrounds vary independently, with two nested scroll areas. One global thumb token would mix unrelated surfaces. | NOT CHECKED |

The other rows remain default-colour proposals only, not accessibility or all-variant passes. Their low idle contrast is intentional subtlety, not a claim of a contrast-standard pass. Hover, drag, keyboard access, forced-colour mode and all supported variants must be checked before rollout. NEW HORIZON is the sole implemented opt-in; Muse PASS iwYQzJYwEjh-S5azxihxj4zu applies to 9d7f3f74, not this wider proposal.

<!-- ===== SNAPSMACK EOF ===== -->
