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

Install an official FRC taxonomy package into the project-local validation
boundary at the same time:

```bat
third_party\arelle\bin\install_arelle.bat -TaxonomyPackage C:\path\to\FRC-2026-Taxonomy-v1.0.0.zip
```

The installer creates a local Python virtual environment under:

```text
third_party/arelle/runtime/venv
```

It installs `arelle-release`, runs a command-line smoke test, validates the
sample Inline XBRL file in `samples/`, and writes local configuration to:

```text
third_party/arelle/config/arelle.config.php
```

Runtime files, logs, taxonomy packages, and local config are intentionally not
committed to git.

Validation runs offline. The adapter automatically loads every ZIP under
`third_party/arelle/taxonomies` and uses `third_party/arelle/runtime/cache`, so
a filing check never silently depends on a live taxonomy download.

## Manual Validation

Validate a generated iXBRL/XHTML file:

```bat
third_party\arelle\bin\validate_ixbrl.bat outbound\ixbrl\accounts_ixbrl_1_1_1.xhtml
```

Logs are written to `third_party/arelle/logs/`.

## Licence Boundary

Project code outside `third_party/arelle/` remains under the repository's normal
licence structure. Arelle-specific scripts, notices, samples, runtime folders,
and adapter code live here to keep third-party licensing obvious.
