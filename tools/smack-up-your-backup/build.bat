@echo off
setlocal enabledelayedexpansion
REM ─────────────────────────────────────────────────────────────────────────
REM  Smack Up Your Backup — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   dist\suyb\suyb.exe   (onedir, per smackupyourbackup.spec)
REM  Installs: C:\snapsmack\suyb\   — the exe and its config.ini live together
REM            in this stable folder so settings persist. Never %APPDATA%.
REM
REM  SS-1419. This script used to bump the version itself and then look for a
REM  one-file exe the spec had stopped producing, so every SUCCESSFUL build
REM  printed "Build FAILED". Both judgements now live in release_guard.py,
REM  which has tests; this file does the moving of files and nothing clever.
REM ─────────────────────────────────────────────────────────────────────────

set PY=python
set INSTALL_DIR=C:\snapsmack\suyb
set INSTALLED_EXE=%INSTALL_DIR%\suyb.exe

REM ── The version is READ, not invented. Refuses unless CHANGELOG.md has an
REM    entry for it, because a build nobody wrote down is a build nobody
REM    decided to make.
for /f "delims=" %%V in ('%PY% release_guard.py release-version') do set BUILD_VER=%%V
if "%BUILD_VER%"=="" (
    %PY% release_guard.py release-version
    echo.
    echo ABORTED: no release version. Nothing was built and nothing was installed.
    pause
    exit /b 1
)
echo Build version: %BUILD_VER%  ^(declared in _version.py, matched in CHANGELOG.md^)

REM ── Refuse early if the installed copy is busy. Checking this AFTER a build
REM    wastes the build; checking it before costs nothing. Codex hit exactly
REM    this on 2026-11-18: the installed suyb.exe was running and may have
REM    been part-way through a backup.
%PY% release_guard.py check-idle "%INSTALLED_EXE%"
if errorlevel 1 (
    echo.
    echo ABORTED before building. Close SMACK UP YOUR BACKUP and run this again.
    pause
    exit /b 1
)

REM ── Clean stale build artifacts (prevents OneDrive / AV lock errors) ──────
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist

echo Installing dependencies...
%PY% -m pip install -r requirements.txt
if errorlevel 1 (
    echo ABORTED: dependency install failed. Nothing was built.
    pause
    exit /b 1
)

set SUYB_BUILD_VER=%BUILD_VER%
echo.
echo Building onedir %BUILD_VER% using smackupyourbackup.spec...
pyinstaller --clean smackupyourbackup.spec
if errorlevel 1 (
    echo.
    echo BUILD FAILED. Nothing was staged and nothing was installed.
    pause
    exit /b 1
)

REM ── Verify the output the spec ACTUALLY produces ──────────────────────────
for /f "delims=" %%E in ('%PY% release_guard.py expected-exe dist') do set BUILT_EXE=%%E
if not exist "%BUILT_EXE%" (
    echo.
    echo BUILD FAILED: pyinstaller reported success but %BUILT_EXE% is not there.
    echo Nothing was staged and nothing was installed.
    pause
    exit /b 1
)
for /f "delims=" %%H in ('%PY% release_guard.py sha256 "%BUILT_EXE%"') do set BUILT_SHA=%%H
echo Built:  %BUILT_EXE%
echo SHA256: %BUILT_SHA%

REM ── Stage the whole onedir folder outside the repo, so the artifact that
REM    gets installed is the one that was verified, and it survives the next
REM    "rmdir /s /q dist".
for /f "delims=" %%S in ('%PY% -c "import time;print(time.strftime('%%Y%%m%%d-%%H%%M%%S'))"') do set STAMP=%%S
set STAGE_DIR=C:\dev\builds\suyb-%BUILD_VER%-%STAMP%
echo Staging to %STAGE_DIR% ...
mkdir "%STAGE_DIR%" 2>nul
xcopy /E /I /Q /Y dist\suyb "%STAGE_DIR%\suyb" >nul
if errorlevel 1 (
    echo ABORTED: could not stage the build. Nothing was installed.
    pause
    exit /b 1
)

REM ── Ask again. The build takes minutes and SUYB may have been opened in
REM    them; the first check is for the operator's time, this one is the one
REM    that protects a backup in flight.
%PY% release_guard.py check-idle "%INSTALLED_EXE%"
if errorlevel 1 (
    echo.
    echo The build is staged at %STAGE_DIR% and was NOT installed.
    echo Close SMACK UP YOUR BACKUP and run install-staged.bat, or re-run this.
    pause
    exit /b 1
)

REM ── Keep the installed build as a rollback BEFORE overwriting it ──────────
if exist "%INSTALL_DIR%" (
    set BACKUP_DIR=C:\dev\builds\suyb-rollback-%STAMP%
    echo Preserving the installed build at !BACKUP_DIR! ...
    xcopy /E /I /Q /Y "%INSTALL_DIR%" "!BACKUP_DIR!" >nul
    if errorlevel 1 (
        echo ABORTED: could not preserve the installed build. Nothing was installed.
        pause
        exit /b 1
    )
)

echo Installing to %INSTALL_DIR% ...
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"
xcopy /E /I /Q /Y "%STAGE_DIR%\suyb" "%INSTALL_DIR%" >nul
if errorlevel 1 (
    echo INSTALL FAILED. The previous build is at !BACKUP_DIR! .
    pause
    exit /b 1
)

REM ── Trust the exact file that is now installed, not the one that was built.
C:\dev\snapsmack\.python-build\python.exe ..\hub\trust-installed-exe.py "%INSTALLED_EXE%"
if errorlevel 1 (
    echo WARNING: could not record trust for %INSTALLED_EXE%.
    pause
    exit /b 1
)

for /f "delims=" %%H in ('%PY% release_guard.py sha256 "%INSTALLED_EXE%"') do set LIVE_SHA=%%H
echo.
echo Installed: %INSTALLED_EXE%
echo SHA256:    %LIVE_SHA%
if /I not "%LIVE_SHA%"=="%BUILT_SHA%" (
    echo.
    echo WARNING: the installed exe does not match what was built.
    echo   built:     %BUILT_SHA%
    echo   installed: %LIVE_SHA%
    pause
    exit /b 1
)
echo Matches the staged build. Rollback copy: C:\dev\builds\suyb-rollback-%STAMP%
echo Done. Launch: %INSTALLED_EXE%
pause
