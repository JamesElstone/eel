param(
    [string]$Destination = ''
)

$ErrorActionPreference = 'Stop'
$expectedSha256 = '504C9DC643195BB5B9AB25A86B9CFF59C6312DE36ADE035BE01CA848D0B81BED'
$downloadUrl = 'https://assets.publishing.service.gov.uk/media/68e8a35c1c8b2a3b5069080c/HMRC-CT-2014-v1-994.zip'
$scriptRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Destination)) {
    $Destination = Join-Path $scriptRoot 'runtime'
}

$destinationRoot = [System.IO.Path]::GetFullPath($Destination)
$versionRoot = Join-Path $destinationRoot 'HMRC-CT-2014-v1-994'
$schemaPath = Join-Path $versionRoot 'CT-2014-v1-994.xsd'
if (Test-Path -LiteralPath $schemaPath) {
    Write-Output "HMRC CT600 V1.994 is already installed at $versionRoot"
    exit 0
}

New-Item -ItemType Directory -Force -Path $destinationRoot | Out-Null
$zipPath = Join-Path $destinationRoot 'HMRC-CT-2014-v1-994.zip'
Invoke-WebRequest -Uri $downloadUrl -OutFile $zipPath
$actualSha256 = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToUpperInvariant()
if ($actualSha256 -ne $expectedSha256) {
    throw "HMRC CT600 bundle checksum mismatch. Expected $expectedSha256 but received $actualSha256."
}

Expand-Archive -LiteralPath $zipPath -DestinationPath $destinationRoot -Force
if (-not (Test-Path -LiteralPath $schemaPath)) {
    throw "The HMRC CT600 bundle did not contain the expected schema: $schemaPath"
}

Remove-Item -LiteralPath $zipPath -Force
Write-Output "Installed HMRC CT600 V1.994 at $versionRoot"

