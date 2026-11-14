"""A local Generative Fill can be watched, and it can be stopped.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

WHAT WAS WRONG. local_fill ran the diffusion child through
subprocess_limits.run(), which is communicate() underneath: it returns nothing
until the child exits. A fill is thirty diffusion steps and, on a 4 GB card,
several minutes of them. So the window could only say "this can take several
minutes", and the only way out of a fill the person did not want was to kill
SNAP SLAPPER and lose the document.

The child was not silent by nature -- nobody was listening. It now prints one
NDJSON line per step, subprocess_limits.stream() delivers those lines while the
child runs, and the same loop polls a cancel callback.

WHAT THESE MEASURE. Two of these tests run a REAL child process, because the
thing being claimed is about process behaviour and a mocked pipe would prove
nothing: that lines arrive before the child exits, and that cancelling actually
kills it. The rest drive local_fill with a fake stream so the parsing can be
checked without a 12 GB model.
"""

import json
import os
import pathlib
import subprocess
import sys
import threading
import time

import pytest
from PIL import Image

HUB = pathlib.Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

import subprocess_limits                                    # noqa: E402
from slapper_qt import local_fill                           # noqa: E402

CHATTY = (
    "import sys, time\n"
    "for i in range(1, 6):\n"
    "    sys.stdout.write('line %d' % i + chr(10))\n"
    "    sys.stdout.flush()\n"
    "    time.sleep(0.25)\n"
)


def _script(tmp_path, body, name="child.py"):
    path = tmp_path / name
    path.write_text(body, encoding="utf-8")
    return str(path)


# ── the process behaviour, measured on a real child ────────────────────────

def test_lines_arrive_while_the_child_is_still_running(tmp_path):
    """The whole point. With run() every line lands at once, at the end."""
    seen = []
    started = time.monotonic()

    def note(line):
        seen.append((line, time.monotonic() - started))

    result = subprocess_limits.stream(
        [sys.executable, "-I", _script(tmp_path, CHATTY)],
        timeout=30, memory_bytes=512 * 1024 * 1024, on_line=note)

    assert result.returncode == 0
    assert [line for line, _ in seen] == ["line %d" % i for i in range(1, 6)]
    # The child spaces its lines 0.25s apart over about 1.25s. If these had
    # been buffered to the end, the first and last would share a timestamp.
    first, last = seen[0][1], seen[-1][1]
    assert last - first > 0.4, (
        "every line arrived at once, so nothing was streamed: %r" % seen)
    # And the full text is still returned, so a caller that ignores progress
    # reads stream() exactly as it read run().
    assert result.stdout.count("line") == 5


def test_cancelling_kills_the_child_and_does_not_wait_it_out(tmp_path):
    """A fill the person did not want must not hold the application hostage."""
    body = ("import sys, time\n"
            "sys.stdout.write('started' + chr(10))\n"
            "sys.stdout.flush()\n"
            "time.sleep(120)\n")
    stop = threading.Event()

    def cancel_once_started(line):
        if line == "started":
            stop.set()

    started = time.monotonic()
    with pytest.raises(subprocess_limits.Cancelled):
        subprocess_limits.stream(
            [sys.executable, "-I", _script(tmp_path, body, "sleeper.py")],
            timeout=120, memory_bytes=512 * 1024 * 1024,
            on_line=cancel_once_started, should_cancel=stop.is_set)
    elapsed = time.monotonic() - started
    assert elapsed < 20, (
        "cancel waited for the child's own 120s instead of killing it (%.1fs)"
        % elapsed)


def test_a_child_that_never_ends_still_times_out(tmp_path):
    body = "import time\ntime.sleep(120)\n"
    with pytest.raises(subprocess.TimeoutExpired):
        subprocess_limits.stream(
            [sys.executable, "-I", _script(tmp_path, body, "hang.py")],
            timeout=1.0, memory_bytes=512 * 1024 * 1024)


def test_a_callback_that_throws_does_not_strand_the_child(tmp_path):
    def explode(_line):
        raise RuntimeError("the UI blew up")

    result = subprocess_limits.stream(
        [sys.executable, "-I", _script(tmp_path, CHATTY, "chatty2.py")],
        timeout=30, memory_bytes=512 * 1024 * 1024, on_line=explode)
    assert result.returncode == 0


def test_stream_refuses_the_arguments_it_cannot_honour(tmp_path):
    path = _script(tmp_path, "print(1)", "trivial.py")
    for kwargs in ({"capture_output": True}, {"stdout": subprocess.DEVNULL},
                   {"stderr": subprocess.DEVNULL}):
        with pytest.raises(ValueError):
            subprocess_limits.stream([sys.executable, "-I", path], timeout=5,
                                     memory_bytes=512 * 1024 * 1024, **kwargs)


# ── what local_fill makes of those lines ───────────────────────────────────

