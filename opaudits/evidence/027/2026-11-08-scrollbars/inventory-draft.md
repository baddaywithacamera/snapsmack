# Scrollbar rollout inventory — draft

Thirty default archive fixtures were inspected in Chromium at 1920. This is not installed-site verification or all-variant coverage. Transparent or layered backgrounds remain unresolved rather than being guessed. Primary scroll surfaces require per-skin selection; nested scroll controls must retain their existing treatment.

Phase one: shared opt-in CSS plus NEW HORIZON only (one skin version affected). Phase two: the other 29 skins remain unchanged pending review of colours and packaging load.

| Skin | Observed default page background | Existing scrollbar rules | Inner scroll areas | Proposed thumb / hover | Contrast |
|---|---|---|---:|---|---|
| 50-shades-of-noah-grey | transparent/layered; underlying surface NOT CHECKED | no | 1 | NOT CHOSEN | NOT CHECKED |
| 52-card-pickup | rgb(245, 240, 232) | no | 0 | #bbbbbb / #999999 | 1.69:1 (proposal only) |
| alfred | rgb(29, 29, 29) | no | 0 | #3b3b3b / #555555 | 1.50:1 (proposal only) |
| aurora | rgb(0, 0, 0) | no | 0 | #3b3b3b / #555555 | 1.87:1 (proposal only) |
| chaplin | rgb(10, 10, 10) | yes | 1 | #3b3b3b / #555555 | 1.77:1 (proposal only) |
| full-monty | rgb(9, 9, 9) | no | 0 | #3b3b3b / #555555 | 1.78:1 (proposal only) |
| galleria | rgb(245, 240, 235); texture present | yes | 0 | NOT CHOSEN — texture review | NOT CHECKED |
| game-on | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| glide | rgb(11, 11, 11) | yes | 0 | #3b3b3b / #555555 | 1.76:1 (proposal only) |
| heuristic | rgb(3, 3, 5) | no | 0 | #3b3b3b / #555555 | 1.84:1 (proposal only) |
| hip-to-be-square | rgb(31, 56, 69); texture present | yes | 0 | NOT CHOSEN — texture review | NOT CHECKED |
| impact-printer | transparent/layered; underlying surface NOT CHECKED | no | 1 | NOT CHOSEN | NOT CHECKED |
| instant-camera | transparent/layered; underlying surface NOT CHECKED | no | 0 | NOT CHOSEN | NOT CHECKED |
| jive-turkey | rgb(0, 0, 0) | no | 0 | #3b3b3b / #555555 | 1.87:1 (proposal only) |
| new-horizon | transparent/layered; underlying surface NOT CHECKED | no | 1 | NOT CHOSEN | NOT CHECKED |
| onyx | rgb(17, 17, 17) | no | 1 | #3b3b3b / #555555 | 1.69:1 (proposal only) |
| parade | rgb(255, 247, 237) | no | 0 | #bbbbbb / #999999 | 1.81:1 (proposal only) |
| photogram | rgb(239, 239, 239) | no | 1 | #bbbbbb / #999999 | 1.67:1 (proposal only) |
| rational-geo | rgb(10, 10, 10) | no | 0 | #3b3b3b / #555555 | 1.77:1 (proposal only) |
| scroll | rgb(255, 255, 255) | no | 1 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| show-n-tell | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| slickr | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| sliders | transparent/layered; underlying surface NOT CHECKED | no | 0 | NOT CHOSEN | NOT CHECKED |
| stanley | rgb(213, 214, 215) | no | 0 | #bbbbbb / #999999 | 1.32:1 (proposal only) |
| sudden-impact | transparent/layered; underlying surface NOT CHECKED; texture present | no | 0 | NOT CHOSEN — texture review | NOT CHECKED |
| telegram | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| the-grid | rgb(255, 255, 255) | no | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| tilez | rgb(255, 255, 255) | yes | 0 | #bbbbbb / #999999 | 1.92:1 (proposal only) |
| true-grit | rgb(42, 42, 42); texture present | no | 2 | NOT CHOSEN — texture review | NOT CHECKED |
| writing-with-impact | rgb(207, 202, 187) | no | 0 | #bbbbbb / #999999 | 1.17:1 (proposal only) |

NEW HORIZON variant-specific proposal: dark #3b3b3b / hover #555555 on #000000; light #bbbbbb / hover #999999 on #f2f2f2. Transparent track, 12px draggable hit area in Chromium; Firefox uses normal native width. Local Chromium tests cover 1920/1440/1024, wheel scrolling, thumb dragging, and native nested controls. Firefox runtime unavailable: NOT CHECKED. These are scrollbar-only fixture checks, not full page parity.

<!-- ===== SNAPSMACK EOF ===== -->