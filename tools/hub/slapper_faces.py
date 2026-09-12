"""SNAP SLAPPER faces: Picasa-style people tagging, entirely on this machine.

WHAT IT DOES FOR THE PHOTOGRAPHER
    Scan a library, find the faces, group the ones that look like the same
    person, let the owner name a group once ("Ray"), and from then on new
    photographs of Ray are suggested as Ray and confirmed with one click.
    Names never leave the computer. No cloud, no key, no quota.

HOW (two small OpenCV models, already inside the opencv-python we ship)
    YuNet  - finds faces (232 KB model)
    SFace  - turns an aligned face into a 128-number fingerprint (37 MB model)
    Both run on CPU through cv2's own runtime: no onnxruntime, no torch, no
    new pip dependency. The two .onnx files are fetched ONCE from OpenCV's
    model zoo into the SNAP SLAPPER config dir, sha256-checked, and reused.
    If a stronger model (InsightFace) is wanted later it slots in behind the
    same FaceEngine interface without touching the store or the UI.

WHERE THE DATA LIVES
    <config_dir("snap-slapper")>/faces.json   (versioned envelope, like the
    other catalogue files). Per photo: mtime + every face's box, fingerprint,
    and who it is. Per person: name + running centroid + count. A photo that
    hasn't changed (same mtime) is never re-scanned.

WHAT IT NEVER DOES
    - upload a photo or a fingerprint anywhere
    - modify the photograph file
    - copy originals into any tool folder  (the store holds numbers, not pixels)

USAGE (headless; the UI is a separate, Codex-lane job)
    engine = FaceEngine.load()                      # None if models not present
    store  = FaceStore(directory)
    scan_paths(engine, store, paths, progress=cb)   # incremental
    groups = store.cluster_unnamed()                # [[face_ref, ...], ...]
    store.name_faces(groups[0], "Ray")              # names + builds centroid
    store.suggest_all()                             # unnamed faces -> "Ray?" where confident
    store.confirm(face_ref) / store.reject(face_ref)
    store.people_in(path)                           # ["Ray", "Noah"] for captions/tags

SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""

import hashlib
import math
import os
import time
import urllib.request

import photo_manager

# --- models -----------------------------------------------------------------
# Pinned to exact files in OpenCV's model zoo. If either hash ever disagrees the
# file is deleted and the engine reports "models unavailable" instead of running
# something we didn't vet.
MODELS = {
    "yunet.onnx": (
        "https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx",
        "8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4",
    ),
    "sface.onnx": (
        "https://github.com/opencv/opencv_zoo/raw/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx",
        "0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79",
    ),
}

# SFace cosine-similarity threshold recommended by OpenCV (same person >= this).
# Clustering uses a slightly stricter bar so a bad merge needs a human, and
# suggestion uses the documented value so a good match doesn't need one.
SAME_PERSON = 0.363
CLUSTER_LINK = 0.40
DETECT_SCORE = 0.80          # YuNet confidence floor
MIN_FACE_PX = 40             # ignore faces smaller than this (background crowd)
MAX_EDGE = 1600              # detect on a downscaled copy; boxes are scaled back

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"}


def _sha256(path):
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def models_dir(directory=None):
    if directory is None:
        try:
            import snap_home
            directory = snap_home.config_dir("snap-slapper")
        except Exception:  # noqa: BLE001
            directory = os.path.join(
                os.environ.get("SNAPSMACK_HOME") or r"C:\snapsmack",
                "config_files", "snap-slapper")
    path = os.path.join(directory, "models")
    os.makedirs(path, exist_ok=True)
    return path


def models_present(directory=None):
    folder = models_dir(directory)
    for name, (_url, digest) in MODELS.items():
        path = os.path.join(folder, name)
        if not os.path.isfile(path) or _sha256(path) != digest:
            return False
    return True


def ensure_models(directory=None, progress=None):
    """Fetch the two model files once (sha256-verified). Returns the folder.

    Raises RuntimeError with a plain message if a download fails or a file
    doesn't match its pinned hash; the caller shows that to the operator.
    """
    folder = models_dir(directory)
    for name, (url, digest) in MODELS.items():
        path = os.path.join(folder, name)
        if os.path.isfile(path) and _sha256(path) == digest:
            continue
        if progress:
            progress("Downloading face model " + name)
        temporary = path + ".part"
        try:
            urllib.request.urlretrieve(url, temporary)  # noqa: S310 (pinned https, hash-checked)
        except Exception as exc:  # noqa: BLE001
            raise RuntimeError("Could not download " + name + ": " + str(exc)) from exc
        if _sha256(temporary) != digest:
            os.remove(temporary)
            raise RuntimeError(name + " did not match its pinned checksum; refusing to use it.")
        os.replace(temporary, path)
    return folder


# --- engine -----------------------------------------------------------------
class Face:
    """One detected face: box in ORIGINAL pixels, detector score, 128-d fingerprint."""

    __slots__ = ("box", "score", "embedding")

    def __init__(self, box, score, embedding):
        self.box = [int(v) for v in box]          # x, y, w, h
        self.score = float(score)
        self.embedding = [float(v) for v in embedding]


class FaceEngine:
    """YuNet + SFace behind one small interface. Construct via FaceEngine.load()."""

    def __init__(self, detector, recogniser):
        self._det = detector
        self._rec = recogniser

    @classmethod
    def load(cls, directory=None):
        """Return an engine, or None if the models aren't on disk yet."""
        if not models_present(directory):
            return None
        import cv2
        folder = models_dir(directory)
        det = cv2.FaceDetectorYN.create(
            os.path.join(folder, "yunet.onnx"), "", (320, 320), DETECT_SCORE, 0.3, 5000)
        rec = cv2.FaceRecognizerSF.create(os.path.join(folder, "sface.onnx"), "")
        return cls(det, rec)

    def detect(self, image_path):
        """Faces in one photograph. Reads the file, never writes it."""
        import cv2
        import numpy as np
        data = np.fromfile(image_path, dtype=np.uint8)   # unicode-safe on Windows
        image = cv2.imdecode(data, cv2.IMREAD_COLOR)
        if image is None:
            return []
        height, width = image.shape[:2]
        scale = 1.0
        longest = max(width, height)
        if longest > MAX_EDGE:
            scale = MAX_EDGE / float(longest)
            image = cv2.resize(image, (int(width * scale), int(height * scale)),
                               interpolation=cv2.INTER_AREA)
        self._det.setInputSize((image.shape[1], image.shape[0]))
        _, found = self._det.detect(image)
        faces = []
        if found is None:
            return faces
        for row in found:
            x, y, w, h = row[:4]
            if min(w, h) < MIN_FACE_PX * scale:
                continue
            aligned = self._rec.alignCrop(image, row)
            embedding = self._rec.feature(aligned).flatten()
            faces.append(Face([x / scale, y / scale, w / scale, h / scale], row[-1], embedding))
        return faces


