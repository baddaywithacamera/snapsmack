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


# ── THE RUNNER ITSELF, RUN ─────────────────────────────────────────────────
# A mutation run removed callback_on_step_end from the runner and every test
# above still passed: they all drive local_fill with a fake child, so nothing
# touched the one line the whole progress feature rests on. These load the
# real runner with stand-ins for torch and diffusers -- the model weighs 12 GB
# and is not in CI, the twenty lines of reporting logic are -- and read what
# it actually prints.

class _FakePipe:
    """Stands in for the diffusers inpainting pipeline."""

    def __init__(self, accepts_callback=True):
        self.accepts_callback = accepts_callback
        self.seen_steps = None

    def to(self, _device):
        return self

    def enable_model_cpu_offload(self):
        pass

    def __call__(self, **kwargs):
        self.seen_steps = kwargs.get("num_inference_steps")
        callback = kwargs.get("callback_on_step_end")
        if callback is not None and not self.accepts_callback:
            raise TypeError("unexpected keyword argument callback_on_step_end")
        if callback is not None:
            for step in range(self.seen_steps):
                callback(self, step, 0, {})
        return type("Out", (), {"images": [Image.new("RGB", (64, 64), "green")]})()


def _load_runner(monkeypatch, pipe):
    import types
    torch = types.ModuleType("torch")
    torch.float16 = "float16"
    torch.float32 = "float32"
    torch.cuda = types.SimpleNamespace(is_available=lambda: False)
    diffusers = types.ModuleType("diffusers")
    diffusers.AutoPipelineForInpainting = types.SimpleNamespace(
        from_pretrained=lambda *a, **k: pipe)
    monkeypatch.setitem(sys.modules, "torch", torch)
    monkeypatch.setitem(sys.modules, "diffusers", diffusers)

    import importlib.util
    path = pathlib.Path(local_fill._resource("local_fill_runner.py"))
    spec = importlib.util.spec_from_file_location("_runner_under_test", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _run_runner(monkeypatch, capsys, tmp_path, pipe):
    Image.new("RGB", (64, 64), "red").save(tmp_path / "in.png")
    Image.new("L", (64, 64), 255).save(tmp_path / "m.png")
    module = _load_runner(monkeypatch, pipe)
    monkeypatch.setattr(sys, "argv", [
        "local_fill_runner.py",
        "--image", str(tmp_path / "in.png"),
        "--mask", str(tmp_path / "m.png"),
        "--output", str(tmp_path / "out.png"),
        "--prompt", ""])
    module.main()
    lines = [json.loads(line) for line in capsys.readouterr().out.splitlines()
             if line.strip().startswith("{")]
    return module, lines


def test_the_real_runner_reports_every_step(monkeypatch, capsys, tmp_path):
    pipe = _FakePipe()
    module, lines = _run_runner(monkeypatch, capsys, tmp_path, pipe)
    stages = [line.get("stage") for line in lines]
    assert "loading" in stages, "the runner says nothing while the model loads"
    steps = [line for line in lines
             if line.get("stage") == "generating" and "step" in line]
    assert len(steps) == module.STEPS + 1, (
        "expected the opening line plus one per step, got %d" % len(steps))
    assert [line["step"] for line in steps] == list(range(module.STEPS + 1))
    assert all(line["steps"] == module.STEPS for line in steps)
    assert "saving" in stages
    assert pipe.seen_steps == module.STEPS == local_fill.RUNNER_STEPS


def test_an_older_diffusers_without_the_callback_still_fills(
        monkeypatch, capsys, tmp_path):
    """Losing the step count is survivable. Losing the fill is not."""
    pipe = _FakePipe(accepts_callback=False)
    module, lines = _run_runner(monkeypatch, capsys, tmp_path, pipe)
    assert (tmp_path / "out.png").is_file(), "the fill was lost to the fallback"
    assert lines[-1].get("ok") is True
    assert any(line.get("stage") == "generating" for line in lines)


def test_the_runner_prints_one_json_object_per_line(monkeypatch, capsys, tmp_path):
    """local_fill parses these line by line; a pretty-printed object breaks it."""
    _module, _lines = _run_runner(monkeypatch, capsys, tmp_path, _FakePipe())
    out = capsys.readouterr()  # already drained; re-run for the raw text
    del out
    pipe = _FakePipe()
    Image.new("RGB", (64, 64), "red").save(tmp_path / "in2.png")
    Image.new("L", (64, 64), 255).save(tmp_path / "m2.png")
    module = _load_runner(monkeypatch, pipe)
    monkeypatch.setattr(sys, "argv", [
        "local_fill_runner.py",
        "--image", str(tmp_path / "in2.png"), "--mask", str(tmp_path / "m2.png"),
        "--output", str(tmp_path / "out2.png"), "--prompt", ""])
    module.main()
    raw = capsys.readouterr().out
    for line in raw.splitlines():
        if line.strip():
            json.loads(line)          # every line stands alone or this throws


def test_what_the_runner_prints_is_what_local_fill_understands(
        monkeypatch, capsys, tmp_path):
    """The two halves agree. This is the join a fake child cannot test."""
    _module, lines = _run_runner(monkeypatch, capsys, tmp_path, _FakePipe())
    seen = []
    read = local_fill._progress_reader(lambda *args: seen.append(args))
    for line in lines:
        read(json.dumps(line))
    assert ("loading", 0, 0) in seen
    assert ("generating", local_fill.RUNNER_STEPS, local_fill.RUNNER_STEPS) in seen
    assert ("saving", 0, 0) in seen
    assert len([s for s in seen if s[0] == "generating"]) == \
        local_fill.RUNNER_STEPS + 1


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
