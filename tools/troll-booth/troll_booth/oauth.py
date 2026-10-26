from __future__ import annotations

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

    Registered clients and issued tokens persist in the broker database so a
    routine service restart does not disconnect every paired agent. Pairing
    codes remain hashed, short-lived and single-use.
    """

    def __init__(self, store: BoothStore, public_url: str):
        self.store = store
        self.public_url = public_url.rstrip("/")
        self.clients: dict[str, OAuthClientInformationFull] = {}
        self.pending: dict[str, PendingApproval] = {}
        self.codes: dict[str, AuthorizationCode] = {}
        self.tokens: dict[str, AccessToken] = {}
        self.refresh_tokens: dict[str, RefreshToken] = {}
        self.code_actors: dict[str, str] = {}

    @staticmethod
    def _token_payload(token: AccessToken | RefreshToken) -> dict:
        payload = token.model_dump(mode="json")
        payload.pop("token", None)
        return payload

    async def get_client(self, client_id: str) -> OAuthClientInformationFull | None:
        cached = self.clients.get(client_id)
        if cached:
            return cached
        payload = self.store.oauth_client(client_id)
        if not payload:
            return None
        client = OAuthClientInformationFull.model_validate(payload)
        self.clients[client_id] = client
        return client

    async def register_client(self, client_info: OAuthClientInformationFull) -> None:
        if not client_info.client_id:
            raise ValueError("client_id is required")
        self.clients[client_info.client_id] = client_info
        self.store.save_oauth_client(client_info.client_id, client_info.model_dump(mode="json"))

    async def authorize(self, client: OAuthClientInformationFull, params: AuthorizationParams) -> str:
        request_id = secrets.token_urlsafe(24)
        self.pending[request_id] = PendingApproval(client=client, params=params, expires_at=time.time() + 300)
        return f"{self.public_url}/approve?request={request_id}"

    def approve(self, request_id: str, pair_code: str) -> str | None:
        pending = self.pending.pop(request_id, None)
        if not pending or pending.expires_at < time.time():
            return None
        identity = self.store.consume_pair_code(pair_code)
        if not identity:
            self.pending[request_id] = pending
            return None
        client_id = pending.client.client_id
        if not client_id:
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
        if "booth:read" not in allowed:
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
        self.code_actors[code_value] = identity["actor"]
        self.store.audit(identity["actor"], "oauth.approved", {"client_id": client_id, "scopes": allowed})
        return construct_redirect_uri(str(params.redirect_uri), code=code_value, state=params.state)

    async def load_authorization_code(self, client: OAuthClientInformationFull, authorization_code: str) -> AuthorizationCode | None:
        code = self.codes.get(authorization_code)
        return code if code and code.client_id == client.client_id and code.expires_at >= time.time() else None

    async def exchange_authorization_code(self, client: OAuthClientInformationFull, authorization_code: AuthorizationCode) -> OAuthToken:
        self.codes.pop(authorization_code.code, None)
        actor = self.code_actors.pop(authorization_code.code, "authenticated-agent")
        access_value = f"access_{secrets.token_urlsafe(32)}"
        refresh_value = f"refresh_{secrets.token_urlsafe(32)}"
        access = AccessToken(
            token=access_value,
            client_id=authorization_code.client_id,
            scopes=authorization_code.scopes,
            expires_at=int(time.time()) + 3600,
            resource=authorization_code.resource,
            subject=actor,
        )
        refresh = RefreshToken(
            token=refresh_value,
            client_id=authorization_code.client_id,
            scopes=authorization_code.scopes,
            expires_at=int(time.time()) + 30 * 86400,
            resource=authorization_code.resource,
            subject=actor,
        )
        self.tokens[access_value] = access
        self.refresh_tokens[refresh_value] = refresh
        self.store.save_oauth_token(access_value, "access", self._token_payload(access), access.expires_at)
        self.store.save_oauth_token(refresh_value, "refresh", self._token_payload(refresh), refresh.expires_at)
        return OAuthToken(
            access_token=access_value,
            token_type="Bearer",
            expires_in=3600,
            refresh_token=refresh_value,
            scope=" ".join(authorization_code.scopes),
        )

    async def load_access_token(self, token: str) -> AccessToken | None:
        value = self.tokens.get(token)
        if not value:
            payload = self.store.oauth_token(token, "access")
            value = AccessToken.model_validate({**payload, "token": token}) if payload else None
            if value:
                self.tokens[token] = value
        if value and value.expires_at and value.expires_at < time.time():
            self.tokens.pop(token, None)
            self.store.delete_oauth_token(token)
            return None
        return value

    async def load_refresh_token(self, client: OAuthClientInformationFull, refresh_token: str) -> RefreshToken | None:
        value = self.refresh_tokens.get(refresh_token)
        if not value:
            payload = self.store.oauth_token(refresh_token, "refresh")
            value = RefreshToken.model_validate({**payload, "token": refresh_token}) if payload else None
            if value:
                self.refresh_tokens[refresh_token] = value
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
            subject=refresh_token.subject or "authenticated-agent",
        )
        self.tokens[access_value] = access
        self.store.save_oauth_token(access_value, "access", self._token_payload(access), access.expires_at)
        return OAuthToken(access_token=access_value, token_type="Bearer", expires_in=3600, scope=" ".join(granted))

    async def revoke_token(self, token: AccessToken | RefreshToken) -> None:
        self.tokens.pop(token.token, None)
        self.refresh_tokens.pop(token.token, None)
        self.store.delete_oauth_token(token.token)

