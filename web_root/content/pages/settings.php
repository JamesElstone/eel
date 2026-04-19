<?php
declare(strict_types=1);

final class _settings extends BaseModulePageFramework
{
    public function id(): string
    {
        return 'settings';
    }

    public function title(): string
    {
        return 'Settings';
    }

    public function subtitle(): string
    {
        return 'Review API mode, import controls, storage paths, and setup checks for the selected company.';
    }

    public function showsTaxYearSelector(): bool
    {
        return false;
    }

    public function cards(): array
    {
        return ['settings_api_mode', 'settings_import_review', 'settings_path_check', 'settings_setup_health'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $settings = (array)($baseContext['settings'] ?? []);

        return [
            'companies_house_api_mode' => HelperFramework::normaliseEnvironmentMode((string)($settings['companies_house_environment'] ?? 'TEST')),
            'hmrc_api_mode' => HelperFramework::normaliseEnvironmentMode((string)($settings['hmrc_mode'] ?? 'TEST')),
            'api_credential_check_results' => [],
            'developer_options' => false,
        ];
    }
}
