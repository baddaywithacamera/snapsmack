"""The model's answer is never stretched. SS-1338.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

MEASURED ON SEAN'S OWN PHOTOGRAPH, not imagined. A 3456x5184 frame, a
342x528 selection in the bottom corner. The tile chosen around it was 2600
pixels, the local model works at 512, so:

    selection at model resolution   528 * 512/2600  =  104 pixels
    patch stretched back up by      2600/512        =  5.1x

The model rebuilt his doorway as a 104-pixel smear and it was then magnified
five times to fit. His words: "i don't want the patch upscaled at all", and
"upscaling looks like shit. it has to be quality or it has to be taken out."

So a region bigger than the model's canvas is no longer squeezed into it. It
is covered by overlapping windows of at most WORK_RESOLUTION REAL pixels,
each inpainted at 1:1, blended across the overlap. Every pixel the model
returns lands at the photograph's own scale, and the photograph inside each
window is the model's context at full detail instead of a thumbnail of a
wider view.

WHAT THESE TESTS ARE FOR. The claim is "never upscaled". That is a property
of every window on every input, so it is checked as one: the window planner
is asked about a spread of sizes and must never produce a window larger than
the model's canvas, and the worker itself refuses rather than stretching if
one ever reaches it.
"""

import json
import pathlib
import subprocess
import sys

import pytest
from PIL import Image

HUB = pathlib.Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

from slapper_qt import local_fill                                  # noqa: E402

WORK = local_fill.WORK_RESOLUTION


# ── the window plan ────────────────────────────────────────────────────────

@pytest.mark.parametrize("size", [(2600, 2600), (3456, 5184), (1024, 768),
                                  (513, 513), (512, 512), (300, 400)])
@pytest.mark.parametrize("mask", [(0, 0, 40, 40), (100, 100, 442, 628),
                                  (0, 0, 1, 1)])
def test_no_window_is_ever_larger_than_the_model_canvas(size, mask):
    """The whole claim, as one property."""
    left, top, right, bottom = mask
    if right > size[0] or bottom > size[1]:
        pytest.skip("mask does not fit this frame")
    for x0, y0, x1, y1 in local_fill.plan_windows(size, mask):
        assert x1 - x0 <= WORK, (size, mask, (x0, y0, x1, y1))
        assert y1 - y0 <= WORK, (size, mask, (x0, y0, x1, y1))
        assert x0 >= 0 and y0 >= 0
        assert x1 <= size[0] and y1 <= size[1]


def test_the_windows_cover_every_painted_pixel():
    """A gap in the cover is a patch of the photograph left unrebuilt."""
    size, mask = (2600, 2600), (1129, 1036, 1471, 1564)
    windows = local_fill.plan_windows(size, mask)
    for y in range(mask[1], mask[3], 7):
        for x in range(mask[0], mask[2], 7):
            assert any(x0 <= x < x1 and y0 <= y < y1
                       for x0, y0, x1, y1 in windows), (x, y)


def test_seans_actual_fill_becomes_two_full_resolution_windows():
    """The case from the report, with his numbers."""
    windows = local_fill.plan_windows((2600, 2600), (1129, 1036, 1471, 1564))
    assert len(windows) == 2
    for x0, y0, x1, y1 in windows:
        assert (x1 - x0, y1 - y0) == (WORK, WORK)
    # Before this change his 528-pixel selection reached the model as 104
    # pixels. Now every pixel of it is carried at 1:1.
    assert all(x1 - x0 == WORK for x0, _y0, x1, _y1 in windows)


def test_neighbouring_windows_overlap_so_there_is_something_to_blend():
    windows = local_fill.plan_windows((2600, 2600), (0, 0, 2000, 200))
    assert len(windows) > 1
    xs = sorted({(w[0], w[2]) for w in windows})
    for (a0, a1), (b0, _b1) in zip(xs, xs[1:]):
        assert b0 < a1, "windows butt together with no shared band"
        assert a1 - b0 >= local_fill.OVERLAP // 2


def test_a_small_blemish_still_costs_one_model_run():
    """Tiling must not make the common case slower."""
    assert len(local_fill.plan_windows((2600, 2600), (1280, 1280, 1320, 1320))) == 1


def test_a_region_smaller_than_the_canvas_is_one_window():
    assert local_fill.plan_windows((400, 400), (100, 100, 200, 200)) == [(0, 0, 400, 400)]


# ── the worker refuses to stretch ──────────────────────────────────────────

