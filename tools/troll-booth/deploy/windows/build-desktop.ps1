$ErrorActionPreference = 'Stop'
$Project = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Python = Join-Path $Project '.venv\Scripts\python.exe'
$Icon = Join-Path $Project 'assets\brain-trust.ico'
$Dist = Join-Path $Project 'dist'
$Build = Join-Path $Project 'build'

if (-not (Test-Path $Python)) {
  throw "BRAIN TRUST's isolated Python environment is missing: $Python"
}
if (-not (Test-Path $Icon)) {
  throw "BRAIN TRUST's application icon is missing: $Icon"
}

& $Python -m pip install -e $Project
& $Python -m PyInstaller --noconfirm --clean --windowed --name 'BRAIN TRUST' `
  --icon $Icon `
  --hidden-import pymysql --hidden-import win32crypt `
  --distpath $Dist --workpath $Build --specpath $Build `
  (Join-Path $Project 'desktop_entry.py')
& $Python -m PyInstaller --noconfirm --clean --windowed --name 'SMACK DEALER' `
  --distpath $Dist --workpath $Build --specpath $Build `
  (Join-Path $Project 'dealer_entry.py')

Write-Host "Built native applications in $Dist"