def cosine(a, b):
    dot = sum(x * y for x, y in zip(a, b))
    na = math.sqrt(sum(x * x for x in a)) or 1e-9
    nb = math.sqrt(sum(y * y for y in b)) or 1e-9
    return dot / (na * nb)


# --- store ------------------------------------------------------------------
class FaceStore:
    """faces.json: per-photo faces + per-person centroids. Pure data, no UI.

    A "face ref" is (photo_key, index). Person state per face:
        person=None,  suggested=None            -> unknown
        person=None,  suggested="Ray"           -> "is this Ray?" (awaiting a click)
        person="Ray", confirmed=True            -> named by a human
        person="Ray", confirmed=False           -> auto-accepted (see accept_suggestions)
        rejected=[...names...]                  -> never suggest these again for this face
    """

    def __init__(self, directory):
        self.directory = directory
        os.makedirs(directory, exist_ok=True)
        self.path = os.path.join(directory, "faces.json")
        data = photo_manager.load_versioned(self.path, "faces", {})
        self.photos = data.get("photos", {}) if isinstance(data, dict) else {}
        self.people = data.get("people", {}) if isinstance(data, dict) else {}

    @staticmethod
    def key(path):
        return os.path.normcase(os.path.abspath(path))

    def save(self):
        photo_manager.save_versioned(self.path, "faces", {
            "photos": self.photos, "people": self.people})

    # -- scanning ---------------------------------------------------------
    def needs_scan(self, path):
        entry = self.photos.get(self.key(path))
        try:
            mtime = os.path.getmtime(path)
        except OSError:
            return False
        return not entry or abs(float(entry.get("mtime", -1)) - mtime) > 1e-6

    def record(self, path, faces):
        """Store a photo's faces. Existing names survive if the face count matches
        (same photo, touched mtime); otherwise the photo starts fresh."""
        key = self.key(path)
        try:
            mtime = os.path.getmtime(path)
        except OSError:
            mtime = time.time()
        old = self.photos.get(key, {}).get("faces", [])
        rows = []
        for i, face in enumerate(faces):
            row = {"box": face.box, "score": round(face.score, 3),
                   "emb": [round(v, 5) for v in face.embedding],
                   "person": None, "confirmed": False, "suggested": None, "rejected": []}
            if len(old) == len(faces) and old[i].get("person"):
                row.update({k: old[i][k] for k in ("person", "confirmed", "rejected") if k in old[i]})
            rows.append(row)
        self.photos[key] = {"mtime": mtime, "faces": rows}

    def forget(self, path):
        self.photos.pop(self.key(path), None)

    def move_path(self, source, target):
        old, new = self.key(source), self.key(target)
        if old in self.photos:
            self.photos[new] = self.photos.pop(old)

    # -- queries ----------------------------------------------------------
    def faces(self):
        """Every face as (ref, row)."""
        for key, entry in self.photos.items():
            for i, row in enumerate(entry.get("faces", [])):
                yield (key, i), row

    def face(self, ref):
        key, i = ref
        return self.photos[key]["faces"][i]

    def unnamed(self):
        return [ref for ref, row in self.faces() if not row.get("person")]

    def pending(self):
        """Faces waiting on a human: 'is this X?'"""
        return [ref for ref, row in self.faces() if not row.get("person") and row.get("suggested")]

    def people_in(self, path):
        """Confirmed + accepted names in one photo, in left-to-right order."""
        entry = self.photos.get(self.key(path))
        if not entry:
            return []
        rows = sorted(entry["faces"], key=lambda r: r["box"][0])
        seen, names = set(), []
        for row in rows:
            name = row.get("person")
            if name and name not in seen:
                seen.add(name)
                names.append(name)
        return names

    def photos_of(self, name):
        return sorted({key for (key, _i), row in self.faces() if row.get("person") == name})

    def counts(self):
        out = {}
        for _ref, row in self.faces():
            name = row.get("person")
            if name:
                out[name] = out.get(name, 0) + 1
        return out

    # -- naming -----------------------------------------------------------
    def _recentre(self, name):
        embs = [row["emb"] for _ref, row in self.faces() if row.get("person") == name]
        if not embs:
            self.people.pop(name, None)
            return
        dim = len(embs[0])
        centroid = [sum(e[d] for e in embs) / len(embs) for d in range(dim)]
        self.people[name] = {"centroid": [round(v, 5) for v in centroid], "count": len(embs)}

    def name_faces(self, refs, name, confirmed=True):
        name = str(name).strip()
        if not name:
            raise ValueError("A person needs a name.")
        touched = set()
        for ref in refs:
            row = self.face(ref)
            if row.get("person"):
                touched.add(row["person"])
            row["person"] = name
            row["confirmed"] = bool(confirmed)
            row["suggested"] = None
        touched.add(name)
        for person in touched:
            self._recentre(person)

    def unname(self, ref):
        row = self.face(ref)
        old = row.get("person")
        row["person"] = None
        row["confirmed"] = False
        if old:
            row.setdefault("rejected", []).append(old)
            self._recentre(old)

    def rename_person(self, old, new):
        new = str(new).strip()
        if not new:
            raise ValueError("A person needs a name.")
        for _ref, row in self.faces():
            if row.get("person") == old:
                row["person"] = new
            if row.get("suggested") == old:
                row["suggested"] = new
        self.people.pop(old, None)
        self._recentre(new)

    def merge_people(self, keep, drop):
        self.rename_person(drop, keep)

    # -- suggestions ------------------------------------------------------
    def best_match(self, emb, exclude=()):
        best, best_sim = None, -1.0
        for name, person in self.people.items():
            if name in exclude:
                continue
            sim = cosine(emb, person["centroid"])
            if sim > best_sim:
                best, best_sim = name, sim
        return best, best_sim

    def suggest_all(self, threshold=SAME_PERSON):
        """For every unknown face, propose the closest known person if confident.
        Returns the number of faces now pending a human's yes/no."""
        pending = 0
        for ref in self.unnamed():
            row = self.face(ref)
            name, sim = self.best_match(row["emb"], exclude=row.get("rejected", []))
            row["suggested"] = name if (name and sim >= threshold) else None
            pending += 1 if row["suggested"] else 0
        return pending

    def confirm(self, ref):
        row = self.face(ref)
        if not row.get("suggested"):
            return
        self.name_faces([ref], row["suggested"], confirmed=True)

    def reject(self, ref):
        row = self.face(ref)
        name = row.get("suggested")
        if name:
            row.setdefault("rejected", []).append(name)
        row["suggested"] = None

    def accept_suggestions(self, threshold=0.55):
        """Auto-accept only the very confident ones (marked confirmed=False so the
        UI can show them as 'auto' and a human can still undo). Returns count."""
        n = 0
        for ref in self.pending():
            row = self.face(ref)
            name, sim = self.best_match(row["emb"], exclude=row.get("rejected", []))
            if name == row["suggested"] and sim >= threshold:
                self.name_faces([ref], name, confirmed=False)
                n += 1
        return n

    # -- clustering -------------------------------------------------------
    def cluster_unnamed(self, link=CLUSTER_LINK, min_size=2):
        """Group unknown faces that look like the same person (greedy,
        average-linkage on cosine similarity). Returns lists of refs, biggest
        first. Singletons are left out unless min_size=1."""
        refs = self.unnamed()
        embs = [self.face(r)["emb"] for r in refs]
        clusters = []          # each: {"refs": [...], "sum": [...], "n": int}
        for ref, emb in zip(refs, embs):
            best, best_sim = None, -1.0
            for cluster in clusters:
                centroid = [v / cluster["n"] for v in cluster["sum"]]
                sim = cosine(emb, centroid)
                if sim > best_sim:
                    best, best_sim = cluster, sim
            if best is not None and best_sim >= link:
                best["refs"].append(ref)
                best["sum"] = [a + b for a, b in zip(best["sum"], emb)]
                best["n"] += 1
            else:
                clusters.append({"refs": [ref], "sum": list(emb), "n": 1})
        groups = [c["refs"] for c in clusters if c["n"] >= min_size]
        groups.sort(key=len, reverse=True)
        return groups


# --- driver -----------------------------------------------------------------
def iter_images(roots):
    for root in roots:
        if os.path.isfile(root):
            if os.path.splitext(root)[1].lower() in IMAGE_EXTS:
                yield root
            continue
        for folder, _dirs, files in os.walk(root):
            for name in files:
                if os.path.splitext(name)[1].lower() in IMAGE_EXTS:
                    yield os.path.join(folder, name)


def scan_paths(engine, store, paths, progress=None, save_every=25, stop=None):
    """Incremental scan. `progress(done, total, path)` if given; `stop()` -> True
    aborts cleanly with everything so far saved. Returns (scanned, skipped)."""
    todo = [p for p in paths if store.needs_scan(p)]
    skipped = len(paths) - len(todo)
    done = 0
    for path in todo:
        if stop and stop():
            break
        try:
            faces = engine.detect(path)
        except Exception:  # noqa: BLE001 - one bad file must not stop the scan
            faces = []
        store.record(path, faces)
        done += 1
        if progress:
            progress(done, len(todo), path)
        if done % save_every == 0:
            store.save()
    store.save()
    return done, skipped

# ===== SNAPSMACK EOF =====