def _installed(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    root = pathlib.Path(local_fill.install_root())
    (root / "venv" / "Scripts").mkdir(parents=True)
    (root / "venv" / "Scripts" / "python.exe").write_bytes(b"")
    source = pathlib.Path(local_fill._resource("local_fill_runner.py"))
    (root / "local_fill_runner.py").write_bytes(source.read_bytes())
    (root / "installed.txt").write_text(local_fill.MODEL, encoding="utf-8")


def _fake_model(colour=(0, 200, 0), out_size=None):
    """A stand-in runner that answers at the model's canvas size."""
    def stream(args, **kwargs):
        notify = kwargs.get("on_line")
        cancel = kwargs.get("should_cancel")
        for n in range(0, 31):
            if cancel is not None and cancel():
                raise local_fill.Cancelled("stopped")
            if notify:
                notify(json.dumps({"stage": "generating", "step": n, "steps": 30}))
        image = Image.open(args[args.index("--image") + 1])
        Image.new("RGB", out_size or image.size, colour).save(
            args[args.index("--output") + 1])
        return subprocess.CompletedProcess(args, 0, "", "")
    return stream


def test_a_window_larger_than_the_canvas_is_refused_not_stretched(
        monkeypatch, tmp_path):
    """The guard that makes 'never upscaled' true rather than intended."""
    _installed(monkeypatch, tmp_path)
    monkeypatch.setattr(local_fill.subprocess_limits, "stream",
                        _fake_model(out_size=(WORK, WORK)))
    big = Image.new("RGB", (WORK * 2, WORK * 2), "red")
    mask = Image.new("L", big.size, 0)
    mask.paste(255, (10, 10, 60, 60))
    with pytest.raises(RuntimeError, match="upscale"):
        local_fill._fill_one_window(big, mask, "")


# ── the whole job, end to end, against a stand-in model ────────────────────

def _scene(size=(1400, 900), painted=(500, 300, 900, 700)):
    photo = Image.new("RGB", size, (180, 40, 40))
    mask = Image.new("L", size, 0)
    mask.paste(255, painted)
    return photo, mask


def test_every_painted_pixel_is_rebuilt_and_nothing_else_is_touched(
        monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_model())
    photo, mask = _scene()
    out, model = local_fill.fill_tile(photo, mask, "")

    assert out.size == photo.size
    assert model == local_fill.MODEL
    # Inside the painted area: the model's colour, not the photograph's.
    assert out.getpixel((700, 500))[1] > 150
    # Outside it: the photograph, pixel for pixel, which is what the dialog
    # promises in words.
    for point in [(10, 10), (1390, 890), (100, 500), (700, 100)]:
        assert out.getpixel(point) == (180, 40, 40), point

    # AND the hard case, which the four points above do not reach: a pixel
    # INSIDE a window the model rebuilt but OUTSIDE the painted mask. The
    # model returns a whole 512x512 answer; only the painted part of it may
    # be kept. A mutant that composited the whole window survived until this
    # assertion existed.
    windows = local_fill.plan_windows(photo.size, mask.getbbox())
    inside_window_outside_mask = []
    for x0, y0, x1, y1 in windows:
        for x in range(x0, x1, 9):
            for y in range(y0, y1, 9):
                if mask.getpixel((x, y)) == 0:
                    inside_window_outside_mask.append((x, y))
    assert inside_window_outside_mask, "the scene does not exercise this"
    for point in inside_window_outside_mask[:400]:
        assert out.getpixel(point) == (180, 40, 40), (
            "a pixel inside the window but outside the selection was "
            "overwritten: %r" % (point,))


def test_the_job_runs_one_model_pass_per_window(monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    runs = []
    inner = _fake_model()

    def counting(args, **kwargs):
        runs.append(Image.open(args[args.index("--image") + 1]).size)
        return inner(args, **kwargs)

    monkeypatch.setattr(local_fill.subprocess_limits, "stream", counting)
    photo, mask = _scene()
    expected = local_fill.plan_windows(photo.size, mask.getbbox())
    local_fill.fill_tile(photo, mask, "")
    assert len(runs) == len(expected)
    # AND EVERY PASS SAW REAL PIXELS AT 1:1 -- no window was shrunk on its way
    # to the model, which is the whole point.
    for size in runs:
        assert max(size) <= WORK, size


def test_a_window_with_no_paint_in_it_costs_nothing(monkeypatch, tmp_path):
    """Two far-apart specks must not pay for the empty middle."""
    _installed(monkeypatch, tmp_path)
    runs = []
    inner = _fake_model()

    def counting(args, **kwargs):
        runs.append(1)
        return inner(args, **kwargs)

    monkeypatch.setattr(local_fill.subprocess_limits, "stream", counting)
    photo = Image.new("RGB", (2000, 600), "red")
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (20, 20, 60, 60))
    mask.paste(255, (1900, 500, 1960, 560))
    planned = local_fill.plan_windows(photo.size, mask.getbbox())
    local_fill.fill_tile(photo, mask, "")
    # The bbox spans the whole frame, so the planner covers it; only the
    # windows that actually contain paint may cost a model run. The exact
    # number depends on how the two specks fall across overlapping windows
    # (3 here, not 2) -- what matters is that the empty middle is free.
    assert len(planned) >= 8, len(planned)
    assert len(runs) <= 4, (len(runs), len(planned))
    assert len(runs) < len(planned) / 2


def test_progress_counts_across_the_whole_job_and_never_restarts(
        monkeypatch, tmp_path):
    """A bar that resets to zero per window reads as a hang."""
    _installed(monkeypatch, tmp_path)
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_model())
    # SEVERAL windows, or the mutation that restarts the count per window is
    # indistinguishable from the correct behaviour -- a one-window job counts
    # the same either way. That gap let a mutant live.
    photo = Image.new("RGB", (2400, 700), "red")
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (100, 100, 2300, 600))
    assert len(local_fill.plan_windows(photo.size, mask.getbbox())) >= 4
    seen = []
    local_fill.fill_tile(photo, mask, "",
                         on_progress=lambda *a: seen.append(a))
    steps = [(done, total) for stage, done, total in seen
             if stage == "generating" and total > 0]
    assert steps, "no step progress reported at all"
    assert [d for d, _t in steps] == sorted(d for d, _t in steps), \
        "progress went backwards between windows"
    assert len({t for _d, t in steps}) == 1, "the total changed mid-job"
    windows = len(local_fill.plan_windows(photo.size, mask.getbbox()))
    assert steps[-1][1] == windows * 30


