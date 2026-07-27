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

EEL uses the balance-sheet instant context for this fact. The fact's transformed
ISO value is the workflow approval date, while its context is
`current_period_end` at the balance-sheet date.

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

## Dimensional contexts

Generated contexts use the registered company number with scheme
`http://www.companieshouse.gov.uk/`. The generator uses explicit dimensions
for the director, accounts type, country of formation, legal form, registered
office, accounting standard, accounts status, creditor maturity and
superseded-original facts. It does not create `xbrldi:typedMember` contexts for
these classifications.

The current revised facts omit `bus:OriginalRevisedDataDimension` and therefore
use its current/default member. Only superseded original facts use
`bus:Superseded`.

Arelle accepted the reviewed artifact against the installed package with no
schema, concept, period-type or dimensional errors. During this audit, the
prior artifact produced one unrelated recommendation that `ix:header` be
nested in an element whose inline style is `display:none`; class-based hiding
was not recognised for that check. The generator now emits the inline style as
well as its CSS class. That recommendation was not evidence against any
categorical fact or dimension above. Arelle validation is not a substitute for
the official Companies House gateway validator.
