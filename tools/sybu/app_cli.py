"""SYBU queue inspection and assistant metadata import. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    C.add_library_commands(sub)
    p = sub.add_parser("queue"); p.add_argument("--folder", required=True)
    p.add_argument("--recovery-dir", help="Existing installed app recovery directory when running from source")
    p = sub.add_parser("queue-import", help="Apply supplied metadata to the existing recovery queue; never post")
    p.add_argument("--folder", required=True); p.add_argument("--input", required=True)
    p.add_argument("--recovery-dir")
    p.add_argument("--apply", action="store_true")


def execute(args):
    result = C.library_command(args)
    if result is not NotImplemented:
        return result
    import recovery
    folder = Path(args.folder).resolve(strict=True)
    if not folder.is_dir():
        raise ValueError("--folder must be an image directory")
    store = recovery.RecoveryStore(str(folder))
    if args.recovery_dir:
        directory = Path(args.recovery_dir).resolve(strict=True)
        if not directory.is_dir():
            raise ValueError("--recovery-dir must be an existing directory")
        store.path = str(directory / Path(store.path).name)
    store.load()
    if args.command == "queue":
        from manifest_parser import ManifestEntry
        items = []
        for path in sorted(folder.iterdir()):
            if path.is_file() and path.suffix.lower() in {".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff"}:
                entry = ManifestEntry(file=path.name)
                items.append({"file": path.name, "image_path": str(path), "saved": store.lookup(entry)})
        return {"folder": str(folder), "items": items, "recovery_path": store.path}
    payload = C.object_json(args.input)
    if set(payload) != {"items"} or not isinstance(payload["items"], list) or not 1 <= len(payload["items"]) <= 500:
        raise ValueError("Expected {items: [...]} with 1–500 metadata objects")
    from manifest_parser import ManifestEntry
    planned, seen = [], set()
    for item in payload["items"]:
        if not isinstance(item, dict) or set(item) - {"file", *recovery.FIELDS}:
            raise ValueError("Unknown queue field")
        name = item.get("file", "")
        if not name or Path(name).name != name or name in seen:
            raise ValueError("Each file must be a unique filename within the selected folder")
        path = (folder / name).resolve(strict=True)
        if path.parent != folder:
            raise ValueError("Queue image is outside selected folder")
        seen.add(name)
        entry = ManifestEntry(file=name)
        current = store.lookup(entry) or {}
        if current.get("status") == "ok":
            raise ValueError("Already-posted images cannot be changed through queue-import")
        for field in recovery.FIELDS:
            value = item.get(field, current.get(field, getattr(entry, field, "")))
            if not isinstance(value, str) or len(value) > 20000:
                raise ValueError("Metadata fields must be bounded strings")
            setattr(entry, field, value)
        if not entry.title and not entry.tags:
            raise ValueError("Queue enrichment needs a title or tags")
        planned.append(entry)
    if args.apply:
        for entry in planned:
            store.upsert(entry)
        check = recovery.RecoveryStore(str(folder)); check.path = store.path; check.load()
        if any((check.lookup(entry) or {}).get("title") != entry.title for entry in planned):
            raise OSError("Recovery write could not be verified")
    return {"status": "stored-locally" if args.apply else "planned", "files": sorted(seen), "posted": False}


def main(argv=None):
    return C.run("sybu", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
