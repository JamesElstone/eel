param(
    [string]$ConfigPath = ""
)

$ErrorActionPreference = "Stop"
$Root = Resolve-Path (Join-Path $PSScriptRoot "..")
$Logs = Join-Path $Root "logs"
$Cache = Join-Path $Root "runtime\cache"
$Taxonomies = Join-Path $Root "taxonomies"
$ArelleCmd = Join-Path $Root "runtime\venv\Scripts\arelleCmdLine.exe"
$Sample = Join-Path $Root "samples\smoke_inline_xbrl.xhtml"

New-Item -ItemType Directory -Force -Path $Logs, $Cache, $Taxonomies | Out-Null

if (!(Test-Path $ArelleCmd)) {
    throw "Arelle command not found at $ArelleCmd. Run install_arelle.bat first."
}

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$helpLog = Join-Path $Logs "arelle_help_$stamp.log"
$sampleLog = Join-Path $Logs "arelle_sample_$stamp.log"

& $ArelleCmd --help *> $helpLog
if ($LASTEXITCODE -ne 0) {
    throw "Arelle --help failed. See $helpLog"
}

if (!(Test-Path $Sample)) {
    throw "Smoke sample not found at $Sample"
}

$ArelleArguments = @(
    "--validate",
    "--validationExitCode",
    "--cacheDirectory", $Cache,
    "--internetConnectivity=offline"
)
foreach ($Package in @(Get-ChildItem -LiteralPath $Taxonomies -Filter "*.zip" -File | Sort-Object FullName)) {
    $ArelleArguments += @("--package", $Package.FullName)
}
$ArelleArguments += @("--file", $Sample)

& $ArelleCmd @ArelleArguments *> $sampleLog
$sampleExit = $LASTEXITCODE

Write-Host "[Arelle] Help log: $helpLog"
Write-Host "[Arelle] Sample validation log: $sampleLog"
Write-Host "[Arelle] Sample validation exit code: $sampleExit"

exit $sampleExit
