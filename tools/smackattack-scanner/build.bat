@echo off
:: GOBSMACKED Scanner — build.bat
:: Produces a single-file exe via PyInstaller.
:: Run from the tools/smackattack-scanner/ directory.

set VERSION=0.1.0
set EXE_NAME=gobsmacked-scanner-%VERSION%
set OUT_DIR=C:\GobsmaskedScanner

echo.
echo  Building GOBSMACKED Scanner v%VERSION%...
echo.

pip install pyinstaller pymysql --quiet

pyinstaller ^
  --onefile ^
  --windowed ^
  --name "%EXE_NAME%" ^
  --distpath "%OUT_DIR%" ^
  --workpath build\work ^
  --specpath build ^
  main.py

echo.
if exist "%OUT_DIR%\%EXE_NAME%.exe" (
    echo  Build complete: %OUT_DIR%\%EXE_NAME%.exe
) else (
    echo  Build FAILED — check output above.
)
echo.
pause
