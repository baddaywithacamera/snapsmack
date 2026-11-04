@echo off
REM Always resolve relative paths from this script's directory. SNAP HQ and
REM repository-root invocations otherwise look for bump_version.py/spec files
REM in the caller's working directory and abort before producing an EXE.
pushd "%~dp0"
if errorlevel 1 exit /b 1
REM ─────────────────────────────────────────────────────────────────────────
REM  COLD SNAP — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\coldsnap\coldsnap.exe
REM  UPX is disabled in the spec — builds finish in 2-5 min, not an hour.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Auto-increment BUILD_VERSION (skip for debug rebuilds: build.bat norev) ─
if /I "%~1"=="norev" (
    echo Skipping version bump ^(norev^) — rebuilding current version.
) else (
    echo Bumping build version...
    python bump_version.py
    if errorlevel 1 (
        echo ERROR: version bump failed. Aborting build.
        pause
        exit /b 1
    )
)

REM ── Read BUILD_VERSION from _version.py (single source of truth) ──────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " _version.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=coldsnap.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Single fixed spec (COLD SNAP — the Qt shell IS coldsnap now; Sean,
REM    2026-09-06: "WE ARE NOT GOING BACK TO THE OLD VERSION IN ANY FASHION").
REM    The spec auto-bundles every local .py + the coldsnap_qt package.
set SPEC_FILE=coldsnap-qt.spec
if not exist %SPEC_FILE% (
    echo ERROR: Spec file %SPEC_FILE% not found.
    pause
    exit /b 1
)

REM ── Clean stale build artifacts (prevents OneDrive / AV lock errors) ──────
if exist build (
    echo Cleaning previous build folder...
    rmdir /s /q build
)
if exist dist (
    echo Cleaning previous dist folder...
    rmdir /s /q dist
)

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building %EXE_NAME%...
REM COLLECT DIRECTORY now, not a single exe (SS-1195). It is built LOCALLY and
REM copied, not written straight into the install directory: PyInstaller puts a
REM COLLECT build in <distpath>\<name>\, so the old --distpath would have made
REM C:\snapsmack\coldsnap\coldsnap\coldsnap.exe and SNAP HQ (tools\hub\main.py)
REM would no longer find it. Building locally first also means a failed build
REM cannot half-overwrite a working install, which this script's own message
REM already claimed.
pyinstaller --clean %SPEC_FILE%
if errorlevel 1 (
    echo ERROR: PyInstaller failed. The existing executable was NOT replaced.
    popd
    exit /b 1
)

echo.
if not exist "dist\coldsnap\%EXE_NAME%" (
    echo Build FAILED - no exe in dist\coldsnap. Install NOT touched.
    pause
    exit /b 1
)
if not exist C:\snapsmack\coldsnap mkdir C:\snapsmack\coldsnap
REM /E copies the whole directory - Qt DLLs and licenses\ included. Copying
REM only the exe would leave something that cannot start.
xcopy /E /I /Y "dist\coldsnap\*" "C:\snapsmack\coldsnap\"
if errorlevel 1 (
    echo Deploy FAILED.
    pause
    exit /b 1
)

if exist "C:\snapsmack\coldsnap\%EXE_NAME%" (
    echo Build successful: C:\snapsmack\coldsnap\%EXE_NAME%
    echo Done. Launch: C:\snapsmack\coldsnap\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)

popd
