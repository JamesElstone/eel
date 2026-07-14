# Mathematic Basis

## Corporation Tax calculation integrity and mathematical assurance

| Document field | Value |
|---|---|
| Document purpose | Explain and evidence the mathematical basis by which the application converts accounting records into a UK Corporation Tax calculation |
| Assurance scope | Accounting data integrity and tax calculation only |
| Application version reviewed | Git commit `a8befd62408aa4fa513d4e8cef159a9837230a77` |
| Review date | 14 July 2026 |
| Rule sources checked | Current GOV.UK and HMRC material listed in Appendix A |
| Example-data policy | All numerical examples in this document are invented, deterministic examples; no live company data is used |

## 1. Purpose and conclusion

This document describes the complete mathematical path from posted double-entry accounting records to an estimated Corporation Tax liability. It is intended to allow a reviewer to reproduce the result independently from the same inputs and to identify the conditions under which the calculation can, and cannot, be relied upon.

The reviewed implementation provides a reproducible and traceable calculation for an ordinary UK-resident company with non-ring-fence trading profits, within the applicability conditions in section 3. The calculation:

1. reads only posted journals within the applicable date range;
2. proves ledger equality by comparing total debits with total credits;
3. constructs profit before Corporation Tax using explicit debit and credit sign conventions;
4. applies sourced tax-treatment rules to accounting expenses;
5. adds back disallowable expenditure and depreciation;
6. substitutes the supported capital-allowance calculation for accounting depreciation;
7. rolls supported trading losses forward in chronological order;
8. apportions profits and thresholds by inclusive calendar days where a financial-year boundary is crossed;
9. applies the small-profits rate, main rate or statutory marginal-relief formula; and
10. rounds and records the result in a repeatable manner.

The mathematical evidence supports use within that defined scope. It does not support tax cases expressly excluded in section 3.

## 2. Assurance proposition

For a Corporation Tax period, the application is treated as having preserved data integrity only when the following proposition is true:

\[
\text{Reliable tax result}
= C \land B \land P \land R \land A \land L \land T
\]

where:

- \(C\) = the population of posted journals is complete for the selected company, accounting period and date interval;
- \(B\) = total debits equal total credits to the penny;
- \(P\) = the accounting period is partitioned into continuous, non-overlapping Corporation Tax periods of no more than 12 months;
- \(R\) = each relevant profit-and-loss nominal has a reviewed tax treatment, with no unresolved `other` or unknown treatment affecting the result;
- \(A\) = the fixed-asset register and supported capital-allowance pools are complete for the period;
- \(L\) = brought-forward losses fall within the supported ordinary trading-loss model; and
- \(T\) = the correct sourced rate rule, augmented-profit assumption and associated-company count apply.

This is deliberately conjunctive. A numerically balanced answer is not considered sufficient if, for example, the period is incomplete or a material expense has no resolved tax treatment.

## 3. Scope and applicability conditions

### 3.1 Included calculation cases

The assurance in this document applies to:

- UK non-ring-fence trading profits;
- accounting records maintained using double-entry journals;
- posted income, cost-of-sales and expense lines allocated to a defined accounting period;
- accounting depreciation calculated by the supported straight-line or reducing-balance methods;
- depreciation add-back;
- disallowable expense add-backs where the period balance represents a positive expense;
- Annual Investment Allowance for supported qualifying plant, machinery, tools, equipment and vans;
- supported car treatment: 100% first-year allowance for qualifying new and unused zero-emission cars, or main/special-rate pool writing-down allowance according to the recorded vehicle facts;
- main-pool and special-rate-pool writing-down allowance;
- pool disposal values and balancing charges produced by the supported capital-allowance model;
- ordinary carried-forward trading losses below the loss-restriction threshold;
- the non-ring-fence rate regimes represented by active, date-effective rate rules;
- short Corporation Tax periods and periods crossing 31 March; and
- the small-profits rate, main rate and marginal relief, including threshold reduction for associated companies.

### 3.2 Conditions that must be true before reliance

The following facts are inputs to the mathematics and must be confirmed for the company being calculated:

