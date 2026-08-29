"""Tiny async runner so network calls never freeze the window.

``run_async`` runs a plain function on a background thread from the global
QThreadPool and delivers the result (or the error text) back on the GUI thread
via queued signals — so callbacks can safely touch widgets.
"""

from PySide6.QtCore import QObject, QRunnable, QThreadPool, Signal, Slot

# Strong references to in-flight jobs. Without this the QRunnable (and its
# _Signals emitter) can be garbage-collected the instant run() returns — before
# the queued result signal is delivered to the GUI thread — so the callback
# silently never fires. That is the classic "the call ran but nothing updated"
# PySide6 bug. Keep each job alive until its result has actually been delivered.
_INFLIGHT = set()


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
    the GUI thread. The job is retained until its result is delivered."""
    job = _Job(fn)
    _INFLIGHT.add(job)

    def _release(*_):
        _INFLIGHT.discard(job)

    # _release runs first so the job is dropped once its result is delivered.
    job.signals.ok.connect(_release)
    job.signals.err.connect(_release)
    if on_ok is not None:
        job.signals.ok.connect(on_ok)
    if on_err is not None:
        job.signals.err.connect(on_err)
    QThreadPool.globalInstance().start(job)
    return job

# ===== SNAPSMACK EOF =====
