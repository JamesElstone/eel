param(
    [Parameter(Mandatory = $true)]
    [string]$File,
    [string]$ConfigPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Logs = Resolve-Path (Join-Path $Root "..\..\file_logs\arelle") -ErrorAction SilentlyContinue
if ($null -eq $Logs) { $Logs = Join-Path $Root "..\..\file_logs\arelle" }
$Cache = Join-Path $Root "runtime\cache"
$Taxonomies = Join-Path $Root "..\frc\taxonomies"
$ArelleCmd = Join-Path $Root "runtime\venv\Scripts\arelleCmdLine.exe"

New-Item -ItemType Directory -Force -Path $Logs, $Cache | Out-Null

if (!(Test-Path $ArelleCmd)) {
    throw "Arelle command not found at $ArelleCmd. Run install_arelle.bat first."
}

$ResolvedFile = Resolve-Path $File
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$log = Join-Path $Logs "arelle_validate_$stamp.log"

$ArelleArguments = @(
    "--validate",
    "--validationExitCode",
    "--cacheDirectory", $Cache,
    "--internetConnectivity=offline"
)
foreach ($Package in @(Get-ChildItem -LiteralPath $Taxonomies -Filter "*.zip" -File -ErrorAction SilentlyContinue | Sort-Object FullName)) {
    $ArelleArguments += @("--package", $Package.FullName)
}
$ArelleArguments += @("--file", $ResolvedFile.Path)

& $ArelleCmd @ArelleArguments *> $log
$exitCode = $LASTEXITCODE

Write-Host "[Arelle] Validation log: $log"
Write-Host "[Arelle] Exit code: $exitCode"

exit $exitCode
