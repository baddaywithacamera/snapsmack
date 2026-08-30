"""Small shared file logger for standalone SnapSmack desktop tools."""

import logging
import os
import sys

import snap_home


_LOGGERS = {}


def setup(tool, logname="run"):
    """Create one UTF-8 per-run log under the shared SnapSmack log folder."""
    key = f"snapsmack.{tool}"
    if key in _LOGGERS:
        return _LOGGERS[key]
    logger = logging.getLogger(key)
    logger.setLevel(logging.INFO)
    logger.propagate = False
    path = snap_home.log_path(tool, logname)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    formatter = logging.Formatter(
        "%(asctime)s %(levelname)s %(name)s %(message)s")
    file_handler = logging.FileHandler(path, encoding="utf-8")
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)
    if getattr(sys, "stderr", None):
        stream = logging.StreamHandler(sys.stderr)
        stream.setFormatter(formatter)
        logger.addHandler(stream)
    logger.log_path = path
    _LOGGERS[key] = logger
    return logger


def get(tool):
    return _LOGGERS.get(f"snapsmack.{tool}") or setup(tool)


# ===== SNAPSMACK EOF =====
