# SNAPSMACK_EOF_HEADER
"""MCP protocol adapter for approved TROLL BOOTH capabilities."""

from __future__ import annotations

import json
from typing import Any

from .broker import Broker
from .policy import Argument


SUPPORTED_PROTOCOLS = {"2025-11-25", "2026-07-28"}


def _argument_schema(argument: Argument) -> dict[str, Any]:
    if argument.kind == "bool":
        schema: dict[str, Any] = {"type": "boolean"}
    elif argument.kind == "int":
        schema = {"type": "integer"}
        if argument.minimum is not None:
            schema["minimum"] = argument.minimum
        if argument.maximum is not None:
            schema["maximum"] = argument.maximum
    elif argument.kind == "csv":
        schema = {"type": "array", "items": {"type": "string"}, "minItems": 1}
    else:
        schema = {"type": "string"}
        if argument.pattern:
            schema["pattern"] = argument.pattern
    if argument.choices:
        schema["enum"] = list(argument.choices)
    return schema


class McpAdapter:
    def __init__(self, broker: Broker):
        self.broker = broker

    def tools(self) -> list[dict[str, Any]]:
        discovered = {row["id"]: row for row in self.broker.list_apps()
                      if row.get("healthy") and row.get("trusted")}
        tools: list[dict[str, Any]] = []
        for app_id, policy in sorted(self.broker.policies.items()):
            if app_id not in discovered:
                continue
            for capability_name, capability in sorted(policy.capabilities.items()):
                properties = {name: _argument_schema(argument)
                              for name, argument in capability.arguments.items()}
                required = [name for name, argument in capability.arguments.items()
                            if argument.required]
                schema: dict[str, Any] = {
                    "type": "object", "properties": properties,
                    "additionalProperties": False,
                }
                if required:
                    schema["required"] = required
                tools.append({
                    "name": f"{app_id}__{capability_name}",
                    "title": f"{policy.display_name}: {capability_name}",
                    "description": (
                        f"Run the approved read-only {capability_name} capability "
                        f"in {policy.display_name}."),
                    "inputSchema": schema,
                    "annotations": {"readOnlyHint": True, "destructiveHint": False,
                                    "idempotentHint": True, "openWorldHint": False},
                })
        return tools

    def handle(self, request: dict[str, Any], actor: str) -> tuple[int, dict[str, Any] | None]:
        method = request.get("method")
        request_id = request.get("id")
        if method == "notifications/initialized" and request_id is None:
            return 202, None
        if request.get("jsonrpc") != "2.0" or request_id is None:
            return 400, self._error(request_id, -32600, "Invalid Request")
        if method == "initialize":
            requested = request.get("params", {}).get("protocolVersion", "2025-11-25")
            version = requested if requested in SUPPORTED_PROTOCOLS else "2025-11-25"
            return 200, self._result(request_id, {
                "protocolVersion": version,
                "capabilities": {"tools": {"listChanged": False}},
                "serverInfo": {"name": "TROLL BOOTH", "version": "0.3.0"},
                "instructions": "Read-only local SnapSmack operations approved by Sean.",
            })
        if method == "ping":
            return 200, self._result(request_id, {})
        if method == "tools/list":
            return 200, self._result(request_id, {"tools": self.tools()})
        if method == "tools/call":
            params = request.get("params", {})
            name = params.get("name") if isinstance(params, dict) else None
            arguments = params.get("arguments", {}) if isinstance(params, dict) else {}
            if not isinstance(name, str) or "__" not in name or not isinstance(arguments, dict):
                return 200, self._error(request_id, -32602, "Invalid tool arguments")
            app_id, capability = name.split("__", 1)
            operation = self.broker.invoke(app_id, capability, arguments, actor,
                                           "Claude Web MCP tool call")
            content = [{"type": "text", "text": json.dumps(operation, ensure_ascii=False)}]
            return 200, self._result(request_id, {
                "content": content, "structuredContent": operation,
                "isError": not operation.get("ok", False),
            })
        return 200, self._error(request_id, -32601, "Method not found")

    @staticmethod
    def _result(request_id: Any, result: dict[str, Any]) -> dict[str, Any]:
        return {"jsonrpc": "2.0", "id": request_id, "result": result}

    @staticmethod
    def _error(request_id: Any, code: int, message: str) -> dict[str, Any]:
        return {"jsonrpc": "2.0", "id": request_id,
                "error": {"code": code, "message": message}}

# ===== SNAPSMACK EOF =====
