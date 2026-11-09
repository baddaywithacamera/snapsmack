# SYBU owner copyright repair evidence

Candidate 0.7.76, based on reviewed 0.7.75 commit9c23d3ab. No build or installation in this repair step. Source-only acceptance, no real posts or Drive uploads.

Nine isolated regressions PASS: actual SOLO post_image with captured multipart payload and real generated JPEG bytes, both empty/nonempty captions x copyright; actual temporary Drive JPEG EXIF captured with fake upload; no hardcoded Artist; fresh config missing field yields empty; GRAM request body remains caption-only and source JPEG bytes unchanged; Qt field loads current value, applies profile, saves through real Engine.save_config/config.save to temporaryINI, reloads empty/nonempty values, and passes visible field into post_start. Qt Engine/profile/network operations are faked, credentials not read. Twenty-four related prompt/queue, Drive status and download gate tests PASS. Explicit temp folders used.

Screenshot1420x880 with app stylesheet/SegoeUI inspected. Labels' pre-existing black backgrounds remain outside this repair's scope. Copyright field is visible on Post, explicitly SOLO EXIF only: GRAM currently uploads source metadata unchanged. No added GRAM metadata mutation or title/description licence injection.

Removed hardcoded foundtextures fallback from config and EXIF builder and removed forced Sean McCormick Artist from generated EXIF. Existing explicitly stored copyright value remains visible, is not silently erased; blank now stays blank. SOLO description never receives a copyright suffix even if supplied. Existing uploaded JPEGs and posts are unchanged.

History: hardcoded copyright and Artist present at earliest followed EXIF file history60afaf74,20May2026. This is a lower bound in available file history, not first deployment proof. Qt source exists by7b0a998e,12September; exact release where controls were lost not established. Any user of affected SOLO code without configured copyright could receive foundtextures licence, and all generated SOLO EXIF could get Sean Artist; actual third-party distribution/use NOT CHECKED. User count~2000 is owner estimate, not a census.

Remediation pending owner route/decision: exact-match read-only description census per site; backup post IDs/descriptions/modified timestamps; dry-run strip only exact unwanted suffix with optimistic concurrency check; Sean operates authorized write. No live DB access. EXIF options: leave historicalfiles (no immediate I/O, wrong metadata persists); regenerate only web derivatives from verified originals (storage/read/transfer and visual-parity checks); replace both web and Drive copies (largest I/O, link stability/download verification and backups required). No automatic bulk rewriting or re-export decision. Source images must remain untouched. Scope/count and costs require census before numerical estimates.

<!-- ===== SNAPSMACK EOF ===== -->
