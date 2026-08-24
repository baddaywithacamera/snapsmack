param(
    [string]$SnapSmackRoot = $env:SNAPSMACK_HOME
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($SnapSmackRoot)) {
    throw 'Pass -SnapSmackRoot or set SNAPSMACK_HOME to the USB suite root.'
}
$resolvedRoot = [System.IO.Path]::GetFullPath($SnapSmackRoot)
if ([System.IO.Path]::GetPathRoot($resolvedRoot) -eq $resolvedRoot) {
    throw 'Refusing to install directly into a drive root. Choose the SnapSmack suite folder.'
}
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$tauri = Join-Path $here 'src-tauri'
Push-Location $tauri
try {
    cargo build --release --offline
} finally {
    Pop-Location
}
$source = Join-Path $tauri 'target\release\pixel-tickler.exe'
$destination = Join-Path $resolvedRoot 'pixel-tickler'
New-Item -ItemType Directory -Force -Path $destination | Out-Null
Copy-Item -LiteralPath $source -Destination (Join-Path $destination 'pixel-tickler.exe') -Force
Write-Host "PIXEL TICKLER installed to $destination"
