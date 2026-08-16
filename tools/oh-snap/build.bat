@echo off
REM ============================================================
REM   Oh Snap!  -  one-click build
REM   Just double-click this file. It checks you have the tools
REM   it needs, then builds the app. The window stays open at the
REM   end so you can read what happened.
REM ============================================================
setlocal
cd /d "%~dp0"

echo.
echo ==================================================
echo    Building Oh Snap!  (SnapSmack skin designer)
echo ==================================================
echo.

REM ---- Check 1: Node.js (needed to run the builder) ----
where npm >nul 2>nul
if errorlevel 1 (
    echo [MISSING] Node.js is not installed on this PC.
    echo.
    echo   What to do:
    echo   1. Go to  https://nodejs.org
    echo   2. Click the big "LTS" download button and install it (accept defaults).
    echo   3. Double-click this build file again.
    echo.
    pause
    exit /b 1
)

REM ---- Check 2: Rust (Oh Snap is built with Rust underneath) ----
where cargo >nul 2>nul
if errorlevel 1 (
    echo [MISSING] Rust is not installed on this PC.
    echo.
    echo   What to do:
    echo   1. Go to  https://rustup.rs
    echo   2. Run the installer and accept the defaults.
    echo   3. If a later step complains about "link.exe" or "MSVC", also install
    echo      "Visual Studio Build Tools" (C++), then try again.
    echo   4. Double-click this build file again.
    echo.
    pause
    exit /b 1
)

echo Both tools found. Starting the build.
echo.
echo ---- Step 1 of 2: gathering the building blocks (npm install) ----
echo (First time can take a few minutes. Leave it running.)
echo.
call npm install
if errorlevel 1 (
    echo.
    echo [STOPPED] Step 1 failed. Read the last few lines above,
    echo or copy them and send them to Claude. Nothing was built.
    echo.
    pause
    exit /b 1
)

echo.
echo ---- Step 2 of 2: building the app (this is the long one) ----
echo (Leave it alone until it says DONE. Can take several minutes.)
echo.
call npm run build
if errorlevel 1 (
    echo.
    echo [STOPPED] The build failed. Read the last few lines above,
    echo or copy them and send them to Claude.
    echo.
    pause
    exit /b 1
)

echo.
echo ==================================================
echo    DONE  -  Oh Snap! built successfully.
echo ==================================================
echo.
echo Your finished files are in this folder:
echo.
echo   Installer (to install / share it):
echo     src-tauri\target\release\bundle\
echo.
echo   The raw program (to just run it):
echo     src-tauri\target\release\
echo.
echo This window is staying open so you can read the above.
echo Close it whenever you're ready.
echo.
pause
endlocal