- the company is within the non-ring-fence Corporation Tax regime;
- the recorded associated-company count is correct for the relevant period;
- augmented profits equal taxable total profits because no relevant exempt distributions need to be added; the current end-to-end calculation uses this equality;
- carried-forward losses are ordinary trading losses available to the continuing trade and are not subject to group allocation, streaming or the £5 million deductions-allowance restriction;
- no unsupported relief, credit or separate source of profit is required;
- every asset relevant to capital allowances is present, dated, valued and classified correctly;
- the statutory availability conditions for any claimed first-year allowance are satisfied for the asset and purchase date; and
- every warning concerning an unreviewed asset or tax treatment has been resolved.

### 3.3 Calculation cases not evidenced by this document

This document does not claim mathematical coverage for:

- ring-fence profits;
- close investment-holding company restrictions;
- qualifying exempt distributions where augmented profits exceed taxable total profits;
- chargeable gains or capital losses;
- periods containing a book profit or loss on disposal that requires removal from trading profit under tax rules;
- capital expenditure charged directly to the profit and loss account: the calculation identifies `capital` treatment rows but the current taxable-profit bridge does not add that separate value back, so such rows must be nil before reliance;
- a credit balance or reversal in a disallowable expense nominal: the implemented add-back takes the magnitude of the net expense row, so these cases require separate review;
- R&D relief or expenditure credits, Patent Box, creative-industry reliefs, charitable donations, group relief, loss carry-back, terminal-loss relief, property-business losses, non-trading loan relationships, foreign tax or double-taxation relief;
- the carried-forward loss restriction above the available deductions allowance;
- structures and buildings allowance, full expensing, the 40% first-year allowance, private-use adjustments or other capital-allowance regimes not listed in section 3.1; or
- a cessation period in which AIA is unavailable or special cessation rules apply.

If any excluded case is present, the ordinary computation may remain a useful starting schedule, but this document does not assert that its resulting tax figure is complete.

## 4. Source data and ledger integrity

### 4.1 Unit of account

The atomic accounting evidence is a journal line containing:

- company identifier;
- accounting-period identifier;
- journal date;
- posted state;
- nominal account;
- debit amount; and
- credit amount.

Monetary records are stored to two decimal places. Calculations use pounds and pence. A journal is balanced when:

\[
\sum_{l=1}^{n} Debit_l = \sum_{l=1}^{n} Credit_l
\]

The comparison tolerance is less than half a penny:

\[
\left|\sum Debit_l - \sum Credit_l\right| < 0.005
\]

Manual journals are rejected before saving when their debit and credit totals differ by at least £0.005 after two-decimal rounding. Generated accounting journals are also tested through the trial-balance and deterministic-fixture suites.

### 4.2 Population selected for profit and loss

For a requested interval \([s,e]\), a journal contributes to the accounting profit calculation only when all of the following are true:

\[
Included(j) =
(Company_j = Company)
\land (Period_j = Period)
\land (Posted_j = 1)
\land (s \le Date_j \le e)
\]

Only nominal accounts classified as `income`, `cost_of_sales` or `expense` enter the profit-and-loss calculation.

Retained-earnings closing journals are excluded because they transfer an already calculated result into equity and would otherwise count the same profit twice. Posted asset-depreciation journals are excluded from the ordinary journal aggregation because depreciation is read from its canonical asset-depreciation schedule; section 6 explains the resulting single-source treatment.

### 4.3 Source coverage proof

Every posted journal is placed in a named source category. Recognised categories include bank imports, expense-register journals, manual journals, director-loan offsets, depreciation and asset disposals. Any unrecognised source is placed in `other`; it is not silently discarded.

Coverage passes only when all three equations hold:

\[
J_{covered}=J_{posted}
\]

\[
D_{covered}=D_{posted}
\]

\[
C_{covered}=C_{posted}
\]

The comparison is made to two decimal places using the same half-penny tolerance. This proves population coverage independently from the profit classification of each nominal account.

## 5. Accounting profit calculation

For each nominal account, let \(D_k\) be total debits and \(C_k\) total credits in the selected interval.

Income uses its natural credit sign:

\[
Income = \sum_{k \in income}(C_k-D_k)
\]

