"""Out-of-process Luminance HDR integration.

Luminance HDR is never bundled.  This module only discovers a user-installed
CLI and constructs an argument vector; no shell is involved.
"""

import os
import shutil


def find_luminance_hdr(saved_path=""):
    """Return the separately installed Luminance HDR CLI, or an empty string."""
    names = ("luminance-hdr-cli.exe",) if os.name == "nt" else ("luminance-hdr-cli",)
    candidates = []
    if saved_path:
        candidates.append(saved_path)
    for name in names:
        found = shutil.which(name)
        if found:
            candidates.append(found)
    if os.name == "nt":
        for variable in ("ProgramFiles", "ProgramFiles(x86)"):
            root = os.environ.get(variable, "")
            if root:
                candidates.extend((
                    os.path.join(root, "Luminance HDR", "luminance-hdr-cli.exe"),
                    os.path.join(root, "LuminanceHDR", "luminance-hdr-cli.exe"),
                ))
    else:
        candidates.extend(("/usr/bin/luminance-hdr-cli",
                           "/usr/local/bin/luminance-hdr-cli",
                           "/snap/bin/luminance-hdr-cli"))
    for candidate in candidates:
        absolute = os.path.abspath(os.path.expanduser(candidate))
        if os.path.isfile(absolute):
            return absolute
    return ""


def output_paths(folder, stem):
    safe = "".join(c if c.isalnum() or c in "-_" else "_" for c in stem).strip("_")
    safe = safe or "snap-hdr"
    return (os.path.abspath(os.path.join(folder, safe + "-master.exr")),
            os.path.abspath(os.path.join(folder, safe + "-16bit.tif")))


def build_command(executable, inputs, hdr_path, tiff_path, *, align="AIS",
                  deghost=0.0, weight="triangular", response="linear",
                  model="debevec", operator="mantiuk06", gamma=1.0,
                  saturation=1.0, detail=1.0, autolevels=False):
    """Build a validated Luminance HDR CLI argv for merge and tone mapping."""
    if len(inputs) < 2:
        raise ValueError("HDR processing requires at least two bracketed photographs")
    allowed = {
        "align": {"AIS", "MTB", "none"},
        "weight": {"triangular", "gaussian", "plateau", "flat"},
        "response": {"linear", "gamma", "log", "srgb"},
        "model": {"robertson", "robertsonauto", "debevec"},
        "operator": {"ashikhmin", "drago", "durand", "fattal", "ferradans",
                     "pattanaik", "reinhard02", "reinhard05", "mai", "mantiuk06",
                     "mantiuk08"},
    }
    values = {"align": align, "weight": weight, "response": response,
              "model": model, "operator": operator}
    for key, value in values.items():
        if value not in allowed[key]:
            raise ValueError(f"Unsupported HDR {key}: {value}")
    argv = [os.path.abspath(executable)]
    if align != "none":
        argv += ["--align", align]
    if float(deghost) > 0:
        argv += ["--autoag", f"{min(1.0, max(0.0, float(deghost))):.2f}"]
    argv += ["--hdrWeight", weight, "--hdrResponseCurve", response,
             "--hdrModel", model, "--save", os.path.abspath(hdr_path),
             "--gamma", f"{float(gamma):.2f}", "--tmo", operator,
             "--output", os.path.abspath(tiff_path), "--ldrTiff", "16b",
             "--ldrTiffDeflate", "true"]
    if autolevels:
        argv.append("--autolevels")
    if operator == "mantiuk06":
        argv += ["--tmoM06Saturation", f"{float(saturation):.2f}",
                 "--tmoM06Detail", f"{float(detail):.2f}"]
    argv.extend(os.path.abspath(path) for path in inputs)
    return argv

