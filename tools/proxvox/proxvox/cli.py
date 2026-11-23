"""PROX VOX — Operator CLI for P1 read, P3/P5 live-authorised write, and P2 audit/flush.

Secrets never come from CLI flags, config files, or environment variables:
  - P1 read token comes strictly from Windows Credential Manager (credentials.py).
  - P3 write password and TOTP are prompted live at runtime and held in memory only.
  - Config files carrying secret keys are refused before any client starts.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import getpass
import json
import os
import shlex
import sys

from .audit import AuditBuffer
from .broker import ReadOnlyBroker, Refused
from .credentials import load_token
from .sink import DEFAULT_OFFHOST_SINK_DIR, flush_audit
from .write_client import WriteClient

_FORBIDDEN_CONFIG_KEYS = frozenset({
    "password", "otp", "totp", "api_token", "token_secret", "secret", "ticket",
})


class ConfigError(Exception):
    """Raised when PROX VOX operator configuration is missing or unsafe."""


def default_config_path() -> str:
    shared = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "_shared"))
    if os.path.isdir(shared) and shared not in sys.path:
        sys.path.insert(0, shared)
    import snap_home
    return os.path.join(snap_home.home(), "proxvox", "config.json")


def default_audit_path() -> str:
    shared = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", "_shared"))
    if os.path.isdir(shared) and shared not in sys.path:
        sys.path.insert(0, shared)
    import snap_home
    return os.path.join(snap_home.home(), "logs", "proxvox-audit.jsonl")


def load_config(path: str = None) -> dict:
    cfg_path = path or default_config_path()
    if not os.path.exists(cfg_path):
        raise ConfigError(
            "PROX VOX config not found at %s. Create it with base_url, token_id, "
            "and pinned_guid (never secrets)." % cfg_path
        )
    with open(cfg_path, "r", encoding="utf-8") as fh:
        data = json.load(fh)
    if not isinstance(data, dict):
        raise ConfigError("PROX VOX config must be a JSON object.")
    bad = sorted(_FORBIDDEN_CONFIG_KEYS.intersection(data.keys()))
    if bad:
        raise ConfigError(
            "PROX VOX config may not contain secret keys (%s). Secrets belong in "
            "Windows Credential Manager (P1) or live prompt (P3)." % ", ".join(bad)
        )
    return data


def _parse_kv_pairs(items: list) -> dict | None:
    if not items:
        return None
    out = {}
    for raw in items:
        if "=" not in raw:
            raise ConfigError("Expected key=value for --data item, got %r." % raw)
        k, v = raw.split("=", 1)
        out[k] = v
    return out


def _prompt_credentials(username: str = "", prompt_fn=None) -> tuple[str, str, str]:
    if prompt_fn is not None:
        return prompt_fn(username)
    user = (username or "").strip() or input("Proxmox username (e.g. proxvox@pve): ").strip()
    pw = getpass.getpass("Proxmox password: ")
    otp = getpass.getpass("TOTP code: ").strip()
    return user, pw, otp


def _make_audit(cfg: dict) -> AuditBuffer:
    audit_path = str(cfg.get("audit_path") or "").strip() or default_audit_path()
    return AuditBuffer(audit_path)


def _run_read(args, cfg: dict, audit: AuditBuffer, *, token_reader=None, actual_guid=None) -> int:
    secret = load_token(reader=token_reader)
    broker = ReadOnlyBroker(
        base_url=cfg["base_url"],
        token_id=cfg["token_id"],
        token_secret=secret,
        audit=audit,
        pinned_guid=cfg.get("pinned_guid", ""),
        actual_guid=actual_guid,
    )
    res = broker.get(args.path, timeout=args.timeout)
    if cfg.get("offhost_sink_enabled"):
        flush_audit(audit, cfg)
    print(json.dumps(res, indent=2, sort_keys=True))
    return 0


def _run_write(args, cfg: dict, audit: AuditBuffer, *, prompt_fn=None, actual_guid=None) -> int:
    wc = WriteClient(
        base_url=cfg["base_url"],
        audit=audit,
        pinned_guid=cfg.get("pinned_guid", ""),
        actual_guid=actual_guid,
    )
    user, pw, otp = _prompt_credentials(args.username or cfg.get("write_username", ""), prompt_fn=prompt_fn)
    wc.authorize(user, pw, otp, timeout=args.timeout)
    try:
        payload = _parse_kv_pairs(args.data)
        res = wc.call(args.method, args.path, data=payload, timeout=args.timeout)
        print(json.dumps(res, indent=2, sort_keys=True))
        return 0
    finally:
        wc.revoke(reason="cli-write-complete")
        if cfg.get("offhost_sink_enabled"):
            flush_audit(audit, cfg)


def _run_session(args, cfg: dict, audit: AuditBuffer, *, prompt_fn=None,
                 input_fn=None, actual_guid=None) -> int:
    wc = WriteClient(
        base_url=cfg["base_url"],
        audit=audit,
        pinned_guid=cfg.get("pinned_guid", ""),
        actual_guid=actual_guid,
    )
    user, pw, otp = _prompt_credentials(args.username or cfg.get("write_username", ""), prompt_fn=prompt_fn)
    wc.authorize(user, pw, otp, timeout=args.timeout)
    reader = input_fn or (lambda p: input(p))
    try:
        while True:
            try:
                line = reader("proxvox> ")
            except EOFError:
                break
            line = (line or "").strip()
            if not line:
                continue
            parts = shlex.split(line)
            cmd = parts[0].lower()
            if cmd in ("exit", "quit"):
                break
            if cmd == "revoke":
                wc.revoke(reason="operator-revoke")
                print(json.dumps({"revoked": True, "authorized": wc.is_authorized()}))
                continue
            if cmd == "status":
                print(json.dumps({"authorized": wc.is_authorized(), "unflushed": audit.unflushed()}))
                continue
            if cmd in ("get", "post", "put", "delete"):
                if len(parts) < 2:
                    print(json.dumps({"error": "usage: <METHOD> <path> [key=value ...]"}))
                    continue
                payload = _parse_kv_pairs(parts[2:]) if len(parts) > 2 else None
                res = wc.call(cmd.upper(), parts[1], data=payload, timeout=args.timeout)
                print(json.dumps(res, sort_keys=True))
                continue
            print(json.dumps({"error": f"unknown command {cmd!r}"}))
        return 0
    finally:
        if wc.is_authorized():
            wc.revoke(reason="session-exit")
        if cfg.get("offhost_sink_enabled"):
            flush_audit(audit, cfg)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="proxvox", description="PROX VOX operator CLI")
    parser.add_argument("--config", help="Path to non-secret config.json")
    sub = parser.add_subparsers(dest="command", required=True)

    p_read = sub.add_parser("read", help="P1 GET-only read via ReadOnlyBroker")
    p_read.add_argument("path")
    p_read.add_argument("--timeout", type=int, default=15)

    p_write = sub.add_parser("write", help="P3 live-authorised write (prompts for password + OTP)")
    p_write.add_argument("method", choices=["POST", "PUT", "DELETE", "GET"])
    p_write.add_argument("path")
    p_write.add_argument("--data", action="append", default=[], help="Form key=value pair")
    p_write.add_argument("--username", default="")
    p_write.add_argument("--timeout", type=int, default=15)

    p_sess = sub.add_parser("session", help="Interactive P3 session (one auth until expiry or revoke)")
    p_sess.add_argument("--username", default="")
    p_sess.add_argument("--timeout", type=int, default=15)

    sub.add_parser("audit-status", help="Show local AuditBuffer unflushed count and P2 sink state")
    sub.add_parser("flush", help="Flush local AuditBuffer to configured P2 off-host sink")
    return parser


def main(argv=None, *, token_reader=None, prompt_fn=None,
         input_fn=None, actual_guid=None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    cfg = load_config(args.config)
    audit = _make_audit(cfg)

    if args.command == "read":
        return _run_read(args, cfg, audit, token_reader=token_reader, actual_guid=actual_guid)
    if args.command == "write":
        return _run_write(args, cfg, audit, prompt_fn=prompt_fn, actual_guid=actual_guid)
    if args.command == "session":
        return _run_session(args, cfg, audit, prompt_fn=prompt_fn,
                            input_fn=input_fn, actual_guid=actual_guid)
    if args.command == "audit-status":
        print(json.dumps({
            "audit_path": audit.path,
            "unflushed": audit.unflushed(),
            "offhost_sink_enabled": bool(cfg.get("offhost_sink_enabled", False)),
            "offhost_sink_dir": str(cfg.get("offhost_sink_dir") or DEFAULT_OFFHOST_SINK_DIR),
        }, sort_keys=True))
        return 0
    if args.command == "flush":
        print(json.dumps(flush_audit(audit, cfg), sort_keys=True))
        return 0
    return 1


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