Cost of sales and expenses use their natural debit sign:

\[
CostOfSales = \sum_{k \in cost\_of\_sales}(D_k-C_k)
\]

\[
PostedOperatingExpenses = \sum_{k \in expense,\ k \ne CT}(D_k-C_k)
\]

The configured Corporation Tax expense nominal is removed from operating expenses. This prevents a circular calculation in which a tax provision reduces the accounting profit used to calculate that same tax provision.

Accounting depreciation is then included once:

\[
OperatingExpenses = PostedOperatingExpenses + Depreciation
\]

The principal accounting subtotals are:

\[
GrossProfit = Income-CostOfSales
\]

\[
ProfitBeforeTax = GrossProfit-OperatingExpenses
\]

The accounting-period-to-Corporation-Tax-period reconciliation is:

\[
ProfitBeforeTax_{AP} = \sum_{i=1}^{m} ProfitBeforeTax_{CT_i}
\]

and the reported reconciliation difference is:

\[
Difference = ProfitBeforeTax_{AP}-\sum ProfitBeforeTax_{CT_i}
\]

A zero difference demonstrates that splitting a long accounting period has neither omitted nor duplicated accounting profit.

## 6. Depreciation calculation and single counting

### 6.1 Depreciable amount

For each asset:

- \(C\) = recorded cost;
- \(R\) = residual value;
- \(Y\) = useful life in years, with a minimum of one;
- \(OD\) = depreciation posted before the current interval; and
- \(d/y\) = inclusive days in the current interval divided by the number of days in the relevant calendar year.

For straight-line depreciation:

\[
AnnualDepreciation = \frac{C-R}{Y}
\]

For reducing-balance depreciation as implemented:

\[
OpeningNBV = \max(R,C-OD)
\]

\[
AnnualDepreciation = OpeningNBV \times \frac{1}{Y}
\]

In both cases:

\[
RemainingCap = \max(0,(C-R)-OD)
\]

\[
PeriodDepreciation = round_2\left(\min\left(RemainingCap,AnnualDepreciation\times\frac{d}{y}\right)\right)
\]

The calculation is bounded by purchase date, disposal date, accounting-period end and the final day of useful life. It cannot depreciate the asset below residual value.

### 6.2 Posted and provisional values

Before year-end completion, the calculation uses already posted depreciation entries plus a deterministic preview for assets not yet posted. After the depreciation entry is posted, that asset-period is no longer pending. The same depreciation is therefore not counted as both posted and previewed.

A final locked tax snapshot is calculated from the fresh year-end position. A live, unlocked calculation remains reproducible but is provisional because its underlying journals, assets and classifications are still open to amendment.

### 6.3 Allocation to a Corporation Tax period

If a depreciation row covers \(r\) inclusive days and overlaps a Corporation Tax period by \(o\) inclusive days:

\[
AllocatedDepreciation = round_2\left(RowDepreciation\times\frac{o}{r}\right)
\]

This daily allocation is also used in the accounting-period/Corporation-Tax-period reconciliation.

## 7. Tax-treatment classification

Each profit-and-loss nominal is assigned a Corporation Tax treatment using the first active, date-effective rule in priority order. A rule may match a specific nominal identifier, nominal code, account type, name text or a combination. If no active rule matches, the nominal account's stored treatment is used.

The recognised states are:

- `allowable`: no adjustment to accounting profit;
- `disallowable`: added back;
- `capital`: identified for review and excluded from this document's reliance unless the amount is nil;
- `other`: manual review required; and
- unknown: manual review required.

For supported disallowable debit expense balances:

\[
DisallowableAddBack = \sum_{k \in disallowable}|D_k-C_k|
\]

Because the implemented expression uses magnitude, a disallowable nominal containing a credit balance, refund or reversal is outside the automatic assurance boundary and must be reviewed separately.

## 8. Capital allowances

### 8.1 Annual Investment Allowance

For supported AIA-qualifying additions, the available limit for a Corporation Tax period is derived from the date-effective annual rule. If the rule is constant throughout the period:

\[
AIALimit = round_2\left(AnnualLimit\times\min\left(1,\frac{PeriodDays}{365}\right)\right)
\]

