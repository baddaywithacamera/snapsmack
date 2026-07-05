@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Up Your Backup — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\SmackUpYourBackup\smackupyourbackup-{version}.exe
REM ─────────────────────────────────────────────────────────────────────────

REM ── Auto-increment the patch version in main.py, then read it back ────────
REM  bump_version.py bumps BUILD_VERSION and prints the new value on stdout.
for /f "delims=" %%V in ('python bump_version.py') do set BUILD_VER=%%V
if "%BUILD_VER%"=="" (
    echo ERROR: version bump failed ^(bump_version.py^). Aborting.
    pause
    exit /b 1
)
set EXE_NAME=smackupyourbackup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%  ^(auto-incremented^)
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

REM ── Build with the single generic spec (version comes from the env var) ──────
set SUYB_BUILD_VER=%BUILD_VER%
echo.
echo Building %EXE_NAME% using smackupyourbackup.spec...
pyinstaller --clean smackupyourbackup.spec

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo.
    echo Deploying to C:\SmackUpYourBackup...
    if not exist C:\SmackUpYourBackup mkdir C:\SmackUpYourBackup
    copy /Y dist\%EXE_NAME% C:\SmackUpYourBackup\%EXE_NAME%
    echo Done. Launch: C:\SmackUpYourBackup\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
