"""External Diffusers runner for SNAP SLAPPER Local Generative Fill.

This file is copied into the separately installed local-AI environment. It is
not imported by the frozen SNAP SLAPPER process.
"""

import argparse
import json
import os
import sys

from PIL import Image

MODEL = "stable-diffusion-v1-5/stable-diffusion-inpainting"

STEPS = 30


def say(**fields):
    """One NDJSON line on stdout, flushed, so the caller sees it as it happens.

    SNAP SLAPPER reads these live. Without them the only honest thing the
    window could say during a multi-minute fill was "this can take several
    minutes", and the person had no way to stop it short of killing the app.
    """
    sys.stdout.write(json.dumps(fields) + chr(10))
    sys.stdout.flush()


def pipeline():
    import torch
    from diffusers import AutoPipelineForInpainting
    dtype = torch.float16 if torch.cuda.is_available() else torch.float32
    options = {"torch_dtype": dtype}
    options["cache_dir"] = os.path.join(os.path.dirname(__file__), "models")
    if torch.cuda.is_available():
        options["variant"] = "fp16"
    pipe = AutoPipelineForInpainting.from_pretrained(MODEL, **options)
    if torch.cuda.is_available():
        pipe.enable_model_cpu_offload()
    else:
        pipe.to("cpu")
    return pipe


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--warmup", action="store_true")
    parser.add_argument("--image")
    parser.add_argument("--mask")
    parser.add_argument("--output")
    parser.add_argument("--prompt", default="")
    args = parser.parse_args()
    say(stage="loading", detail="Loading the model")
    pipe = pipeline()
    if args.warmup:
        print(json.dumps({"ok": True, "model": MODEL}))
        return
    image = Image.open(args.image).convert("RGB")
    mask = Image.open(args.mask).convert("L")
    prompt = args.prompt.strip() or (
        "realistic photograph, seamlessly reconstruct the selected area from the "
        "surrounding pixels, matching texture, lighting, perspective, focus and grain")
    negative = (
        "frame, border, seam, text, watermark, illustration, painting, oversaturated, "
        "different lighting, changed subject, changed composition")
    say(stage="generating", step=0, steps=STEPS)

    def progressed(_pipe, step, _timestep, kwargs):
        # step is zero-based and fires AFTER the step, so step + 1 is how many
        # are done. Diffusers requires this callback to return the kwargs dict.
        say(stage="generating", step=min(step + 1, STEPS), steps=STEPS)
        return kwargs

    try:
        result = pipe(prompt=prompt, negative_prompt=negative, image=image,
                      mask_image=mask, num_inference_steps=STEPS,
                      guidance_scale=7.0,
                      callback_on_step_end=progressed).images[0]
    except TypeError:
        # An older diffusers has no callback_on_step_end. Losing the per-step
        # line is survivable; failing the fill over it is not. The caller
        # already treats a silent run as "working, no step count".
        say(stage="generating", detail="This diffusers build reports no steps")
        result = pipe(prompt=prompt, negative_prompt=negative, image=image,
                      mask_image=mask, num_inference_steps=STEPS,
                      guidance_scale=7.0).images[0]
    say(stage="saving")
    result.save(args.output, "PNG")
    print(json.dumps({"ok": True, "model": MODEL, "output": args.output}))


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