Where a limit changes during a period, the sourced amounts are first weighted by overlap days. The resulting formula is equivalent to summing each rule's annual limit multiplied by its covered days divided by 365.

For qualifying asset \(a\), processed chronologically:

\[
AIA_a = round_2(\min(Cost_a,AIA_{remaining}))
\]

\[
AIA_{remaining,new}=round_2(AIA_{remaining}-AIA_a)
\]

Any unrelieved qualifying cost is added to the main pool. Business cars are not allocated AIA.

### 8.2 First-year allowance and writing-down allowance

Supported new and unused zero-emission cars receive:

\[
FYA_a = Cost_a
\]

The implemented factual gate is the recorded combination of `new_unused` and zero-emission status. Reliance additionally requires confirmation that the statutory first-year allowance is available for the recorded purchase date.

Other supported cars are assigned to the main or special-rate pool from their recorded facts and date-effective CO2 threshold. Missing facts produce an explicit warning and conservative special-rate treatment where implemented; an unreviewed generic vehicle classification is excluded from the allowance model until resolved.

For each pool:

\[
PreWDAPool = OpeningWDV + UnrelievedAdditions - DisposalValue
\]

If \(PreWDAPool<0\):

\[
BalancingCharge=|PreWDAPool|,\quad PreWDAPool=0
\]

Otherwise the writing-down allowance is:

\[
WDA = round_2\left(PreWDAPool\times WeightedRate\times\min\left(1,\frac{PeriodDays}{365}\right)\right)
\]

\[
ClosingWDV = round_2(PreWDAPool-WDA)
\]

The pool's net deduction is:

\[
NetCapitalAllowances = AIA + FYA + WDA + BalancingAllowance - BalancingCharge
\]

Consequently, subtracting net capital allowances from accounting profit both deducts allowances and adds balancing charges:

\[
-NetCapitalAllowances = -AIA-FYA-WDA-BalancingAllowance+BalancingCharge
\]

Pool disposal arithmetic is therefore traceable. The wider Corporation Tax treatment of an accounting profit or loss on disposal, or of a chargeable gain, is outside this document's assurance scope as stated in section 3.3.

## 9. Taxable profit bridge

Let:

- \(P\) = accounting profit or loss before Corporation Tax;
- \(E\) = supported disallowable expense add-backs;
- \(D\) = accounting depreciation;
- \(CA\) = net capital allowances from section 8.

The implemented bridge is:

\[
TaxableBeforeLosses = round_2(P+E+D-CA)
\]

This reflects the tax principle that accounting profit is the starting point, depreciation is not a tax deduction, and supported capital allowances provide the replacement tax deduction.

The bridge is arithmetically self-reconciling:

\[
BridgeDifference = TaxableBeforeLosses-(P+E+D-CA)=0.00
\]

Any non-zero difference is a calculation failure.

## 10. Trading-loss roll-forward

Let:

- \(T\) = taxable result before losses;
- \(L_{BF}\) = supported losses brought forward;
- \(L_U\) = losses used in the current period;
- \(L_C\) = new loss created; and
- \(L_{CF}\) = losses carried forward.

The implemented ordinary loss roll-forward is:

\[
L_U=\min(\max(0,T),L_{BF})
\]

\[
TaxableProfit=\max(0,round_2(T-L_U))
\]

\[
L_C=\max(0,-T)
\]

\[
L_{CF}=round_2(L_{BF}-L_U+L_C)
\]

Periods are processed chronologically. Losses are consumed from the existing loss pool before a later loss is added. The identities checked are:

\[
L_U \le L_{BF}
\]

\[
L_U \le \max(0,T)
\]

\[
TaxableProfit \ge 0
\]

\[
L_{CF} \ge 0
\]

This model does not implement the special restriction that can limit carried-forward loss use above the available £5 million deductions allowance. Such a case is outside scope.

## 11. Corporation Tax periods and financial-year allocation

### 11.1 Corporation Tax period derivation

An accounts period longer than 12 months is partitioned into consecutive Corporation Tax periods. Starting at date \(s_i\):

