# EEL Accounts Software Quality Management

## 1. Purpose and scope

This document defines how changes to EEL Accounts are developed, tested, recorded,
and communicated in a controlled and repeatable way. It applies to the complete
accounting and filing path: double-entry ledger processing, Corporation Tax
computation, FRS 105 accounts production, iXBRL generation, GovTalk/XML generation,
and the validation and audit evidence supporting those outputs.

The principal quality objective is traceability. A figure in a statutory account or
Corporation Tax return should be capable of being traced back through its calculation
and mapping to the underlying ledger entries and source transactions. The same
lineage supports review, defect investigation, user explanations, and comparison of
results between software changes.

This policy is owned by the EEL Accounts project maintainer. It is reviewed when the
development, testing, release, or user-notification process changes; when a material
legislative or filing requirement changes; and before the first formal release. EEL
Accounts remains development software and this policy does not represent HMRC
approval or certification.

## 2. Change control and testing

Before a change is made, its likely effect is considered across the ledger,
calculations, statutory accounts, filing formats, database state, and historical
periods. Changes are kept focused so that their purpose and consequences can be
reviewed. The implementation, relevant tests, and any dated legislative data or
schema changes are recorded together where practical.

The normal change process is:

1. Define the change or defect and identify the affected calculations, outputs, and
   accounting periods.
2. Implement a focused change and add or amend tests that demonstrate the required
   behaviour.
3. Run the tests most closely related to the change, followed by proportionate
   regression testing.
4. Where relevant, validate generated iXBRL, GovTalk/XML, CT600, or other filing
   artefacts against the applicable schemas, taxonomies, mappings, and business
   rules.
5. Review the resulting diff for unintended changes and record the work in a
   descriptive Git commit.

EEL Accounts currently uses a local PHP test suite containing focused service and
behaviour tests. The complete suite is run through `web_root/tests/index.php` when a
broad regression check is required. Corporation Tax and filing work has a dedicated
CT600 runner at `web_root/tests/run_ct600_mvp.php`. Golden accounting and tax
integration tests are run through `web_root/tests/run_golden_integration.php` and
compare the application against controlled fixtures and independent expected
results. Relevant changes also exercise schema, taxonomy, iXBRL, and GovTalk/XML
validation paths.

The extent of regression testing is based on risk. A display-only change may require
focused rendering tests, while a change to journals, tax rules, filing mappings, or
generated artefacts requires the affected focused tests plus the relevant golden,
CT600, and integration coverage. A material calculation or filing failure must be
resolved before a formal release can be made.

Full automated continuous-integration testing is a future roadmap item. The current
GitHub Actions workflow checks tracked text-file line endings; it is not a
comprehensive CI test suite. Until full CI is introduced, application tests are run
locally and the person making the change is responsible for reviewing their results.

## 3. Regression protection and historical calculations

EEL Accounts separates the effective date of accounting and tax rules from the date
on which the software is changed. Legislative rules retain evidence such as their
source URL, source checked date, rule version, and effective dates. HMRC and other
filing artefacts are versioned and selected using their recorded applicability. Where
a required artefact is absent, changed, incompatible, or unverified, affected filing
operations are designed to fail closed rather than silently select an unsupported
fallback.

Golden fixtures preserve known ledger, accounting, and tax scenarios. Tests cover
the transformation from source activity and journals through year-end calculations
and filing values. Legislative changes should include cases before, on, and after an
effective-date boundary, with earlier-period expectations retained unless an
evidenced correction is required.

Consequently, a new year's CT600 rules or artefacts do not replace the basis used for
an earlier period. The new version is added with its own applicability, current-year
and boundary cases are tested, and historical fixtures continue to be run against
their previous expectations. This provides the concise answer to the regression
question: this year's CT600 support is checked alongside dated rules, versioned
artefacts, and preserved tests for previous years, so an unintended change to a
historical calculation is detectable.

Expected golden results must not be changed merely to make a failing test pass. A
change to an expected value requires review of the underlying accounting or
legislative basis and a record of why the previous expectation was incorrect or no
longer applicable.

## 4. Version control and releases

Git is the version-control system and GitHub is the canonical remote repository.
Commits provide an attributable history of code, data, tests, and documentation
changes. Commit messages should describe the outcome of the change, and defect fixes
should be linkable to their issue and regression coverage where applicable.

The `main` branch contains the latest development version of EEL Accounts. It may
include work completed since the last formal release and must not be represented as
a formal release merely because it is available from the repository.

Formal releases will begin when the project maintainer determines that the software
has reached sufficient maturity. They will use a two-part `MAJOR.MINOR` version and a
corresponding annotated Git tag in the form `vMAJOR.MINOR`:

- `MINOR` is incremented for patches, small enhancements, and bug fixes.
- `MAJOR` is incremented for fundamental code refactors or other substantial
  architectural changes.

Each formal release will identify the exact tested commit and include concise release
notes. Existing tags such as `beta_0.1` are legacy development identifiers created
before this policy and do not establish a separate release-numbering convention.

## 5. Defect management

Defects are tracked as GitHub issues. A useful defect record includes the observed
and expected behaviour, reproduction information, severity and user impact, affected
development or release versions, affected accounting or tax periods, and any relevant
screenshots or generated artefacts.

Corrective work should link the issue to the fixing commit and identify the test that
prevents recurrence. An issue is closed when the correction and its regression
coverage have been reviewed and the relevant tests pass. Defects affecting ledger
integrity, statutory figures, tax calculations, filing validity, security, or data
loss receive priority. A known material calculation or filing defect blocks a formal
release; if users may already be affected, it also triggers the communication process
below.

## 6. Legislative and filing updates

Legislative and filing changes are assessed using authoritative sources, including
HMRC, GOV.UK, Companies House, and the Financial Reporting Council. The implementation
retains appropriate provenance, which may include the source URL, the date checked,
the published or artefact version, effective or applicability dates, and a file or
inventory hash.

An update is incorporated by determining its scope and effective boundary, adding or
amending dated rules or versioned artefacts, and testing representative periods before,
at, and after that boundary. Filing schemas, taxonomies, packages, and mappings are
verified before use. Historical versions and results are preserved so that processing
an earlier accounting period continues to use the basis applicable to that period.

Where an external source or package changes unexpectedly, EEL Accounts records or
reports the discrepancy and prevents affected filing output where the required basis
cannot be verified. Legislative evidence and related tests form part of the change
record rather than being treated as an undocumented configuration adjustment.

## 7. User communication and retained evidence

Formal releases will include release notes summarising material changes. Changes to
calculations, filing compatibility, schemas or taxonomies, data migrations, and
corrections to previously produced results will be highlighted clearly, together with
any action required from users. Known affected users will be contacted directly about
critical updates until EEL Accounts has a dedicated in-application update-notification
facility.

Quality and audit evidence is retained through the Git commit and tag history,
GitHub issues, test results, legislative source metadata, schema and artefact versions
and hashes, generated-output metadata, and the application's ledger-to-filing audit
records. Taken together, these records show what changed, why it changed, which basis
was applied, how it was tested, and which exact code produced an output.
