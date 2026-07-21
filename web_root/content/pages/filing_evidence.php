<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

final class _filing_evidence extends PageContextFramework
{
    public function id(): string { return 'filing_evidence'; }
    public function title(): string { return 'Filing Evidence'; }
    public function subtitle(): string { return 'Reproduce the exact frozen calculations, artifacts and filing history behind an EEL Evidence ID.'; }
    public function ajaxPendingBlurScope(): string { return 'page'; }
    public function cards(): array
    {
        return [
            'filing_evidence_lookup',
            'filing_evidence_overview',
            'filing_evidence_artifacts',
            'filing_evidence_calculations',
            'filing_evidence_calculation_detail',
        ];
    }

    protected function handlePageAction(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if (!in_array($request->action(), ['lookup-filing-evidence', 'select-filing-evidence-area'], true)) {
            return ActionResultFramework::none();
        }
        $companyId = max(0, (int)$request->input('company_id', 0));
        if ($request->action() === 'lookup-filing-evidence') {
            $reference = trim((string)$request->input('evidence_id', ''));
            $resolved = (new \eel_accounts\Service\FilingEvidenceService())->resolve($companyId, $reference);
            $flashes = [];
            if (empty($resolved['found']) && empty($resolved['empty'])) {
                $flashes[] = ['type' => 'error', 'message' => (string)(($resolved['errors'] ?? [])[0] ?? 'Filing evidence was not found.')];
            }
            return new ActionResultFramework(true, ['filing.evidence.selection'], $flashes, [
                'evidence_reference' => $reference,
                'evidence_bundle_id' => (int)($resolved['bundle_id'] ?? 0),
                'evidence_artifact_row_id' => (int)($resolved['selected_artifact_id'] ?? 0),
                'evidence_snapshot_id' => 0,
                'evidence_area_code' => '',
                'evidence_detail_page' => 1,
            ]);
        }
        return ActionResultFramework::success(['filing.evidence.selection'], [], [
            'evidence_reference' => trim((string)$request->input('evidence_reference', '')),
            'evidence_bundle_id' => max(0, (int)$request->input('evidence_bundle_id', 0)),
            'evidence_artifact_row_id' => max(0, (int)$request->input('evidence_artifact_row_id', 0)),
            'evidence_snapshot_id' => max(0, (int)$request->input('evidence_snapshot_id', 0)),
            'evidence_area_code' => strtolower(trim((string)$request->input('evidence_area_code', ''))),
            'evidence_detail_page' => max(1, (int)$request->input('evidence_detail_page', 1)),
        ]);
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $query = $actionResult->query();
        return ['filing_evidence' => [
            'reference' => (string)($query['evidence_reference'] ?? $request->input('evidence_reference', '')),
            'bundle_id' => max(0, (int)($query['evidence_bundle_id'] ?? $request->input('evidence_bundle_id', 0))),
            'artifact_row_id' => max(0, (int)($query['evidence_artifact_row_id'] ?? $request->input('evidence_artifact_row_id', 0))),
            'snapshot_id' => max(0, (int)($query['evidence_snapshot_id'] ?? $request->input('evidence_snapshot_id', 0))),
            'area_code' => strtolower(trim((string)($query['evidence_area_code'] ?? $request->input('evidence_area_code', '')))),
            'detail_page' => max(1, (int)($query['evidence_detail_page'] ?? $request->input('evidence_detail_page', 1))),
        ]];
    }
}
