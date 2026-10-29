# CHAPLIN, 29 October 2026

Live 969D: recovered CHAPLIN 0.2.28 through the signed registry reinstall. No pre-recovery hash comparison was performed. The recovery subsequently verified provenance. Settings B -> A and side ornaments ON -> OFF survived fresh admin reload. Top/bottom stayed ON.

The before-css screenshots are live. The css-candidate screenshots are isolated local renders of captured public markup using the modified skin stylesheet, with scripts removed. They are NOT installed-build certification. Origin-sensitive fonts differ locally; no font pass is claimed. Photos and ornaments loaded. The 1920 captures use the normal 889px viewport height; the 1440/1024 captures use 1080px height.

Candidate fixes: rules behind ornament masks, owner gap between corners and rules, viewport clearance at 1024 (frame x8..1016, zero horizontal overflow), no ornament frame on static-page hero. About image remains loaded at 640x640. Its size and title typography still differ from the reference; not claimed exact. Cream artwork colour also remains different from the white reference.

Verification: chaplin-frame-layer-regression.cjs passes at 1920/1440/1024, and removing the repair reproduces three defects. Existing PHP ornament regression: 39 assertions pass. Archive gap selectors are excluded; archive ornaments remain visible in the offline test. Live archive was observed loading 40 links with T/M/C controls. Full archive geometry and all controls are NOT CHECKED by this repair.

No ticket closed; deployment and owner acceptance remain outstanding.

<!-- ===== SNAPSMACK EOF ===== -->
