[CmdletBinding()]
param(
    [string]$Python = "py",
    [string]$InstallRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
)

$ErrorActionPreference = "Stop"
$venv = Join-Path $InstallRoot ".venv"
$venvPython = Join-Path $venv "Scripts\python.exe"
$venvPythonw = Join-Path $venv "Scripts\pythonw.exe"

if (-not (Test-Path -LiteralPath $venvPython)) {
    & $Python -m venv $venv
}

& $venvPython -m pip install --disable-pip-version-check -e $InstallRoot

$launcherDir = Join-Path $env:LOCALAPPDATA "SnapSmack\TrollBooth"
New-Item -ItemType Directory -Force -Path $launcherDir | Out-Null
$launcher = Join-Path $launcherDir "run-owner.cmd"
$launcherBody = "@echo off`r`n`"$venvPythonw`" -m troll_booth.desktop`r`n"
Set-Content -LiteralPath $launcher -Value $launcherBody -Encoding Ascii -NoNewline

$startupDir = [Environment]::GetFolderPath("Startup")
$startupLauncher = Join-Path $startupDir "TROLL BOOTH Owner Console.cmd"
$startupBody = "@echo off`r`nstart `"`" /b `"$launcher`"`r`n"
Set-Content -LiteralPath $startupLauncher -Value $startupBody -Encoding Ascii -NoNewline

Start-Process -FilePath $venvPythonw -ArgumentList "-m", "troll_booth.desktop" -WindowStyle Hidden
Write-Host "TROLL BOOTH desktop application installed and started."
Write-Host "Source: $InstallRoot"
Write-Host "Launcher: $launcher"
Write-Host "Startup entry: $startupLauncher"