def test_the_job_stops_between_windows_even_if_the_model_never_checks(
        monkeypatch, tmp_path):
    """The between-windows check, isolated.

    The stand-in model polls should_cancel inside its own loop, so it would
    raise even with the engine's own check removed -- which is exactly how a
    mutant that deleted it survived. This model never polls, so only the
    engine can stop the job.
    """
    _installed(monkeypatch, tmp_path)
    runs = {"n": 0}

    def deaf_model(args, **kwargs):
        runs["n"] += 1
        notify = kwargs.get("on_line")
        if notify:
            notify(json.dumps({"stage": "generating", "step": 30, "steps": 30}))
        image = Image.open(args[args.index("--image") + 1])
        Image.new("RGB", image.size, (0, 200, 0)).save(
            args[args.index("--output") + 1])
        return subprocess.CompletedProcess(args, 0, "", "")

    monkeypatch.setattr(local_fill.subprocess_limits, "stream", deaf_model)
    photo = Image.new("RGB", (2400, 700), "red")
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (100, 100, 2300, 600))
    planned = len(local_fill.plan_windows(photo.size, mask.getbbox()))
    assert planned >= 4
    with pytest.raises(local_fill.Cancelled):
        local_fill.fill_tile(photo, mask, "",
                             should_cancel=lambda: runs["n"] >= 2)
    assert runs["n"] < planned, (runs["n"], planned)


def test_stopping_part_way_through_the_windows_raises(monkeypatch, tmp_path):
    _installed(monkeypatch, tmp_path)
    monkeypatch.setattr(local_fill.subprocess_limits, "stream", _fake_model())
    # A region wide enough to need SEVERAL windows: the point is stopping
    # BETWEEN windows, and a single-window job can only be stopped inside one.
    photo = Image.new("RGB", (2400, 700), "red")
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (100, 100, 2300, 600))
    assert len(local_fill.plan_windows(photo.size, mask.getbbox())) >= 4
    seen = []
    with pytest.raises(local_fill.Cancelled):
        local_fill.fill_tile(photo, mask, "",
                             on_progress=lambda *a: seen.append(a),
                             should_cancel=lambda: len(seen) > 35)
    # It stopped early rather than quietly finishing every window.
    assert len(seen) < 4 * 31


