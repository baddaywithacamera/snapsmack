@echo off
setlocal
cd /d "%~dp0"
C:\dev\snapsmack\.python-build\python.exe -m unittest discover -s tests
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\Scripts\pyinstaller.exe --clean blogger-flogger.spec
if errorlevel 1 exit /b 1
if not exist C:\snapsmack\blogger-flogger mkdir C:\snapsmack\blogger-flogger
REM COLLECT DIRECTORY, not a single exe (SS-1195). /E copies the whole thing --
REM the Qt DLLs and licenses\ included; copying only the exe would leave
REM something that cannot start.
REM
REM THE EXE IS NAMED IN THE SPEC NOW, NOT RENAMED HERE. This line used to copy
REM blogger-flogger-0.1.0.exe to blogger-flogger.exe, which is the name
REM tools\hub\qt_main.py launches. Nothing renames a file inside a COLLECT
REM directory, so the spec names it blogger-flogger and it lands correctly.
xcopy /E /I /Y "dist\blogger-flogger\*" "C:\snapsmack\blogger-flogger\"
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py C:\snapsmack\blogger-flogger\blogger-flogger.exe
if errorlevel 1 exit /b 1
echo BLOGGER FLOGGER built successfully.
endlocal
