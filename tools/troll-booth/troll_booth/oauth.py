from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.

import secrets
import time
from dataclasses import dataclass

from mcp.server.auth.provider import (
    AccessToken,
    AuthorizationCode,
    AuthorizationParams,
    OAuthAuthorizationServerProvider,
    RefreshToken,
    construct_redirect_uri,
)
from mcp.shared.auth import OAuthClientInformationFull, OAuthToken

from .store import BoothStore


@dataclass
class PendingApproval:
    client: OAuthClientInformationFull
    params: AuthorizationParams
    expires_at: float


class BoothOAuthProvider(OAuthAuthorizationServerProvider[AuthorizationCode, RefreshToken, AccessToken]):
    """Small owner-approved OAuth provider for the first single-owner release.

    Clients, codes and tokens live in memory so a restart revokes every Claude
    session. Pairing codes are hashed, single-use and stored in SQLite.
    """

    def __init__(self, store: BoothStore, public_url: str):
        self.store = store
        self.public_url = public_url.rstrip("/")
        self.clients: dict[str, OAuthClientInformationFull] = {}
        self.pending: dict[str, PendingApproval] = {}
        self.codes: dict[str, AuthorizationCode] = {}
        self.code_subjects: dict[str, str] = {}
        self.tokens: dict[str, AccessToken] = {}
        self.refresh_tokens: dict[str, RefreshToken] = {}
        self.refresh_subjects: dict[str, str] = {}

    async def get_client(self, client_id: str) -> OAuthClientInformationFull | None:
        return self.clients.get(client_id)

    async def register_client(self, client_info: OAuthClientInformationFull) -> None:
        if not client_info.client_id:
            raise ValueError("client_id is required")
        self.clients[client_info.client_id] = client_info

    async def authorize(self, client: OAuthClientInformationFull, params: AuthorizationParams) -> str:
        request_id = secrets.token_urlsafe(24)
        self.pending[request_id] = PendingApproval(client=client, params=params, expires_at=time.time() + 300)
        return f"{self.public_url}/approve?request={request_id}"

    def approve(self, request_id: str, pair_code: str) -> str | None:
        # EVERY REFUSAL BELOW LOOKS IDENTICAL FROM OUTSIDE, and must: the
        # approve route answers one deliberately vague sentence with a 403 so
        # a stranger learns nothing about which part they got wrong. That is
        # correct, and it left the OWNER equally blind. A real pairing failed
        # on 29 October with a 403 three minutes after the code was issued,
        # and nobody could say which of the five refusals fired, because the
        # audit log only recorded success.
        #
        # So each one now says why, to the audit log only. No pair code, no
        # token, no client secret and no request id is ever written: the
        # reason is a fixed string this file chooses, and the only variable
        # detail is scope NAMES, which the success record already carries.
        pending = self.pending.pop(request_id, None)
        if not pending:
            # Unknown or already-consumed request. self.pending is in memory,
            # so a restart loses every approval in flight and lands here.
            self.store.audit("unknown", "oauth.approve_denied", {"reason": "pending_unknown"})
            return None
        if pending.expires_at < time.time():
            self.store.audit("unknown", "oauth.approve_denied", {"reason": "pending_expired"})
            return None
        identity = self.store.consume_pair_code(pair_code)
        if not identity:
            # Wrong, already used, or already expired. A code that has been
            # used is also swept away the next time one is issued. Issuing a
            # second code does NOT affect a live unused first one -- an
            # earlier note here said it did, and a test disproved it.
            self.pending[request_id] = pending
            self.store.audit("unknown", "oauth.approve_denied", {"reason": "pair_code_rejected"})
            return None
        client_id = pending.client.client_id
        if not client_id:
            self.store.audit(identity["actor"], "oauth.approve_denied", {"reason": "client_id_missing"})
            return None
        params = pending.params
        requested = params.scopes or ["booth:read"]
        # The short-lived owner code is the step-up decision. Some browser MCP
        # clients request only their original read scope even after the server
        # advertises an optional write scope, so an explicitly write-enabled
        # pairing code may add booth:write to that one session. A normal code
        # can never gain it.
        allowed = [scope for scope in requested if scope in identity["scopes"]]
        if "booth:write" in identity["scopes"] and "booth:write" not in allowed:
            allowed.append("booth:write")
        for scope in ("conversation:read", "conversation:post"):
            if scope in identity["scopes"] and scope not in allowed:
                allowed.append(scope)
        if not {"booth:read", "conversation:read"}.intersection(allowed):
            # Scope names only. Both lists are already in the success record.
            self.store.audit(identity["actor"], "oauth.approve_denied", {
                "reason": "no_usable_scope",
                "client_id": client_id,
                "requested": list(requested),
                "granted": list(identity["scopes"]),
            })
            return None
        code_value = f"code_{secrets.token_urlsafe(24)}"
        code = AuthorizationCode(
            code=code_value,
            client_id=client_id,
            scopes=allowed,
            expires_at=time.time() + 300,
            code_challenge=params.code_challenge,
            redirect_uri=params.redirect_uri,
            redirect_uri_provided_explicitly=params.redirect_uri_provided_explicitly,
            resource=params.resource,
        )
        self.codes[code_value] = code
        self.code_subjects[code_value] = identity["actor"]
        self.store.audit(identity["actor"], "oauth.approved", {"client_id": client_id, "scopes": allowed})
        return construct_redirect_uri(str(params.redirect_uri), code=code_value, state=params.state)

    async def load_authorization_code(self, client: OAuthClientInformationFull, authorization_code: str) -> AuthorizationCode | None:
        code = self.codes.get(authorization_code)
        return code if code and code.client_id == client.client_id and code.expires_at >= time.time() else None

    async def exchange_authorization_code(self, client: OAuthClientInformationFull, authorization_code: AuthorizationCode) -> OAuthToken:
        self.codes.pop(authorization_code.code, None)
        subject = self.code_subjects.pop(authorization_code.code, "authenticated-agent")
        access_value = f"access_{secrets.token_urlsafe(32)}"
        refresh_value = f"refresh_{secrets.token_urlsafe(32)}"
        access = AccessToken(
            token=access_value,
            client_id=authorization_code.client_id,
            scopes=authorization_code.scopes,
            expires_at=int(time.time()) + 3600,
            resource=authorization_code.resource,
            subject=subject,
        )
        refresh = RefreshToken(
            token=refresh_value,
            client_id=authorization_code.client_id,
            scopes=authorization_code.scopes,
            expires_at=int(time.time()) + 86400,
            resource=authorization_code.resource,
        )
        self.tokens[access_value] = access
        self.refresh_tokens[refresh_value] = refresh
        self.refresh_subjects[refresh_value] = subject
        return OAuthToken(
            access_token=access_value,
            token_type="Bearer",
            expires_in=3600,
            refresh_token=refresh_value,
            scope=" ".join(authorization_code.scopes),
        )

    async def load_access_token(self, token: str) -> AccessToken | None:
        value = self.tokens.get(token)
        if value and value.expires_at and value.expires_at < time.time():
            self.tokens.pop(token, None)
            return None
        return value

    async def load_refresh_token(self, client: OAuthClientInformationFull, refresh_token: str) -> RefreshToken | None:
        value = self.refresh_tokens.get(refresh_token)
        if value and value.client_id == client.client_id and (not value.expires_at or value.expires_at >= time.time()):
            return value
        return None

    async def exchange_refresh_token(self, client: OAuthClientInformationFull, refresh_token: RefreshToken, scopes: list[str]) -> OAuthToken:
        granted = [scope for scope in scopes if scope in refresh_token.scopes] if scopes else refresh_token.scopes
        access_value = f"access_{secrets.token_urlsafe(32)}"
        access = AccessToken(
            token=access_value,
            client_id=refresh_token.client_id,
            scopes=granted,
            expires_at=int(time.time()) + 3600,
            resource=refresh_token.resource,
            subject=self.refresh_subjects.get(refresh_token.token, "authenticated-agent"),
        )
        self.tokens[access_value] = access
        return OAuthToken(access_token=access_value, token_type="Bearer", expires_in=3600, scope=" ".join(granted))

    async def revoke_token(self, token: AccessToken | RefreshToken) -> None:
        self.tokens.pop(token.token, None)
        self.refresh_tokens.pop(token.token, None)
        self.refresh_subjects.pop(token.token, None)

# ===== SNAPSMACK EOF =====