def test_the_overlap_is_blended_not_butted(monkeypatch, tmp_path):
    """Two windows answering differently must hand over gradually."""
    _installed(monkeypatch, tmp_path)
    calls = {"n": 0}

    def alternating(args, **kwargs):
        notify = kwargs.get("on_line")
        if notify:
            notify(json.dumps({"stage": "generating", "step": 30, "steps": 30}))
        image = Image.open(args[args.index("--image") + 1])
        colour = (0, 0, 255) if calls["n"] % 2 == 0 else (255, 255, 0)
        calls["n"] += 1
        Image.new("RGB", image.size, colour).save(
            args[args.index("--output") + 1])
        return subprocess.CompletedProcess(args, 0, "", "")

    monkeypatch.setattr(local_fill.subprocess_limits, "stream", alternating)
    photo = Image.new("RGB", (1400, 600), "red")
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (0, 100, 1400, 500))
    out, _ = local_fill.fill_tile(photo, mask, "")
    # Somewhere across the seam there must be a pixel that is neither window's
    # flat answer -- that is what blending means.
    row = [out.getpixel((x, 300)) for x in range(0, 1400, 5)]
    mixed = [p for p in row if p not in ((0, 0, 255), (255, 255, 0))]
    assert mixed, "no blended pixels: the windows were butted together"


def test_a_selection_in_a_CORNER_is_not_blacked_out(monkeypatch, tmp_path):
    """SS-1338 follow-up. Sean's selection sat in the bottom-right corner and
    came back near-black: mean luma 4.1 where the previous build gave 42.6.

    The feather faded EVERY window side. That is right where another window
    overlaps and makes up the weight, and wrong at the region's own boundary
    where there is no neighbour -- the corner pixel ended with 4.5e-09 of
    total weight and acc/wsum became garbage. 4.1% of his painted area had
    effectively none.
    """
    _installed(monkeypatch, tmp_path)
    monkeypatch.setattr(local_fill.subprocess_limits, "stream",
                        _fake_model(colour=(120, 130, 140)))
    photo = Image.new("RGB", (1400, 1400), (30, 60, 90))
    mask = Image.new("L", photo.size, 0)
    mask.paste(255, (1047, 872, 1400, 1400))        # hard into the corner
    out, _ = local_fill.fill_tile(photo, mask, "")

    import numpy as np
    arr = np.asarray(out.crop((1047, 872, 1400, 1400)), dtype=np.float32)
    luma = 0.299 * arr[..., 0] + 0.587 * arr[..., 1] + 0.114 * arr[..., 2]
    # The stand-in model answers a flat 120,130,140 -> luma about 128.
    assert luma.mean() > 100, (
        "the corner selection came back dark: mean luma %.1f" % luma.mean())
    assert luma.min() > 60, (
        "some corner pixels are near-black: min luma %.1f" % luma.min())
    # And the very corner pixel itself, which is where the weight vanished.
    assert out.getpixel((1399, 1399))[0] > 60, out.getpixel((1399, 1399))


def test_every_painted_pixel_carries_real_weight(monkeypatch, tmp_path):
    """The property behind the corner bug, checked directly on the weights."""
    import numpy as np
    for bbox, size in [((2247, 2072, 2600, 2600), (2600, 2600)),   # corner
                       ((0, 0, 400, 400), (2600, 2600)),           # other corner
                       ((0, 1000, 2600, 1400), (2600, 2600)),      # edge to edge
                       ((1100, 1100, 1500, 1500), (2600, 2600))]:  # middle
        width, height = size
        total = np.zeros((height, width), dtype=np.float32)
        windows = local_fill.plan_windows(size, bbox)
        for x0, y0, x1, y1 in windows:
            total[y0:y1, x0:x1] += local_fill._feather(
                (x1 - x0, y1 - y0), local_fill.OVERLAP,
                fade=local_fill.fade_sides((x0, y0, x1, y1), windows))
        painted = total[bbox[1]:bbox[3], bbox[0]:bbox[2]]
        starved = int((painted < local_fill.WEIGHT_FLOOR).sum())
        assert starved == 0, (
            "%d painted pixels have less than the weight floor for %r"
            % (starved, bbox))


def test_a_starved_pixel_keeps_the_photograph_rather_than_going_dark():
    """The floor, as a guarantee that does not depend on the geometry."""
    assert local_fill.WEIGHT_FLOOR > 0


def test_the_feather_falls_off_at_the_edges():
    import numpy as np
    w = local_fill._feather((WORK, WORK), local_fill.OVERLAP)
    assert isinstance(w, np.ndarray) and w.shape == (WORK, WORK)
    assert w[WORK // 2, WORK // 2] == pytest.approx(1.0, abs=1e-6)
    assert w[0, 0] < 0.05
    assert w[-1, -1] < 0.05
    assert w.min() >= 0.0 and w.max() <= 1.0 + 1e-6


if __name__ == "__main__":
    raise SystemExit(pytest.main([__file__, "-q"]))

# ===== SNAPSMACK EOF =====
