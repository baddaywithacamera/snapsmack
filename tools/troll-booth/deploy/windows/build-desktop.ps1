$ErrorActionPreference = 'Stop'
$Project = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Python = (py -3.10 -c "import sys; print(sys.executable)").Trim()
$Dist = Join-Path $Project 'dist'
$Build = Join-Path $Project 'build'

& $Python -m pip install -e $Project
& $Python -m PyInstaller --noconfirm --clean --windowed --name 'TROLL BOOTH' `
  --hidden-import pymysql --hidden-import win32crypt `
  --distpath $Dist --workpath $Build --specpath $Build `
  (Join-Path $Project 'desktop_entry.py')
& $Python -m PyInstaller --noconfirm --clean --windowed --name 'SMACK DEALER' `
  --distpath $Dist --workpath $Build --specpath $Build `
  (Join-Path $Project 'dealer_entry.py')
& $Python -m PyInstaller --noconfirm --clean --onefile --windowed --name 'TROLL BOOTH WORKER' `
  --hidden-import pymysql --hidden-import win32crypt `
  --distpath (Join-Path $Dist 'TROLL BOOTH') --workpath $Build --specpath $Build `
  (Join-Path $Project 'worker_entry.py')

Write-Host "Built native applications in $Dist"
