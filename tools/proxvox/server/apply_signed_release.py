"""Apply one published SnapSmack release through an authorized PROX VOX shell.

# SNAPSMACK_EOF_HEADER
Stage this program, its PHP companion and the release manifest outside the
document root. Run as the site's web account with a private backup directory.
"""

import argparse
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import subprocess
import tempfile
import time
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
import uuid
import zipfile


VERSION = re.compile(r"0\.7\.[0-9]+D")
DOMAIN = re.compile(r"[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?")
SIGNATURE_SIDE_EFFECTS = (
    ".htaccess", ".user.ini", "core/release-pubkey.php",
    "core/constants.php", "core/.snapsmack-installer-state.php", "install.php", "setup.php", "fedup.php",
    "llms.txt", "robots.txt", "security.txt", ".well-known/security.txt",
)
RUNTIME_ROOTS = {"data", "backups", "img_uploads", "media_assets", "logs", "cache"}


def approved_path(site, name):
    path = PurePosixPath(name)
    if (not path.parts or path.is_absolute() or "\\" in name or ":" in name
            or any(part in ("..", ".") for part in path.parts)):
        raise ValueError(f"Unexpected release path: {name!r}")
    candidate = site
    for part in path.parts:
        candidate /= part
        if candidate.is_symlink():
            raise ValueError(f"Release path traverses a symlink: {name}")
    return candidate


def php_call(program, site, domain, before, package, state, step):
    result = subprocess.run(
        ["php", "-d", "display_errors=stderr", str(program), step, domain, before, str(package), str(state)],
        cwd=site, capture_output=True, text=True, timeout=240,
    )
    if result.returncode:
        error = re.search(r"Uncaught RuntimeException: ([^\r\n]+)", result.stderr)
        raise RuntimeError(error[1] if error else f"Native updater stopped during {step}.")
    last = result.stdout.splitlines()[-1:] or [""]
    try:
        receipt = json.loads(last[0])
    except ValueError as error:
        raise RuntimeError(f"Native updater returned no {step} receipt.") from error
    if not isinstance(receipt, dict) or receipt.get("ok") is not True:
        raise RuntimeError(f"Native updater refused {step}.")
    return receipt


def retired_paths(updater):
    for name in ("FILES", "DIRS"):
        body = re.search(r"const UPDATER_DEPRECATED_" + name + r"\s*=\s*\[(.*?)\n\];", updater, re.S)
        if body is None:
            raise RuntimeError("Could not inventory the updater's retired paths.")
        yield from re.findall(r"^\s*'([^']+)'\s*=>", body[1], re.M)


def release_paths(site, package, expected_version, state):
    files = set(SIGNATURE_SIDE_EFFECTS)
    files.update(retired_paths((site / "core/updater.php").read_text(encoding="utf-8")))
    files.update(("migrations", "smackback-manifest.json"))
    with zipfile.ZipFile(package) as archive:
        constants = archive.read("core/constants.php").decode("utf-8")
        if f"define('SNAPSMACK_VERSION_SHORT', '{expected_version}')" not in constants:
            raise RuntimeError("The signed archive declares a different version.")
        files.update(retired_paths(archive.read("core/updater.php").decode("utf-8")))
        (state / "canonical.sql").write_bytes(archive.read("database/schema/snapsmack_canonical.sql"))
        for entry in archive.infolist():
            path = PurePosixPath(entry.filename)
            if stat.S_ISLNK(entry.external_attr >> 16):
                raise RuntimeError("The signed archive contains a file symlink.")
            if not path.parts or path.parts[0] in RUNTIME_ROOTS:
                raise RuntimeError("The signed archive contains a runtime-data path.")
            target = approved_path(site, entry.filename)
            if not entry.is_dir() or not target.exists():
                files.add(path.as_posix())
            for parent in path.parents:
                if parent.parts and not approved_path(site, parent.as_posix()).exists():
                    files.add(parent.as_posix())
    selected = []
    for name in sorted(files, key=lambda item: (len(PurePosixPath(item).parts), item)):
        target = approved_path(site, name)
        selected.append(target)
    return selected


def snapshot_files(site, selected, state):
    copies = state / "files"
    copies.mkdir(mode=0o700)
    inventory = {}
    for target in selected:
        name = target.relative_to(site).as_posix()
        if target.is_dir():
            shutil.copytree(target, copies / name, symlinks=True, dirs_exist_ok=True)
            inventory[name] = "directory"
        elif target.is_file():
            dest = copies / name
            dest.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            shutil.copy2(target, dest)
            inventory[name] = "file"
        elif not target.exists():
            inventory[name] = "absent"
        else:
            raise RuntimeError(f"Cannot snapshot special file {name}.")
    (state / "file-inventory.json").write_text(json.dumps(inventory), encoding="utf-8")
    return inventory