\[
e_i=\min(AccountsEnd,s_i+1\ year-1\ day)
\]

\[
s_{i+1}=e_i+1\ day
\]

The derivation is valid only when:

- the first Corporation Tax period starts on the accounts start date;
- each later period starts one day after the previous period ends;
- no Corporation Tax period exceeds 12 months; and
- the final Corporation Tax period ends on the accounts end date.

### 11.2 Financial-year segments

A Corporation Tax period crossing 31 March is divided into financial-year segments. Let:

- \(N\) = taxable total profits for the Corporation Tax period;
- \(A\) = augmented profits;
- \(D\) = inclusive days in the Corporation Tax period;
- \(d_i\) = inclusive days in segment \(i\); and
- \(FYDays_i\) = 365 or 366 days in that financial year.

Profits are apportioned on a strict time basis:

\[
q_i=\frac{d_i}{D}
\]

\[
N_i=round_{10}(Nq_i),\quad A_i=round_{10}(Aq_i)
\]

This follows HMRC's distinction that profits are apportioned by accounting-period days, while thresholds are apportioned by days in the relevant financial year.

## 12. Corporation Tax rate mathematics

### 12.1 Applicable sourced rates

For ordinary non-ring-fence profits in the reviewed rule range:

- before 1 April 2023, the applicable main rate is 19%;
- from 1 April 2023, the small-profits rate is 19%, the main rate is 25%, the lower limit is £50,000, the upper limit is £250,000, and the standard marginal-relief fraction is \(3/200=0.015\).

Rates are stored as date-effective sourced rules. A calculation fails if no active rule completely covers the required financial year; it does not silently invent a rate.

### 12.2 Adjusted limits

Let \(a\) be the number of other associated companies. The divisor is the total number of associated companies including the subject company:

\[
CompanyDivisor=a+1
\]

For financial-year segment \(i\):

\[
Lower_i=round_{10}\left(50000\times\frac{d_i}{FYDays_i}\div CompanyDivisor\right)
\]

\[
Upper_i=round_{10}\left(250000\times\frac{d_i}{FYDays_i}\div CompanyDivisor\right)
\]

### 12.3 Band decision

For a segment after 1 April 2023:

If \(A_i\le Lower_i\):

\[
Tax_i=N_i\times 0.19
\]

If \(Lower_i<A_i\le Upper_i\), marginal relief is:

\[
MR_i=\left(F\times(Upper_i-A_i)\right)\times\frac{N_i}{A_i}
\]

where \(F=3/200\). Tax is:

\[
Tax_i=\max(0,N_i\times0.25-MR_i)
\]

If \(A_i>Upper_i\):

\[
Tax_i=N_i\times0.25
\]

For a segment to which the pre-1 April 2023 flat regime applies:

\[
Tax_i=N_i\times0.19
\]

The Corporation Tax liability is:

\[
CorporationTax=round_2\left(\sum_i round_2(Tax_i)\right)
\]

and the reported effective rate is:

\[
EffectiveRate=round_6\left(\frac{CorporationTax}{N}\right)
\]

In the current end-to-end calculation, \(A=N\). If qualifying exempt distributions make \(A>N\), this assumption is false and the result is outside this assurance scope.

## 13. Rounding policy

Rounding is explicit and repeatable:

- source money values are held to two decimal places;
- P&L subtotals, adjustments, pool movements, losses and final liabilities are rounded to two decimal places;
- financial-year profit shares and adjusted thresholds are retained to ten decimal places before band calculation;
- effective rates are rounded to six decimal places;
- each financial-year segment's liability is rounded to two decimal places before segments are summed; and
- equality checks use a tolerance strictly below £0.005.

Let \(round_p(x)\) mean conventional rounding to \(p\) decimal places. Rounding at the stated stages is part of the defined algorithm. An independent reproduction must round at the same stages, rather than only rounding the final answer.

## 14. Synthetic worked examples

All figures in this section are fictional.

### 14.1 Accounting profit, tax adjustments and small-profits rate

Assumptions: 12-month period wholly after 1 April 2023, no associated companies, no exempt distributions, no losses brought forward.

