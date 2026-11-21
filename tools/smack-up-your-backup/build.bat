@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Up Your Backup — onedir build & install script (SS-1419)
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\suyb\suyb.exe (+ _internal)
REM ─────────────────────────────────────────────────────────────────────────
pushd "%~dp0"
if errorlevel 1 exit /b 1

for /f "delims=" %%V in ('python -c "import _version, pathlib, re, sys; v=_version.BUILD_VERSION; cl=pathlib.Path('CHANGELOG.md').read_text(encoding='utf-8'); sys.exit(0 if re.search(r'^##\s+'+re.escape(v)+r'\b', cl, re.M) else 1); print(v)"') do set CHECK_OK=%%V
for /f "delims=" %%V in ('python -c "import _version; print(_version.BUILD_VERSION)"') do set BUILD_VER=%%V
python -c "import _version, pathlib, re, sys; v=_version.BUILD_VERSION; cl=pathlib.Path('CHANGELOG.md').read_text(encoding='utf-8'); sys.exit(0 if re.search(r'^##\s+'+re.escape(v)+r'\b', cl, re.M) else 1)"
if errorlevel 1 (
    echo ERROR: BUILD_VERSION %BUILD_VER% has no matching "## %BUILD_VER%" entry in CHANGELOG.md.
    popd
    exit /b 1
)

echo Build version: %BUILD_VER% (verified in CHANGELOG.md)

if exist build (
    echo Cleaning previous build folder...
    rmdir /s /q build
)
if exist dist (
    echo Cleaning previous dist folder...
    rmdir /s /q dist
)

set SUYB_BUILD_VER=%BUILD_VER%
echo.
echo Building onedir dist\suyb\suyb.exe using smackupyourbackup.spec...
pyinstaller --noconfirm --clean smackupyourbackup.spec
if errorlevel 1 (
    echo ERROR: PyInstaller build failed.
    popd
    exit /b 1
)

if not exist "dist\suyb\suyb.exe" (
    echo ERROR: dist\suyb\suyb.exe was not produced.
    popd
    exit /b 1
)

echo.
echo Staging and backing up existing C:\snapsmack\suyb install...
if exist "C:\snapsmack\suyb\suyb.exe" (
    for /f "delims=" %%T in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set TS=%%T
    powershell -NoProfile -Command "Copy-Item -Recurse -Force 'C:\snapsmack\suyb' ('C:\snapsmack\suyb-backup-%BUILD_VER%-' + '%TS%'); Copy-Item -Force 'C:\snapsmack\suyb\suyb.exe' 'C:\snapsmack\suyb\suyb.exe.bak'"
)
if not exist "C:\snapsmack\suyb" mkdir "C:\snapsmack\suyb"
robocopy "dist\suyb" "C:\snapsmack\suyb" /E /NFL /NDL /NJH /NJS >nul
if errorlevel 8 (
    echo ERROR: robocopy deployment failed.
    popd
    exit /b 1
)

python ..\hub\trust-installed-exe.py "C:\snapsmack\suyb\suyb.exe"
if errorlevel 1 (
    echo ERROR: Failed to record trusted executable pin for SNAP HQ.
    popd
    exit /b 1
)

echo Build and deployment complete: C:\snapsmack\suyb\suyb.exe (v%BUILD_VER%)
popd
