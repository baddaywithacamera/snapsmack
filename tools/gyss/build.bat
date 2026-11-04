@echo off
setlocal
cd /d "%~dp0"
echo Building GET YOUR SHIT SORTED 0.7.35 with Qt...
python -m pip install -r requirements.txt
if errorlevel 1 exit /b 1
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist
pyinstaller --clean gyss.spec
if errorlevel 1 exit /b 1
if not exist "dist\gyss\GET YOUR SHIT SORTED.exe" exit /b 1
if not exist "C:\snapsmack\gyss" mkdir "C:\snapsmack\gyss"

REM THE HOT SWAP IS GONE, AND THAT IS A REAL CHANGE (SS-1195).
REM
REM This used to copy the new exe to "...exe.new" and then move it over the
REM live one, so the tool could be replaced while it was running. A COLLECT
REM build is a DIRECTORY of a hundred-odd files, and Windows cannot swap a
REM directory atomically while a file inside it is open -- so there is no
REM equivalent of that trick. Copying over a running install would half-replace
REM it: some DLLs new, the locked exe old. That is worse than refusing.
REM
REM So the build REFUSES while the tool is running, and says so, rather than
REM leaving a mixed install behind. Close it and run the build again.
tasklist /FI "IMAGENAME eq GET YOUR SHIT SORTED.exe" 2>nul | find /I "GET YOUR SHIT SORTED.exe" >nul
if not errorlevel 1 (
    echo.
    echo GET YOUR SHIT SORTED is RUNNING. Close it and build again.
    echo The install was NOT touched. A COLLECT directory cannot be swapped
    echo under a running exe the way the single file could.
    exit /b 1
)

REM /E copies the whole directory - Qt DLLs and licenses\ included. Copying
REM only the exe would leave something that cannot start.
xcopy /E /I /Y "dist\gyss\*" "C:\snapsmack\gyss\"
if errorlevel 1 exit /b 1
if exist "C:\dev\snapsmack\.python-build\python.exe" "C:\dev\snapsmack\.python-build\python.exe" "..\hub\trust-installed-exe.py" "C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe"
echo Installed: C:\snapsmack\gyss\GET YOUR SHIT SORTED.exe
endlocal
REM ===== SNAPSMACK EOF =====
