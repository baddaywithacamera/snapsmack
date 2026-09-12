"""
SNAP SLAPPER faces — Picasa-style local people tagging (slapper_faces.py).

Part 1 uses a fake engine with hand-made fingerprints so the store, clustering,
naming, suggestion and incremental-scan logic are tested on every machine.
Part 2 runs the real YuNet+SFace models on a real photograph IF the models are
present (models dir from SNAPSMACK_HOME, or $SLAPPER_FACE_MODELS); otherwise it
prints SKIP and does not fail — CI boxes don't have 38 MB of model files.

Run: python tools/hub/tests/test_slapper_faces.py   (exit 0 = all pass)
"""

import math
import os
import random
import sys
import tempfile
import time

_HUB = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _HUB)
sys.path.insert(0, os.path.join(os.path.dirname(_HUB), "_shared"))

_tmp = tempfile.mkdtemp(prefix="slapper-faces-")
os.environ["SNAPSMACK_HOME"] = os.path.join(_tmp, "root")

import slapper_faces as sf  # noqa: E402

FAILED = 0


def check(ok, message):
    global FAILED
    print(("PASS " if ok else "FAIL ") + message)
    if not ok:
        FAILED += 1


# --- synthetic fingerprints --------------------------------------------------
random.seed(7)


def _unit(v):
    n = math.sqrt(sum(x * x for x in v)) or 1.0
    return [x / n for x in v]


def person_vector():
    return _unit([random.gauss(0, 1) for _ in range(128)])


def face_of(base, noise=0.06):
    return _unit([b + random.gauss(0, noise) for b in base])


RAY, NOAH, STRANGER = person_vector(), person_vector(), person_vector()


class FakeEngine:
    """Maps a filename to a list of fingerprints so scan_paths() can be driven."""

    def __init__(self, plan):
        self.plan = plan
        self.calls = 0

    def detect(self, path):
        self.calls += 1
        name = os.path.basename(path)
        return [sf.Face([10 * i, 5, 50, 50], 0.95, emb) for i, emb in enumerate(self.plan.get(name, []))]


def touch(path, text="x"):
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text)
    return path


# --- Part 1: store logic -------------------------------------------------------
lib = os.path.join(_tmp, "lib")
os.makedirs(lib)
files = {
    "ray1.jpg": [face_of(RAY)], "ray2.jpg": [face_of(RAY)], "ray3.jpg": [face_of(RAY)],
    "noah1.jpg": [face_of(NOAH)], "noah2.jpg": [face_of(NOAH)],
    "both.jpg": [face_of(NOAH), face_of(RAY)],          # Noah left (x=0), Ray right (x=10)
    "stranger.jpg": [face_of(STRANGER)],
    "empty.jpg": [],
}
paths = [touch(os.path.join(lib, n)) for n in files]
engine = FakeEngine(files)
store = sf.FaceStore(os.path.join(_tmp, "cfg"))

done, skipped = sf.scan_paths(engine, store, paths)
check(done == 8 and skipped == 0, "first scan visits every photo (%d/%d)" % (done, skipped))
check(len(list(store.faces())) == 8, "eight faces recorded across seven photos")
check(store.people_in(os.path.join(lib, "empty.jpg")) == [], "photo with no faces -> no people")

done, skipped = sf.scan_paths(engine, store, paths)
check(done == 0 and skipped == 8, "second scan skips everything (mtime unchanged)")

# clustering finds the two real people, leaves the stranger out (min_size=2)
groups = store.cluster_unnamed()
check(len(groups) == 2, "clustering yields two groups (got %d)" % len(groups))
check(sorted(len(g) for g in groups) == [3, 4], "group sizes are Ray=4 and Noah=3 (got %s)" % sorted(len(g) for g in groups))
ray_group = groups[0]                      # biggest first
ray_keys = {k for k, _i in ray_group}
check(all("ray" in os.path.basename(k) or "both" in os.path.basename(k) for k in ray_keys),
      "biggest group is only Ray photos (+ the shared one)")

# naming a cluster names every face in it and builds a centroid
store.name_faces(ray_group, "Ray")
check(store.counts().get("Ray") == 4, "naming the cluster tags four faces as Ray")
check("Ray" in store.people and store.people["Ray"]["count"] == 4, "Ray has a centroid of 4")
check(store.people_in(os.path.join(lib, "ray1.jpg")) == ["Ray"], "people_in(ray1) == ['Ray']")

store.name_faces(groups[1], "Noah")
check(store.people_in(os.path.join(lib, "both.jpg")) == ["Noah", "Ray"], "both.jpg lists Noah then Ray (left to right)")

# a new photo of Ray arrives -> suggestion, then confirm
files["ray4.jpg"] = [face_of(RAY)]
new = touch(os.path.join(lib, "ray4.jpg"))
sf.scan_paths(engine, store, [new])
pending = store.suggest_all()
ref4 = (store.key(new), 0)
check(store.face(ref4)["suggested"] == "Ray", "new Ray photo is suggested as Ray")
check(pending >= 1, "suggest_all reports pending faces")
store.confirm(ref4)
check(store.face(ref4)["person"] == "Ray" and store.face(ref4)["confirmed"], "confirm() names it and marks confirmed")
check(store.people["Ray"]["count"] == 5, "centroid count grows to 5")

# the stranger is NOT suggested as anyone
stranger_ref = (store.key(os.path.join(lib, "stranger.jpg")), 0)
check(store.face(stranger_ref)["suggested"] is None, "stranger gets no suggestion")

