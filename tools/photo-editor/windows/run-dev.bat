@echo off
setlocal
cd /d "%~dp0src-tauri"
cargo run --offline
endlocal
