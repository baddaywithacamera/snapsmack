"""Safe, UI-independent organizer operations for SNAP SLAPPER."""

import datetime
import os
import tempfile

from PIL import Image

import photo_manager


EXIF_IFD = 0x8769               # where cameras actually put the capture time
DATETIME_ORIGINAL = 36867
DATETIME = 306


def capture_date(path):
    """When the photograph was TAKEN, falling back to the file's own time.

    DateTimeOriginal lives in the Exif sub-IFD, and Pillow's ``getexif()``
    does not expose that: reading 36867 from the base IFD returns None for
    every ordinary camera JPEG. This silently fell through to the file's
    modified time, so "Import, group into YYYY/MM folders" filed photographs
    under the month they were COPIED and {date} in Batch Rename wrote that
    date into the filename. The library's own date sort has always read the
    sub-IFD; this now does the same.
    """
    try:
        with Image.open(path) as image:
            exif = image.getexif()
            raw = None
            try:
                raw = exif.get_ifd(EXIF_IFD).get(DATETIME_ORIGINAL)
            except Exception:  # noqa: BLE001 — damaged EXIF is not an error here
                raw = None
            # Some writers do put one of these in the base IFD; take them
            # before giving up on the file's own metadata.
            raw = raw or exif.get(DATETIME_ORIGINAL) or exif.get(DATETIME)
        if raw:
            return datetime.datetime.strptime(
                str(raw).strip(), "%Y:%m:%d %H:%M:%S")
    except (OSError, ValueError, TypeError):
        pass
    return datetime.datetime.fromtimestamp(os.path.getmtime(path))


def import_photos(paths, destination, date_folders=False, skip_duplicates=True):
    """Atomically import files, optionally grouped YYYY/MM; return outputs/skips."""
    destination = os.path.abspath(destination)
    os.makedirs(destination, exist_ok=True)
    known = set()
    if skip_duplicates:
        for root, _dirs, files in os.walk(destination):
            for name in files:
                candidate = os.path.join(root, name)
                try:
                    known.add(photo_manager.content_hash(candidate))
                except OSError:
                    continue
    outputs, skipped = [], []
    for source in paths:
        try:
            digest = photo_manager.content_hash(source) if skip_duplicates else None
            if digest and digest in known:
                skipped.append(source)
                continue
            target_dir = destination
            if date_folders:
                date = capture_date(source)
                target_dir = os.path.join(destination, f"{date.year:04d}", f"{date.month:02d}")
            os.makedirs(target_dir, exist_ok=True)
            target = photo_manager.unique_path(
                os.path.join(target_dir, os.path.basename(source)))
            outputs.append(photo_manager.atomic_copy(source, target, prefix=".snap-import-"))
            if digest:
                known.add(digest)
        except OSError as exc:
            skipped.append(f"{source}: {exc}")
    return outputs, skipped


def batch_rename(paths, pattern):
    """Rename as a transaction. Pattern supports {name}, {n}, {date}."""
    paths = [os.path.abspath(path) for path in paths]
    if not paths:
        return []
    targets = []
    for number, source in enumerate(paths, 1):
        stem, extension = os.path.splitext(os.path.basename(source))
        date = capture_date(source).strftime("%Y-%m-%d")
        try:
            renamed = pattern.format(name=stem, n=number, date=date).strip()
        except (KeyError, ValueError) as exc:
            raise ValueError("Use only {name}, {n}, and {date} in the pattern") from exc
        renamed = os.path.basename(renamed)
        if not renamed or renamed in (".", ".."):
            raise ValueError("The rename pattern produced an empty or invalid name")
        if not os.path.splitext(renamed)[1]:
            renamed += extension
        targets.append(os.path.join(os.path.dirname(source), renamed))
    normalized = [os.path.normcase(path) for path in targets]
    if len(set(normalized)) != len(normalized):
        raise FileExistsError("The rename pattern produces duplicate filenames")
    sources = {os.path.normcase(path) for path in paths}
    for target in targets:
        if os.path.exists(target) and os.path.normcase(target) not in sources:
            raise FileExistsError(target)
    temporary = []
    completed = []
    try:
        for source in paths:
            descriptor, temp = tempfile.mkstemp(
                prefix=".snap-rename-", suffix=os.path.splitext(source)[1],
                dir=os.path.dirname(source))
            os.close(descriptor)
            os.remove(temp)
            os.replace(source, temp)
            temporary.append((source, temp))
        for (source, temp), target in zip(temporary, targets):
            os.replace(temp, target)
            completed.append((source, target))
        return completed
    except Exception:
        for source, target in reversed(completed):
            if os.path.exists(target) and not os.path.exists(source):
                os.replace(target, source)
        for source, temp in reversed(temporary):
            if os.path.exists(temp) and not os.path.exists(source):
                os.replace(temp, source)
        raise


# ===== SNAPSMACK EOF =====
