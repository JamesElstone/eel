<?php
declare(strict_types=1);

final class _director_loan_termsCard extends CardBaseFramework
{
    public function key(): string { return 'director_loan_terms'; }
    public function title(): string { return 'Terms'; }
    public function helper(array $context): string { return 'Maintain terms by participator loan party. Locked periods retain a read-only snapshot.'; }
    public function services(): array { return [[
        'key'=>'partyTerms','service'=>\eel_accounts\Service\ParticipatorLoanPartyTermsService::class,'method'=>'fetchTermsWorkspace',
        'params'=>['companyId'=>':company.id','accountingPeriodId'=>':company.accounting_period_id'],
    ]]; }
    protected function additionalInvalidationFacts(): array { return ['director.loan.state','year.end.director.loan.offset','year.end.checklist','companies.house.snapshot','year.end.companies.house.comparison','ixbrl.readiness','ixbrl.accounts.mapping','ixbrl.facts.preview','ixbrl.generation']; }
    public function handleError(string $serviceKey,array $error,array $context): string { return ''; }
    public function render(array $context): string
    {
        $workspace=(array)($context['services']['partyTerms']??[]); $companyId=(int)($context['company']['id']??0); $periodId=(int)($context['company']['accounting_period_id']??0);
        if (empty($workspace['success'])) return '<section class="panel-soft settings-stack">'.$this->errors((array)($workspace['errors']??['Participator loan terms are unavailable.'])).'</section>';
        $parties=(array)($workspace['parties']??[]);
        if ($parties===[]) return '<section class="panel-soft settings-stack"><div class="eyebrow">Participator loan terms</div><div class="helper">No historic or current attributed Participator Loan entries exist. Assign an entry on the Participant Loan Assignment tab first.</div></section>';
        $html='<section class="settings-stack director-loan-reporting-presentation">'
            . '<section class="panel-soft settings-stack"><h3 class="card-title">Entity Terms Register</h3><div class="helper">Terms belong to the party across accounting periods. This period '.(!empty($workspace['is_locked'])?'is locked, so its saved snapshot is read only.':'is open, so changes apply to reporting until it is locked.').'</div>'
            . $this->termsTable($parties)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? [$this->key()]),
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
            ]) . '</section>';
        if (empty($workspace['is_locked'])) $html .= $this->addForm($companyId, $periodId, $parties);
        return $html.'</section>';
    }
    private function form(int $companyId,int $periodId,array $entry): string
    {
        $party=(array)($entry['party']??[]); $terms=(array)($entry['terms']??[]); $locked=!empty($entry['is_locked']); $disabled=$locked?' disabled':''; $partyId=(int)($party['id']??0);
        $checked=static fn(string $key): string => !empty($terms[$key])?' checked':'';
        $option=static fn(string $key,string $value): string => (string)($terms[$key]??'')===$value?' selected':'';
        $hasSavedTerms = !empty($entry['explicit']);
        return '<form id="participator-loan-terms-' . $partyId . '" method="post" data-ajax="true" class="panel-soft settings-stack">'.HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            .'<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="save_participator_loan_party_terms"><input type="hidden" name="company_id" value="'.$companyId.'"><input type="hidden" name="accounting_period_id" value="'.$periodId.'"><input type="hidden" name="party_id" value="'.$partyId.'">'
            .'<div class="status-head"><div><h3 class="card-title">'.($hasSavedTerms ? 'Edit terms' : 'Add terms').'</h3><div class="helper">'.HelperFramework::escape((string)($party['legal_name']??'Participator')).'</div></div>'.($locked?'<span class="badge warning">Locked snapshot</span>':'').'</div>'
            .'<div class="participator-loan-terms-fields"><label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="interest_rate_percent" data-no-submit-on-change="true" min="0" max="100" step="0.0001" value="'.HelperFramework::escape(number_format((float)($terms['interest_rate_percent']??0),4,'.','')).'"'.$disabled.'></label>'
            .'<label><span class="helper">Security</span><select class="select" name="security_type" data-no-submit-on-change="true"'.$disabled.'><option value="unsecured"'.$option('security_type','unsecured').'>Unsecured</option><option value="secured"'.$option('security_type','secured').'>Secured</option></select></label>'
            .'<label><span class="helper">When is the amount repayable?</span><select class="select" name="repayment_timing" data-no-submit-on-change="true"'.$disabled.'><option value="within_12_months"'.$option('repayment_timing','within_12_months').'>Within 12 months</option><option value="after_12_months"'.$option('repayment_timing','after_12_months').'>After 12 months</option></select></label>'
            .'<label><span class="helper">Settlement intention</span><select class="select" name="settlement_intention" data-no-submit-on-change="true"'.$disabled.'><option value="independently"'.$option('settlement_intention','independently').'>Independently</option><option value="net"'.$option('settlement_intention','net').'>Net</option><option value="simultaneous"'.$option('settlement_intention','simultaneous').'>Simultaneous</option></select></label></div>'
            .'<label class="checkbox-row"><input type="checkbox" name="repayable_on_demand" value="1"'.$checked('repayable_on_demand').$disabled.'><span>Repayable on demand</span></label>'
            .'<label class="checkbox-row"><input type="checkbox" name="deferment_right_confirmed" value="1"'.$checked('deferment_right_confirmed').$disabled.'><span>The company can defer repayment for at least 12 months.</span></label>'
            .'<label class="checkbox-row"><input type="checkbox" name="set_off_right_confirmed" value="1"'.$checked('set_off_right_confirmed').$disabled.'><span>A legally enforceable right of set-off exists.</span></label>'
            .($locked?'':'<div><button class="button primary" type="submit">Save terms</button></div>').'</form>';
    }
    private function addForm(int $companyId, int $periodId, array $entries): string
    {
        $options = '<option value="">Select Entity</option>';
        foreach ($entries as $entry) {
            $party = (array)($entry['party'] ?? []);
            $terms = (array)($entry['terms'] ?? []);
            $options .= '<option value="' . (int)($party['id'] ?? 0) . '"'
                . ' data-interest-rate-percent="' . HelperFramework::escape(number_format((float)($terms['interest_rate_percent'] ?? 0), 4, '.', '')) . '"'
                . ' data-security-type="' . HelperFramework::escape((string)($terms['security_type'] ?? 'unsecured')) . '"'
                . ' data-repayable-on-demand="' . (!empty($terms['repayable_on_demand']) ? '1' : '0') . '"'
                . ' data-repayment-timing="' . HelperFramework::escape((string)($terms['repayment_timing'] ?? 'within_12_months')) . '"'
                . ' data-deferment-right-confirmed="' . (!empty($terms['deferment_right_confirmed']) ? '1' : '0') . '"'
                . ' data-set-off-right-confirmed="' . (!empty($terms['set_off_right_confirmed']) ? '1' : '0') . '"'
                . ' data-settlement-intention="' . HelperFramework::escape((string)($terms['settlement_intention'] ?? 'independently')) . '"'
                . ' data-explicit="' . (!empty($entry['explicit']) ? '1' : '0') . '">'
                . HelperFramework::escape((string)($party['legal_name'] ?? 'Participator')) . '</option>';
        }
        return '<form id="participator-loan-terms-add" method="post" data-ajax="true" class="panel-soft settings-stack">'
            . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken())
            . '<input type="hidden" name="card_action" value="DirectorLoan"><input type="hidden" name="intent" value="save_participator_loan_party_terms"><input type="hidden" name="company_id" value="' . $companyId . '"><input type="hidden" name="accounting_period_id" value="' . $periodId . '">'
            . '<h3 class="card-title" data-participator-loan-terms-title>Add terms</h3><label class="participator-loan-terms-entity-field"><span class="helper">Entity</span><select class="select" name="party_id" data-participator-loan-terms-entity data-no-submit-on-change="true" required>' . $options . '</select></label>'
            . '<div class="participator-loan-terms-fields"><label><span class="helper">Interest rate (%)</span><input class="input" type="number" name="interest_rate_percent" data-no-submit-on-change="true" min="0" max="100" step="0.0001" value="0.0000"></label>'
            . '<label><span class="helper">Security</span><select class="select" name="security_type" data-no-submit-on-change="true"><option value="unsecured">Unsecured</option><option value="secured">Secured</option></select></label>'
            . '<label><span class="helper">When is the amount repayable?</span><select class="select" name="repayment_timing" data-no-submit-on-change="true"><option value="within_12_months">Within 12 months</option><option value="after_12_months">After 12 months</option></select></label>'
            . '<label><span class="helper">Settlement intention</span><select class="select" name="settlement_intention" data-no-submit-on-change="true"><option value="independently">Independently</option><option value="net">Net</option><option value="simultaneous">Simultaneous</option></select></label></div>'
            . '<label class="checkbox-row"><input type="checkbox" name="repayable_on_demand" value="1" checked><span>Repayable on demand</span></label>'
            . '<label class="checkbox-row"><input type="checkbox" name="deferment_right_confirmed" value="1"><span>The company can defer repayment for at least 12 months.</span></label>'
            . '<label class="checkbox-row"><input type="checkbox" name="set_off_right_confirmed" value="1"><span>A legally enforceable right of set-off exists.</span></label>'
            . '<div><button class="button primary" type="submit">Save terms</button></div></form>';
    }
    private function errors(array $errors): string { return implode('',array_map(static fn($e)=>'<div class="helper">'.HelperFramework::escape((string)$e).'</div>',$errors)); }

    private function termsTable(array $entries): TableFramework
    {
        $rows = array_map(static function (array $entry): array {
            $party = (array)($entry['party'] ?? []);
            $terms = (array)($entry['terms'] ?? []);
            return [
                'party' => (string)($party['legal_name'] ?? 'Participator'),
                'entity_type' => HelperFramework::labelFromKey((string)($party['party_type'] ?? ''), '_'),
                'interest_rate' => number_format((float)($terms['interest_rate_percent'] ?? 0), 4, '.', '') . '%',
                'security' => HelperFramework::labelFromKey((string)($terms['security_type'] ?? 'unsecured'), '_'),
                'on_demand' => !empty($terms['repayable_on_demand']) ? 'Yes' : 'No',
                'repayment_timing' => (string)($terms['repayment_timing'] ?? '') === 'after_12_months' ? 'After 12 months' : 'Within 12 months',
                'deferral' => !empty($terms['deferment_right_confirmed']) ? 'Yes' : 'No',
                'set_off' => !empty($terms['set_off_right_confirmed']) ? 'Yes' : 'No',
                'settlement' => HelperFramework::labelFromKey((string)($terms['settlement_intention'] ?? 'independently'), '_'),
                'party_id' => (int)($party['id'] ?? 0),
                'explicit' => !empty($entry['explicit']),
            ];
        }, $entries);
        return TableFramework::make('participator_loan_party_terms', $rows)
            ->filename('participator-loan-party-terms')
            ->exportLimit(5000)
            ->empty('No participator loan parties are available.')
            ->textColumn('party', 'Entity')
            ->textColumn('entity_type', 'Type')
            ->textColumn('interest_rate', 'Interest rate')
            ->textColumn('security', 'Security')
            ->textColumn('on_demand', 'On demand')
            ->textColumn('repayment_timing', 'Repayment')
            ->textColumn('deferral', 'Deferral 12+ months')
            ->textColumn('set_off', 'Legal set-off')
            ->textColumn('settlement', 'Settlement')
            ->column(
                'action',
                'Action',
                html: static fn(array $row): string => '<button class="button button-inline" type="button" data-participator-loan-terms-edit="true" data-party-id="'
                    . (int)($row['party_id'] ?? 0) . '">Edit</button>',
                exportable: false,
                cellClass: 'cell-fit'
            );
    }
}
