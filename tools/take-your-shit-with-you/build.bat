@echo off
REM TAKE YOUR SHIT WITH YOU - build.bat
REM Builds a single-file Windows .exe via PyInstaller.
REM Run from the tools/take-your-shit-with-you/ directory.

echo === TAKE YOUR SHIT WITH YOU build ===

REM Run the test suite FIRST. This tool's whole promise is "nothing went
REM missing"; shipping it without proving the verification path works would be
REM the one unforgivable bug.
py -m unittest discover -s tests -v || python -m unittest discover -s tests -v
if errorlevel 1 (
    echo.
    echo *** TESTS FAILED - build aborted. ***
    pause
    exit /b 1
)

REM cryptography + keyring back the credential vault. Without them the exe still
REM runs, but "Key security" reports encryption unavailable and the export key
REM stays base64 - so they are NOT optional in a shipped build.
pip install --upgrade pyinstaller requests cryptography keyring

REM Auto-increment the patch version before building, and ABORT loudly if the
REM bump fails, so a stale exe is never shipped silently.
py bump_version.py || python bump_version.py
if errorlevel 1 (
    echo.
    echo *** VERSION BUMP FAILED - is Python on PATH? Build aborted. ***
    pause
    exit /b 1
)

REM --version-file stamps the Windows file-version resource from the
REM version_info.txt bump_version.py just regenerated. Without it the exe has no
REM version in Properties > Details however high BUILD_VERSION climbs.
REM --paths ..\_shared + --hidden-import snap_vault: the shared credential vault
REM lives outside this folder; in dev a sys.path bootstrap in config.py finds it,
REM in a frozen bundle PyInstaller has to be told.
REM --collect-submodules keyring.backends: keyring picks its backend at runtime
REM by import, so static analysis misses every backend and the frozen exe quietly
REM reports "no keychain" instead of failing loudly.
REM --add-data schema: the portable JSON Schema ships inside every archive, so it
REM has to be inside the exe to be copied out.
pyinstaller --onefile --windowed --name tyswy --version-file version_info.txt --paths ..\_shared --hidden-import snap_vault --hidden-import snap_paths --collect-submodules keyring.backends --add-data "schema;schema" main.py

echo.
echo Done. Exe is in dist\tyswy.exe
pause
REM ===== SNAPSMACK EOF =====
