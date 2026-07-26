# FRC 2026 categorical and dimensional fact strategy

This note records the evidence used by EEL Accounts for the empty categorical
facts, director-signing marker and approval-date context in revised FRS 105
accounts.

The evidence basis is the installed FRC 2026 taxonomy package
`frc-2026-v1.0.0-ae80ae12d9d747ac.zip`, SHA-256
`ae80ae12d9d747ac531150b0051bcd67e7c9acf44313da19105b4cc013566462`.
Paths below are paths inside that package.

## Why the categorical facts are empty

`general/2026-01-01/types/types-2026-01-01.xsd` defines
`types:fixedItemType` as a restriction of `xbrli:stringItemType` whose length
is fixed at zero. A fact of this type must therefore contain no literal text,
Boolean or enumeration lexical value. A self-closing `ix:nonNumeric` and an
`ix:nonNumeric` with separate empty start and end tags are equivalent.

The primary fact indicates what is being classified. Its context supplies the
classification through an XBRL dimension and member. The following
representations are used:

| Concept | 2026 declaration | Meaning carrier in EEL | Valid representation |
| --- | --- | --- | --- |
| `bus:CountryFormationOrIncorporation` | `types:fixedItemType`, duration | `countries:CountriesRegionsDimension = countries:EnglandWales` | Empty `ix:nonNumeric` |
| `bus:LegalFormEntity` | `types:fixedItemType`, duration | `bus:LegalFormEntityDimension = bus:PrivateLimitedCompanyLtd` | Empty `ix:nonNumeric` |
| `bus:EntityTradingStatus` | `types:fixedItemType`, duration | The implicit `bus:EntityTradingDefault`, or one explicit non-default trading-status member | Empty `ix:nonNumeric` |
| `bus:AccountingStandardsApplied` | `types:fixedItemType`, duration | `bus:AccountingStandardsDimension = bus:Micro-entities` | Empty `ix:nonNumeric` |
| `bus:AccountsStatusAuditedOrUnaudited` | `types:fixedItemType`, duration | `bus:AccountsStatusDimension = bus:AuditExempt-NoAccountantsReport` | Empty `ix:nonNumeric` |
| `bus:AccountsType` | `types:fixedItemType`, duration | `bus:AccountsTypeDimension = bus:FullAccounts` | Empty `ix:nonNumeric` |
| `core:DirectorSigningFinancialStatements` | `types:fixedItemType`, duration | `bus:EntityOfficersDimension = bus:Director1` | Empty `ix:nonNumeric`; the name is a separate fact |

The business declarations are in
`cd/2026-01-01/business/bus-2026-01-01.xsd`. The signing declaration is in
`fr/2026-01-01/core/frc-core-2026-01-01.xsd`.

The business label linkbase expressly says to use the corresponding dimension
for country of formation, legal form, trading status, accounting standards and
accounts status. The definition linkbases connect the listed members to their
dimensions and connect each fixed primary item to its dimensional hypercube.

## Entity trading status

`cd/2026-01-01/business/bus-2026-01-01-definition.xml` declares:

- `bus:EntityTradingDefault` as the default member of
  `bus:EntityTradingStatusDimension`;
- `bus:EntityHasNeverTraded` and
  `bus:EntityNoLongerTradingButTradedInPast` as its two non-default members.

The English label for `bus:EntityTradingDefault` is
“Entity is trading [default]”. XBRL Dimensions requires a default member to be
omitted from an instance context. EEL therefore emits the empty
`bus:EntityTradingStatus` fact on the ordinary duration context when the entity
is trading. It emits an explicit status dimension only for `never_traded` or
`no_longer_trading`.

It would be wrong to put the word `trading`, a Boolean, an enumeration QName
or the explicit member `bus:EntityTradingDefault` inside the current
representation.

## Director signing

`core:DirectorSigningFinancialStatements` is not a Boolean or a name field.
Its fixed-item type requires zero-length content. EEL pairs it with:

- the same duration `contextRef` as `bus:NameEntityOfficer`;
- `bus:EntityOfficersDimension = bus:Director1`; and
- a non-empty visible `bus:NameEntityOfficer` containing the selected
  approving director's frozen name.

This makes the signer unambiguous without putting invalid text in the signing
marker. The historic Companies House reference uses the same marker/name
pattern. EEL additionally validates that exactly one marker and one name are
present, they share a context, and that context identifies `bus:Director1`.

## Approval-date context

`core:DateAuthorisationFinancialStatementsForIssue` is declared in the 2026
core schema as `xbrli:dateItemType` with `xbrli:periodType="instant"`. The
literal date value and the XBRL context instant serve different purposes: the
fact value records the approval date, while the instant context locates the
fact in the reporting snapshot.

