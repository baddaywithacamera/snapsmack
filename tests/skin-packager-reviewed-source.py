"""Run the Skin Packager refusal and publication paths with disposable files."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from pathlib import Path
import json
import re
import shutil
import subprocess
import tempfile
from zipfile import ZipFile


repo = Path(__file__).resolve().parents[1]
target = "79193b80169693b54792f705354914c55d054ddd"
stale = "4d530582"
baseline = "tests/fixtures/skin-version-content-baseline.json"


def git_bytes(ref, name):
    return subprocess.check_output(["git", "show", f"{ref}:{name}"], cwd=repo)


def verify(root):
    app = root / "smack-central"
    app.mkdir()
    shutil.copy(repo / "smack-central/sc-skins.php", app)
    shutil.copytree(repo / "core", root / "core")
    (root / "assets").mkdir()
    shutil.copy(repo / "assets/ASSET-INVENTORY.json", root / "assets")
    (root / "releases").mkdir()
    (app / "sc-auth.php").write_text("""<?php
    define('RELEASES_DIR', dirname(__DIR__) . '/releases');
    define('RELEASES_URL', 'https://example.test/releases');
    define('SMACK_RELEASE_PRIVKEY', sodium_bin2hex(sodium_crypto_sign_secretkey(
        sodium_crypto_sign_seed_keypair(str_repeat("x", SODIUM_CRYPTO_SIGN_SEEDBYTES))
    )));
    """)
    (app / "sc-layout-top.php").write_text("")
    (app / "sc-layout-bottom.php").write_text("")
    archive = root / "source.zip"


    def create_source(ref, include_baseline=True):
        paths = subprocess.check_output(
            ["git", "ls-tree", "-r", "--name-only", ref, "--", "skins/alfred"],
            cwd=repo, text=True,
        ).splitlines()
        if include_baseline:
            paths.append(baseline)
        with ZipFile(archive, "w") as z:
            for name in paths:
                z.writestr(f"snapsmack-{target}/{name}", git_bytes(target if name == baseline else ref, name))


    runner = root / "request.php"
    runner.write_text("""<?php
    function sc_download_to_file(string $url, string $dest): bool {
        $expected = 'https://github.com/baddaywithacamera/snapsmack/archive/' . $GLOBALS['target'] . '.zip';
        if ($url !== $expected) throw new RuntimeException('Archive request was not pinned.');
        return copy($GLOBALS['argv'][3], $dest);
    }
    function sc_github_get(string $endpoint): array|false {
        $prefix = 'repos/baddaywithacamera/snapsmack/contents/';
        [$path, $query] = explode('?', substr($endpoint, strlen($prefix)), 2);
        if ($query !== 'ref=' . $GLOBALS['target']) throw new RuntimeException('Contents request was not pinned.');
        $path = rawurldecode($path);
        $zip = new ZipArchive();
        $zip->open($GLOBALS['argv'][3]);
        $archive_prefix = 'snapsmack-' . $GLOBALS['target'] . '/' . $path;
        $file = $zip->getFromName($archive_prefix);
        if ($file !== false) return ['type' => 'file', 'content' => base64_encode($file)];
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, $archive_prefix . '/')) continue;
            $parts = explode('/', substr($name, strlen($archive_prefix) + 1), 2);
            $entry = count($parts) === 1
                ? ['type' => 'file', 'size' => strlen($zip->getFromIndex($i)), 'download_url' => $name]
                : ['type' => 'dir'];
            $entries[$parts[0]] = $entry + ['name' => $parts[0]];
        }
        return $entries ? array_values($entries) : false;
    }
    function sc_http_raw(string $url): string|false {
        $zip = new ZipArchive();
        $zip->open($GLOBALS['argv'][3]);
        return $zip->getFromName($url);
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = json_decode($argv[1], true);
    $target = $argv[2];
    include __DIR__ . '/smack-central/sc-skins.php';
    """)


    def request(**values):
        cp = subprocess.run(
            ["php", str(runner), json.dumps(values), target, str(archive)],
            cwd=root, encoding="utf-8", capture_output=True,
        )
        if cp.returncode:
            raise RuntimeError(cp.stderr)
        return cp.stdout


    def fetch(single=False):
        options = {"fetch_one_skin": "1", "skin_slug": "alfred"} if single else {"fetch_skins": "1"}
        html = request(ref=target, **options)
        return re.search(r'name="tmp_key" value="([a-f0-9]{32})"', html).group(1)


    def package(run, commit=target):
        return request(package="1", ref=commit, tmp_key=run, skins=["alfred"])


    assert "Enter the full reviewed commit SHA" in request(fetch_skins="1", ref="dev")
    print("Mutable branch rejected before download.")
    create_source(target, include_baseline=False)
    assert "no readable skin version baseline" in request(fetch_skins="1", ref=target)
    assert not list((root / "releases").rglob("*.zip"))
    print("Missing baseline refused before packaging.")
    create_source(stale)
    assert "files or rendering options disagree with the reviewed baseline" in package(fetch())
    assert not list((root / "releases").rglob("*.zip"))
    print("Earlier ALFRED files refused under 1.0.29 and the reviewed baseline.")
    create_source(target)
    run = fetch()
    assert "do not belong to the selected reviewed commit" in package(run, "1" * 40)
    assert not list((root / "releases").rglob("*.zip"))
    print("A cached extraction refuses a different SHA.")
    result = package(run)
    registry = root / "releases/skins/registry.json"
    published = root / "releases/skins/alfred-1.0.29.zip"
    if not registry.is_file():
        raise RuntimeError(re.findall(r"BLOCKED[^<]*|Could not [^<]*|Failed to [^<]*", result))
    assert json.loads(registry.read_text())["skins"]["alfred"]["source_commit"] == target
    with ZipFile(published) as z:
        assert json.loads(z.read("smackback-manifest.json"))["source_commit"] == target
        assert z.read("alfred/style.css") == git_bytes(target, "skins/alfred/style.css")
    print("Reviewed files and SHA are present in the registry and package.")
    previous = published.read_bytes()
    assert "This version is already published or older" in package(fetch(single=True))
    assert published.read_bytes() == previous
    print("Both fetch paths are pinned. Duplicate version refused and package bytes preserved.")


if __name__ == "__main__":
    with tempfile.TemporaryDirectory(prefix="snapsmack-skin-package-") as scratch:
        verify(Path(scratch))

# ===== SNAPSMACK EOF =====
