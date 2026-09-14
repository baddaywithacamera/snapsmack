"""External Diffusers runner for SNAP SLAPPER Local Generative Fill.

This file is copied into the separately installed local-AI environment. It is
not imported by the frozen SNAP SLAPPER process.
"""

import argparse
import json
import os

from PIL import Image

MODEL = "stable-diffusion-v1-5/stable-diffusion-inpainting"


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
    result = pipe(prompt=prompt, negative_prompt=negative, image=image,
                  mask_image=mask, num_inference_steps=30,
                  guidance_scale=7.0).images[0]
    result.save(args.output, "PNG")
    print(json.dumps({"ok": True, "model": MODEL, "output": args.output}))


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
