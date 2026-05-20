@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Your Batch Up — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\SmackYourBatchUp\smackyourbatchup-{version}.exe
REM  UPX is disabled in the spec — builds finish in 2-5 min, not an hour.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=smackyourbatchup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Use versioned spec file ───────────────────────────────────────────────
set SPEC_FILE=smackyourbatchup-%BUILD_VER%.spec
if not exist %SPEC_FILE% (
    echo ERROR: Spec file %SPEC_FILE% not found.
    echo When bumping the version, copy and rename the previous spec file,
    echo then update the name= field inside it to match the new version.
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
if not exist C:\SmackYourBatchUp mkdir C:\SmackYourBatchUp
pyinstaller --clean %SPEC_FILE% --distpath "C:\SmackYourBatchUp"

echo.
if exist "C:\SmackYourBatchUp\%EXE_NAME%" (
    echo Build successful: C:\SmackYourBatchUp\%EXE_NAME%
    echo Done. Launch: C:\SmackYourBatchUp\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)
