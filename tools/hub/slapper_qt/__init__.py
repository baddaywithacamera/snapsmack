"""SNAP SLAPPER — PySide6 (Qt) editor shell.

Phase 1 of the Qt rebuild. This package replaces ONLY the user-interface shell.
All image work still runs through the existing, tested ``editor_engine`` — Qt
never touches image math; it drives ``EditorDocument`` and displays the PIL
image that ``render()`` returns.

The Tk application (``editor_ui.py``) is left completely intact and runnable so
nothing is lost while the Qt shell is built out phase by phase.
"""

BUILD_VERSION = "0.7.30"

# shiboken6 (PySide6's binding layer) injects a `Self` special form into the
# STDLIB typing module on Python 3.10, where none exists — and 3.10's own
# Union type-check rejects it, so any library whose annotations use Self via
# typing_extensions (psd_tools → PSD export) crashes with "Plain typing.Self
# is not valid as type argument" once Qt is loaded. Undo the injection here,
# at the package door, so everything imported after the Qt shell sees stock
# typing. 3.11+ has a real typing.Self — left untouched there.
import sys as _sys
import typing as _typing
import PySide6  # noqa: F401 — force the injection to happen NOW, so the
                # cleanup below covers every import order.
if _sys.version_info < (3, 11) and hasattr(_typing, "Self"):
    del _typing.Self
    _sys.modules.pop("typing_extensions", None)

__all__ = ["theme", "engine_bridge", "widgets", "editor_window", "app",
           "BUILD_VERSION"]

# ===== SNAPSMACK EOF =====
