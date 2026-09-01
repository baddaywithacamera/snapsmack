@echo off
REM CRONOMETER build script. Output: C:\snapsmack\cronometer\cronometer.exe
setlocal

if /I "%~1"=="norev" (
    echo Rebuilding current version without a version bump.
) else (
    echo Bumping build version...
    python bump_version.py
    if errorlevel 1 exit /b 1
)

for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " cronometer.py') do set RAW_VER=%%V
set "BUILD_VER=%RAW_VER:"=%"
echo Building CRONOMETER %BUILD_VER%...

if not exist cronometer.spec (
    echo ERROR: cronometer.spec was not found.
    exit /b 1
)

if exist build rmdir /s /q build
if exist dist rmdir /s /q dist

python -m pip install -r requirements.txt
if errorlevel 1 exit /b 1

if not exist C:\snapsmack\cronometer mkdir C:\snapsmack\cronometer
python -m PyInstaller --noconfirm --clean cronometer.spec --distpath "C:\snapsmack\cronometer"
if errorlevel 1 exit /b 1

if not exist "C:\snapsmack\cronometer\cronometer.exe" (
    echo ERROR: packaged executable was not created.
    exit /b 1
)

echo Build successful: C:\snapsmack\cronometer\cronometer.exe
endlocal
exit /b 0
REM ===== SNAPSMACK EOF =====
