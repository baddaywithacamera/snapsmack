#!/usr/bin/env bash

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


# ─────────────────────────────────────────────────────────────────────────────
#  Smack Up Your Backup — build script (Git Bash on Windows / Linux / macOS)
#  Requires: Python 3.11+, pip install -r requirements.txt
#  Output:   dist/suyb/suyb.exe   (onedir, per smackupyourbackup.spec)
#  Installs: /c/snapsmack/suyb — the exe and its config.ini live together in
#            this stable folder so settings persist. Not $HOME, not %APPDATA%.
#
#  SS-1419. Two things were wrong here and the same two were wrong in
#  build.bat:
#
#    1. It bumped the version itself, so running the build was enough to
#       invent a release number the changelog had never heard of.
#    2. It built with raw --onefile flags instead of smackupyourbackup.spec
#       and then looked for dist/smackupyourbackup-<ver>. Its own comment
#       admitted the consequence: an exe built this way does not bundle
#       tools/_shared, so it falls back to next-to-exe config instead of the
#       shared C:\snapsmack layout. It produced a knowingly-wrong artifact
#       and called the result a successful build.
#
#  Both are fixed by deferring to the same two things build.bat uses: the
#  spec for WHAT to build, and release_guard.py for the judgements. The
#  judgements have tests; this file moves files.
#
#  NOT EXERCISED: no Linux or macOS build has been run against this file.
#  The Windows path (build.bat) is the one in use.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

PY="${PYTHON:-python3}"
INSTALL_DIR="${SUYB_INSTALL_DIR:-/c/snapsmack/suyb}"
INSTALLED_EXE="${INSTALL_DIR}/suyb.exe"

# ── The version is READ, not invented. release_guard.py refuses unless
#    CHANGELOG.md has an entry for it.
if ! BUILD_VER="$("${PY}" release_guard.py release-version)"; then
    echo ""
    echo "ABORTED: no release version. Nothing was built and nothing was installed."
    exit 1
fi
export SUYB_BUILD_VER="${BUILD_VER}"
echo "Build version: ${BUILD_VER}  (declared in _version.py, matched in CHANGELOG.md)"

# ── Refuse early if the installed copy is busy. Checking after a build wastes
#    the build; checking before costs nothing.
if ! "${PY}" release_guard.py check-idle "${INSTALLED_EXE}"; then
    echo ""
    echo "ABORTED before building. Close SMACK UP YOUR BACKUP and run this again."
    exit 1
fi

# ── Clean stale build artifacts ───────────────────────────────────────────────
rm -rf build dist

echo "Installing dependencies..."
"${PY}" -m pip install -r requirements.txt

echo ""
echo "Building onedir ${BUILD_VER} using smackupyourbackup.spec..."
pyinstaller --clean smackupyourbackup.spec

# ── Verify the output the spec ACTUALLY produces ──────────────────────────────
BUILT_EXE="$("${PY}" release_guard.py expected-exe dist)"
if [ ! -f "${BUILT_EXE}" ]; then
    echo ""
    echo "BUILD FAILED: pyinstaller reported success but ${BUILT_EXE} is not there."
    echo "Nothing was staged and nothing was installed."
    exit 1
fi
BUILT_SHA="$("${PY}" release_guard.py sha256 "${BUILT_EXE}")"
echo "Built:  ${BUILT_EXE}"
echo "SHA256: ${BUILT_SHA}"

# ── Stage outside the repo, so the artifact that gets installed is the one
#    that was verified and it survives the next clean.
STAMP="$(date +%Y%m%d-%H%M%S)"
STAGE_DIR="${SUYB_STAGE_ROOT:-/c/dev/builds}/suyb-${BUILD_VER}-${STAMP}"
echo "Staging to ${STAGE_DIR} ..."
mkdir -p "${STAGE_DIR}"
cp -R dist/suyb "${STAGE_DIR}/suyb"

# ── Ask again. The build takes minutes and SUYB may have been opened in them.
if ! "${PY}" release_guard.py check-idle "${INSTALLED_EXE}"; then
    echo ""
    echo "The build is staged at ${STAGE_DIR} and was NOT installed."
    echo "Close SMACK UP YOUR BACKUP and install the staged folder, or re-run this."
    exit 1
fi

# ── Keep the installed build as a rollback BEFORE overwriting it ──────────────
if [ -d "${INSTALL_DIR}" ]; then
    BACKUP_DIR="${SUYB_STAGE_ROOT:-/c/dev/builds}/suyb-rollback-${STAMP}"
    echo "Preserving the installed build at ${BACKUP_DIR} ..."
    cp -R "${INSTALL_DIR}" "${BACKUP_DIR}"
fi

echo "Installing to ${INSTALL_DIR} ..."
mkdir -p "${INSTALL_DIR}"
cp -R "${STAGE_DIR}/suyb/." "${INSTALL_DIR}/"
chmod +x "${INSTALLED_EXE}" 2>/dev/null || true

LIVE_SHA="$("${PY}" release_guard.py sha256 "${INSTALLED_EXE}")"
echo ""
echo "Installed: ${INSTALLED_EXE}"
echo "SHA256:    ${LIVE_SHA}"
if [ "${LIVE_SHA}" != "${BUILT_SHA}" ]; then
    echo ""
    echo "WARNING: the installed exe does not match what was built."
    echo "  built:     ${BUILT_SHA}"
    echo "  installed: ${LIVE_SHA}"
    exit 1
fi
echo "Matches the staged build."
if [ -n "${BACKUP_DIR:-}" ]; then
    echo "Rollback copy: ${BACKUP_DIR}"
fi
echo "Done. Launch: ${INSTALLED_EXE}"
# ===== SNAPSMACK EOF =====
