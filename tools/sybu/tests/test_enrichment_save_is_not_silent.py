# -*- coding: utf-8 -*-
"""SS-1137: a paid enrichment that fails to save must not fail silently.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

HOW TO RUN IT. From tools/sybu, with that folder on the path:

    cd tools/sybu && PYTHONPATH=. python tests/test_enrichment_save_is_not_silent.py

Every suite in this folder imports its module by bare name, so `python
tests/<file>.py` puts tests/ on sys.path and NOT tools/sybu, and the import
fails. I reported one of these as a regression on that basis before checking.
It is the invocation, not the code.

WHAT THIS GUARDS. main.py's enrich callback used to do this, inline:

    except Exception as exc:
        print(f"[shared library] enrichment save failed: {exc}")

and then set the status to "enriched" regardless. Two faults in four lines.
print() reaches nobody in a built exe -- there is no console -- so a Gemini
result Sean had already paid for could fail to persist with no trace anywhere;
and the row still read as enriched, so the one item that needed his attention
looked exactly like the ones that worked.

The repair lives in library_bridge, not main.py, because MAIN.PY CANNOT BE
IMPORTED: a 60-second timed import produced nothing at all. Nothing defined in
that file can be reached by a test, so a fix left in there could only be
checked by breaking the library on a real machine and watching.
"""

import logging
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
SYBU = os.path.dirname(HERE)
SHARED = os.path.join(os.path.dirname(SYBU), '_shared')
for path in (SYBU, SHARED):
    if path not in sys.path:
        sys.path.insert(0, path)

import library_bridge                                                # noqa: E402

_fails = 0


def check(label, ok):
    global _fails
    print(('PASS ' if ok else 'FAIL ') + label)
    if not ok:
        _fails += 1


class _Recorder:
    """Stands in for record_enrichment. Raises when asked to."""

    def __init__(self, blow_up=None):
        self.calls = []
        self.blow_up = blow_up

    def __call__(self, site, image_path, entry, session=None):
        self.calls.append((site, image_path, session))
        if self.blow_up is not None:
            raise self.blow_up
        return {'ok': True}


class _Audit:
    def __init__(self):
        self.errors = []

    def error(self, fmt, *args):
        self.errors.append(fmt % args if args else fmt)


def main():
    entry = object()

    # ── the happy path is unchanged ─────────────────────────────────────────
    ok_rec, audit = _Recorder(), _Audit()
    saved, failure = library_bridge.save_enrichment_to_library(
        'https://example.ca', '/photos/DSC_1.jpg', entry,
        session='S', recorder=ok_rec, audit=audit)
    check('a successful save reports saved', saved is True)
    check('and has no failure message', failure == '')
    check('and logs no error', audit.errors == [])
    check('and passed the site, path and session through',
          ok_rec.calls == [('https://example.ca', '/photos/DSC_1.jpg', 'S')])

    # ── THE FAULT: a failed save must be LOUD ───────────────────────────────
    bad_rec, audit = _Recorder(blow_up=OSError('disk full')), _Audit()
    saved, failure = library_bridge.save_enrichment_to_library(
        'https://example.ca', '/photos/DSC_2.jpg', entry,
        session=None, recorder=bad_rec, audit=audit)
    check('a failed save reports NOT saved', saved is False)
    check('the caller is handed a message to show, not a print',
          isinstance(failure, str) and failure != '')
    check('the message names the photograph', 'DSC_2.jpg' in failure)
    check('it names the actual error, so he can act on it', 'disk full' in failure)
    check('it says plainly the result is NOT in the library',
          'NOT' in failure and 'library' in failure)
    check('it warns that the money will be spent again',
          'charged again' in failure)
    check('it says what to do about it', 'Re-enrich' in failure)

    # The log is a FILE in the real app. That is what survives a built exe with
    # no console, a closed window, and a crash.
    check('the failure reaches the audit log', len(audit.errors) == 1)
    check('and the log line carries the full path, not just the name',
          audit.errors and '/photos/DSC_2.jpg' in audit.errors[0])
    check('and the reason', audit.errors and 'disk full' in audit.errors[0])

    # ── IT MUST NEVER BECOME THE THING IT REPLACED ──────────────────────────
    # A broken audit logger must not swallow the return value: the message is
    # the owner-visible half and has to survive a logging failure.
    class _BrokenAudit:
        def error(self, *a, **k):
            raise RuntimeError('logger is broken')

    saved, failure = library_bridge.save_enrichment_to_library(
        'https://example.ca', '/photos/DSC_3.jpg', entry,
        recorder=_Recorder(blow_up=ValueError('nope')), audit=_BrokenAudit())
    check('a broken logger does not hide the failure from the caller',
          saved is False and 'DSC_3.jpg' in failure)

    # An empty path must still produce a usable sentence rather than crashing.
    saved, failure = library_bridge.save_enrichment_to_library(
        '', '', entry, recorder=_Recorder(blow_up=OSError('x')), audit=_Audit())
    check('an empty path still yields a message, not an exception',
          saved is False and 'photograph' in failure)

    # ── AND THE CALL SITE, WHICH NO TEST CAN REACH ──────────────────────────
    # main.py cannot be imported, so this is pinned at the source with the
    # reason stated rather than left unchecked. Two things matter: the print is
    # gone, and the status is no longer set to "enriched" unconditionally.
    tk = open(os.path.join(SYBU, 'main.py'), encoding='utf-8').read()
    check('main.py no longer prints the save failure',
          'enrichment save failed' not in tk)
    check('main.py calls the checked saver',
          'library_bridge.save_enrichment_to_library(' in tk)
    i = tk.index('library_bridge.save_enrichment_to_library(')
    window = tk[i:i + 900]
    check('and it branches on the result instead of always saying enriched',
          'if saved:' in window and 'else:' in window)
    check('the failure is shown in the error colour, not the success one',
          'FG_ERR' in window)
    # The default recorder must be the real one, or production saves nothing.
    check('the default recorder is record_enrichment',
          'recorder or record_enrichment' in
          open(os.path.join(SYBU, 'library_bridge.py'), encoding='utf-8').read())


if __name__ == '__main__':
    logging.disable(logging.CRITICAL)      # keep the real logger quiet in tests
    main()
    if _fails:
        raise SystemExit('\n%d FAILURE(S)\n' % _fails)
    print('enrichment save is not silent: PASS')

# ===== SNAPSMACK EOF =====
