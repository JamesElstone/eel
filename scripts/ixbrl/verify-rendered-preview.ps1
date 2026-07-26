[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$PdfPath,

    [string]$PdfInfoPath = '',

    [string]$PdfToTextPath = '',

    [ValidateRange(1, 20)]
    [int]$ExpectedPageCount = 5
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Resolve-PreviewExecutable {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$ConfiguredPath,

        [Parameter(Mandatory = $true)]
        [string]$CommandName,

        [Parameter(Mandatory = $true)]
        [string[]]$Candidates
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
        return $command.Source
    }

    throw "$CommandName could not be found. Supply its path explicitly."
}

function Assert-PreviewMatch {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Text,

        [Parameter(Mandatory = $true)]
        [string]$Pattern,

        [Parameter(Mandatory = $true)]
        [string]$Description
    )

    if ($Text -notmatch $Pattern) {
        throw "Rendered-preview check failed: $Description"
    }
}

$resolvedPdf = (Resolve-Path -LiteralPath $PdfPath -ErrorAction Stop).Path
if (-not (Test-Path -LiteralPath $resolvedPdf -PathType Leaf)) {
    throw "The rendered PDF was not found: $PdfPath"
}

$programFiles = [Environment]::GetFolderPath('ProgramFiles')
$userProfile = [Environment]::GetFolderPath('UserProfile')
$popplerBin = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\native\poppler\Library\bin'

$resolvedPdfInfo = Resolve-PreviewExecutable `
    -ConfiguredPath $PdfInfoPath `
    -CommandName 'pdfinfo.exe' `
    -Candidates @(
        (Join-Path $popplerBin 'pdfinfo.exe')
    )

$resolvedPdfToText = Resolve-PreviewExecutable `
    -ConfiguredPath $PdfToTextPath `
    -CommandName 'pdftotext.exe' `
    -Candidates @(
        (Join-Path $popplerBin 'pdftotext.exe'),
        (Join-Path $programFiles 'Git\mingw64\bin\pdftotext.exe')
    )

$pdfInfo = (& $resolvedPdfInfo $resolvedPdf | Out-String)
if ($LASTEXITCODE -ne 0) {
    throw "pdfinfo failed with exit code $LASTEXITCODE."
}

Assert-PreviewMatch -Text $pdfInfo -Pattern "(?m)^Pages:\s+$ExpectedPageCount\s*$" -Description "the PDF must contain exactly $ExpectedPageCount pages"
Assert-PreviewMatch -Text $pdfInfo -Pattern '(?m)^Page size:.*\(A4\)\s*$' -Description 'the PDF page size must be A4'

$temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$textDirectory = [IO.Path]::GetFullPath(
    (Join-Path $temporaryRoot ('eel-ixbrl-preview-check-' + [Guid]::NewGuid().ToString('N')))
)
if (-not $textDirectory.StartsWith(
    $temporaryRoot.TrimEnd('\') + '\',
    [StringComparison]::OrdinalIgnoreCase
)) {
    throw 'The temporary preview-check directory escaped the system temporary directory.'
}

New-Item -ItemType Directory -Path $textDirectory -Force | Out-Null

try {
    $pageTexts = @()
    for ($page = 1; $page -le $ExpectedPageCount; $page++) {
        $textPath = Join-Path $textDirectory ("page-$page.txt")
        & $resolvedPdfToText -f $page -l $page -layout -enc UTF-8 $resolvedPdf $textPath
        if ($LASTEXITCODE -ne 0) {
            throw "pdftotext failed for page $page with exit code $LASTEXITCODE."
        }
        $pageTexts += Get-Content -LiteralPath $textPath -Raw -Encoding UTF8
    }

    Assert-PreviewMatch -Text $pageTexts[0] -Pattern 'REVISED MICRO-ENTITY ACCOUNTS' -Description 'page 1 must be the revised-accounts title page'
    Assert-PreviewMatch -Text $pageTexts[1] -Pattern 'Revised accounts statements' -Description 'page 2 must contain the revision statements'
    Assert-PreviewMatch -Text $pageTexts[2] -Pattern 'Profit and loss account' -Description 'page 3 must begin the profit and loss account'
    Assert-PreviewMatch -Text $pageTexts[2] -Pattern 'Turnover' -Description 'the profit and loss table must begin on page 3'
    Assert-PreviewMatch -Text $pageTexts[2] -Pattern 'Profit / \(loss\) for the financial year' -Description 'the profit and loss table must end on page 3'
    Assert-PreviewMatch -Text $pageTexts[2] -Pattern '\(127\.11\)' -Description 'the annual loss must render visibly as a loss'
    Assert-PreviewMatch -Text $pageTexts[3] -Pattern 'Micro-entity Balance Sheet' -Description 'page 4 must begin the balance sheet'
    Assert-PreviewMatch -Text $pageTexts[3] -Pattern 'Capital and reserves' -Description 'the balance-sheet table must end on page 4'
    Assert-PreviewMatch -Text $pageTexts[3] -Pattern 'Approved by the board' -Description 'the approval block must remain with the balance sheet'
    Assert-PreviewMatch -Text $pageTexts[4] -Pattern 'Notes to the Revised Micro-entity Accounts' -Description 'page 5 must begin the notes'
    Assert-PreviewMatch -Text $pageTexts[4] -Pattern 'Advances and credits to directors' -Description 'the director-loan note must remain on the notes page'

    $allText = $pageTexts -join "`n"
    if ($allText -match '(?im)(?:^|\s)true(?:\s|$)') {
        throw 'Rendered-preview check failed: a raw boolean true is visible.'
    }
    if ($allText -match '(?i)EEL-(?:AR|EVIDENCE)-[A-Z0-9-]+') {
        throw 'Rendered-preview check failed: an internal evidence-artifact identifier is visible.'
    }

    [ordered]@{
        pdf = $resolvedPdf
        page_count = $ExpectedPageCount
        page_size = 'A4'
        page_checks = 'passed'
        visible_internal_identifiers = $false
        visible_raw_true = $false
        pdfinfo = $resolvedPdfInfo
        pdftotext = $resolvedPdfToText
    } | ConvertTo-Json -Depth 3
}
finally {
    if (Test-Path -LiteralPath $textDirectory -PathType Container) {
        Remove-Item -LiteralPath $textDirectory -Recurse -Force
    }
}
