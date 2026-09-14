@echo off
setlocal
cd /d "%~dp0"
C:\dev\snapsmack\.python-build\python.exe -m unittest discover -s tests
if errorlevel 1 exit /b 1
C:\dev\snapsmack\.python-build\Scripts\pyinstaller.exe --clean fed-up.spec
if errorlevel 1 exit /b 1
if not exist C:\snapsmack\fed-up mkdir C:\snapsmack\fed-up
copy /Y dist\fed-up.exe C:\snapsmack\fed-up\fed-up.exe
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py C:\snapsmack\fed-up\fed-up.exe
if errorlevel 1 exit /b 1
echo FED UP built successfully.
endlocal
