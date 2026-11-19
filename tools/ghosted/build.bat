@echo off
setlocal
cd /d "%~dp0"
C:\dev\snapsmack\.python-build\python.exe -m pytest tests --basetemp "%~dp0\_pytest_tmp"
if errorlevel 1 exit /b 1
if exist "%~dp0\_pytest_tmp" rmdir /s /q "%~dp0\_pytest_tmp"
C:\dev\snapsmack\.python-build\Scripts\pyinstaller.exe --clean ghosted.spec
if errorlevel 1 exit /b 1
if not exist C:\snapsmack\ghosted-stage-0.1.0 mkdir C:\snapsmack\ghosted-stage-0.1.0
copy /Y dist\ghosted-0.1.0-test.exe C:\snapsmack\ghosted-stage-0.1.0\ghosted.exe
echo GHOSTED staged successfully.
endlocal
