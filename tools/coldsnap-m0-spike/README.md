# COLD SNAP canvas editor — Milestone 0 spike

**Throwaway.** Not a tool, not packaged, not imported by anything. Delete it
once the question is answered.

## Run it

```bash
python tools/coldsnap-m0-spike/spike.py
```

Nothing to install if COLD SNAP already runs on the machine: it needs PySide6
and nothing else.

## What to do, Sean

A window opens with a dark page you can write in, and a log strip underneath.

1. Click in the page and **type**.
2. **Paste** something with Ctrl+V.
3. **Drag a photograph out of a folder and drop it on the text.**

The log strip says what happened at each step. The line that matters is:

```
READ BY THE APP: <filename>, <n> bytes, <n> ms. Placing it.
```

That means Python opened your file, read the bytes itself, and then put the
picture in the page — which is the architecture the canvas editor needs: the
app owns the file, the page never touches disk.

If you see `DROP CARRIED NO LOCAL FILE`, or nothing at all when you let go,
that is the failure the spike exists to find. Say so and nothing else starts.

## What was already measured, so you do not have to

On Windows, driven with synthetic Qt events on 13 November 2026:

| | |
|---|---|
| Typing into the block | **PASS** |
| Pasting text | **PASS** |
| Dropping an image file | **PASS** — read in 84 ms, placed in the page |
| Editable block renders | **PASS** |

**The one thing a machine cannot settle is a real drag from a real file
manager.** Synthetic events prove the handler, the mime path, the file read
and the insertion all work; they cannot prove the operating system hands a
genuine drag to the window. That is the thirty seconds of your time this spike
is asking for.

## Linux Mint 22.3 — NOT CHECKED

I have no Linux machine. Nothing here is Windows-specific by design, and the
Chromium flag the spike sets (`--disable-gpu`) is the usual remedy when the
pane renders black on a Linux desktop. **But I have not run it**, and the
honest position is that the Linux answer is unknown until it runs on the Mint
box: does the window open, does the pane load, do typing, paste and drop work.

## Size

The spike runs from source, so there is no installer to weigh. The cost that
matters is QtWebEngine itself, already measured for the same PySide6 6.8.3:

- **Packaged, one-file exe:** 42.8 MiB without the pane, 134.7 MiB with it
  (**+91.9 MiB**), build time +35%.
- **On disk at runtime:** `Qt6WebEngineCore.dll` alone is 154,433,672 bytes,
  plus `icudtl.dat` at 10,717,680 — about 167.6 MB of WebEngine files that
  must be present for the pane to run.

A true unpacked-at-runtime figure for the one-file exe (`_MEI` extraction) is
still **NOT MEASURED**.

<!-- ===== SNAPSMACK EOF ===== -->
