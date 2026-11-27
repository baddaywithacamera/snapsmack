"""Transport one signed release action through an already authorized PROX VOX console.

# SNAPSMACK_EOF_HEADER
The caller supplies the existing pinned host-console adapter and its audit buffer.
"""

import base64
import hashlib
import json
from pathlib import Path
import re
import shlex
import uuid


FILES = ("apply-signed-release.php", "apply_signed_release.py")
BACKUP_ROOT = "/var/backups/snapsmack-release"
DEV_VERSION = re.compile(r"0\.7\.[0-9]+D")


def apply_release(client, run_shell, domain, installed, manifest, source, expected_hashes):
    """Stage the reviewed action on CT102, then run it once for one site."""
    version = manifest.get("version", "")
    if (not DEV_VERSION.fullmatch(version) or not DEV_VERSION.fullmatch(installed)
            or not re.fullmatch(r"[a-z0-9][a-z0-9.-]*\.[a-z]{2,}", domain) or ".." in domain):
        raise ValueError("Invalid site or release version.")
    release_url = f"https://snapsmack.ca/releases/snapsmack-{version}.zip"
    if manifest.get("download_url") != release_url:
        raise ValueError("The manifest points outside the published release URL.")
    payloads = {}
    for filename in FILES:
        data = (Path(source) / filename).read_bytes()
        if hashlib.sha256(data).hexdigest() != expected_hashes.get(filename):
            raise ValueError(f"The installed release action differs from its reviewed {filename}.")
        payloads[filename] = data
    payloads["release.json"] = json.dumps(manifest, separators=(",", ":")).encode()

    nonce = uuid.uuid4().hex
    temp = "/tmp/proxvox-signed-" + nonce
    parent = f"{BACKUP_ROOT}/{domain}-{version}-{nonce}"
    backup = parent + "/snapshot"
    client.audit.record("signed_site_update_attempt", {"site": domain, "release": version, "from": installed})

    def audited(command, marker):
        if not client.is_authorized():
            raise RuntimeError("The PROX VOX session expired before the command.")
        output = run_shell(command)
        if marker not in (line.strip() for line in output.splitlines()):
            failures = [line.strip() for line in output.splitlines() if "update failed" in line or "RuntimeError:" in line]
            raise RuntimeError(failures[-1][:400] if failures else "The host returned no verified command receipt.")
        return output

    try:
        audited(f"umask 077; mkdir -- {temp} && echo STAGING_READY", "STAGING_READY")
        for filename, data in payloads.items():
            encoded = base64.b64encode(data).decode("ascii")
            destination = f"{temp}/{filename}"
            for offset in range(0, len(encoded), 1300):
                chunk = encoded[offset:offset + 1300]
                audited(f"printf '%s' '{chunk}' | base64 -d >> {destination} && echo CHUNK_STAGED", "CHUNK_STAGED")
        messages = []
        for filename, data in payloads.items():
            digest = hashlib.sha256(data).hexdigest()
            messages.append(f"printf '%s  %s\\n' '{digest}' '{temp}/{filename}' | sha256sum -c --status -")
        messages.extend([
            f"pct exec 102 -- sh -c 'install -d -m 0711 {BACKUP_ROOT} && "
            f"install -d -m 0700 -o www-data -g www-data {parent} && mkdir -m 0700 {temp}'",
            *(f"pct push 102 {temp}/{name} {temp}/{name} --perms 0600" for name in payloads),
            f"pct exec 102 -- chown -R www-data:www-data -- {temp}",
        ])
        download = (
            f"umask 077; curl --fail --silent --show-error --max-time 90 "
            f"-A 'SnapSmack-Updater/{installed}' -o {temp}/package.zip {shlex.quote(release_url)}"
        )
        messages.extend([
            f"pct exec 102 -- runuser -u www-data -- sh -c {shlex.quote(download)}",
            f"pct exec 102 -- runuser -u www-data -- python3 {temp}/apply_signed_release.py "
            f"{shlex.quote(domain)} {installed} {temp}/package.zip {temp}/release.json {backup}",
            "echo PROXVOX_SIGNED_RELEASE_PASS",
        ])
        output = audited("set -e\n" + "\n".join(messages), "PROXVOX_SIGNED_RELEASE_PASS")
        receipts = [line.strip() for line in output.splitlines() if line.strip().startswith('{"site":')]
        if len(receipts) != 1:
            raise RuntimeError(f"The site returned no unambiguous update receipt. Backup: {backup}")
        receipt = json.loads(receipts[0])
        if receipt.get("site") != domain or receipt.get("version") != version or receipt.get("http") != 200:
            raise RuntimeError(f"The site update did not verify. Backup: {backup}")
        client.audit.record("signed_site_update_verified", receipt)
        return receipt
    finally:
        if client.is_authorized():
            names = " ".join(f"{temp}/{name}" for name in (*payloads, "package.zip"))
            try:
                run_shell(f"rm -f -- {names}; rmdir -- {temp}; "
                          f"pct exec 102 -- sh -c 'rm -f -- {names}; rmdir -- {temp}'")
            except Exception:
                client.audit.record("signed_site_update_cleanup_pending", {"site": domain, "stage": temp})


# ===== SNAPSMACK EOF =====
