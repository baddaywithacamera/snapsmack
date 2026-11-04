@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  UNZUCKER — build script (Qt / PySide6).  SS-1191
REM
REM  WHAT CHANGED FROM THE TKINTER BUILD, and why each one matters:
REM
REM   * It builds from unzucker.spec instead of a pyinstaller command line.
REM     The spec is where the excludes, the licence files, the SVG removal and
REM     the version resource live, and a command line cannot carry them.
REM   * COLLECT, NOT --onefile. Qt is used under the LGPLv3, which obliges us
REM     to let a recipient relink it. A onefile exe unpacks to a temp directory
REM     at run time and cannot be relinked. The output is a DIRECTORY now.
REM   * The pinned build runtime is used, not whatever "pip"/"pyinstaller" is
REM     on PATH. Building a desktop tool from an ambient interpreter is how the
REM     desktop specs drifted before.
REM   * verify_exe.py runs after the build and FAILS it on a wrong entry point
REM     or any tkinter in the payload. SYBU 0.7.67 shipped its old tkinter
REM     window after being ported; this is the check that catches that.
REM   * The version is NOT auto-incremented here any more. It lives in
REM     unzucker_core.py, the spec stamps the exe from it, and the owner
REM     decides when a number moves. The old script rewrote main.py on every
REM     build, which meant a version could change without anyone choosing it.
REM
REM  Output: dist\unzucker\unzucker.exe  (a directory, with licenses\ beside it)
REM
REM  SNAPSMACK_EOF_HEADER
REM      REM ===== SNAPSMACK EOF =====
REM ─────────────────────────────────────────────────────────────────────────

setlocal
set PY=C:\dev\snapsmack\.python-build\python.exe

if not exist "%PY%" (
    echo The pinned build runtime is missing: %PY%
    echo Run bootstrap-build-runtime.ps1 first. Not falling back to PATH.
    exit /b 1
)

REM ── Read BUILD_VERSION from the ONE place it lives ───────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " unzucker_core.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
echo Build version: %BUILD_VER%   (from unzucker_core.py)
echo.

echo Installing dependencies into the pinned runtime...
"%PY%" -m pip install -r requirements.txt
if errorlevel 1 (
    echo Dependency install FAILED.
    exit /b 1
)

echo.
echo Building from unzucker.spec ...
"%PY%" -m PyInstaller --noconfirm --clean unzucker.spec
if errorlevel 1 (
    echo Build FAILED.
    exit /b 1
)

if not exist "dist\unzucker\unzucker.exe" (
    echo Build produced no exe. Check the output above.
    exit /b 1
)

echo.
echo Verifying the exe ...
REM --no-icon-check: UNZUCKER has no icon asset anywhere in the repository.
REM tools\hub\icons\ has one for eleven tools and none for this one, and the old
REM build passed no --icon either, so the Tk exe always shipped the stock
REM PyInstaller icon. Drop this flag once the asset exists.
"%PY%" ..\_build\verify_exe.py "dist\unzucker\unzucker.exe" ^
    --entry unzucker_launcher ^
    --no-tk ^
    --version %BUILD_VER% ^
    --no-icon-check
if errorlevel 1 (
    echo.
    echo VERIFY FAILED — not deploying. The exe stays in dist\ for inspection.
    exit /b 1
)

echo.
echo Deploying to C:\snapsmack\unzucker ...
if not exist C:\snapsmack\unzucker mkdir C:\snapsmack\unzucker
REM /E copies the whole COLLECT directory, Qt DLLs and licenses\ included. A
REM copy of just the exe would produce something that cannot start.
xcopy /E /I /Y "dist\unzucker\*" "C:\snapsmack\unzucker\"
if errorlevel 1 (
    echo Deploy FAILED.
    exit /b 1
)

"%PY%" ..\hub\trust-installed-exe.py C:\snapsmack\unzucker\unzucker.exe
echo.
echo Done. Launch: C:\snapsmack\unzucker\unzucker.exe
endlocal

REM ===== SNAPSMACK EOF =====