| Fictional input | Amount |
|---|---:|
| Income | £150,000.00 |
| Cost of sales | £50,000.00 |
| Posted operating expenses, including £2,000 disallowable entertainment | £54,000.00 |
| Accounting depreciation | £6,000.00 |
| Supported capital allowances | £8,000.00 |

Accounting profit:

\[
GrossProfit=150000-50000=100000
\]

\[
ProfitBeforeTax=100000-(54000+6000)=40000
\]

Taxable profit:

\[
TaxableBeforeLosses=40000+2000+6000-8000=40000
\]

There are no brought-forward losses, so taxable profit is £40,000. The small-profits rate applies:

\[
CorporationTax=40000\times19\%=£7,600.00
\]

The example also demonstrates why depreciation is present twice: it first reduces accounting profit, is then added back for tax, and the separate capital-allowance deduction replaces it.

### 14.2 Creation and later use of a trading loss

First fictional period:

| Input | Amount |
|---|---:|
| Accounting loss | £(12,000.00) |
| Disallowable add-back | £2,000.00 |
| Depreciation add-back | £3,000.00 |
| Capital allowances | £8,000.00 |
| Losses brought forward | £5,000.00 |

\[
T=-12000+2000+3000-8000=-15000
\]

No brought-forward loss is used against another loss. The new loss is £15,000 and:

\[
L_{CF}=5000-0+15000=£20,000.00
\]

Taxable profit and Corporation Tax are both nil.

Second fictional period:

\[
T=25000,\quad L_{BF}=20000
\]

\[
L_U=\min(25000,20000)=20000
\]

\[
TaxableProfit=25000-20000=£5,000.00
\]

\[
L_{CF}=20000-20000+0=£0.00
\]

At 19%, Corporation Tax is:

\[
5000\times19\%=£950.00
\]

### 14.3 Marginal relief

Assumptions: 12-month period, no associated companies, taxable total profits and augmented profits both £100,000.

\[
MainRateTax=100000\times25\%=£25,000.00
\]

\[
MR=\left(\frac{3}{200}\times(250000-100000)\right)\times\frac{100000}{100000}
\]

\[
MR=0.015\times150000=£2,250.00
\]

\[
CorporationTax=25000-2250=£22,750.00
\]

The effective rate is 22.75%.

### 14.4 Short period with one other associated company

Assumptions: a 183-day segment in a 365-day financial year, one other associated company, and taxable total profits equal augmented profits of £40,000.

\[
Lower=50000\times\frac{183}{365}\div2=£12,534.25
\]

\[
Upper=250000\times\frac{183}{365}\div2=£62,671.23
\]

The profit lies in the marginal-relief band:

\[
MR=0.015\times(62671.2328767-40000)=£340.07
\]

\[
CorporationTax=(40000\times25\%)-340.07=£9,659.93
\]

## 15. Traceability and repeatability

### 15.1 Calculation trace

Each calculation exposes the values needed to reproduce it:

- accounting profit or loss;
- disallowable add-backs;
- depreciation add-back;
- capital allowances;
- taxable result before losses;
- losses brought forward, used, created and carried forward;
- taxable profit;
- associated-company count;
- each financial-year rate band, limits, relief and liability;
- effective rate; and
- final Corporation Tax liability.

The persisted year-end calculation also records a SHA-256 computation hash derived from the calculation identity and principal mathematical inputs and result, including company, accounting period, Corporation Tax period, dates, accounting profit, disallowables, depreciation, capital allowances, losses, associated-company count and rate liability. The hash is an integrity fingerprint of those values; it is not described as a hash of every source journal line.

### 15.2 Live and final calculations

An unlocked period is recalculated live from the current accounting evidence and is not treated as final. At successful year-end lock, fresh Corporation Tax summaries are calculated and persisted within the same database transaction as the lock. If the transaction fails, the generated calculation evidence is rolled back. A locked period reads its stored calculation snapshot.

This creates two explicit states:

- **provisional:** repeatable from current open records, but those records may still change; and
- **final:** calculated from the completed year-end position and retained with its computation hash.

### 15.3 Independent mathematical oracle

