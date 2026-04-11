@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Your Batch Up — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\SmackYourBatchUp\smackyourbatchup-{version}.exe
REM  EXIF is handled by piexif (pure Python) — no external dependencies.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=smackyourbatchup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

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
pyinstaller smackyourbatchup-%BUILD_VER%.spec

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo.
    echo Deploying to C:\SmackYourBatchUp...
    if not exist C:\SmackYourBatchUp mkdir C:\SmackYourBatchUp
    copy /Y dist\%EXE_NAME% C:\SmackYourBatchUp\%EXE_NAME%
    echo Done. Launch: C:\SmackYourBatchUp\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
