"""Console installer for SNAP SLAPPER's separately installed local fill tool."""

import os
import shutil
import subprocess
import sys


def run(args):
    subprocess.run(args, check=True)


def main():
    if len(sys.argv) != 3:
        raise SystemExit("usage: install_local_fill.py INSTALL_ROOT RUNNER_SOURCE")
    root, runner_source = map(os.path.abspath, sys.argv[1:])
    os.makedirs(root, exist_ok=True)
    venv = os.path.join(root, "venv")
    python = os.path.join(venv, "Scripts", "python.exe")
    if not os.path.isfile(python):
        print("Creating the separate Local Generative Fill environment…", flush=True)
        run([sys.executable, "-m", "venv", venv])
    run([python, "-m", "pip", "install", "--upgrade", "pip"])
    run([python, "-m", "pip", "install", "torch", "torchvision",
         "--index-url", "https://download.pytorch.org/whl/cu126"])
    run([python, "-m", "pip", "install", "diffusers==0.40.0",
         "transformers", "accelerate", "safetensors", "pillow"])
    runner = os.path.join(root, "local_fill_runner.py")
    shutil.copy2(runner_source, runner)
    print("Downloading and checking the inpainting model…", flush=True)
    run([python, runner, "--warmup"])
    with open(os.path.join(root, "installed.txt"), "w", encoding="utf-8") as handle:
        handle.write("stable-diffusion-v1-5/stable-diffusion-inpainting\n")
    print("\nLocal Generative Fill is installed. You may close this window.", flush=True)
    input("Press Enter to close…")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