The deterministic assurance suite contains a pure accounting oracle and a separate pure Corporation Tax oracle. They do not call the production calculation classes and do not read the database. Their expected results are computed independently from a synthetic semantic ledger specification.

The comparison process is:

1. build a fresh in-memory synthetic company and ledger;
2. calculate expected debits, credits, profit, adjustments, losses and tax in the independent oracle;
3. calculate the same results through the production accounting and tax paths;
4. compare each semantic value, normally to two decimal places; and
5. fail the test on any unexplained difference.

This is stronger than recording the application's own output as an expected snapshot because the expected mathematics is independently expressed.

## 16. Verification evidence for this version

The following deterministic test programs were executed against commit `a8befd62408aa4fa513d4e8cef159a9837230a77` on 14 July 2026. Each completed with exit code 0:

| Test program | Calculation evidence |
|---|---|
| `test_GoldenAccountsFixture.php` | Synthetic journals remain balanced and agree with their semantic manifest |
| `test_GoldenAccountingOracle.php` | Independent ledger and Corporation Tax oracles agree with calculated P&L, add-backs, losses, rates and tax |
| `test_GoldenYearEndLifecycle.php` | Depreciation, capital allowances, tax and final locked results remain arithmetically stable through year end |
| `test_ManualJournalService.php` | Manual double-entry validation |
| `test_TrialBalanceService.php` | Debit/credit equality and trial-balance calculations |
| `test_ProfitLossService.php` | Profit-before-tax calculation, Corporation Tax expense separation and long-period reconciliation |
| `test_ProfitLossSourceCoverageService.php` | All known and unknown posted source types reconcile by count, debits and credits |
| `test_CorporationTaxTreatmentRuleService.php` | Priority, date-effective tax classification and fallback behaviour |
| `test_CorporationTaxPeriodService.php` | Continuous period partitioning with a maximum of 12 months |
| `test_CorporationTaxRateService.php` | 19% rate, 19%/25% bands, marginal relief and associated-company threshold reduction |
| `test_TaxRateRuleService.php` | Parsing and date-weighting of sourced tax and capital-allowance rules |
| `test_VehicleService.php` | AIA, qualifying zero-emission car FYA and special-rate WDA calculations |
| `test_YearEndClosePreviewService.php` | Depreciation single counting, fresh final calculation and transactional rollback |

The test data is synthetic. No production or live company figures are embedded in the expected results.

### 16.1 Reproduction commands

From the project root, the principal evidence can be rerun with:

```powershell
php web_root/tests/test_GoldenAccountsFixture.php
php web_root/tests/test_GoldenAccountingOracle.php
php web_root/tests/test_GoldenYearEndLifecycle.php
php web_root/tests/test_ManualJournalService.php
php web_root/tests/test_TrialBalanceService.php
php web_root/tests/test_ProfitLossService.php
php web_root/tests/test_ProfitLossSourceCoverageService.php
php web_root/tests/test_CorporationTaxTreatmentRuleService.php
php web_root/tests/test_CorporationTaxPeriodService.php
php web_root/tests/test_CorporationTaxRateService.php
php web_root/tests/test_TaxRateRuleService.php
php web_root/tests/test_VehicleService.php
php web_root/tests/test_YearEndClosePreviewService.php
```

A non-zero exit status, an exception or a reported comparison difference invalidates the corresponding assurance statement until investigated.

## 17. Review protocol for an individual calculation

For a specific company's calculation, a reviewer should be able to perform the following data-integrity review without relying on the displayed tax total alone:

1. Confirm the accounting and Corporation Tax period start/end dates and that the CT periods cover the accounts continuously.
2. Confirm the posted-journal population count and source-coverage reconciliation.
3. Confirm total debits equal total credits.
4. Recalculate income, cost of sales, operating expenses, depreciation and profit before tax using section 5.
5. Confirm the sum of CT-period profit equals accounting-period profit.
6. Inspect all `other`, unknown, `capital`, negative disallowable and asset-warning states; reliance requires these to be nil or separately resolved.
7. Recalculate the taxable-profit bridge using section 9.
8. Reconcile the capital-allowance pool opening WDV, additions, AIA/FYA, disposals, WDA, balancing charges and closing WDV.
9. Recalculate the loss roll-forward using section 10.
10. Confirm non-ring-fence status, augmented-profit assumption and associated-company count.
11. Recalculate each financial-year band using inclusive days and section 12.
12. Confirm the sum of band liabilities equals the reported Corporation Tax to the penny.
13. For a final period, record the calculation version, active rule versions and computation hash.

