@echo off
REM GET YOUR SHIT SORTED — Build script
REM Requires: Rust, Node.js, Tauri CLI
REM Usage: build.bat [dev|release]
REM   dev     — cargo tauri dev (hot-reload)
REM   release — cargo tauri build (produces .msi and .exe in src-tauri/target/release/bundle/)

set MODE=%1
if "%MODE%"=="" set MODE=dev

if "%MODE%"=="dev" (
    echo Starting GYSS in dev mode...
    cargo tauri dev
) else if "%MODE%"=="release" (
    echo Building GYSS release...
    cargo tauri build
    echo.
    echo Installers in: src-tauri\target\release\bundle\
) else (
    echo Unknown mode: %MODE%
    echo Usage: build.bat [dev^|release]
    exit /b 1
)
