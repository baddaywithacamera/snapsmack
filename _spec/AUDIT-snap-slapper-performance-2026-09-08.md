# SNAP SLAPPER performance diagnosis

Date: 2026-09-08

Observed build: installed Windows build 0.7.30

Scope: why the editor feels disproportionately heavy during ordinary editing. This is a diagnosis, not a completed optimization.

## Live observation

With the editor and library windows open but idle, the principal `SNAP SLAPPER` process reported:

- Working set: approximately 99 MB
- Private committed memory: approximately 841 MB
- Threads: 29
- Handles: approximately 1,870
- CPU over a three-second idle sample: 0%

A second tiny process (approximately 8 MB working set) is the launcher/single-instance wrapper, not another full editor. The live sample does not show an idle CPU loop. The poor feel is therefore more consistent with bursty render latency, memory retention, and queued work during interaction than with continuous background processing.

## Primary cause: stale previews consume work even when their result is obsolete

Slider changes are debounced for only 45 ms. Each dispatch snapshots the document, constructs a fresh `EditorDocument`, restores the complete state, and renders the adjustment pipeline in a background job. A generation token prevents an old result from replacing a newer result, but it does not cancel or coalesce a job already running.

During a quick slider drag, the application can submit renders faster than the pure Pillow/NumPy pipeline completes them. Old jobs continue consuming CPU and allocating images even though their results will be discarded. The UI thread remains nominally free, but CPU contention and delayed useful frames make controls feel viscous.

## Secondary causes

### The full adjustment pipeline is recomputed

The editor renderer applies the document pipeline to a proxy image for every useful preview. There is no stage cache that reuses the output immediately before the adjustment currently being manipulated. Moving a late-stage slider can therefore repeat earlier work unnecessarily.

### Histogram work follows every accepted preview

Every displayed render refreshes the live histogram. Histogram calculation is cheaper than the main render but still adds conversion, sampling, and four histogram passes to the critical feedback path. It need not run at preview-frame frequency while a control is moving.

### Native-pixel mode is intentionally expensive

At 100% zoom, non-interactive renders operate at native image resolution. This is correct for focus inspection but should be unmistakably presented as a high-quality inspection mode, not an ordinary editing state. Returning to Fit should always restore the fast proxy path immediately.

### Memory is released logically but not necessarily returned to Windows

Pillow, NumPy, Qt image buffers, worker jobs, and the Python/native allocators can retain committed arenas after large temporary images are freed. The observed 841 MB private commit with only about 99 MB resident is consistent with substantial reserved/committed process memory that is not actively resident. It is still excessive for the apparent workload and should be profiled over open/edit/close cycles.

### Parallel thumbnail systems add threads and allocation churn

The filmstrip uses its own two-thread pool, the library has thumbnail and scan pools, and preview rendering uses Qt's global thread pool. This separation prevents one queue from completely starving another, but total concurrency is not governed by one application-wide resource budget.

## Required repair

Governing rule: **use it, then lose it**. Render resources are temporary unless they demonstrably make the next interaction faster. The editor may retain the reduced source proxy, the currently displayed preview, bounded undo state, and a small byte-budgeted cache. Intermediate images, obsolete jobs, native-resolution inspection buffers, histogram samples, and off-screen thumbnails must be released promptly when their immediate consumer is finished.

1. Use a latest-only preview scheduler: at most one active render plus one replaceable pending request.
2. Make cooperative cancellation available between major render stages so obsolete jobs stop early.
3. Use two quality tiers: a small interactive proxy during motion and a sharper settled render after release.
4. Raise the motion debounce adaptively when rendering is slower than the requested frame rate.
5. Refresh the histogram on the settled render, or at a separately throttled low rate.
6. Cache stable pipeline stages and invalidate from the earliest changed stage instead of rerendering everything.
7. Set an application-wide worker budget covering previews, filmstrip thumbnails, and library thumbnails.
8. Bound and instrument every image cache by estimated bytes, not merely item count.
9. Drop references to obsolete rendered images and completed jobs immediately; verify Qt pixmaps and signals do not retain them.
10. Add visible `Interactive / Settling / Full quality` rendering states only if feedback cannot remain seamless; ideally the optimization makes these unnecessary.

## Performance acceptance criteria

- Idle editor CPU remains effectively zero.
- Fit-mode slider feedback begins within 50 ms and remains responsive under continuous dragging.
- There is never more than one active and one pending preview render per editor window.
- Releasing a slider produces the settled preview within 250 ms for a typical 24 MP JPEG on the target machine.
- Histogram work cannot delay the next visible preview.
- Opening and closing 25 photographs from one folder does not produce monotonic private-memory growth.
- Returning from 100% to Fit releases native-resolution working buffers and immediately restores interactive rendering.
- Library and filmstrip thumbnail loading cannot reduce interactive preview service below its target.

## Bottom line

SNAP SLAPPER currently behaves like a full-resolution batch renderer that happens to be connected to sliders. Its idle behavior is acceptable, but its interactive scheduling wastes work. The fastest win is not rewriting the image engine: it is enforcing latest-only rendering, separating motion quality from settled quality, and moving histogram updates off the critical feedback path.