# reject: a wrong suggestion is remembered and never repeated
files["ray5.jpg"] = [face_of(RAY)]
p5 = touch(os.path.join(lib, "ray5.jpg"))
sf.scan_paths(engine, store, [p5])
store.suggest_all()
ref5 = (store.key(p5), 0)
store.reject(ref5)
store.suggest_all()
check(store.face(ref5)["suggested"] is None and "Ray" in store.face(ref5)["rejected"],
      "rejected suggestion is not offered again")

# auto-accept only the confident ones
files["ray6.jpg"] = [face_of(RAY, noise=0.02)]      # very close
files["ray7.jpg"] = [face_of(RAY, noise=0.13)]      # borderline
p6, p7 = touch(os.path.join(lib, "ray6.jpg")), touch(os.path.join(lib, "ray7.jpg"))
sf.scan_paths(engine, store, [p6, p7])
store.suggest_all()
n = store.accept_suggestions(threshold=0.9)
r6 = store.face((store.key(p6), 0))
check(n >= 1 and r6["person"] == "Ray" and r6["confirmed"] is False, "very confident match auto-accepted, flagged as auto")

# rename / merge / unname keep the store consistent
store.rename_person("Ray", "Raymond")
check("Ray" not in store.people and store.people["Raymond"]["count"] >= 5, "rename moves every face + centroid")
store.name_faces([stranger_ref], "Ray")
store.merge_people("Raymond", "Ray")
check("Ray" not in store.people and store.counts()["Raymond"] >= 6, "merge folds one person into another")
store.unname(stranger_ref)
check(store.face(stranger_ref)["person"] is None and "Raymond" in store.face(stranger_ref)["rejected"],
      "unname clears the face and remembers the wrong name")

# persistence: save, reload, same answers
store.save()
again = sf.FaceStore(os.path.join(_tmp, "cfg"))
check(again.counts() == store.counts() and again.people.keys() == store.people.keys(), "faces.json round-trips")

# a photo edited on disk (new mtime) is rescanned; a same-count rescan keeps its names
time.sleep(0.05)
touch(os.path.join(lib, "ray1.jpg"), "edited")
os.utime(os.path.join(lib, "ray1.jpg"), None)
done, _ = sf.scan_paths(engine, again, [os.path.join(lib, "ray1.jpg")])
check(done == 1 and again.people_in(os.path.join(lib, "ray1.jpg")) == ["Raymond"],
      "edited photo rescanned, name survives a same-face-count rescan")

# move_path follows a rename on disk
again.move_path(os.path.join(lib, "ray1.jpg"), os.path.join(lib, "renamed.jpg"))
check(again.people_in(os.path.join(lib, "renamed.jpg")) == ["Raymond"], "move_path carries faces to the new path")

# a scan error on one file doesn't stop the run
class Boom(FakeEngine):
    def detect(self, path):
        if "noah1" in path:
            raise RuntimeError("corrupt")
        return super().detect(path)

fresh = sf.FaceStore(os.path.join(_tmp, "cfg2"))
done, _ = sf.scan_paths(Boom(files), fresh, paths)
check(done == 8, "a file that blows up is recorded as no faces and the scan continues")

# stop() aborts cleanly with progress saved
fresh2 = sf.FaceStore(os.path.join(_tmp, "cfg3"))
count = {"n": 0}
def stop():
    count["n"] += 1
    return count["n"] > 3
done, _ = sf.scan_paths(FakeEngine(files), fresh2, paths, stop=stop, save_every=1)
check(0 < done < 8 and os.path.isfile(fresh2.path), "stop() halts early and faces.json exists")

# nothing in the store is a pixel: the photo file is untouched and no copy exists
before = open(os.path.join(lib, "noah1.jpg"), "rb").read()
check(before == b"x", "photograph file untouched by scanning")
check(not any(f.lower().endswith((".jpg", ".png")) for f in os.listdir(os.path.join(_tmp, "cfg"))),
      "no image copies in the config dir")

# --- Part 2: real models (optional) --------------------------------------------
mdir = os.environ.get("SLAPPER_FACE_MODELS")
real_models = None
if mdir and os.path.isfile(os.path.join(mdir, "yunet.onnx")):
    real_models = os.path.dirname(mdir)  # models_dir(directory) appends /models
photo = os.path.join(os.path.dirname(os.path.dirname(_HUB)), "projects", "snapsmack-ca", "img", "sean-paddleboard.jpg")
if real_models and os.path.isfile(photo):
    eng = sf.FaceEngine.load(real_models)
    check(eng is not None, "real engine loads from " + mdir)
    faces = eng.detect(photo)
    check(len(faces) == 1, "real detector finds exactly one face in the paddleboard photo (got %d)" % len(faces))
    if faces:
        check(len(faces[0].embedding) == 128 and faces[0].score > 0.8, "real embedding is 128-d with a confident score")
        x, y, w, h = faces[0].box
        check(0 <= x < 1950 and 0 <= y < 1300 and w > 40 and h > 40, "box is in original-pixel coordinates")
        again2 = eng.detect(photo)
        check(sf.cosine(faces[0].embedding, again2[0].embedding) > 0.99, "same photo -> same fingerprint")
else:
    print("SKIP real-model test (set SLAPPER_FACE_MODELS=<dir with yunet.onnx+sface.onnx>)")

check(sf.FaceEngine.load(os.path.join(_tmp, "nomodels")) is None, "engine reports None when models are absent")

print()
print("FAILED: %d" % FAILED if FAILED else "ALL PASS")
sys.exit(1 if FAILED else 0)

# ===== SNAPSMACK EOF =====
