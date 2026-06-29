param(
    [Parameter(Mandatory = $true)]
    [string]$File,
    [string]$ConfigPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Logs = Join-Path $Root "logs"
$ArelleCmd = Join-Path $Root "runtime\venv\Scripts\arelleCmdLine.exe"

New-Item -ItemType Directory -Force -Path $Logs | Out-Null

if (!(Test-Path $ArelleCmd)) {
    throw "Arelle command not found at $ArelleCmd. Run install_arelle.bat first."
}

$ResolvedFile = Resolve-Path $File
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$log = Join-Path $Logs "arelle_validate_$stamp.log"

& $ArelleCmd --validate --file $ResolvedFile *> $log
$exitCode = $LASTEXITCODE

Write-Host "[Arelle] Validation log: $log"
Write-Host "[Arelle] Exit code: $exitCode"

exit $exitCode
