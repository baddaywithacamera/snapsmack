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

REM ── Auto-generate spec if it doesn't exist for this version ─────────────
set SPEC_FILE=smackyourbatchup-%BUILD_VER%.spec
if not exist %SPEC_FILE% (
    echo Spec file not found -- generating from template...
    python -c "import re,sys; src=open('smackyourbatchup-0.7.7a-04.spec').read(); out=re.sub(r'0\.7\.7a-04',sys.argv[1],src); open(sys.argv[2],'w').write(out); print('Generated',sys.argv[2])" %BUILD_VER% %SPEC_FILE%
)

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building %EXE_NAME%...
if not exist C:\SmackYourBatchUp mkdir C:\SmackYourBatchUp
pyinstaller %SPEC_FILE% --distpath "C:\SmackYourBatchUp"

echo.
if exist C:\SmackYourBatchUp\%EXE_NAME% (
    echo Build successful: C:\SmackYourBatchUp\%EXE_NAME%
    echo Done. Launch: C:\SmackYourBatchUp\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
