$ErrorActionPreference = 'Stop'
$Project = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Dist = Join-Path $Project 'dist'
$DealerExe = Join-Path $Dist 'SMACK DEALER\SMACK DEALER.exe'
$BoothExe = Join-Path $Dist 'TROLL BOOTH\TROLL BOOTH.exe'
$Programs = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\SnapSmack'
$Desktop = [Environment]::GetFolderPath('Desktop')
$Startup = [Environment]::GetFolderPath('Startup')
New-Item -ItemType Directory -Force -Path $Programs | Out-Null

function New-Shortcut([string]$Path, [string]$Target, [string]$Description) {
    $Shell = New-Object -ComObject WScript.Shell
    $Shortcut = $Shell.CreateShortcut($Path)
    $Shortcut.TargetPath = $Target
    $Shortcut.WorkingDirectory = Split-Path $Target
    $Shortcut.Description = $Description
    $Shortcut.Save()
}

if (-not (Test-Path $DealerExe) -or -not (Test-Path $BoothExe)) { & (Join-Path $PSScriptRoot 'build-desktop.ps1') }
New-Shortcut (Join-Path $Programs 'SMACK DEALER.lnk') $DealerExe 'Launch SnapSmack admin applications'
New-Shortcut (Join-Path $Programs 'TROLL BOOTH.lnk') $BoothExe 'Open the SnapSmack agent bridge'
New-Shortcut (Join-Path $Desktop 'SMACK DEALER.lnk') $DealerExe 'Launch SnapSmack admin applications'
New-Shortcut (Join-Path $Startup 'TROLL BOOTH.lnk') $BoothExe 'Keep the SnapSmack agent bridge available'
$OldStartup = Join-Path $Startup 'TROLL BOOTH Owner Console.cmd'
if (Test-Path $OldStartup) { Rename-Item -LiteralPath $OldStartup -NewName 'TROLL BOOTH Owner Console.cmd.disabled' -Force }
Write-Host 'Installed SMACK DEALER and TROLL BOOTH shortcuts.'
