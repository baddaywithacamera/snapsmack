#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  Smack Up Your Backup — build script (macOS / Linux)
#  Requires: Python 3.11+, pip install -r requirements.txt
#  Output:   ~/SmackUpYourBackup/smackupyourbackup-{version}
# ─────────────────────────────────────────────────────────────────────────────
set -e

# ── Read BUILD_VERSION from main.py ──────────────────────────────────────────
BUILD_VER=$(python3 -c "
import re, sys
m = re.search(r'BUILD_VERSION\s*=\s*\"([^\"]+)\"', open('main.py').read())
print(m.group(1) if m else sys.exit(1))
")
EXE_NAME="smackupyourbackup-${BUILD_VER}"
echo "Build version: ${BUILD_VER}"
echo "Output name:   ${EXE_NAME}"

# ── Clean stale build artifacts ───────────────────────────────────────────────
if [ -d build ]; then
    echo "Cleaning previous build folder..."
    rm -rf build
fi
if [ -d dist ]; then
    echo "Cleaning previous dist folder..."
    rm -rf dist
fi

echo "Installing dependencies..."
pip3 install -r requirements.txt

echo ""
echo "Building ${EXE_NAME}..."
pyinstaller \
    --onefile \
    --windowed \
    --clean \
    --name "${EXE_NAME}" \
    --hidden-import=tkinter \
    --hidden-import=tkinter.ttk \
    --hidden-import=tkinter.filedialog \
    --hidden-import=tkinter.messagebox \
    --hidden-import=requests \
    --hidden-import=googleapiclient \
    --hidden-import=google.auth \
    --hidden-import=google.auth.transport.requests \
    --hidden-import=google.oauth2.credentials \
    --hidden-import=google_auth_oauthlib.flow \
    --hidden-import=googleapiclient.discovery \
    --hidden-import=googleapiclient.http \
    main.py

echo ""
DEPLOY_DIR="${HOME}/SmackUpYourBackup"
if [ -f "dist/${EXE_NAME}" ]; then
    echo "Build successful: dist/${EXE_NAME}"
    echo ""
    echo "Deploying to ${DEPLOY_DIR}..."
    mkdir -p "${DEPLOY_DIR}"
    cp -f "dist/${EXE_NAME}" "${DEPLOY_DIR}/${EXE_NAME}"
    chmod +x "${DEPLOY_DIR}/${EXE_NAME}"
    echo "Done. Launch: ${DEPLOY_DIR}/${EXE_NAME}"
else
    echo "Build FAILED. Check output above for errors."
    exit 1
fi
