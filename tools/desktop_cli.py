"""Source entry point for desktop automation. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import subprocess
import sys

APPS = {"gyss": "gyss", "sybu": "sybu", "slapper": "hub", "lewks": "hub",
        "coldsnap": "coldsnap", "shots-fired": "shots-fired", "suyb": "smack-up-your-backup",
        "smackpress": "smackpress"}


def main():
    if len(sys.argv) < 2 or sys.argv[1] in ("--help", "-h"):
        print("Usage: python tools/desktop_cli.py APP COMMAND [options]\nApps: " + ", ".join([*APPS, "ohsnap"]))
        return 0
    app = sys.argv[1]
    root = Path(__file__).resolve().parent
    if app == "ohsnap":
        return subprocess.call(["node", str(root / "oh-snap/scripts/cli.cjs"), *sys.argv[2:]])
    if app not in APPS:
        print("Unknown desktop app", file=sys.stderr); return 2
    # Separate process keeps legacy names such as config/_version isolated.
    return subprocess.call([sys.executable, str(root / APPS[app] / "app_cli.py"), *sys.argv[2:]])


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
