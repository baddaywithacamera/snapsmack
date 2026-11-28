"""Restore the 1007D updater helper on the 23 remaining spokes.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python SNAPSMACK EOF marker.
"""

from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
from pathlib import Path
import re
import subprocess

from server.proxvox_writer import FLEET_SITES


BASELINE = "521cf6ecab3aafb960e2c1d763531d780c7c31c63977cdec691061211a1759a9"
VERSION = re.compile(rb"(?m)^define\([ \t]*'SNAPSMACK_VERSION_SHORT'[ \t]*,[ \t]*'0\.7\.1007D'[ \t]*\);[^\r\n]*\r?\n")
HELPER = b"function snap_version_with_epoch"
TARGET = "core/constants.php"
SOURCE = Path(__file__).resolve().parent


def digest(data):
    return hashlib.sha256(data).hexdigest()


def package():
    candidate = subprocess.check_output(
        ["git", "-C", str(SOURCE), "show", "v0.7.1007D:" + TARGET],
        creationflags=subprocess.CREATE_NO_WINDOW,
    )
    if digest(candidate) != BASELINE or not VERSION.search(candidate):
        raise RuntimeError("The replacement file does not match the 0.7.1007D release.")
    match = re.search(rb"(?m)^function snap_version_with_epoch\([^\n]+\n(?:    [^\n]+\n)+\}", candidate)
    if not match:
        raise RuntimeError("The release helper is missing.")
    return match[0]


def preflight(reader, helper):
    def inspect(site):
        directory = f"/var/www/sites/{site}/"
        path = directory + TARGET
        current = reader.read_file(path, vmid=102)
        manifest = json.loads(reader.read_file(directory + "smackback-manifest.json", vmid=102))
        baseline = manifest.get("files", {}).get(TARGET, {}).get("hash")
        version = VERSION.search(current)
        if baseline != BASELINE or not version or b"function snap_version_compare" not in current:
            raise RuntimeError(f"{site} is outside the approved 0.7.1007D restore.")
        before = digest(current)
        if helper in current:
            return site, path, before, current
        if HELPER in current:
            raise RuntimeError(f"{site} has a different updater repair. Inspect it first.")
        end = version.end()
        candidate = current[:end] + b"\n" + helper + b"\n\n" + current[end:]
        check = subprocess.run([r"C:\php\php.exe", "-l"], input=candidate, capture_output=True,
                               creationflags=subprocess.CREATE_NO_WINDOW)
        if check.returncode:
            raise RuntimeError(f"{site} did not pass the PHP syntax check.")
        return site, path, before, candidate

    with ThreadPoolExecutor(max_workers=4) as pool:
        return list(pool.map(inspect, FLEET_SITES))


def restore(client, reader, writer, plan, status):
    verified = 0
    for site, path, before, candidate in plan:
        status(f"Checking {site} ({verified + 1} of {len(plan)})…")
        after = digest(candidate)
        if before != after:
            grant = client.mint_file_grant(target="102", path=path, sha256=after)
            receipt = writer.put_file("102", path, candidate, grant, before)
            if not receipt.get("verified") or not receipt.get("backup_path"):
                raise RuntimeError(f"{site} did not return a verified write and backup.")
        if digest(reader.read_file(path, vmid=102)) != after:
            raise RuntimeError(f"{site} did not match the release file after verification.")
        client.audit.record("fleet_updater_file_verified", {"site": site, "sha256": after})
        verified += 1
        status(f"Verified {verified} of {len(plan)}. Last site {site}.")
    return verified


# ===== SNAPSMACK EOF =====
