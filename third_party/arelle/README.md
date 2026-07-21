# Arelle iXBRL Validator

This directory is the third-party boundary for Arelle integration.

Arelle is not part of EEL Accounts or eelKit. It is an external Apache-2.0
licensed XBRL/iXBRL validator used by this project to check generated Inline
XBRL exports before filing review.

## Windows Install

From the project root:

```bat
third_party\arelle\bin\install_arelle.bat
```

The installer creates a local Python virtual environment under:

```text
third_party/arelle/runtime/venv
```

It installs `arelle-release`, runs a command-line smoke test, and validates the
sample Inline XBRL file in `samples/`. Arelle settings are held centrally in
`secure/app.php` under `arelle`.

Validation runs offline. Download and verify the FRC Taxonomy Suite from the
Tax Artifacts page; the verified package is stored under
`third_party/frc/taxonomies` and selected by the application, rather than by
Arelle scanning a private folder.

## Manual Validation

Validate a generated iXBRL/XHTML file:

```bat
third_party\arelle\bin\validate_ixbrl.bat outbound\ixbrl\accounts_ixbrl_1_1_1.xhtml
```

The script uses managed ZIPs in `third_party/frc/taxonomies`; install one from
Tax Artifacts first. Logs are written to `file_logs/arelle/`.

## Licence Boundary

Project code outside `third_party/arelle/` remains under the repository's normal
licence structure. Arelle-specific scripts, notices, samples, runtime folders,
and adapter code live here to keep third-party licensing obvious.
