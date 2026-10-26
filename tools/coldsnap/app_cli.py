"""COLD SNAP draft automation over the shared document store. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    C.add_library_commands(sub)
    for name in ("batches", "batch-inspect", "batch-create", "batch-draft-create", "batch-draft-update"):
        p = sub.add_parser(name, help="Operate on the desktop's actual COLD SNAP batch files")
        p.add_argument("--root", help="Existing session root; defaults to the desktop app's store")
        if name in {"batch-inspect", "batch-draft-create", "batch-draft-update"}:
            p.add_argument("--batch", required=True)
        if name == "batch-create":
            p.add_argument("--name", required=True); p.add_argument("--mode", required=True, choices=["photoblog", "carousel", "smacktalk"])
        if name in {"batch-draft-create", "batch-draft-update"}:
            p.add_argument("--input", required=True)
        if name == "batch-draft-update":
            p.add_argument("--id", required=True); p.add_argument("--expected-sha256", required=True)
        if name not in {"batches", "batch-inspect"}:
            p.add_argument("--apply", action="store_true")
    for name in ("drafts", "draft-inspect", "draft-create", "draft-save"):
        p = sub.add_parser(name); p.add_argument("--site", required=True)
        if name in {"draft-inspect", "draft-save"}:
            p.add_argument("--id", required=True)
        if name in {"draft-create", "draft-save"}:
            p.add_argument("--apply", action="store_true")
        if name == "draft-create":
            p.add_argument("--title", default="")
        if name == "draft-save":
            p.add_argument("--input", required=True)
            p.add_argument("--expected-revision", type=int, required=True)


def execute(args):
    result = C.library_command(args)
    if result is not NotImplemented:
        return result
    if args.command.startswith("batch"):
        return batch_command(args)
    import snap_library as library
    if args.command == "drafts":
        return {"items": library.drafts(args.site)}
    if args.command == "draft-inspect":
        value = library.draft(args.site, args.id)
        if value is None:
            raise ValueError("Draft not found")
        return value
    if args.command == "draft-create":
        return library.create_draft(args.site, title=args.title) if args.apply else {"status": "planned", "title": args.title}
    payload = C.object_json(args.input)
    if set(payload) - {"title", "blocks"} or not payload:
        raise ValueError("Draft update supports only title and blocks")
    if "title" in payload and (not isinstance(payload["title"], str) or len(payload["title"]) > 1000):
        raise ValueError("Invalid draft title")
    if "blocks" in payload and (not isinstance(payload["blocks"], list) or len(payload["blocks"]) > 500):
        raise ValueError("Expected no more than 500 blocks")
    if args.apply:
        return library.save_draft(args.site, args.id, expected_revision=args.expected_revision, **payload)
    current = library.draft(args.site, args.id)
    if not current or current["revision"] != args.expected_revision:
        raise ValueError("Draft revision conflict")
    return {"status": "planned", "id": args.id, "revision": current["revision"], "changes": payload}


def batch_command(args):
    import hashlib
    import re
    import sumna_offline as O
    root = Path(args.root or O.SESSIONS_ROOT).resolve()
    for value in (getattr(args, "batch", None), getattr(args, "id", None)):
        if value and not re.fullmatch(r"[A-Za-z0-9_-]{1,100}", value):
            raise ValueError("Invalid batch/draft ID")
    store = O.SessionStore(str(root))
    if args.command == "batches":
        return {"root": str(root), "items": [session.meta for session in store.list()]}
    if args.command == "batch-create":
        return store.create(args.name, args.mode).meta if args.apply else {"status": "planned", "name": args.name, "mode": args.mode}
    session = store.load(args.batch)
    if not session:
        raise ValueError("Batch not found")
    if args.command == "batch-inspect":
        drafts = []
        for draft in session.list_drafts():
            value = draft.to_dict()
            value["cli_sha256"] = hashlib.sha256(Path(session._draft_path(draft.draft_id)).read_bytes()).hexdigest()
            drafts.append(value)
        return {"batch": session.meta, "drafts": drafts}
    updates = C.object_json(args.input)
    allowed = {"title", "caption", "alt", "tags", "colophon", "post_date"}
    if not updates or set(updates) - allowed or any(not isinstance(value, str) or len(value) > 1_000_000 for value in updates.values()):
        raise ValueError("Draft input supports bounded title, caption, alt, tags, colophon and post_date text")
    if args.command == "batch-draft-create":
        kind = {"photoblog": O.KIND_SOLO, "carousel": O.KIND_GRAM_SINGLE, "smacktalk": O.KIND_SMACKTALK}[session.mode]
        draft = O.Draft(draft_id=O._new_id(), kind=kind, mode=session.mode)
    else:
        draft = session.load_draft(args.id)
        if not draft:
            raise ValueError("Draft not found")
        actual = hashlib.sha256(Path(session._draft_path(draft.draft_id)).read_bytes()).hexdigest()
        if actual != args.expected_sha256:
            raise ValueError("Draft changed since inspection")
        if draft.status != O.ST_DRAFT:
            raise ValueError("Only unqueued local drafts may be edited through this command")
        if draft.body_blocks and "caption" in updates:
            raise ValueError("This draft uses BIGGIE blocks; edit its body in the desktop block editor")
    for field, value in updates.items():
        setattr(draft, field, value)
    if args.apply:
        session.add_draft(draft, copy_images=False)
    return {"status": "stored-locally" if args.apply else "planned", "draft": draft.to_dict(), "published": False}


def main(argv=None):
    return C.run("coldsnap", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
