"""Shared desktop CLI protocol. No GUI or AI provider is imported here.

SNAPSMACK_EOF_HEADER: preserve the canonical EOF marker.
"""
from __future__ import annotations

import argparse
import contextlib
import io
import json
import math
import os
from pathlib import Path
import sqlite3
import sys
import tempfile

PROTOCOL = 1


class OperationError(ValueError):
    def __init__(self, message, details):
        super().__init__(message)
        self.details = details


def read_json(path):
    path = Path(path)
    if path.stat().st_size > 4_000_000:
        raise ValueError("JSON input exceeds 4 MB")
    def reject(value):
        raise ValueError("Non-finite JSON number")
    def number(value):
        result = float(value)
        if not math.isfinite(result):
            return reject(value)
        return result
    return json.loads(path.read_text(encoding="utf-8-sig"), parse_constant=reject, parse_float=number)


def object_json(path):
    value = read_json(path)
    if not isinstance(value, dict):
        raise ValueError("JSON root must be an object")
    return value


def atomic_json(path, value):
    path = Path(path).resolve()
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=".cli-", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, allow_nan=False, indent=2)
            stream.write("\n"); stream.flush(); os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def output_path(args, *protected):
    path = Path(args.output).resolve()
    for item in protected:
        if not item:
            continue
        original = Path(item).resolve()
        if path == original or (path.exists() and original.exists() and os.path.samefile(path, original)):
            raise ValueError("Output must differ from the source and input files")
    if path.exists() and not getattr(args, "overwrite", False):
        raise ValueError("Output exists; choose another path or pass --overwrite")
    path.parent.mkdir(parents=True, exist_ok=True)
    return str(path)


def output_options(parser):
    parser.add_argument("--output", required=True)
    parser.add_argument("--overwrite", action="store_true")


def profile(site, key_type):
    from urllib.parse import urlparse
    import snap_connections
    parsed = urlparse(site)
    if parsed.scheme not in ("https", "http") or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError("--site must be an HTTP(S) site URL without credentials")
    if parsed.scheme == "http" and parsed.hostname not in ("localhost", "127.0.0.1", "::1"):
        raise ValueError("Remote connections require HTTPS")
    selected = snap_connections.resolve(site, key_type)
    if not selected or not selected.get("api_key"):
        raise PermissionError("No saved connection for the requested site and app")
    return selected


def add_library_commands(sub):
    p = sub.add_parser("images", help="List the local image catalogue with stable asset-ID pagination")
    p.add_argument("--site", required=True); p.add_argument("--after", default="")
    p.add_argument("--limit", type=int, default=100)
    p = sub.add_parser("enrichment-list", help="Read retained results, including expired cache entries")
    p.add_argument("--site", required=True); p.add_argument("--image-sha256")
    p.add_argument("--after", default=""); p.add_argument("--limit", type=int, default=100)
    p = sub.add_parser("enrichment-import", help="Store assistant findings locally; never call an AI provider")
    p.add_argument("--site", required=True); p.add_argument("--image", required=True)
    p.add_argument("--input", required=True); p.add_argument("--model", default="assistant")
    p.add_argument("--prompt", default="assistant-import-v1")
    p.add_argument("--apply", action="store_true", help="Commit locally (default is a plan)")


def local_database(site):
    import snap_home
    # Do not initialize a catalogue merely to inspect it.
    return Path(snap_home.site_db_dir(site)) / "catalog.sqlite"


