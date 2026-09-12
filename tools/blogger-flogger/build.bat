@echo off
setlocal
cd /d "%~dp0"
C:\dev\snapsmack\.python-build\python.exe -m unittest discover -s tests
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\Scripts\pyinstaller.exe --clean blogger-flogger.spec
if errorlevel 1 exit /b 1
if not exist C:\snapsmack\blogger-flogger mkdir C:\snapsmack\blogger-flogger
copy /Y dist\blogger-flogger-0.1.0.exe C:\snapsmack\blogger-flogger\blogger-flogger.exe
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py C:\snapsmack\blogger-flogger\blogger-flogger.exe
if errorlevel 1 exit /b 1
echo BLOGGER FLOGGER built successfully.
endlocal
