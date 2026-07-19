<?php
declare(strict_types=1);

final class CtFilingMappingsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));
        $service = new \eel_accounts\Service\CtFilingMappingService();
        try {
            $message = match ($intent) {
                'create_draft' => 'Draft mapping profile #' . $service->cloneDraft((string)$request->input('target_type', ''), (int)$request->input('package_id', 0), $this->actor($request)) . ' created.',
                'save_mapping' => $this->done(fn() => $service->saveMapping((string)$request->input('target_type', ''), (int)$request->input('profile_id', 0), [
                    'canonical_key' => $request->input('canonical_key', ''), 'target_xpath' => $request->input('target_xpath', ''),
                    'taxonomy_concept' => $request->input('taxonomy_concept', ''), 'value_type' => $request->input('value_type', ''),
                    'period_type' => $request->input('period_type', ''), 'context_profile' => $request->input('context_profile', ''),
                    'unit_ref' => $request->input('unit_ref', ''), 'decimals_value' => $request->input('decimals_value', ''),
                    'dimensions_json' => $request->input('dimensions_json', ''), 'sign_multiplier' => $request->input('sign_multiplier', 1),
                    'presentation_section' => $request->input('presentation_section', ''), 'presentation_label' => $request->input('presentation_label', ''),
                    'null_policy' => $request->input('null_policy', 'omit'), 'is_required' => $request->input('is_required', 0),
                    'sort_order' => $request->input('sort_order', 100),
                ], $this->actor($request)), 'Draft mapping saved; validate the profile again.'),
                'validate' => $this->validated($service->validateProfile((int)$request->input('profile_id', 0), $this->actor($request))),
                'activate' => $this->done(fn() => $service->activateProfile((int)$request->input('profile_id', 0), $this->actor($request)), 'Mapping profile activated.'),
                'retire' => $this->done(fn() => $service->retireProfile((int)$request->input('profile_id', 0), $this->actor($request)), 'Mapping profile retired.'),
                default => throw new RuntimeException('Unknown mapping-maintenance action.'),
            };
            return new ActionResultFramework(true, ['ct.filing.mappings', 'page.context'], [['type' => 'success', 'message' => $message]]);
        } catch (Throwable $exception) {
            return new ActionResultFramework(false, ['ct.filing.mappings'], [['type' => 'error', 'message' => $exception->getMessage()]]);
        }
    }
    private function validated(array $result): string { if (empty($result['success'])) { throw new RuntimeException(implode(' ', (array)$result['errors'])); } return 'Mapping profile validated as compatible.'; }
    private function done(Closure $action, string $message): string { $action(); return $message; }
    private function actor(RequestFramework $request): string
    {
        try { $session = new SessionAuthenticationService(); $session->startSession(); $device = trim((string)AntiFraudService::instance($request)->requestValue('Client-Device-ID')); $id = $session->authenticatedUserId($device !== '' ? $device : null); return $id > 0 ? 'user:' . $id : 'web_app'; } catch (Throwable) { return 'web_app'; }
    }
}