def library_command(args):
    if args.command not in {"images", "enrichment-list", "enrichment-import"}:
        return NotImplemented
    if args.command in {"images", "enrichment-list"}:
        if not 1 <= args.limit <= 500:
            raise ValueError("--limit must be between 1 and 500")
        path = local_database(args.site)
        if not path.is_file():
            return {"items": [], "next_after": None}
        with sqlite3.connect(path.resolve().as_uri() + "?mode=ro", uri=True) as conn:
            conn.row_factory = sqlite3.Row
            table, key = ("assets", "asset_id") if args.command == "images" else ("enrichment_cache", "cache_key")
            if not conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (table,)).fetchone():
                return {"items": [], "next_after": None}
            where, params = f"{key} > ?", [args.after]
            if args.command == "enrichment-list" and args.image_sha256:
                where += " AND image_sha256 = ?"; params.append(args.image_sha256)
            rows = [dict(row) for row in conn.execute(
                f"SELECT * FROM {table} WHERE {where} ORDER BY {key} LIMIT ?", params + [args.limit + 1])]
        more = len(rows) > args.limit
        rows = rows[:args.limit]
        if args.command == "enrichment-list":
            for row in rows:
                row["bundle"] = json.loads(row.pop("bundle_json"))
                row["accepted"] = json.loads(row.pop("accepted_json") or "{}")
                row.pop("raw_response", None)
        else:
            import snap_library
            for row in rows:
                row["local_image_path"] = snap_library.asset_file(args.site, row["asset_id"])
        return {"items": rows, "next_after": rows[-1][key] if more else None}
    import snap_enrichment_cache as cache
    bundle = object_json(args.input)
    if not bundle:
        raise ValueError("Enrichment bundle is empty")
    # This is an observation store, not publication. Retain all JSON fields,
    # including detailed description and sensitive assessment; accept none.
    digest = cache.image_sha256(args.image)
    from urllib.parse import urlparse
    domain = (urlparse(args.site).hostname or args.site).lower()
    if len(args.model) > 100 or not args.model.strip() or len(args.prompt) > 100000:
        raise ValueError("Model/run provenance exceeds supported limits")
    prompt_hash = cache.prompt_sha256(args.prompt)
    old = None
    database = local_database(args.site)
    if database.is_file():
        with sqlite3.connect(database.resolve().as_uri() + "?mode=ro", uri=True) as conn:
            if conn.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='enrichment_cache'").fetchone():
                key = cache.cache_key(digest, domain, args.model, prompt_hash)
                row = conn.execute("SELECT cache_key,bundle_json FROM enrichment_cache WHERE cache_key=?", (key,)).fetchone()
                if row:
                    old = {"cache_key": row[0], "bundle": json.loads(row[1])}
    if old:
        if old["bundle"] != bundle:
            raise ValueError("This run key already has different findings; use a new --prompt run identifier to preserve history")
        return {"status": "reused", "cache_key": old["cache_key"], "applied_to_site": False}
    result = {"status": "planned", "image_sha256": digest, "fields": sorted(bundle), "applied_to_site": False}
    if args.apply:
        saved = cache.put(args.site, digest, domain, args.model, prompt_hash, bundle,
                          accepted={}, source="desktop")
        result.update(status="stored-locally", cache_key=saved["cache_key"], pending_sync=True)
    return result


def run(app, configure, execute, argv=None):
    """Run one CLI request and return an exit code. Windowed builds use --result."""
    argv = list(sys.argv[1:] if argv is None else argv)
    if sys.stdout is not None and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    result_path = None
    # Accept --result anywhere, including before the subcommand. It is the
    # output channel for console=False packaged apps; never redirect GUI logs.
    if "--result" in argv:
        index = argv.index("--result")
        if index + 1 >= len(argv):
            return 2
        result_path = argv[index + 1]; del argv[index:index + 2]
    parser = argparse.ArgumentParser(prog=app, description=f"{app} desktop automation (protocol {PROTOCOL})")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("capabilities", help="Describe available CLI commands")
    configure(sub)
    code = 0
    try:
        if result_path:
            for index, token in enumerate(argv[:-1]):
                if token in {"--input", "--image", "--project", "--output", "--recipe", "--db"}:
                    candidate = argv[index + 1]
                    same = Path(result_path).resolve() == Path(candidate).resolve()
                    if not same and Path(result_path).exists() and Path(candidate).exists():
                        same = os.path.samefile(result_path, candidate)
                    if same:
                        result_path = None
                        raise ValueError("--result must differ from all input/output paths")
        args = parser.parse_args(argv)
        # Prevent overwriting a photograph/project/input with the result envelope.
        if result_path:
            protected = [getattr(args, field, None) for field in ("input", "image", "project", "output", "recipe")]
            for item in protected:
                if item and (Path(result_path).resolve() == Path(item).resolve() or (Path(result_path).exists() and Path(item).exists() and os.path.samefile(result_path, item))):
                    result_path = None
                    raise ValueError("--result must differ from all input/output paths")
        with contextlib.redirect_stdout(io.StringIO()):
            import snap_hq_gate
            try:
                snap_hq_gate.require()
            except SystemExit as exc:
                raise PermissionError(str(exc)) from exc
            if args.command == "capabilities":
                data = {"commands": sorted(sub.choices), "paid_ai_fallback": False,
                        "result_file": True, "scope": "Saved files and app services; no live GUI-session attachment"}
            else:
                data = execute(args)
        envelope = {"ok": True, "protocol": PROTOCOL, "app": app, "data": data}
    except SystemExit as exc:
        if exc.code == 0:
            return 0
        code = 2; envelope = {"ok": False, "protocol": PROTOCOL, "app": app,
                              "error": {"code": "usage", "message": "Invalid arguments; use --help"}}
    except Exception as exc:
        code = 3 if isinstance(exc, PermissionError) else 2
        envelope = {"ok": False, "protocol": PROTOCOL, "app": app,
                    "error": {"code": "permission" if code == 3 else "operation", "message": str(exc)}}
        if isinstance(exc, OperationError):
            envelope["error"]["details"] = exc.details
    if result_path:
        try:
            atomic_json(result_path, envelope)
        except OSError:
            if sys.stderr is not None:
                print("Could not write CLI result file", file=sys.stderr)
            return 2
    elif sys.stdout is not None:
        print(json.dumps(envelope, ensure_ascii=False, allow_nan=False, default=str))
    return code

# ===== SNAPSMACK EOF =====
