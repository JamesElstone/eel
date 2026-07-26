# iXBRL reference diagnostics and previews

Compare the tracked Companies House reference with the current EEL artifact:

```powershell
php scripts/ixbrl/compare-references.php `
  web_root/tests/fixtures/ixbrl/14337285_22_23_aa_2025-05-29.xhtml `
  web_root/tests/fixtures/ixbrl/revised_accounts_49_79_e1fa128843783d8d.xhtml
```

The comparison is structural. It deliberately does not require identical
figures or taxonomy versions, and parses XML with external resolution disabled
and `LIBXML_NONET`. The later `ccfb3dfce4f81bc0` EEL snapshot is also retained;
it differs from `e1fa128843783d8d` only in regenerated evidence identifiers.

Regenerate the deterministic review fixture from the current source model
without approving or preparing a filing workflow artifact:

```powershell
php scripts/ixbrl/regenerate-reviewed-fixture.php `
  49 79 output/ixbrl/revised_accounts_49_79_final.xhtml
```

This diagnostic command is deliberately limited to `output/ixbrl/` and the
generated integration-fixture directory. It resolves paths against the
repository root and checks real parent paths so `..`, symlink and junction
escapes are rejected. Production artifacts must continue through the accounts
filing-approval and Companies House preparation workflows.

Create a PDF and one PNG per PDF page:

```powershell
powershell -NoProfile -File scripts/ixbrl/render-preview.ps1 `
  -InputPath files/14337285/ixbrl/revised_accounts_49_79_example.xhtml `
  -OutputDirectory files/14337285/ixbrl/previews `
  -BaseName revised-accounts-preview `
  -Overwrite
```

The renderer uses print CSS through an installed headless Chrome or Edge, then
uses Poppler `pdftoppm` to rasterise each page at 144 DPI by default. Output
paths and executable paths are configurable. It uses a fresh temporary browser
profile, disables browser background services, suppresses PDF headers and
footers, and waits for compositor work before printing.

Verify the rendered PDF's A4 page count, section boundaries, table/approval
cohesion, visible loss sign, and absence of visible internal identifiers or raw
boolean values:

```powershell
powershell -NoProfile -File scripts/ixbrl/verify-rendered-preview.ps1 `
  -PdfPath files/14337285/ixbrl/previews/revised-accounts-preview.pdf
```

The verifier uses Poppler `pdfinfo` and `pdftotext`. Their paths can be supplied
explicitly where they are not available on `PATH`.

## Final revised-accounts pre-submission validation

Run every available local layer and write machine-readable reports:

```powershell
php scripts/ixbrl/validate-revised-accounts.php `
  output/ixbrl/revised_accounts_49_79_final.xhtml
```

The command prints and records separate results for:

- XML parsing with network resolution disabled;
- XHTML and Inline XBRL envelope checks;
- Arelle taxonomy validation;
- facts, contexts and units;
- accounting arithmetic;
- duplicate and conflicting facts;
- visible-to-tagged reconciliation;
- official Companies House TEST/LIVE validation.

Reports default to `output/ixbrl/validation/`. Each run publishes its three
JSON reports together in a new artifact-hash- and run-identified directory;
prior evidence is never overwritten and a partially written bundle is never
published. Override the parent directory with `--output-dir`. The command
exits non-zero on any failed or unavailable mandatory layer. In particular,
it intentionally reports overall `FAIL` until an official Companies House
result for the same artifact hash is supplied:

```powershell
php scripts/ixbrl/validate-revised-accounts.php `
  output/ixbrl/revised_accounts_49_79_final.xhtml `
  --companies-house-result path/to/companies-house-result.json
```

Use `--skip-arelle` only for diagnostic development; it makes the taxonomy
layer `NOT RUN` and can never produce a filing-ready result.

The complete official validation and evidence-retention procedure is in
[COMPANIES_HOUSE_VALIDATION.md](COMPANIES_HOUSE_VALIDATION.md).