def _installed(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    root = pathlib.Path(local_fill.install_root())
    (root / "venv" / "Scripts").mkdir(parents=True)
    (root / "venv" / "Scripts" / "python.exe").write_bytes(b"")
    source = pathlib.Path(local_fill._resource("local_fill_runner.py"))
    (root / "local_fill_runner.py").write_bytes(source.read_bytes())
    (root / "installed.txt").write_text(local_fill.MODEL, encoding="utf-8")
    return root


def _fake_stream(lines, on_cancel=None):
    def stream(args, **kwargs):
        notify = kwargs.get("on_line")
        cancel = kwargs.get("should_cancel")
        for line in lines:
            if cancel is not None and cancel():
                raise subprocess_limits.Cancelled("stopped")
            if notify:
                notify(line)
        if on_cancel is not None:
            on_cancel()
        output = args[args.index("--output") + 1]
        size = Image.open(args[args.index("--image") + 1]).size
        Image.new("RGB", size, "blue").save(output)
        return subprocess.CompletedProcess(args, 0, chr(10).join(lines), "")
    return stream


def _tile_and_mask():
    tile = Image.new("RGB", (400, 400), "red")
    mask = Image.new("L", tile.size, 0)
    for y in range(190, 210):
        for x in range(190, 210):
            mask.putpixel((x, y), 255)
    return tile, mask


def test_every_diffusion_step_reaches_the_caller(monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    lines = [json.dumps({"stage": "loading", "detail": "Loading the model"})]
    lines += [json.dumps({"stage": "generating", "step": n, "steps": 30})
              for n in range(0, 31)]
    lines += [json.dumps({"stage": "saving"}),
              json.dumps({"ok": True, "model": local_fill.MODEL})]
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_stream(lines))

    seen = []
    tile, mask = _tile_and_mask()
    local_fill.fill_tile(tile, mask, "", on_progress=lambda *a: seen.append(a))

    assert seen[0] == ("loading", 0, 0), seen[:2]
    steps = [s for s in seen if s[0] == "generating"]
    assert len(steps) == 31, "a step went missing: %d of 31" % len(steps)
    assert steps[0] == ("generating", 0, 30)
    assert steps[-1] == ("generating", 30, 30)
    assert seen[-1] == ("saving", 0, 0)
    # The final result line is not progress and must not be reported as a stage.
    assert all(s[0] in ("loading", "generating", "saving") for s in seen)


def test_the_step_count_matches_the_runner(monkeypatch, tmp_path):
    """A bar drawn against 30 while the runner does 50 is a lying bar."""
    runner = pathlib.Path(local_fill._resource("local_fill_runner.py"))
    body = runner.read_text(encoding="utf-8")
    assert "STEPS = %d" % local_fill.RUNNER_STEPS in body
    assert "num_inference_steps=STEPS" in body, (
        "the runner hardcodes a step count again, so RUNNER_STEPS can drift")


def test_noise_from_a_chatty_dependency_does_not_move_the_bar(monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    lines = ["",
             "A matching Triton is not available",
             "{not json at all",
             json.dumps(["a", "list", "not", "an", "object"]),
             json.dumps({"stage": "something-else", "step": 99, "steps": 99}),
             json.dumps({"ok": True}),
             json.dumps({"stage": "generating", "step": 7, "steps": 30})]
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_stream(lines))
    seen = []
    tile, mask = _tile_and_mask()
    local_fill.fill_tile(tile, mask, "", on_progress=lambda *a: seen.append(a))
    assert seen == [("generating", 7, 30)], seen


def test_a_fill_with_no_progress_callback_still_works(monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    lines = [json.dumps({"stage": "generating", "step": 1, "steps": 30})]
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_stream(lines))
    tile, mask = _tile_and_mask()
    patch, model = local_fill.fill_tile(tile, mask, "")
    assert patch.size == tile.size
    assert model == local_fill.MODEL


def test_cancelling_a_fill_raises_rather_than_returning_a_half_patch(
        monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    lines = [json.dumps({"stage": "generating", "step": n, "steps": 30})
             for n in range(30)]
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_stream(lines))
    tile, mask = _tile_and_mask()
    seen = []

    def progress(*args):
        seen.append(args)

    with pytest.raises(subprocess_limits.Cancelled):
        local_fill.fill_tile(tile, mask, "", on_progress=progress,
                             should_cancel=lambda: len(seen) >= 3)
    assert len(seen) == 3, "the fill ran on past the cancel: %d steps" % len(seen)


def test_local_fill_exposes_cancelled_so_callers_need_not_reach_past_it():
    assert local_fill.Cancelled is subprocess_limits.Cancelled


if __name__ == "__main__":
    raise SystemExit(pytest.main([__file__, "-q"]))

# ===== SNAPSMACK EOF =====
