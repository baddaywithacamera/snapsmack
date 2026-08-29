"""Tiny async runner so network calls never freeze the window.

``run_async`` runs a plain function on a background thread from the global
QThreadPool and delivers the result (or the error text) back on the GUI thread
via queued signals — so callbacks can safely touch widgets.
"""

from PySide6.QtCore import QObject, QRunnable, QThreadPool, Signal, Slot


class _Signals(QObject):
    ok = Signal(object)
    err = Signal(str)


class _Job(QRunnable):
    def __init__(self, fn):
        super().__init__()
        self._fn = fn
        self.signals = _Signals()

    @Slot()
    def run(self):
        try:
            result = self._fn()
        except Exception as exc:  # noqa: BLE001 — surfaced to the UI, never crashes the pool
            self.signals.err.emit(str(exc))
        else:
            self.signals.ok.emit(result)


def run_async(fn, on_ok=None, on_err=None):
    """Run ``fn()`` off-thread. ``on_ok(result)`` / ``on_err(message)`` fire on
    the GUI thread. Returns the job (kept alive by the pool until it finishes)."""
    job = _Job(fn)
    if on_ok is not None:
        job.signals.ok.connect(on_ok)
    if on_err is not None:
        job.signals.err.connect(on_err)
    QThreadPool.globalInstance().start(job)
    return job

# ===== SNAPSMACK EOF =====
