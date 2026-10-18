"""Typed, read-only capability policy for the first TROLL BOOTH build."""

from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
import re
from typing import Any


class PolicyError(ValueError):
    """A requested app, capability, or argument is outside policy."""


@dataclass(frozen=True)
class Argument:
    flag: str = ""
    kind: str = "string"
    positional: bool = False
    required: bool = False
    minimum: int | None = None
    maximum: int | None = None
    choices: tuple[str, ...] = ()
    pattern: str = ""

    def render(self, name: str, value: Any) -> list[str]:
        if self.kind == "bool":
            if not isinstance(value, bool):
                raise PolicyError(f"{name} must be true or false")
            return ([self.flag] if value else [])
        if self.kind == "int":
            if isinstance(value, bool) or not isinstance(value, int):
                raise PolicyError(f"{name} must be an integer")
            if self.minimum is not None and value < self.minimum:
                raise PolicyError(f"{name} must be at least {self.minimum}")
            if self.maximum is not None and value > self.maximum:
                raise PolicyError(f"{name} must be at most {self.maximum}")
            rendered = str(value)
        elif self.kind == "csv":
            if not isinstance(value, list) or not value or not all(
                    isinstance(item, str) and item for item in value):
                raise PolicyError(f"{name} must be a non-empty list of strings")
            rendered = ",".join(value)
        else:
            if not isinstance(value, str) or (self.required and not value):
                raise PolicyError(f"{name} must be a string")
            if "\x00" in value or "\r" in value or "\n" in value:
                raise PolicyError(f"{name} contains a forbidden control character")
            if self.choices and value not in self.choices:
                raise PolicyError(f"{name} must be one of: {', '.join(self.choices)}")
            if self.pattern and re.fullmatch(self.pattern, value) is None:
                raise PolicyError(f"{name} has an invalid format")
            rendered = value
        if self.positional:
            return [rendered]
        if not self.flag:
            raise PolicyError(f"{name} has no command-line mapping")
        return [self.flag, rendered]


@dataclass(frozen=True)
class Capability:
    name: str
    mode: str = "read"
    arguments: dict[str, Argument] = field(default_factory=dict)

    def argv(self, values: dict[str, Any]) -> list[str]:
        if self.mode != "read":
            raise PolicyError("the first TROLL BOOTH build is read-only")
        unknown = sorted(set(values) - set(self.arguments))
        if unknown:
            raise PolicyError("unknown argument(s): " + ", ".join(unknown))
        result = [self.name]
        for name, argument in self.arguments.items():
            if name not in values:
                if argument.required:
                    raise PolicyError(f"missing required argument: {name}")
                continue
            result.extend(argument.render(name, values[name]))
        result.append("--json")
        return result


@dataclass(frozen=True)
class AppPolicy:
    app_id: str
    display_name: str
    directory: str
    entrypoint: str
    capabilities: dict[str, Capability]
    actor_flag: bool = False


def builtin_policies() -> dict[str, AppPolicy]:
    return {
        "log-flogger": AppPolicy(
            app_id="log-flogger",
            display_name="LOG FLOGGER",
            directory="../log-flogger",
            entrypoint="lf.py",
            capabilities={
                "gather": Capability("gather", arguments={
                    "apps": Argument(flag="--app", kind="csv"),
                    "days": Argument(flag="--days", kind="int", minimum=0, maximum=3660),
                }),
                "analyze": Capability("analyze", arguments={
                    "apps": Argument(flag="--app", kind="csv"),
                    "days": Argument(flag="--days", kind="int", minimum=0, maximum=3660),
                    "quiet_days": Argument(flag="--quiet-days", kind="int", minimum=1, maximum=3660),
                    "band": Argument(flag="--band", choices=(
                        "LOSING WORK", "DOOR OPEN", "STOPPED",
                        "WORKING ROUND IT", "WANTED")),
                    "limit": Argument(flag="--limit", kind="int", minimum=1, maximum=250),
                }),
            },
        ),
        "ticket-wicket": AppPolicy(
            app_id="ticket-wicket",
            display_name="TICKET WICKET",
            directory="../ticket-wicket",
            entrypoint="tw.py",
            actor_flag=True,
            capabilities={
                "queue": Capability("queue", arguments={
                    "all": Argument(flag="--all", kind="bool"),
                    "band": Argument(flag="--band", choices=(
                        "LOSING WORK", "DOOR OPEN", "STOPPED",
                        "WORKING ROUND IT", "WANTED")),
                }),
                "show": Capability("show", arguments={
                    "id": Argument(kind="string", positional=True, required=True,
                                   pattern=r"[A-Z]{2,8}-[0-9]{1,12}"),
                }),
                "stale": Capability("stale"),
            },
        ),
    }


def resolve_app_directory(tool_root: Path, policy: AppPolicy) -> Path:
    resolved = (tool_root / policy.directory).resolve()
    tools_root = tool_root.parent.resolve()
    if resolved.parent != tools_root:
        raise PolicyError(f"app directory escapes tools root: {policy.app_id}")
    return resolved

# ===== SNAPSMACK EOF =====