EEL uses the balance-sheet instant context for this fact. In the supplied
artifact:

- fact value: `17 July 2026`, transformed to the ISO value `2026-07-17`;
- context: `current_period_end`;
- context instant: `2023-09-30`.

This satisfies the 2026 concept period type, matches the structure emitted by
the Companies House online filing reference (whose approval-date value also
uses the balance-sheet instant), and passes FRC 2026 taxonomy validation in
Arelle. The taxonomy schema alone requires an instant context but does not say
that the context instant must equal the date lexical value. A separate
approval-date instant would be schema-compatible but would depart from the
selected Companies House/FRC filing convention.

Regression coverage exists in:

- `web_root/tests/test_IxbrlFactBuilderService.php`;
- `web_root/tests/test_IxbrlAccountingService.php`; and
- `web_root/tests/test_IxbrlCategoricalFacts.php`.

## Dimensional contexts in the supplied final artifact

All contexts use entity identifier `14337285` with scheme
`http://www.companieshouse.gov.uk/`. There are no `xbrldi:typedMember`
contexts.

| Context ID | Period | Explicit dimension(s) | Facts using the context |
| --- | --- | --- | --- |
| `current_period_duration_director_1` | 2022-09-05 to 2023-09-30 | `bus:EntityOfficersDimension = bus:Director1` | `core:DirectorSigningFinancialStatements`, `bus:NameEntityOfficer` |
| `current_period_duration_accounts_type` | 2022-09-05 to 2023-09-30 | `bus:AccountsTypeDimension = bus:FullAccounts` | `bus:AccountsType` |
| `current_period_duration_country_formation` | 2022-09-05 to 2023-09-30 | `countries:CountriesRegionsDimension = countries:EnglandWales` | `bus:CountryFormationOrIncorporation` |
| `current_period_duration_legal_form` | 2022-09-05 to 2023-09-30 | `bus:LegalFormEntityDimension = bus:PrivateLimitedCompanyLtd` | `bus:LegalFormEntity` |
| `current_period_duration_registered_office` | 2022-09-05 to 2023-09-30 | `bus:EntityContactTypeDimension = bus:RegisteredOffice`; `countries:CountriesRegionsDimension = countries:UnitedKingdom` | `bus:AddressLine1`, `bus:AddressLine2`, `bus:AddressLine3`, `bus:PostalCodeZip` |
| `current_period_duration_accounting_standards` | 2022-09-05 to 2023-09-30 | `bus:AccountingStandardsDimension = bus:Micro-entities` | `bus:AccountingStandardsApplied` |
| `current_period_duration_accounts_status` | 2022-09-05 to 2023-09-30 | `bus:AccountsStatusDimension = bus:AuditExempt-NoAccountantsReport` | `bus:AccountsStatusAuditedOrUnaudited` |
| `current_period_end_creditors_within_one_year` | instant 2023-09-30 | `core:MaturitiesOrExpirationPeriodsDimension = core:WithinOneYear` | `core:Creditors` |
| `current_period_end_creditors_after_one_year` | instant 2023-09-30 | `core:MaturitiesOrExpirationPeriodsDimension = core:AfterOneYear` | `core:Creditors` |
| `current_period_end_superseded` | instant 2023-09-30 | `bus:OriginalRevisedDataDimension = bus:Superseded` | Original `core:CurrentAssets`, `core:Equity`, `core:FixedAssets`, `core:NetAssetsLiabilities`, `core:NetCurrentAssetsLiabilities`, `core:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal`, `core:TotalAssetsLessCurrentLiabilities` |
| `current_period_end_superseded_creditors_within_one_year` | instant 2023-09-30 | `core:MaturitiesOrExpirationPeriodsDimension = core:WithinOneYear`; `bus:OriginalRevisedDataDimension = bus:Superseded` | Original `core:Creditors` |

The current revised facts omit `bus:OriginalRevisedDataDimension` and therefore
use its current/default member. Only superseded original facts use
`bus:Superseded`.

Arelle 2.43.0 accepted the supplied artifact against the installed package
with no schema, concept, period-type or dimensional errors. During this audit,
the prior artifact produced one unrelated recommendation that `ix:header` be
nested in an element whose inline style is `display:none`; class-based hiding
was not recognised for that check. The generator now emits the inline style as
well as its CSS class. That recommendation was not evidence against any
categorical fact or dimension above. Arelle validation is not a substitute for
the official Companies House gateway validator.
