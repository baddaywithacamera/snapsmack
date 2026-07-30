@echo off
setlocal EnableExtensions EnableDelayedExpansion
REM GET YOUR SHIT SORTED - Build script
REM Requires: Rust and the Tauri CLI
REM Usage: build.bat [dev|release]
REM   dev     - cargo tauri dev
REM   release - build, then install the completed portable exe in C:\GYSS

set "MODE=%~1"
if "%MODE%"=="" set "MODE=dev"
set "GYSS_ROOT=%~dp0"
set "GYSS_INSTALL_DIR=C:\GYSS"
set "GYSS_BUILT_EXE=%GYSS_ROOT%src-tauri\target\release\get-your-shit-sorted.exe"
set "GYSS_INSTALLED_EXE=%GYSS_INSTALL_DIR%\GET YOUR SHIT SORTED.exe"

if /I "%MODE%"=="dev" (
    echo Starting GYSS in dev mode...
    pushd "%GYSS_ROOT%"
    cargo tauri dev
    set "BUILD_RESULT=!ERRORLEVEL!"
    popd
    exit /b !BUILD_RESULT!
) else if /I "%MODE%"=="release" (
    echo Building GYSS release...
    pushd "%GYSS_ROOT%"
    cargo tauri build
    set "BUILD_RESULT=!ERRORLEVEL!"
    popd
    if not "!BUILD_RESULT!"=="0" (
        echo GYSS build failed. Nothing was installed.
        exit /b !BUILD_RESULT!
    )
    if not exist "%GYSS_BUILT_EXE%" (
        echo Build reported success but the completed executable was not found:
        echo %GYSS_BUILT_EXE%
        exit /b 1
    )
    if not exist "%GYSS_INSTALL_DIR%" mkdir "%GYSS_INSTALL_DIR%"
    if errorlevel 1 (
        echo Could not create %GYSS_INSTALL_DIR%
        exit /b 1
    )
    copy /Y "%GYSS_BUILT_EXE%" "%GYSS_INSTALLED_EXE%" >nul
    if errorlevel 1 (
        echo Build succeeded, but installation to %GYSS_INSTALLED_EXE% failed.
        exit /b 1
    )
    echo.
    echo GYSS installed successfully:
    echo %GYSS_INSTALLED_EXE%
) else (
    echo Unknown mode: %MODE%
    echo Usage: build.bat [dev^|release]
    exit /b 1
)
