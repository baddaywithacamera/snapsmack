@echo off
setlocal
cd /d "%~dp0"
C:\dev\snapsmack\.python-build\python.exe -m unittest discover -s tests
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\Scripts\pyinstaller.exe --clean fed-up.spec
if errorlevel 1 exit /b 1
if not exist C:\snapsmack\fed-up mkdir C:\snapsmack\fed-up
REM COLLECT DIRECTORY, not a single exe (SS-1195). /E copies the whole thing —
REM the Qt DLLs and licenses\ included; copying only the exe would leave
REM something that cannot start. The exe is still named fed-up.exe and still
REM lands at C:\snapsmack\fed-up\fed-up.exe, which is what
REM tools\hub\qt_main.py launches.
xcopy /E /I /Y "dist\fed-up\*" "C:\snapsmack\fed-up\"
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py C:\snapsmack\fed-up\fed-up.exe
if errorlevel 1 exit /b 1
echo FED UP built successfully.
endlocal
