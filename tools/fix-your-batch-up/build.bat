@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Fix Your Batch Up — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\FixYourBatchUp\fixyourbatchup-{version}.exe
REM
REM  Build paths are kept OUTSIDE OneDrive to avoid file-lock errors from
REM  OneDrive syncing mid-build (which corrupts base_library.zip etc).
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=fixyourbatchup-%BUILD_VER%.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Build outside OneDrive so sync locks can't corrupt the zip/pyc files ──
set BUILD_WORK=C:\FixYourBatchUp\build
set BUILD_DIST=C:\FixYourBatchUp\dist

REM ── Clean stale build artifacts ───────────────────────────────────────────
if exist "%BUILD_WORK%" (
    echo Cleaning previous work folder...
    rmdir /s /q "%BUILD_WORK%"
)
if exist "%BUILD_DIST%" (
    echo Cleaning previous dist folder...
    rmdir /s /q "%BUILD_DIST%"
)

echo Installing dependencies...
pip install -r requirements.txt
pip install pyinstaller

echo.
echo Building %EXE_NAME%...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name fixyourbatchup-%BUILD_VER% ^
    --workpath="%BUILD_WORK%" ^
    --distpath="%BUILD_DIST%" ^
    --paths=. ^
    --paths=..\ft-batch-poster ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=PIL ^
    --hidden-import=PIL.Image ^
    --hidden-import=PIL.ImageTk ^
    --hidden-import=cv2 ^
    --hidden-import=imagehash ^
    --hidden-import=scipy ^
    --hidden-import=scipy.fftpack ^
    --hidden-import=pywt ^
    --hidden-import=numpy ^
    --hidden-import=requests ^
    --hidden-import=googleapiclient ^
    --hidden-import=google.auth ^
    --hidden-import=google.auth.transport.requests ^
    --hidden-import=google.oauth2.credentials ^
    --hidden-import=google_auth_oauthlib.flow ^
    --hidden-import=googleapiclient.discovery ^
    --hidden-import=googleapiclient.http ^
    --hidden-import=matcher ^
    --hidden-import=local_drive ^
    --hidden-import=drive ^
    --hidden-import=multiprocessing ^
    --hidden-import=multiprocessing.pool ^
    --hidden-import=concurrent.futures ^
    --collect-all=cv2 ^
    --collect-all=imagehash ^
    main.py

echo.
if exist "%BUILD_DIST%\%EXE_NAME%" (
    echo Build successful: %BUILD_DIST%\%EXE_NAME%
    echo.
    echo Deploying to C:\FixYourBatchUp...
    copy /Y "%BUILD_DIST%\%EXE_NAME%" "C:\FixYourBatchUp\%EXE_NAME%"
    echo Done. Launch: C:\FixYourBatchUp\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
