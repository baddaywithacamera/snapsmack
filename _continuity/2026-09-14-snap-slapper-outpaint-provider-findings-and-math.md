# SNAP SLAPPER — outpaint provider findings and math

Recorded 2026-09-14 after controlled tests with Gemini and Stability AI and a
technical discussion with Gemini. This note preserves both established facts
and experimental advice; they are deliberately labelled separately.

## What the tests established

- `gemini-3.1-flash-image` through the Gemini Developer API `generateContent`
  is a whole-image multimodal generation route, not a first-class masked
  outpainting contract. Visual guides are instructions, not enforced masks.
- SNAP SLAPPER's local restoration of the captured rectangle remains the only
  hard pixel boundary on this route.
- A one-pixel edge stretched across a new margin is a bad seed. It creates a
  constant-axis stripe that can survive unchanged or be interpreted as real
  structure. The observed blue pavement and barcode-like bottom band were
  consistent with this failure.
- A red overlay is also an ambiguous visual instruction. It injects colour into
  the input rather than supplying an API-enforced target.
- Stability AI's dedicated outpaint endpoint obeyed the requested canvas shape,
  but the tested photograph still produced implausible continuations and seams.
  A dedicated endpoint is not, by itself, proof of acceptable output.

## Controlled Gemini experiment implemented in build 0.7.54

1. Fill new pixels with neutral RGB `(128, 128, 128)` in an uncompressed PNG.
2. Send IMAGE 1 as the unexpanded local reference and IMAGE 2 as the reference
   positioned on the grey-padded target canvas. Do not send a red guide.
3. Send each non-zero edge as a separate request in left, right, top, bottom
   order. A user's multi-edge/corner gesture remains one SNAP SLAPPER operation.
4. Restore the entire preceding canvas locally after every edge pass, then
   restore the original captured photograph again at the final original offset.
   Thus later passes may use earlier generated pixels as context but cannot
   rewrite them, and no generated pixel can survive inside the captured frame.

This is an experiment, not a claim that Gemini now provides reliable outpaint.

## Canvas and 20% budget math (shipping)

Let the original captured dimensions be `W x H`, and let the selected integer
padding be `L, R, T, B` pixels. Then:

```text
W_out = W + L + R
H_out = H + T + B
G     = W_out * H_out - W * H
      = H(L + R) + W(T + B) + (L + R)(T + B)
```

`G` is the generated canvas area. The product term matters for corners: counting
only four strips would omit the newly created corner rectangles.

Across an editing history, with `G_used` already committed, a proposed expand is
allowed only when:

```text
G_used + G <= 0.20 * W_original * H_original
```

The denominator never grows after an expand. It is the originally captured
frame, not the current expanded canvas. Rounding edge percentages to pixels is
performed before calculating `G`, so the UI and the enforced budget refer to the
same actual pixels.

The original photograph's box in the final canvas is:

```text
(x0, y0, x1, y1) = (L, T, L + W, T + H)
```

The generated-area mask is 255 outside that box and 0 inside it. The final local
paste into `(L, T)` is the hard preservation guarantee.

## One-sided seam calibration (research candidate; not implemented)

The provenance rule forbids feathering generated pixels into the captured
photograph. Any correction must be one-sided: read original pixels for a target,
but write only to generated pixels outside the boundary.

For each colour channel `c`, take an original reference strip immediately inside
the boundary (`R_c`) and the model's returned overlap for the same coordinates
(`Q_c`). A conventional affine match is:

```text
gain_c   = clamp(std(R_c) / max(std(Q_c), epsilon), gain_min, gain_max)
offset_c = mean(R_c) - gain_c * mean(Q_c)
matched_c(p) = gain_c * generated_c(p) + offset_c
```

Over an outside-only corridor of width `K`, with distance `d = 0` at the original
edge and `d = K` farther into generated space:

```text
t = clamp(d / K, 0, 1)
s = t*t*(3 - 2*t)                 # smoothstep
output = (1 - s)*matched + s*raw_generated
```

At the seam the generated side is statistically matched to the captured edge;
farther out it returns smoothly to the model output. Original pixels are never
written. This can reduce tonal/noise steps but cannot repair invented geometry.
It requires visual tests before adoption, and all gain clamps and corridor widths
must be calibrated rather than copied from a chatbot answer.

## Candidate rejection math (research; thresholds not established)

All metrics should operate in a defined colour space and normalized `[0,1]`
range. Do not bake thresholds into production until a labelled test set includes
sky, foliage, architecture, water, skin, low light and film grain.

### Unpainted neutral fill

For generated pixels `P` and the prefill `F = 128/255`:

```text
MSE_fill = mean((P - F)^2)
```

A very low value indicates the model left the neutral seed substantially
untouched. Calibration should use known failed and successful outputs; Gemini's
chat response lost its proposed numeric threshold during equation rendering, so
no numeric value from that response is authoritative.

### Axis-smear / stripe detection

Let `D_axis(P)` be finite differences along the expansion direction and
`D_cross(P)` differences parallel to the seam:

```text
E_axis  = mean(abs(D_axis(P)))
E_cross = mean(abs(D_cross(P)))
ratio   = E_axis / max(E_cross, epsilon)
```

Extremely low or high ratios can flag stretched bands or pathological texture,
but legitimate horizons, siding and other directional scenes make this a warning
metric rather than a universal verdict.

### Boundary discontinuity

Let `O0` be the last original row/column, `O1` the preceding original row/column,
and `G0` the first generated row/column:

```text
seam_step     = mean(abs(G0 - O0))
interior_step = mean(abs(O0 - O1))
seam_ratio    = seam_step / max(interior_step, epsilon)
```

Gemini suggested, heuristically, rejecting around `seam_step > 15/255` or
`seam_ratio > 3`. Those numbers are unverified starting points only. A curb can
be geometrically wrong while still passing a mean-colour test, so acceptance
cannot rely on this metric alone.

## Google route requiring independent verification

Gemini identified Vertex AI Imagen editing (`edit_image`, an outpaint edit mode,
and a separate black/white mask) as Google's first-class masked route and said it
requires Google Cloud credentials rather than the existing Gemini API key. It
also claimed lifecycle/deprecation concerns around standalone Imagen endpoints.
Both the exact current model IDs and lifecycle claim must be checked against
current official Google Cloud documentation before implementation. Do not build
new credential infrastructure from this conversation alone.

<!-- ===== SNAPSMACK EOF ===== -->
