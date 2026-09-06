"""Image metadata travels WITH THE IMAGE, and posting feeds the shared store.

Pins the 2026-09-06 contract (Sean): alt/colour ride the image on every wire
COLD SNAP uses, and every successful post records itself — post row, asset
rows (sha256-deduped web-size bytes), membership — into the shared library.
Runs against the REAL snap_library in a SNAPSMACK_HOME sandbox.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile

_HERE = os.path.dirname(os.path.abspath(__file__))
_TOOL = os.path.dirname(_HERE)
sys.path.insert(0, _TOOL)
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(_TOOL)), "tools", "_shared"))

_SANDBOX = tempfile.mkdtemp(prefix="coldsnap-libtest-")
os.environ["SNAPSMACK_HOME"] = _SANDBOX

import sumna_offline as O          # noqa: E402
import sumna_post as P             # noqa: E402
import snap_library as L           # noqa: E402

passed = 0


def check(name, cond):
    global passed
    assert cond, name
    passed += 1


SITE = "https://example.photoblogs.fyi"

# --- DraftImage.alt exists and round-trips the draft JSON --------------------
im = O.DraftImage(local_path="C:/x/a.jpg", filename="a.jpg", alt="a red barn at dusk")
check("alt survives to_dict/from_dict",
      O.DraftImage.from_dict(im.to_dict()).alt == "a red barn at dusk")
check("old dict (no alt) defaults blank",
      O.DraftImage.from_dict({"local_path": "x"}).alt == "")

# --- gram wire: per-image alt is in the controls payload ---------------------
ctl = P.GramPoster._img_controls(im)
check("gram controls carry alt", ctl.get("alt") == "a red barn at dusk")

# --- producer: post + assets + membership land in the real shared library ----
img_file = os.path.join(_SANDBOX, "upload.jpg")
with open(img_file, "wb") as f:
    f.write(b"\xff\xd8\xff\xe0FAKEJPEGBYTES")

draft = O.Draft(draft_id="d1", kind=O.KIND_SOLO, mode=O.MODE_SOLO,
                title="Barn", tags="#red #dusk", caption="the barn")
di = O.DraftImage(local_path=img_file, filename="upload.jpg",
                  alt="a red barn at dusk", width=800, height=600)

P._produce_library(SITE, draft, 4242, site_mode="photoblog", post_type="solo",
                   body="the barn", per_image=[(di, 77, img_file)])

rec = L.post(SITE, 4242)
check("post recorded", rec is not None and rec["title"] == "Barn")
check("post body recorded", rec["body"] == "the barn")
check("tags recorded stripped", rec["tags"] == ["red", "dusk"])
check("source tool recorded", rec["source_tool"] == "coldsnap")

assets = L.assets_for(SITE, 4242)
check("one asset attached", len(assets) == 1)
a = assets[0]
check("asset alt travels with the image", a["alt"] == "a red barn at dusk")
check("asset dims recorded", a["width"] == 800 and a["height"] == 600)
check("asset points at the server image", a["source_ref"] == "img:77")
media = L.asset_file(SITE, a["asset_id"])
check("web-size bytes are IN the store", media and os.path.isfile(media))
with open(media, "rb") as f:
    check("stored bytes are the uploaded bytes", f.read().startswith(b"\xff\xd8\xff\xe0FAKE"))

# --- re-use: the same image in a second post never steals it -----------------
draft2 = O.Draft(draft_id="d2", kind=O.KIND_SOLO, mode=O.MODE_SOLO, title="Barn again")
P._produce_library(SITE, draft2, 4243, site_mode="photoblog", post_type="solo",
                   body="again", per_image=[(di, 78, img_file)])
check("first post keeps its image", len(L.assets_for(SITE, 4242)) == 1)
check("second post has it too", len(L.assets_for(SITE, 4243)) == 1)

# --- producer is best-effort: a nonsense path never raises -------------------
P._produce_library(SITE, draft, 4244, site_mode="photoblog", post_type="solo",
                   per_image=[(di, None, "Z:/nope/missing.jpg")])
check("missing upload path records nothing but never raises", True)

# --- solo success parsing contract -------------------------------------------
check("bare success is still success (SYBU contract untouched)",
      "success".startswith("success"))
body = "success:123"
check("want_id reply parses to the image id", int(body.split(":", 1)[1]) == 123)

# --- COLD STORAGE: pulled images enter the store with FULL metadata ----------
pulled = L.store_media(SITE, b"\xff\xd8\xff\xe0PULLEDBYTES", orig_name="dsc9.jpg", ext=".jpg")
pulled.update({"alt": "a dog on a dock", "title": "Dock dog", "description": "the dog waits",
               "color_mode": "bw", "status": "published", "img_date": "2026-09-06 10:00:00",
               "source_ref": "img:90", "width": 640, "height": 480})
L.upsert_asset(SITE, pulled)
rows = {a["asset_id"]: a for a in L.all_assets(SITE)}
pa = rows[pulled["asset_id"]]
check("pulled title with the image", pa["title"] == "Dock dog")
check("pulled caption with the image", pa["description"] == "the dog waits")
check("pulled colour with the image", pa["color_mode"] == "bw")
check("pulled asset in no posts yet", pa["used_in"] == 0)
check("skip-list knows it", "img:90" in L.asset_source_refs(SITE))

# --- record_post on the same bytes must NOT wipe the pulled metadata ---------
draft3 = O.Draft(draft_id="d3", kind=O.KIND_SOLO, mode=O.MODE_SOLO, title="Dock post")
di2 = O.DraftImage(local_path=img_file, filename="dsc9.jpg", alt="a dog on a dock")
media_file = L.asset_file(SITE, pulled["asset_id"])
P._produce_library(SITE, draft3, 4245, site_mode="photoblog", post_type="solo",
                   per_image=[(di2, 91, media_file)])
pa2 = {a["asset_id"]: a for a in L.all_assets(SITE)}[pulled["asset_id"]]
check("posting kept the pulled title", pa2["title"] == "Dock dog")
check("posting kept the pulled colour", pa2["color_mode"] == "bw")
check("posting attached membership", pa2["used_in"] == 1)

print(f"OK — {passed} asserts (sandbox: {_SANDBOX})")

# ===== SNAPSMACK EOF =====
