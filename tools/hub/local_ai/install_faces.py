"""Console installer for SNAP SLAPPER's separately installed People component.

People (face finding) needs OpenCV, which is 82 MB of library on its own and
would more than double SNAP SLAPPER's 73 MB download for everybody, including
the photographers who never use it. So it installs here instead, once, for
those who want it -- the same arrangement as Local Generative Fill.

Nothing here contacts anything but PyPI and OpenCV's model zoo, and the two
model files are checked against pinned hashes by slapper_faces itself.
"""

import os
import shutil
import subprocess
import sys


def run(args):
    subprocess.run(args, check=True)


def main():
    if len(sys.argv) != 4:
        raise SystemExit(
            "usage: install_faces.py INSTALL_ROOT RUNNER_SOURCE ENGINE_SOURCE")
    root, runner_source, engine_source = map(os.path.abspath, sys.argv[1:])
    os.makedirs(root, exist_ok=True)
    venv = os.path.join(root, "venv")
    python = os.path.join(venv, "Scripts", "python.exe")
    if not os.path.isfile(python):
        print("Creating the separate People environment…", flush=True)
        run([sys.executable, "-m", "venv", venv])
    run([python, "-m", "pip", "install", "--upgrade", "pip"])
    # Headless: the face models need OpenCV's own runtime, not its window
    # toolkit, and headless is the smaller of the two builds.
    run([python, "-m", "pip", "install", "opencv-python-headless>=4.8.0",
         "numpy"])
    runner = os.path.join(root, "faces_runner.py")
    shutil.copy2(runner_source, runner)
    # The detector itself travels with the runner so there is one
    # implementation, not a copy here that drifts from the application's.
    shutil.copy2(engine_source, os.path.join(root, "slapper_faces.py"))
    print("Downloading and checking the two face models…", flush=True)
    run([python, runner, "--warmup", "--models", root])
    with open(os.path.join(root, "installed.txt"), "w", encoding="utf-8") as handle:
        handle.write("yunet+sface via opencv-python-headless\n")
    print("\nPeople is installed. Close this window, then reopen SNAP SLAPPER's "
          "library.", flush=True)
    input("Press Enter to close…")


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
