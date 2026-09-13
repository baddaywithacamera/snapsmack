@echo off
setlocal
cd /d "%~dp0"
echo Building GET YOUR SHIT SORTED 0.7.17 with Qt...
python -m pip install -r requirements.txt
if errorlevel 1 exit /b 1
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist
pyinstaller --clean gyss.spec
if errorlevel 1 exit /b 1
if not exist "dist\GET YOUR SHIT SORTED.exe" exit /b 1
if not exist "C:\snapsmack\gyss" mkdir "C:\snapsmack\gyss"
copy /B /Y "dist\GET YOUR SHIT SORTED.exe" "C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe.new" >nul
if errorlevel 1 exit /b 1
move /Y "C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe.new" "C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe" >nul
if errorlevel 1 exit /b 1
if exist "C:\dev\snapsmack\.python-build\python.exe" "C:\dev\snapsmack\.python-build\python.exe" "..\hub\trust-installed-exe.py" "C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe"
echo Installed: C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe
endlocal
REM ===== SNAPSMACK EOF =====