Completion of these steps provides a direct arithmetic audit trail from ledger evidence to tax liability.

## 18. Change control

This assurance is version-specific. It must be reviewed when any of the following changes:

- accounting sign conventions or journal inclusion criteria;
- nominal account types or tax-treatment rules;
- depreciation methods;
- capital-allowance eligibility, rates or limits;
- loss utilisation logic;
- Corporation Tax rates, thresholds, marginal-relief fraction or associated-company rules;
- rounding stages; or
- the composition of the computation hash or final snapshot.

The current rule tables retain source URL, source checked date, rule version, effective dates and active state. Missing date coverage causes a calculation error instead of an unsourced fallback.

## Appendix A: authoritative mathematical sources

The following official sources were checked for this version. They are included to make the legal and mathematical provenance inspectable; the application rule version remains the reproducibility anchor for a historical calculation.

1. HMRC, [Accounting periods for Corporation Tax](https://www.gov.uk/corporation-tax-accounting-period) — Corporation Tax periods cannot exceed 12 months, so a longer accounts period must be divided.
2. HMRC Business Income Manual, [BIM35201: the role of generally accepted accounting practice](https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim35201) — accounts are the starting point; depreciation and other disallowed expenditure require tax adjustment.
3. HMRC, [Corporation Tax rates and allowances](https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax) — non-ring-fence rates, £50,000/£250,000 limits and standard fraction of 3/200.
4. HMRC Company Taxation Manual, [CTM03925: marginal-relief formula](https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm03925) — \((F\times(U-A))\times(N/A)\).
5. HMRC Company Taxation Manual, [CTM03955: accounting periods straddling a financial year](https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm03955) — time apportionment of profits and financial-year-day apportionment of limits.
6. HMRC Company Taxation Manual, [CTM01750: rates of tax](https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm01750) — profits crossing 31 March are time apportioned and each portion is charged for its financial year.
7. HMRC, [Marginal Relief for Corporation Tax](https://www.gov.uk/guidance/corporation-tax-marginal-relief) — short-period and associated-company reductions to limits.
8. HMRC, [Capital allowances overview](https://www.gov.uk/capital-allowances) — capital allowances deduct qualifying asset value from profit before tax.
9. HMRC, [Annual Investment Allowance](https://www.gov.uk/capital-allowances/annual-investment-allowance) — £1 million amount, exclusions for cars and time adjustment for short periods.
10. HMRC, [Writing-down allowance rates and pools](https://www.gov.uk/work-out-capital-allowances/rates-and-pools) — main and special-rate pool treatment and date-effective rates.
11. HMRC, [Work out and claim relief from Corporation Tax trading losses](https://www.gov.uk/guidance/corporation-tax-calculating-and-claiming-a-loss) — tax adjustments, capital allowances, balancing charges and trading-loss treatment.
12. HMRC, [Carry forward Corporation Tax losses](https://www.gov.uk/guidance/carry-forward-corporation-tax-losses) — supported basis for carrying qualifying trading losses to future periods.
13. HMRC Company Taxation Manual, [CTM04830: restriction on carried-forward losses](https://www.gov.uk/hmrc-internal-manuals/company-taxation-manual/ctm04830) — £5 million deductions allowance and 50% restriction beyond it, expressly outside the ordinary model in this document.

## Appendix B: document conversion

Markdown is the maintained source format. It can be converted to Word or PDF without changing the mathematical content. For example, using Pandoc:

```powershell
pandoc "Mathematic Basis.md" -o "Mathematic Basis.docx"
pandoc "Mathematic Basis.md" -o "Mathematic Basis.pdf"
```

PDF generation may require a PDF engine in addition to Pandoc. The converted document should retain the Git commit, review date and rule-source appendix so that the evidence remains version-specific.
