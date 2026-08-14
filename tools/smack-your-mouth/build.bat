@echo off
REM -------------------------------------------------------------------------
REM  SMACK YOUR MOUTH - build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\smack-your-mouth\smackmouth.exe
REM  UPX is disabled in the spec - builds finish in 2-5 min, not an hour.
REM -------------------------------------------------------------------------

REM -- Auto-increment BUILD_VERSION (skip for debug rebuilds: build.bat norev) -
if /I "%~1"=="norev" (
    echo Skipping version bump ^(norev^) - rebuilding current version.
) else (
    echo Bumping build version...
    python bump_version.py
    if errorlevel 1 (
        echo ERROR: version bump failed. Aborting build.
        pause
        exit /b 1
    )
)

REM -- Read BUILD_VERSION from main.py --------------------------------------
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=smackmouth.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM -- Single fixed spec. smackmouth.spec auto-bundles every local .py, so
REM    there is NO per-version spec to clone/rename.
set SPEC_FILE=smackmouth.spec
if not exist %SPEC_FILE% (
    echo ERROR: Spec file %SPEC_FILE% not found.
    pause
    exit /b 1
)

REM -- Clean stale build artifacts (prevents OneDrive / AV lock errors) -----
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
if not exist C:\snapsmack\smack-your-mouth mkdir C:\snapsmack\smack-your-mouth
pyinstaller --clean %SPEC_FILE% --distpath "C:\snapsmack\smack-your-mouth"

echo.
if exist "C:\snapsmack\smack-your-mouth\%EXE_NAME%" (
    echo Build successful: C:\snapsmack\smack-your-mouth\%EXE_NAME%
    echo Done. Launch: C:\snapsmack\smack-your-mouth\%EXE_NAME%
) else (
    echo Build FAILED - check output above for errors.
    pause
    exit /b 1
)
