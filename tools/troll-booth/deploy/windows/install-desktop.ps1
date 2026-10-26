$ErrorActionPreference = 'Stop'
$Project = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Dist = Join-Path $Project 'dist'
$DealerExe = Join-Path $Dist 'SMACK DEALER\SMACK DEALER.exe'
$BoothExe = Join-Path $Dist 'BRAIN TRUST\BRAIN TRUST.exe'
$BoothIcon = Join-Path $Project 'assets\brain-trust.ico'
$Programs = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\SnapSmack'
$Desktop = [Environment]::GetFolderPath('Desktop')
$Startup = [Environment]::GetFolderPath('Startup')
New-Item -ItemType Directory -Force -Path $Programs | Out-Null

function New-Shortcut([string]$Path, [string]$Target, [string]$Description, [string]$Icon = '') {
    $Shell = New-Object -ComObject WScript.Shell
    $Shortcut = $Shell.CreateShortcut($Path)
    $Shortcut.TargetPath = $Target
    $Shortcut.WorkingDirectory = Split-Path $Target
    $Shortcut.Description = $Description
    $Shortcut.IconLocation = if ($Icon) { "$Icon,0" } else { "$Target,0" }
    $Shortcut.Save()
}

if (-not (Test-Path $DealerExe) -or -not (Test-Path $BoothExe)) { & (Join-Path $PSScriptRoot 'build-desktop.ps1') }
New-Shortcut (Join-Path $Programs 'SMACK DEALER.lnk') $DealerExe 'Launch SnapSmack admin applications'
New-Shortcut (Join-Path $Programs 'BRAIN TRUST.lnk') $BoothExe 'Open the SnapSmack agent bridge' $BoothIcon
New-Shortcut (Join-Path $Desktop 'SMACK DEALER.lnk') $DealerExe 'Launch SnapSmack admin applications'
New-Shortcut (Join-Path $Startup 'BRAIN TRUST.lnk') $BoothExe 'Keep the SnapSmack agent bridge available' $BoothIcon
$OldShortcut = Join-Path $Programs 'TROLL BOOTH.lnk'
if (Test-Path $OldShortcut) { Remove-Item -LiteralPath $OldShortcut -Force }
$OldStartupShortcut = Join-Path $Startup 'TROLL BOOTH.lnk'
if (Test-Path $OldStartupShortcut) { Remove-Item -LiteralPath $OldStartupShortcut -Force }
$OldStartup = Join-Path $Startup 'TROLL BOOTH Owner Console.cmd'
if (Test-Path $OldStartup) { Rename-Item -LiteralPath $OldStartup -NewName 'TROLL BOOTH Owner Console.cmd.disabled' -Force }
Write-Host 'Installed SMACK DEALER and BRAIN TRUST shortcuts.'
