<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CorporationTaxFilingScopeService
{
    public const SCOPE_VERSION = 'ct-supplementary-scope-v2';

    /** @return array<string,array{page:string,label:string,question:string,url:string}> */
    public function definitions(): array
    {
        $forms = 'https://www.gov.uk/government/collections/corporation-tax-forms';
        return [
            'ct600b' => ['page' => 'CT600B', 'label' => 'Controlled foreign companies, foreign permanent-establishment exemptions or hybrid mismatches', 'question' => 'Does the company have controlled foreign companies, exempt foreign permanent establishments or hybrid-mismatch matters?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600b-controlled-foreign-companies-and-foreign-permanent-establishment-exemptions-hybrid-and-other-mismatches'],
            'ct600c' => ['page' => 'CT600C', 'label' => 'Group or consortium relief', 'question' => 'Is the company claiming or surrendering group or consortium relief?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600c-group-and-consortium-relief'],
            'ct600d' => ['page' => 'CT600D', 'label' => 'Overseas life assurance business', 'question' => 'Does the company carry on overseas life assurance business requiring CT600D?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600d-insurance'],
            'ct600e' => ['page' => 'CT600E', 'label' => 'Charities and community amateur sports clubs', 'question' => 'Is the company a charity or community amateur sports club?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600e-charities-and-community-amateur-sports-clubs'],
            'ct600f' => ['page' => 'CT600F', 'label' => 'Tonnage tax', 'question' => 'Does the company operate within the tonnage-tax regime?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600f-tonnage-tax'],
            'ct600g' => ['page' => 'CT600G', 'label' => 'Northern Ireland Corporation Tax', 'question' => 'Does the company have Northern Ireland profits requiring CT600G?', 'url' => $forms],
            'ct600h' => ['page' => 'CT600H', 'label' => 'Cross-border royalties', 'question' => 'Has the company made cross-border royalty payments requiring CT600H?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600h-cross-border-royalties'],
            'ct600i' => ['page' => 'CT600I', 'label' => 'Ring-fence oil and gas activities', 'question' => 'Does the company have ring-fence oil or gas activities or supplementary-charge profits?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600i-supplementary-charge-in-respect-of-ring-fence-trades'],
            'ct600j' => ['page' => 'CT600J', 'label' => 'Disclosure of tax avoidance schemes', 'question' => 'Must the company report a DOTAS scheme reference number or promoter reference number?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600j-disclosure-of-tax-avoidance-schemes'],
            'ct600k' => ['page' => 'CT600K', 'label' => 'Restitution tax', 'question' => 'Is the company liable to restitution tax?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600k-restitution-tax'],
            'ct600l' => ['page' => 'CT600L', 'label' => 'Research and development', 'question' => 'Is the company claiming an R&D tax relief, expenditure credit or payable credit?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600l-research-and-development'],
            'ct600m' => ['page' => 'CT600M', 'label' => 'Freeports and Investment Zones', 'question' => 'Is the company claiming Freeport or Investment Zone allowances or reliefs?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600m-freeports-and-investment-zones'],
            'ct600n' => ['page' => 'CT600N', 'label' => 'Residential Property Developer Tax', 'question' => 'Is the company within Residential Property Developer Tax?', 'url' => 'https://www.gov.uk/guidance/supplementary-pages-ct600n-residential-property-developer-tax'],
            'ct600p' => ['page' => 'CT600P', 'label' => 'Creative industry reliefs', 'question' => 'Is the company claiming film, television, animation, games, theatre, orchestra or museum and gallery relief?', 'url' => 'https://www.gov.uk/guidance/completing-the-ct600p-page-for-creative-industries-reliefs'],
        ];
    }

    /** @return array<string,mixed> */
    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return ['available' => false, 'complete' => false, 'errors' => ['Select a company and accounting period.'], 'definitions' => $this->definitions(), 'answers' => []];
        }
        if (!\InterfaceDB::tableExists('corporation_tax_scope_confirmations')) {
            return ['available' => false, 'complete' => false, 'errors' => ['Run the CT600A and filing-scope migration.'], 'definitions' => $this->definitions(), 'answers' => []];
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM corporation_tax_scope_confirmations WHERE company_id = :company_id AND accounting_period_id = :period_id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId]
        );
        $answers = is_array($row) ? json_decode((string)$row['answers_json'], true) : [];
        $answers = is_array($answers) ? $answers : [];
        $errors = [];
        foreach ($this->definitions() as $key => $definition) {
            $answer = (string)($answers[$key] ?? 'no');
            if (!in_array($answer, ['no', 'yes'], true)) {
                $errors[] = $definition['page'] . ' scope must be answered Yes or No.';
            } elseif ($answer !== 'no') {
                $errors[] = $definition['page'] . ' may be required: ' . $definition['label'] . '.';
            }
        }
        $basis = [
            'scope_version' => self::SCOPE_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'answers' => array_replace(array_fill_keys(array_keys($this->definitions()), 'no'), $answers),
            'revision' => (int)($row['revision'] ?? 0),
        ];
        $basisJson = $this->canonicalJson($basis);
        if (is_array($row) && (!hash_equals((string)$row['basis_hash'], hash('sha256', $basisJson))
            || (string)$row['scope_version'] !== self::SCOPE_VERSION)) {
            $errors[] = 'The Corporation Tax scope confirmation is stale or failed its integrity check.';
        }
        return [
            'available' => true,
            'stored' => is_array($row),
            'complete' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'definitions' => $this->definitions(),
            'answers' => $basis['answers'],
            'revision' => $basis['revision'],
            'confirmed_by' => (string)($row['confirmed_by'] ?? ''),
            'confirmed_at' => (string)($row['confirmed_at'] ?? ''),
            'basis' => $basis,
            'basis_hash' => hash('sha256', $basisJson),
        ];
    }

    /** @return array<string,mixed> */
    public function saveAnswer(int $companyId, int $accountingPeriodId, string $field, string $answer, string $actor): array
    {
        $definitions = $this->definitions();
        if (!isset($definitions[$field])) {
            return ['success' => false, 'errors' => ['Select a recognised CT600 supplementary-page scope question.']];
        }
        if (!in_array($answer, ['no', 'yes'], true)) {
            return ['success' => false, 'errors' => ['Choose Yes or No.']];
        }
        $actor = trim($actor);
        if ($actor === '') {
            return ['success' => false, 'errors' => ['The scope confirmation must identify its author.']];
        }
        $current = $this->fetch($companyId, $accountingPeriodId);
        if (empty($current['available'])) {
            return ['success' => false, 'errors' => (array)$current['errors']];
        }
        $answers = (array)$current['answers'];
        $answers[$field] = $answer;
        ksort($answers, SORT_STRING);
        $revision = max(1, (int)($current['revision'] ?? 0) + 1);
        $basis = [
            'scope_version' => self::SCOPE_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'answers' => $answers,
            'revision' => $revision,
        ];
        $json = $this->canonicalJson($basis);
        $sql = 'INSERT INTO corporation_tax_scope_confirmations
                (company_id, accounting_period_id, scope_version, answers_json, revision, confirmed_by, confirmed_at, basis_hash)
             VALUES (:company_id, :period_id, :scope_version, :answers_json, :revision, :confirmed_by, CURRENT_TIMESTAMP, :basis_hash)';
        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(company_id, accounting_period_id) DO UPDATE SET scope_version = excluded.scope_version,
                answers_json = excluded.answers_json, revision = excluded.revision, confirmed_by = excluded.confirmed_by,
                confirmed_at = CURRENT_TIMESTAMP, basis_hash = excluded.basis_hash'
            : ' ON DUPLICATE KEY UPDATE scope_version = VALUES(scope_version), answers_json = VALUES(answers_json),
                revision = VALUES(revision), confirmed_by = VALUES(confirmed_by), confirmed_at = CURRENT_TIMESTAMP,
                basis_hash = VALUES(basis_hash)';
        \InterfaceDB::prepareExecute(
            $sql,
            [
                'company_id' => $companyId, 'period_id' => $accountingPeriodId,
                'scope_version' => self::SCOPE_VERSION, 'answers_json' => $this->canonicalJson($answers),
                'revision' => $revision, 'confirmed_by' => $actor, 'basis_hash' => hash('sha256', $json),
            ]
        );
        return ['success' => true, 'errors' => []];
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key => $child) { $item[$key] = $sort($child); }
            return $item;
        };
        return (string)json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
