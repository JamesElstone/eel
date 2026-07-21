<?php
declare(strict_types=1);

final class TaxArtifactsRefreshAction implements ActionInterfaceFramework
{
    /** @var list<array{label: string, action: class-string<ActionInterfaceFramework>, intent: string}> */
    private const STAGES = [
        ['label' => 'HMRC CT filing artefacts', 'action' => TaxRatesAction::class, 'intent' => 'hmrc_ct_artifacts_refresh'],
        ['label' => 'FRC accounts taxonomy', 'action' => FrcTaxonomyAction::class, 'intent' => 'refresh_frc_taxonomy'],
        ['label' => 'Companies House filing schemas', 'action' => CompaniesHouseSchemaArtifactsAction::class, 'intent' => 'refresh_companies_house_accounts_schemas'],
        ['label' => 'HMRC VAT rates', 'action' => TaxRatesVatAction::class, 'intent' => 'refresh_hmrc_vat_rates'],
        ['label' => 'HMRC VAT thresholds', 'action' => TaxThresholdsVatAction::class, 'intent' => 'refresh_hmrc_vat_thresholds'],
    ];

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (trim((string)$request->input('intent', '')) !== 'refresh_all_tax_artifacts') {
            return new ActionResultFramework(false, ['page.context'], [[
                'type' => 'error',
                'message' => 'Unknown tax artefact refresh action.',
            ]]);
        }

        $progress = $services->actionProgress();
        $progress->report('Starting tax artefact refresh.', 0);
        // The refresh updates several independent sources. Re-render every page card
        // after the progress stream completes so no stale status remains visible.
        $facts = ['page.reload'];
        $messages = [];
        $successfulStages = 0;

        foreach (self::STAGES as $stage) {
            $progress->report('Refreshing ' . $stage['label'] . '…');
            try {
                $action = new $stage['action']();
                $result = $action->handle($request->withMergedPostValues(['intent' => $stage['intent']]), $services);
            } catch (Throwable $exception) {
                $result = new ActionResultFramework(false, [], [[
                    'type' => 'error',
                    'message' => $stage['label'] . ' refresh failed: ' . $exception->getMessage(),
                ]]);
            }

            $facts = array_values(array_unique(array_merge($facts, $result->changedFacts())));
            $messages = array_merge($messages, $result->flashMessages());
            if ($result->isSuccess()) {
                $successfulStages++;
                $progress->report($stage['label'] . ' refresh complete.');
            } else {
                $progress->report($stage['label'] . ' refresh needs attention.');
            }
        }

        $success = $successfulStages === count(self::STAGES);
        $progress->report(
            $success ? 'All tax artefacts refreshed.' : 'Tax artefact refresh completed with issues.',
            100
        );
        array_unshift($messages, [
            'type' => $success ? 'success' : 'warning',
            'message' => $successfulStages . ' of ' . count(self::STAGES) . ' tax artefact refreshes completed successfully.',
        ]);

        return new ActionResultFramework($success, $facts, $messages);
    }
}
