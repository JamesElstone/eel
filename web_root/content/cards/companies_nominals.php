<?php
declare(strict_types=1);

final class _companies_nominalsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_nominals';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        if (empty($page['has_valid_selected_company'])) {
            return '';
        }

        $settings = (array)($page['settings'] ?? []);
        $nominalAccounts = (array)($page['nominal_accounts'] ?? []);
        $nominalSuggestions = $this->buildNominalDefaultSuggestions($nominalAccounts);

        $suggestionsHtml = '';
        if ($nominalSuggestions !== []) {
            $suggestionsHtml = '<div class="card" style="margin-bottom: 16px; box-shadow: none;">
                <div class="card-header"><h3 class="card-title">Suggested Assignments</h3></div>
                <div class="card-body">
                    <div class="list">
                        <div class="list-item"><strong>Default bank nominal</strong><span>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions['default_bank_nominal_id'] ?? null, ' ')) . '</span></div>
                        <div class="list-item"><strong>Default expense nominal</strong><span>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions['default_expense_nominal_id'] ?? null, ' ')) . '</span></div>
                        <div class="list-item"><strong>Director loan nominal</strong><span>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions['director_loan_nominal_id'] ?? null, ' ')) . '</span></div>
                        <div class="list-item"><strong>VAT control nominal</strong><span>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions['vat_nominal_id'] ?? null, ' ')) . '</span></div>
                        <div class="list-item"><strong>Fallback uncategorised nominal</strong><span>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominalSuggestions['uncategorised_nominal_id'] ?? null, ' ')) . '</span></div>
                    </div>
                    <div style="margin-top: 16px;">
                        <button class="button primary" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'apply_nominal_suggestions\'" data-ajax-card-update="companies-nominals,companies-setup-health">Apply Suggested Assignments</button>
                    </div>
                </div>
            </div>';
        }

        return '<div class="card settings-section" data-section="nominals">
            <div class="card-header"><h2 class="card-title">Nominal Defaults</h2></div>
            <div class="card-body">'
                . $suggestionsHtml . '
                <div class="form-grid">
                    <div class="form-row full"><label for="default_bank_nominal_id">Default bank nominal</label><select class="select" id="default_bank_nominal_id" name="default_bank_nominal_id"><option value="">Select nominal account</option>' . $this->nominalOptions($nominalAccounts, (string)($settings['default_bank_nominal_id'] ?? '')) . '</select></div>
                    <div class="form-row full"><label for="default_expense_nominal_id">Default expense nominal</label><select class="select" id="default_expense_nominal_id" name="default_expense_nominal_id"><option value="">Select nominal account</option>' . $this->nominalOptions($nominalAccounts, (string)($settings['default_expense_nominal_id'] ?? '')) . '</select><div class="helper">New expense claim lines default to this nominal unless you choose another one.</div></div>
                    <div class="form-row"><label for="director_loan_nominal_id">Director loan nominal</label><select class="select" id="director_loan_nominal_id" name="director_loan_nominal_id"><option value="">Select nominal account</option>' . $this->nominalOptions($nominalAccounts, (string)($settings['director_loan_nominal_id'] ?? '')) . '</select></div>
                    <div class="form-row"><label for="vat_nominal_id">VAT control nominal</label><select class="select" id="vat_nominal_id" name="vat_nominal_id"><option value="">Select nominal account</option>' . $this->nominalOptions($nominalAccounts, (string)($settings['vat_nominal_id'] ?? '')) . '</select></div>
                    <div class="form-row full"><label for="uncategorised_nominal_id">Fallback uncategorised nominal</label><select class="select" id="uncategorised_nominal_id" name="uncategorised_nominal_id"><option value="">Select nominal account</option>' . $this->nominalOptions($nominalAccounts, (string)($settings['uncategorised_nominal_id'] ?? '')) . '</select><div class="helper">Used when a transaction lands without a confident category match.</div></div>
                </div>
                <div style="margin-top: 16px;">
                    <button class="button section-save-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'save_nominals\'" data-ajax-card-update="companies-nominals,companies-setup-health">Save Nominal Defaults</button>
                </div>
            </div>
        </div>';
    }

    private function nominalOptions(array $nominalAccounts, string $selectedId): string
    {
        $html = '';
        foreach ($nominalAccounts as $nominal) {
            $id = (string)($nominal['id'] ?? '');
            $html .= '<option value="' . HelperFramework::escape($id) . '"' . ($id === $selectedId ? ' selected' : '') . '>' . HelperFramework::escape(FormattingFramework::nominalLabel($nominal, ' ')) . '</option>';
        }
        return $html;
    }

    private function buildNominalDefaultSuggestions(array $nominalAccounts): array
    {
        $normalised = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'code' => trim((string)($row['code'] ?? '')),
                'name' => trim((string)($row['name'] ?? '')),
                'account_type' => strtolower(trim((string)($row['account_type'] ?? ''))),
                'subtype_code' => strtolower(trim((string)($row['subtype_code'] ?? ''))),
            ];
        }, $nominalAccounts);

        return array_filter([
            'default_bank_nominal_id' => $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'bank' || $row['code'] === '1200' || str_contains(strtolower($row['name']), 'bank'))),
            'default_expense_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);
                return $row['id'] > 0 && $row['account_type'] === 'expense' && !str_contains($name, 'director loan') && !str_contains($name, 'vat') && !str_contains($name, 'tax');
            }),
            'director_loan_nominal_id' => $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'director_loan_liability' || str_contains(strtolower($row['name']), 'director loan'))),
            'vat_nominal_id' => $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && ($row['subtype_code'] === 'vat_control' || str_contains(strtolower($row['name']), 'vat') || str_contains(strtolower($row['code']), 'vat'))),
            'uncategorised_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);
                return $row['id'] > 0 && ($row['code'] === '9999' || str_contains($name, 'uncategorised') || str_contains($name, 'unclassified'));
            }),
        ], static fn(?array $row): bool => $row !== null);
    }

    private function firstMatchingNominal(array $nominals, callable $predicate): ?array
    {
        foreach ($nominals as $nominal) {
            if ($predicate($nominal)) {
                return $nominal;
            }
        }
        return null;
    }
}
