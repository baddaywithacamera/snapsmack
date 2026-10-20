from __future__ import annotations

import asyncio
import html
import json
import os
from pathlib import Path
from urllib.parse import parse_qs
from urllib.parse import urlparse

import uvicorn
from mcp.server.auth.middleware.auth_context import get_access_token
from mcp.server.auth.settings import AuthSettings, ClientRegistrationOptions
from mcp.server.mcpserver import MCPServer
from mcp.server.transport_security import TransportSecuritySettings
from pydantic import AnyHttpUrl
from starlette.requests import Request
from starlette.responses import HTMLResponse, JSONResponse, RedirectResponse

from . import __version__
from .oauth import BoothOAuthProvider
from .store import BoothStore


PUBLIC_URL = os.environ.get("TROLL_BOOTH_PUBLIC_URL", "http://127.0.0.1:8765").rstrip("/")
DATABASE = os.environ.get("TROLL_BOOTH_DATABASE", str(Path.home() / ".troll-booth" / "remote.sqlite3"))
WORKER_TOKEN = os.environ.get("TROLL_BOOTH_WORKER_TOKEN", "")
store = BoothStore(DATABASE)
provider = BoothOAuthProvider(store, PUBLIC_URL)

auth = AuthSettings(
    issuer_url=AnyHttpUrl(PUBLIC_URL),
    resource_server_url=AnyHttpUrl(f"{PUBLIC_URL}/mcp"),
    required_scopes=["booth:read"],
    client_registration_options=ClientRegistrationOptions(
        enabled=True,
        valid_scopes=["booth:read", "booth:write"],
        default_scopes=["booth:read", "booth:write"],
    ),
    validate_token_resource=True,
)
mcp = MCPServer("TROLL BOOTH", version=__version__, auth=auth, auth_server_provider=provider)


def _actor() -> str:
    token = get_access_token()
    return token.subject if token and token.subject else "authenticated-agent"


def _require_scope(scope: str) -> None:
    token = get_access_token()
    scopes = set(token.scopes if token else [])
    if scope not in scopes:
        raise PermissionError(f"This paired session requires the {scope} permission.")


async def _queued(capability: str, arguments: dict, reason: str, ticket_id: str | None = None) -> dict:
    job_id = store.enqueue(_actor(), capability, arguments, reason, ticket_id)
    for _ in range(150):
        await asyncio.sleep(0.2)
        job = store.job(job_id)
        if job and job["status"] in {"complete", "failed"}:
            return store.take_job(job_id) or {"id": job_id, "status": "consumed"}
    return {"id": job_id, "status": "queued", "message": "Local worker has not returned yet."}


@mcp.tool(description="Report TROLL BOOTH health, connected local workers, and queued read-only work.")
def booth_status() -> dict:
    return store.status()


@mcp.tool(description="List locally installed SnapSmack applications exposed by the paired read-only worker.")
async def list_apps(reason: str = "Inspect available SnapSmack tools") -> dict:
    return await _queued("apps.list", {}, reason)


@mcp.tool(description="Read the declared capability sheet for one installed SnapSmack application.")
async def app_capabilities(app: str, reason: str = "Inspect app capabilities") -> dict:
    return await _queued("apps.capabilities", {"app": app}, reason)


@mcp.tool(description="List locally stored collaboration rooms without exposing the history database publicly.")
async def list_conversations(limit: int = 100, reason: str = "List collaboration rooms") -> dict:
    return await _queued("conversation.rooms", {"limit": limit}, reason)


@mcp.tool(description="Read messages from one locally stored collaboration room in chronological order.")
async def conversation_messages(
    room_id: str, limit: int = 200, before: int | None = None, reason: str = "Read collaboration history"
) -> dict:
    return await _queued(
        "conversation.messages", {"room_id": room_id, "limit": limit, "before": before}, reason
    )


@mcp.tool(description="Full-text search locally stored collaboration history, optionally within one room.")
async def search_conversation(
    query: str, room_id: str | None = None, limit: int = 50, reason: str = "Search collaboration history"
) -> dict:
    return await _queued(
        "conversation.search", {"query": query, "room_id": room_id, "limit": limit}, reason
    )


@mcp.tool(description="Post an authenticated message to a collaboration room. The sender is taken from the paired identity.")
async def post_message(
    room_id: str,
    body: str,
    title: str | None = None,
    ticket_id: str | None = None,
    task_id: str | None = None,
    reason: str = "Collaborate in TROLL BOOTH",
) -> dict:
    _require_scope("booth:write")
    return await _queued(
        "conversation.post",
        {"room_id": room_id, "body": body, "title": title, "ticket_id": ticket_id, "task_id": task_id},
        reason,
        ticket_id,
    )


@mcp.tool(description="List active file or subsystem claims so agents avoid editing the same scope.")
async def list_work_scopes(reason: str = "Check active work scopes") -> dict:
    return await _queued("scope.list", {}, reason)