def restore_files(site, state, inventory):
    copies = state / "files"
    restored_names = []
    failed = {}
    for name, kind in reversed(tuple(inventory.items())):
        try:
            target = approved_path(site, name)
            if kind == "file":
                target.parent.mkdir(parents=True, exist_ok=True)
                with tempfile.NamedTemporaryFile(dir=target.parent, delete=False) as temporary:
                    restored = Path(temporary.name)
                try:
                    shutil.copy2(copies / name, restored)
                    os.replace(restored, target)
                finally:
                    restored.unlink(missing_ok=True)
            elif kind == "directory":
                target.parent.mkdir(parents=True, exist_ok=True)
                staging = Path(tempfile.mkdtemp(dir=target.parent, prefix=".release-restore-"))
                restored = staging / "restore"
                displaced = staging / "displaced"
                try:
                    shutil.copytree(copies / name, restored, symlinks=True)
                    if target.exists():
                        os.replace(target, displaced)
                    try:
                        os.replace(restored, target)
                    except OSError:
                        if displaced.exists():
                            os.replace(displaced, target)
                        raise
                except OSError:
                    # Retain the displaced path if even moving it back failed.
                    if not displaced.exists():
                        shutil.rmtree(staging)
                    raise
                shutil.rmtree(staging)
            elif target.is_dir():
                shutil.rmtree(target)
            elif target.exists():
                target.unlink()
            restored_names.append(name)
        except (OSError, ValueError) as error:
            failed[name] = str(error)
    report = state / "file-rollback.json"
    report.write_text(json.dumps({"restored": restored_names, "failed": failed}), encoding="utf-8")
    if failed:
        raise RuntimeError(f"{len(failed)} file paths could not be restored. See {report}.")


def probe(domain):
    request = Request(
        f"https://{domain}/?proxvox_check={uuid.uuid4().hex}",
        headers={"User-Agent": "Mozilla/5.0", "Cache-Control": "no-cache"},
    )
    with urlopen(request, timeout=20) as response:
        final = urlsplit(response.geturl())
        if response.status != 200 or final.scheme != "https" or final.hostname != domain:
            raise RuntimeError("Public homepage did not return HTTP 200 on the approved host.")


def update(site, domain, before, package, manifest, state, health=probe):
    version = manifest.get("version", "")
    if not VERSION.fullmatch(version) or not VERSION.fullmatch(before):
        raise ValueError("Expected published development release versions.")
    if int(version[4:-1]) <= int(before[4:-1]):
        raise ValueError("The signed release must be newer than the current installation.")
    if not DOMAIN.fullmatch(domain) or ".." in domain:
        raise ValueError("Invalid hostname or starting version.")
    if manifest.get("download_url") != f"https://snapsmack.ca/releases/snapsmack-{version}.zip":
        raise ValueError("The manifest does not name the approved release URL.")
    if state.is_relative_to(site):
        raise ValueError("Use a private backup directory outside the document root.")
    if not isinstance(manifest.get("checksum_sha256"), str):
        raise ValueError("The published manifest is missing its checksum.")
    if not site.is_dir() or site.is_symlink() or not (site / "data").is_dir():
        raise ValueError("Site or maintenance directory is unavailable.")
    state.mkdir(mode=0o700)
    (state / "release.json").write_text(json.dumps(manifest), encoding="utf-8")
    program = Path(__file__).with_name("apply-signed-release.php").resolve()

    def step(name):
        return php_call(program, site, domain, before, package, state, name)

    step("verify")
    files = release_paths(site, package, version, state)
    lock = approved_path(site, "data/maintenance.lock")
    owner = uuid.uuid4().hex

    def lock_content():
        return json.dumps({"since": int(time.time()), "reason": "update", "site_name": domain, "owner": owner})

    def holds_lock():
        return lock.is_file() and json.loads(lock.read_text(encoding="utf-8")).get("owner") == owner

    def hold():
        with lock.open("x", encoding="utf-8") as handle:
            handle.write(lock_content())

    def release():
        if holds_lock():
            lock.unlink()

    def refresh():
        if not holds_lock():
            raise RuntimeError("The update lock is absent or belongs to another operation.")
        # The CMS expires an abandoned lock after five minutes. Each native
        # operation has a shorter timeout, so refresh between the phases.
        lock.write_text(lock_content(), encoding="utf-8")

    hold()
    changed = False
    try:
        inventory = snapshot_files(site, files, state)
        refresh()
        step("backup")
        refresh()
        changed = True
        extracted = step("extract")
        refresh()
        finalized = step("finalize")
        receipt = step("inspect")
        release()
        health(domain)
        return {"site": domain, "version": receipt["version"], "http": 200, "backup": str(state),
                "files": extracted["files"], "migrations": finalized["migrations"]}
    except Exception as error:
        if not changed:
            release()
            raise
        try:
            if not lock.exists():
                hold()
            refresh()
            restore_files(site, state, inventory)
            refresh()
            step("restore")
        except Exception as rollback_error:
            raise RuntimeError(f"{domain} update failed. Rollback incomplete: {rollback_error}. Backup: {state}") from error
        release()
        raise RuntimeError(f"{domain} update failed and was rolled back: {error}. Backup: {state}") from error


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("domain")
    parser.add_argument("before")
    parser.add_argument("package", type=Path)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("backup", type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    if not DOMAIN.fullmatch(args.domain) or ".." in args.domain:
        parser.error("invalid site hostname")
    site = Path("/var/www/sites") / args.domain
    if site.resolve() != site:
        parser.error("site path is redirected")
    manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    result = update(site, args.domain, args.before, args.package.resolve(strict=True),
                    manifest, args.backup.resolve())
    print(json.dumps(result))


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
