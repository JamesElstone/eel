<?php
declare(strict_types=1);

final class CtComputationTaxonomyAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        try {
            if (trim((string)$request->input('intent', '')) === 'save_package') {
                $id = (new \eel_accounts\Service\HmrcCtComputationCatalogueService())->savePackage([
                    'id' => $request->input('package_id', 0), 'taxonomy_version' => $request->input('taxonomy_version', ''),
                    'artifact_version' => $request->input('artifact_version', ''), 'applicable_from' => $request->input('applicable_from', ''),
                    'applicable_to' => $request->input('applicable_to', ''), 'source_url' => $request->input('source_url', ''),
                    'download_url' => $request->input('download_url', ''), 'local_path' => $request->input('local_path', ''),
                    'entry_point_path' => $request->input('entry_point_path', ''), 'combined_dpl_entry_point_path' => $request->input('combined_dpl_entry_point_path', ''),
                ]);
                return new ActionResultFramework(true, ['hmrc.ct.computation.taxonomy', 'ct.filing.mappings', 'page.context'], [['type' => 'success', 'message' => 'Computation-taxonomy package #' . $id . ' saved and compatibility re-evaluated.']]);
            }
            if (trim((string)$request->input('intent', '')) !== 'catalogue') { throw new RuntimeException('Unknown computation-taxonomy action.'); }
            $result = (new \eel_accounts\Service\HmrcCtComputationCatalogueService())->catalogueDirectory((int)$request->input('package_id', 0), (string)$request->input('directory', ''));
            return new ActionResultFramework(true, ['hmrc.ct.computation.taxonomy', 'ct.filing.mappings', 'page.context'], [['type' => 'success', 'message' => 'Catalogued ' . (int)$result['file_count'] . ' files and ' . (int)$result['concept_count'] . ' concepts. Review entry points and mappings before verification.']]);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['hmrc.ct.computation.taxonomy'], [['type' => 'error', 'message' => $exception->getMessage()]]);
        }
    }
}
