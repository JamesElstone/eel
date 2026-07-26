[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,

    [Parameter(Mandatory = $true)]
    [string]$OutputDirectory,

    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]*$')]
    [string]$BaseName = 'ixbrl-preview',

    [string]$ChromePath = '',

    [string]$PdfToPpmPath = '',

    [ValidateRange(72, 600)]
    [int]$Dpi = 144,

    [switch]$Overwrite
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Resolve-ExecutablePath {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$ConfiguredPath,

        [Parameter(Mandatory = $true)]
        [string[]]$Candidates,

        [Parameter(Mandatory = $true)]
        [string]$CommandName,

        [string[]]$CommandRelativeCandidates = @()
    )

    if ($ConfiguredPath -ne '') {
        $configured = Get-Item -LiteralPath $ConfiguredPath -ErrorAction Stop
        if ($configured.PSIsContainer) {
            throw "$CommandName path is a directory: $ConfiguredPath"
        }
        return $configured.FullName
    }

    foreach ($candidate in $Candidates) {
        if ($candidate -ne '' -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            return (Get-Item -LiteralPath $candidate).FullName
        }
    }

    $command = Get-Command $CommandName -ErrorAction SilentlyContinue
    if ($null -ne $command -and $command.Source -ne '') {
        foreach ($relativeCandidate in $CommandRelativeCandidates) {
            $candidate = [IO.Path]::GetFullPath(
                (Join-Path (Split-Path -Parent $command.Source) $relativeCandidate)
            )
            if (Test-Path -LiteralPath $candidate -PathType Leaf) {
                return (Get-Item -LiteralPath $candidate).FullName
            }
        }
        return $command.Source
    }

    throw "$CommandName could not be found. Supply its path explicitly."
}

$resolvedInput = (Resolve-Path -LiteralPath $InputPath -ErrorAction Stop).Path
if (-not (Test-Path -LiteralPath $resolvedInput -PathType Leaf)) {
    throw "The input iXBRL/XHTML file was not found: $InputPath"
}

if (-not (Test-Path -LiteralPath $OutputDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
}
$resolvedOutput = (Resolve-Path -LiteralPath $OutputDirectory -ErrorAction Stop).Path

$programFiles = [Environment]::GetFolderPath('ProgramFiles')
$programFilesX86 = [Environment]::GetFolderPath('ProgramFilesX86')
$userProfile = [Environment]::GetFolderPath('UserProfile')

$resolvedChrome = Resolve-ExecutablePath `
    -ConfiguredPath $ChromePath `
    -CommandName 'chrome.exe' `
    -Candidates @(
        (Join-Path $programFiles 'Google\Chrome\Application\chrome.exe'),
        (Join-Path $programFilesX86 'Google\Chrome\Application\chrome.exe'),
        (Join-Path $programFilesX86 'Microsoft\Edge\Application\msedge.exe'),
        (Join-Path $programFiles 'Microsoft\Edge\Application\msedge.exe')
    )

$resolvedPdfToPpm = Resolve-ExecutablePath `
    -ConfiguredPath $PdfToPpmPath `
    -CommandName 'pdftoppm.exe' `
    -CommandRelativeCandidates @('..\..\native\poppler\Library\bin\pdftoppm.exe') `
    -Candidates @(
        (Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin\pdftoppm.exe')
    )

$pdfPath = Join-Path $resolvedOutput ($BaseName + '.pdf')
$pngPrefix = Join-Path $resolvedOutput ($BaseName + '-page')
$existingPngs = @(Get-ChildItem -LiteralPath $resolvedOutput -Filter ($BaseName + '-page-*.png') -File)

if ((Test-Path -LiteralPath $pdfPath -PathType Leaf) -or $existingPngs.Count -gt 0) {
    if (-not $Overwrite) {
        throw "Preview output already exists. Use -Overwrite to replace only the '$BaseName' preview files."
    }
    if (Test-Path -LiteralPath $pdfPath -PathType Leaf) {
        Remove-Item -LiteralPath $pdfPath -Force
    }
    foreach ($png in $existingPngs) {
        Remove-Item -LiteralPath $png.FullName -Force
    }
}

$temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$profilePath = [IO.Path]::GetFullPath(
    (Join-Path $temporaryRoot ('eel-ixbrl-render-' + [Guid]::NewGuid().ToString('N')))
)
if (-not $profilePath.StartsWith(
    $temporaryRoot.TrimEnd('\') + '\',
    [StringComparison]::OrdinalIgnoreCase
)) {
    throw 'The temporary Chrome profile path escaped the system temporary directory.'
}

New-Item -ItemType Directory -Path $profilePath -Force | Out-Null

try {
    $inputUri = ([System.Uri]::new($resolvedInput)).AbsoluteUri
    $chromeArguments = @(
        '--headless=new',
        '--disable-gpu',
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-component-update',
        '--disable-default-apps',
        '--disable-sync',
        '--metrics-recording-only',
        '--no-default-browser-check',
        '--no-first-run',
        '--run-all-compositor-stages-before-draw',
        '--virtual-time-budget=1000',
        '--no-pdf-header-footer',
        ('--user-data-dir=' + $profilePath),
        ('--print-to-pdf=' + $pdfPath),
        $inputUri
    )

    $quotedChromeArguments = @(
        $chromeArguments | ForEach-Object {
            '"' + ([string]$_).Replace('"', '\"') + '"'
        }
    )
    $chromeProcess = Start-Process `
        -FilePath $resolvedChrome `
        -ArgumentList $quotedChromeArguments `
        -Wait `
        -PassThru `
        -WindowStyle Hidden
    if ($chromeProcess.ExitCode -ne 0) {
        throw "Headless Chromium failed with exit code $($chromeProcess.ExitCode)."
    }
    if ((-not (Test-Path -LiteralPath $pdfPath -PathType Leaf)) -or ((Get-Item -LiteralPath $pdfPath).Length -le 0)) {
        throw 'Headless Chromium did not create a non-empty PDF preview.'
    }

    & $resolvedPdfToPpm -png -r $Dpi $pdfPath $pngPrefix
    if ($LASTEXITCODE -ne 0) {
        throw "Poppler pdftoppm failed with exit code $LASTEXITCODE."
    }

    $pagePngs = @(
        Get-ChildItem -LiteralPath $resolvedOutput -Filter ($BaseName + '-page-*.png') -File |
            Sort-Object -Property Name
    )
    if ($pagePngs.Count -eq 0) {
        throw 'Poppler did not create any page PNG previews.'
    }

    [ordered]@{
        input = $resolvedInput
        pdf = $pdfPath
        pages = @($pagePngs | ForEach-Object { $_.FullName })
        page_count = $pagePngs.Count
        dpi = $Dpi
        chrome = $resolvedChrome
        pdftoppm = $resolvedPdfToPpm
    } | ConvertTo-Json -Depth 3
}
finally {
    if (Test-Path -LiteralPath $profilePath -PathType Container) {
        Remove-Item -LiteralPath $profilePath -Recurse -Force
    }
}
