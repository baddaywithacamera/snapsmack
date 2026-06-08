@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  Unzucker — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\tools\unzucker-{version}.exe
REM  Auto-increments the patch version in main.py on every build.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Read current BUILD_VERSION from main.py ───────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set OLD_VER=%RAW_VER:"=%

REM ── Increment patch number ────────────────────────────────────────────────
for /f "tokens=1,2,3 delims=." %%A in ("%OLD_VER%") do (
    set MAJOR=%%A
    set MINOR=%%B
    set /a PATCH=%%C+1
)
set BUILD_VER=%MAJOR%.%MINOR%.%PATCH%

REM ── Write new version back to main.py ────────────────────────────────────
powershell -Command "(Get-Content main.py) -replace 'BUILD_VERSION = \"%OLD_VER%\"', 'BUILD_VERSION = \"%BUILD_VER%\"' | Set-Content main.py -Encoding UTF8"

set EXE_NAME=unzucker-%BUILD_VER%.exe
echo Previous version: %OLD_VER%
echo Build version:    %BUILD_VER%
echo Output name:      %EXE_NAME%

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building %EXE_NAME%...
pyinstaller ^
    --onefile ^
    --windowed ^
    --clean ^
    --name unzucker-%BUILD_VER% ^
    --hidden-import=tkinter ^
    --hidden-import=tkinter.ttk ^
    --hidden-import=PIL ^
    --hidden-import=PIL.Image ^
    --hidden-import=PIL.ImageTk ^
    --hidden-import=piexif ^
    main.py

echo.
if exist dist\%EXE_NAME% (
    echo Build successful: dist\%EXE_NAME%
    echo.
    echo Deploying to C:\tools...
    copy /Y dist\%EXE_NAME% C:\tools\%EXE_NAME%
    echo Done. Launch: C:\tools\%EXE_NAME%
) else (
    echo Build FAILED. Check output above for errors.
)
pause