@mcp.tool(description="Claim a file, directory, or subsystem before editing it. Conflicting claims are rejected.")
async def claim_work_scope(
    scope: str,
    task_id: str | None = None,
    note: str | None = None,
    ttl: int = 3600,
    reason: str = "Prevent overlapping edits",
) -> dict:
    _require_scope("booth:write")
    return await _queued(
        "scope.claim", {"scope": scope, "task_id": task_id, "note": note, "ttl": ttl}, reason, task_id
    )


@mcp.tool(description="Release a work scope previously claimed by this paired identity.")
async def release_work_scope(scope: str, reason: str = "Work scope completed") -> dict:
    _require_scope("booth:write")
    return await _queued("scope.release", {"scope": scope}, reason)


def _worker_authorized(request: Request) -> bool:
    if not WORKER_TOKEN:
        return False
    return request.headers.get("authorization", "") == f"Bearer {WORKER_TOKEN}"


@mcp.custom_route("/health", methods=["GET"])
async def health(_: Request) -> JSONResponse:
    return JSONResponse({"status": "ok", "service": "troll-booth", "version": __version__})


@mcp.custom_route("/approve", methods=["GET", "POST"])
async def approve(request: Request):
    request_id = request.query_params.get("request", "")
    if request.method == "GET":
        safe_id = html.escape(request_id, quote=True)
        return HTMLResponse(
            "<!doctype html><meta name=viewport content='width=device-width'>"
            "<title>Pair Claude with TROLL BOOTH</title>"
            "<style>body{font:18px system-ui;max-width:42rem;margin:8vh auto;padding:2rem;background:#111;color:#eee}"
            "input,button{font:inherit;padding:.7rem;margin:.4rem 0;width:100%;box-sizing:border-box}button{background:#75ff3b}</style>"
            "<h1>TROLL BOOTH</h1><p>Nobody crosses without papers.</p>"
            "<p>Enter the short-lived code shown by the local TROLL BOOTH application. Never enter your password or TOTP here.</p>"
            f"<form method=post action='/approve?request={safe_id}'><input name=code autocomplete=one-time-code required>"
            "<button>Pair this agent session</button></form>"
        )
    body = parse_qs((await request.body()).decode("utf-8", "replace"))
    code = body.get("code", [""])[0]
    redirect = provider.approve(request_id, code)
    if not redirect:
        return HTMLResponse("Pairing request or code is invalid or expired.", status_code=403)
    return RedirectResponse(redirect, status_code=302)


@mcp.custom_route("/worker/heartbeat", methods=["POST"])
async def worker_heartbeat(request: Request) -> JSONResponse:
    if not _worker_authorized(request):
        return JSONResponse({"error": "unauthorized"}, status_code=401)
    data = await request.json()
    store.heartbeat(str(data["worker_id"]), str(data.get("version", "unknown")), list(data.get("apps", [])))
    return JSONResponse({"ok": True})


@mcp.custom_route("/worker/claim", methods=["POST"])
async def worker_claim(request: Request) -> JSONResponse:
    if not _worker_authorized(request):
        return JSONResponse({"error": "unauthorized"}, status_code=401)
    return JSONResponse({"job": store.claim()})


@mcp.custom_route("/worker/result", methods=["POST"])
async def worker_result(request: Request) -> JSONResponse:
    if not _worker_authorized(request):
        return JSONResponse({"error": "unauthorized"}, status_code=401)
    data = await request.json()
    ok = store.finish(str(data["job_id"]), data.get("result"), data.get("error"))
    return JSONResponse({"ok": ok}, status_code=200 if ok else 409)


@mcp.custom_route("/owner/pair-code", methods=["POST"])
async def owner_pair_code(request: Request) -> JSONResponse:
    """Issue a code only to the authenticated local worker/owner application."""
    if not _worker_authorized(request):
        return JSONResponse({"error": "unauthorized"}, status_code=401)
    data = await request.json()
    actor = str(data.get("actor", "")).strip().lower()
    if actor not in {"claude", "codex"}:
        return JSONResponse({"error": "actor must be claude or codex"}, status_code=400)
    scopes = ["booth:read", "booth:write"] if bool(data.get("write", True)) else ["booth:read"]
    code = store.issue_pair_code(actor, scopes, ttl=300)
    return JSONResponse({"actor": actor, "code": code, "expires_in": 300, "scopes": scopes})


public_host = urlparse(PUBLIC_URL).netloc
app = mcp.streamable_http_app(
    transport_security=TransportSecuritySettings(
        enable_dns_rebinding_protection=True,
        allowed_hosts=[public_host, "127.0.0.1:*", "localhost:*"],
        allowed_origins=["https://claude.ai", "https://www.claude.ai", PUBLIC_URL],
    )
)


def main() -> None:
    uvicorn.run(app, host=os.environ.get("TROLL_BOOTH_BIND", "127.0.0.1"), port=int(os.environ.get("TROLL_BOOTH_PORT", "8765")))


if __name__ == "__main__":
    main()

