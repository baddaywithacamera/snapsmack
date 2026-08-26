# SNAPSMACK_EOF_HEADER
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$pythonVersion = '3.12.10'
$installerUrl = "https://www.python.org/ftp/python/$pythonVersion/python-$pythonVersion-amd64.exe"
$installerSha256 = '67B5635E80EA51072B87941312D00EC8927C4DB9BA18938F7AD2D27B328B95FB'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$runtimePath = Join-Path $repoRoot '.python-build'
$pythonPath = Join-Path $runtimePath 'python.exe'
$installerPath = Join-Path $env:TEMP "snapsmack-python-$pythonVersion-amd64.exe"

Write-Host "Preparing the isolated SNAP SLAPPER build runtime..."
try {
    Invoke-WebRequest -Uri $installerUrl -OutFile $installerPath

    $actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $installerPath).Hash
    if ($actualHash -ne $installerSha256) {
        throw "Python installer checksum mismatch. Expected $installerSha256; received $actualHash."
    }

    $signature = Get-AuthenticodeSignature -LiteralPath $installerPath
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Python Software Foundation') {
        throw 'Python installer signature is not a valid Python Software Foundation signature.'
    }

    $arguments = @(
        '/quiet'
        'InstallAllUsers=0'
        "TargetDir=$runtimePath"
        'Include_launcher=0'
        'Include_test=0'
        'PrependPath=0'
        'Shortcuts=0'
    )
    $installer = Start-Process -FilePath $installerPath -ArgumentList $arguments -Wait -PassThru
    if ($installer.ExitCode -ne 0) {
        throw "Python installer exited with code $($installer.ExitCode)."
    }

    & $pythonPath -m pip install --disable-pip-version-check -r (Join-Path $PSScriptRoot 'requirements.txt')
    if ($LASTEXITCODE -ne 0) { throw 'Could not install the SNAP SLAPPER build requirements.' }

    & $pythonPath -c 'import tkinter as tk; root=tk.Tk(); root.withdraw(); root.destroy()'
    if ($LASTEXITCODE -ne 0) { throw 'The isolated runtime was installed, but its Tk runtime did not start.' }

    Write-Host "Build runtime ready: $pythonPath"
}
finally {
    if (Test-Path -LiteralPath $installerPath) {
        Remove-Item -LiteralPath $installerPath -Force
    }
}
# SNAPSMACK_EOF
