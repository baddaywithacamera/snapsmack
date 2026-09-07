"""SNAP HQ keeps configuration out of the launcher dashboard.

SNAPSMACK_EOF_HEADER: last non-empty line must be # ===== SNAPSMACK EOF =====
"""

import ast
from pathlib import Path


SOURCE = Path(__file__).resolve().parents[1] / "main.py"


def _self_calls(function_name):
    tree = ast.parse(SOURCE.read_text(encoding="utf-8"))
    hub = next(
        node for node in tree.body
        if isinstance(node, ast.ClassDef) and node.name == "Hub"
    )
    method = next(
        node for node in hub.body
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        and node.name == function_name
    )
    return [
        node.func.attr
        for node in ast.walk(method)
        if isinstance(node, ast.Call)
        and isinstance(node.func, ast.Attribute)
        and isinstance(node.func.value, ast.Name)
        and node.func.value.id == "self"
    ]


def test_launcher_contains_only_launcher_surface():
    calls = _self_calls("__init__")
    assert "_build_launcher" in calls
    assert "_build_setup" not in calls
    assert "_build_profiles" not in calls
    assert "_build_prompts" not in calls
    assert "_load_creds" not in calls


def test_settings_owns_every_configuration_surface():
    calls = _self_calls("_open_settings")
    for required in (
        "_build_setup",
        "_build_profiles",
        "_build_prompts",
        "_load_creds",
        "_refresh_profiles",
        "_refresh_prompt_sites",
    ):
        assert required in calls

    assert "_build_launcher" not in calls


def test_repair_has_packaged_layout_proof():
    source = SOURCE.read_text(encoding="utf-8")
    assert 'BUILD_VERSION = "0.7.41"' in source
    assert 'SNAP_HQ_LAYOUT_QA_MARKER' in source
    assert 'Settings content leaked into the SNAP HQ launcher' in source
    assert 'SNAP HQ Settings is missing a configuration section' in source


def test_launcher_breakpoints_use_available_width():
    tree = ast.parse(SOURCE.read_text(encoding="utf-8"))
    helper = next(
        node for node in tree.body
        if isinstance(node, ast.FunctionDef) and node.name == "_launcher_column_count"
    )
    module = ast.Module(body=[helper], type_ignores=[])
    namespace = {}
    exec(compile(module, str(SOURCE), "exec"), namespace)
    columns = namespace["_launcher_column_count"]

    assert columns(599) == 1
    assert columns(600) == 2
    assert columns(899) == 2
    assert columns(900) == 3


def test_dashboard_scrollbar_is_content_driven():
    source = SOURCE.read_text(encoding="utf-8")
    assert "self._install_auto_scroll(\n            self._body_canvas" in source
    assert "body_scroll.pack(" not in source

# ===== SNAPSMACK EOF =====
