# FRC 2026 accounts-taxonomy compatibility

Checked on 26 July 2026.

## Conclusion

EEL's schema reference is the correct FRC 2026 entry point for its FRS 105
micro-entity accounts profile:

`https://xbrl.frc.org.uk/FRS-102/2026-01-01/FRS-102-2026-01-01.xsd`

The `2026-01-01` in that URL identifies the taxonomy release. It does not mean
that an account period must start in 2026. The FRC's 2026 Accounts Taxonomies
Design document says that the `FRS-102` entry point is useful for financial
statements prepared under both FRS 102 and FRS 105. The current HMRC collector
table accepts the FRC 2026 accounts taxonomy for accounting periods starting
on or after 1 April 2015, with no announced end date. The Elstone fixture
period, 5 September 2022 to 30 September 2023, is inside that published window.

Companies House announced that it would accept the 2026, 2025, 2024, 2023 and
2022 FRC taxonomy suites from the beginning of April 2026. Therefore an
intended filing date of 26 July 2026 is inside the published availability
window. This is a policy/date compatibility result, not evidence that the
specific filing has passed the Companies House validator or XML Gateway.

Revised accounts remain prepared by reference to the date of the original
accounts. Selecting the current tagging taxonomy does not change the
recognition, measurement, or disclosure rules applicable at that original
date.

## Official evidence

- [FRC 2026 Taxonomy Suite](https://www.frc.org.uk/library/standards-codes-policy/accounting-and-reporting/frc-taxonomies/current-frc-taxonomy-suites/2026-frc-taxonomy-suite/):
  v1.0.0 was published on 18 November 2025; the FRC recommends that HMRC and
  Companies House filers use the most up-to-date version where possible.
- [FRC Accounts Taxonomies Design 2026](https://media.frc.org.uk/documents/Accounts_Taxonomies_Design_2026.pdf):
  section 2.1 identifies `FRS-102` as the entry point for accounts prepared
  under FRS 102 and FRS 105.
- [HMRC taxonomies accepted](https://www.gov.uk/government/publications/taxonomies-accepted-by-hm-revenue-and-customs/taxonomies-accepted-by-hmrc):
  the FRC 2026 accounts-taxonomy row starts at 1 April 2015 and currently has
  no stated accounting-period end date.
- [Companies House XML Gateway 2026 taxonomy notice](https://xmlforum.companieshouse.gov.uk/t/2026-frc-taxonomies-update/1903):
  Companies House announced acceptance of the 2026 suite from the beginning
  of April 2026.
- [Companies House XBRL validator help](https://ewf.companieshouse.gov.uk/help/en/stdwf/xbrl_validator.html):
  describes the separate schema, XBRL, Inline XBRL and business-rule checks
  performed by the collector.

The official schema URL returned HTTP 200 on 26 July 2026.

## Bundled package evidence

The installed archive is:

`third_party/frc/taxonomies/frc-2026-v1.0.0-ae80ae12d9d747ac.zip`

Observed SHA-256:

`ae80ae12d9d747ac531150b0051bcd67e7c9acf44313da19105b4cc013566462`

Its taxonomy-package manifest declares:

- identifier `https://xbrl.frc.org.uk/fr/2026-01-01/v1-0-0`;
- version `1.0.0`;
- entry point name `FRS-102`;
- the exact EEL schema reference shown above.

The runtime package check pins this exact official SHA-256 in code and then
verifies the manifest identity, version, entry point and schema target
namespace. The manifest is not treated as its own trust anchor: a fabricated
or truncated archive with self-declared FRC metadata is rejected before it can
be selected for validation.

## Runtime policy and configuration

`IxbrlTaxonomyCompatibilityService` validates:

- the exact configured schema reference;
- the `FRS_105` accounts profile;
- valid and ordered reporting dates;
- the published accounting-period window;
- the intended Companies House filing date against the configured Gateway
  availability window;
- the installed archive against the pinned official SHA-256;
- the taxonomy-package manifest and entry-point identity.

The report basis freezes the reporting-date policy identity, so changing the
policy makes an earlier accounts filing approval stale. Companies House
preparation and the final pre-send path both recheck current compatibility.

Collector dates can be tightened without code changes in `secure/app.php`:

```php
'ixbrl' => [
    'accounts_taxonomy_compatibility' => [
        'reporting_period_start_from' => '2015-04-01',
        'reporting_period_end_to' => null,
        'companies_house_gateway_available_from' => '2026-04-01',
        'companies_house_gateway_available_to' => null,
    ],
],
```

The default Gateway start represents the Companies House published
beginning-of-April window. If Companies House supplies a more precise
environment-specific date, configure that later date.

## What remains externally confirmable only

No TEST or LIVE XML Gateway submission was made as part of this check.
Consequently, this work does not claim:

- acceptance of the particular revised-accounts document;
- satisfaction of unpublished or environment-specific Gateway rules;
- validity of presenter credentials or the company authentication code;
- a Companies House submission reference or acceptance status.

Those points require the configured Companies House TEST Gateway and its
validator/acknowledgement response. A successful local Arelle run is useful,
but is not an official Companies House validation.
