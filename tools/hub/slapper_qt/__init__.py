"""SNAP SLAPPER — PySide6 (Qt) editor shell.

Phase 1 of the Qt rebuild. This package replaces ONLY the user-interface shell.
All image work still runs through the existing, tested ``editor_engine`` — Qt
never touches image math; it drives ``EditorDocument`` and displays the PIL
image that ``render()`` returns.

The Tk application (``editor_ui.py``) is left completely intact and runnable so
nothing is lost while the Qt shell is built out phase by phase.
"""

BUILD_VERSION = "0.7.22"

__all__ = ["theme", "engine_bridge", "widgets", "editor_window", "app",
           "BUILD_VERSION"]

# ===== SNAPSMACK EOF =====
