<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo 'Card performance profile is only available from the command line.' . PHP_EOL;
    return;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$_GET = [];
$_POST = [];
$_REQUEST = [];
$_FILES = [];
$_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$options = card_profile_options($argv ?? []);
if (!empty($options['help'])) {
    card_profile_usage();
    exit(0);
}

$thresholdMs = (int)$options['threshold_ms'];
$services = card_profile_page_services();
$request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []);
$serviceResolver = new ReflectionMethod(CardRendererFramework::class, 'resolveCardService');
$serviceResolver->setAccessible(true);
$cardKeys = card_profile_filtered_card_keys(card_profile_card_keys(), (string)$options['card']);
$cardPageMap = card_profile_card_page_map();
$pageGroups = card_profile_page_groups($cardKeys, $cardPageMap, (string)$options['page']);
$baseContext = card_profile_base_context(
    (int)$options['company_id'],
    (int)$options['accounting_period_id'],
    (int)$options['ct_period_id'],
    $cardKeys
);

$slowRows = [];
$errors = [];

foreach ($pageGroups as $pageId => $pageCardKeys) {
    $pageResult = card_profile_profile_page(
        (string)$pageId,
        $pageCardKeys,
        $baseContext,
        $request,
        $services,
        $serviceResolver
    );

    foreach ($pageResult['profiles'] as $profile) {
        if ($profile['service_ms'] > $thresholdMs || $profile['render_ms'] > $thresholdMs) {
            $slowRows[] = $profile;
        }
    }

    array_push($errors, ...$pageResult['errors']);
}

card_profile_print_report($slowRows, $errors, $thresholdMs, $baseContext);

/**
 * @return array{threshold_ms: int, company_id: int, accounting_period_id: int, ct_period_id: int, page: string, card: string, help: bool}
 */
function card_profile_options(array $argv): array
{
    $options = [
        'threshold_ms' => 100,
        'company_id' => 0,
        'accounting_period_id' => 0,
        'ct_period_id' => 0,
        'page' => '',
        'card' => '',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        $argument = trim((string)$argument);
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        foreach (['threshold-ms', 'company-id', 'accounting-period-id', 'ct-period-id', 'page', 'card'] as $name) {
            $prefix = '--' . $name . '=';
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }

            $key = str_replace('-', '_', $name);
            $value = substr($argument, strlen($prefix));
            $options[$key] = in_array($key, ['page', 'card'], true)
                ? strtolower(str_replace('-', '_', trim($value)))
                : max(0, (int)$value);
        }
    }

    $options['threshold_ms'] = max(0, (int)$options['threshold_ms']);

    return $options;
}

function card_profile_usage(): void
{
    echo 'Usage: php web_root/tests/profile_card_performance.php [--threshold-ms=100] [--company-id=ID] [--accounting-period-id=ID] [--ct-period-id=ID] [--page=page_id] [--card=card_key]' . PHP_EOL;
    echo 'Profiles every card through declared card services and render(), using GET context only.' . PHP_EOL;
}

function card_profile_page_services(): PageServiceFramework
{
    $uploadBasePath = (string)(AppConfigurationStore::get('uploads.upload_base_dir') ?? '');
    $appServices = new AppService($uploadBasePath);
    $pageServices = new PageServiceFramework($appServices);
    $pageServices->setSiteContextCoordinator(SiteContextCoordinatorFramework::fromConfiguration($appServices));

    return $pageServices;
}

/**
 * @return list<string>
 */
function card_profile_card_keys(): array
{
    $files = glob(APP_CARDS . '*.php');
    if ($files === false) {
        return [];
    }

    sort($files);
    $keys = [];
    foreach ($files as $file) {
        if (is_file($file)) {
            $keys[] = basename($file, '.php');
        }
    }

    return array_values($keys);
}

/**
 * @param list<string> $cardKeys
 * @return list<string>
 */
function card_profile_filtered_card_keys(array $cardKeys, string $requestedCard): array
{
    $requestedCard = trim($requestedCard);
    if ($requestedCard === '') {
        return $cardKeys;
    }

    return array_values(array_filter(
        $cardKeys,
        static fn(string $cardKey): bool => $cardKey === $requestedCard
    ));
}

/**
 * @return array<string, string>
 */
function card_profile_card_page_map(): array
{
    $files = glob(APP_PAGES . '*.php');
    if ($files === false) {
        return [];
    }

    sort($files);
    $map = [];
    foreach ($files as $file) {
        $pageKey = basename($file, '.php');
        if (!preg_match('/^[a-z0-9_]+$/', $pageKey)) {
            continue;
        }

        $className = HelperFramework::pageKeyToClassName($pageKey);
        if (!class_exists($className)) {
            continue;
        }

        $page = new $className();
        if (!$page instanceof PageInterfaceFramework) {
            continue;
        }

        foreach (card_profile_page_card_keys($page) as $cardKey) {
            $map[$cardKey] ??= $page->id();
        }
    }

    return $map;
}

/**
 * @param list<string> $cardKeys
 * @param array<string, string> $cardPageMap
 * @return array<string, list<string>>
 */
function card_profile_page_groups(array $cardKeys, array $cardPageMap, string $requestedPage): array
{
    $groups = [];
    foreach ($cardKeys as $cardKey) {
        $pageId = (string)($cardPageMap[$cardKey] ?? 'card_profile');
        if ($requestedPage !== '' && $pageId !== $requestedPage) {
            continue;
        }

        $groups[$pageId] ??= [];
        $groups[$pageId][] = $cardKey;
    }

    return $groups;
}

/**
 * @return list<string>
 */
function card_profile_page_card_keys(PageInterfaceFramework $page): array
{
    $cards = [];
    foreach ($page->cards() as $cardKey) {
        $cards[] = (string)$cardKey;
    }

    if (method_exists($page, 'cardLayout')) {
        foreach ((array)$page->cardLayout() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ((array)($entry['cards'] ?? []) as $cardKey) {
                $cards[] = (string)$cardKey;
            }
        }
    }

    return array_values(array_unique(array_filter($cards, static fn(string $cardKey): bool => $cardKey !== '')));
}

/**
 * @param list<string> $cardKeys
 * @return array<string, mixed>
 */
function card_profile_base_context(int $companyId, int $accountingPeriodId, int $ctPeriodId, array $cardKeys): array
{
    $company = card_profile_company_context($companyId, $accountingPeriodId);
    $companyId = (int)$company['company']['id'];
    $accountingPeriodId = (int)$company['company']['accounting_period_id'];
    $ct = card_profile_ct_context($companyId, $accountingPeriodId, $ctPeriodId);
    $csrfToken = card_profile_csrf_token();

    return array_merge($company, [
        'auth' => [
            'user_id' => 0,
            'role_id' => 0,
        ],
        'page' => [
            'page_id' => 'card_profile',
            'page_cards' => $cardKeys,
            'cards_dom_ids' => [],
            'csrf_token' => $csrfToken,
            'settings' => AppConfigurationStore::config(),
        ],
        'tax' => $ct,
        'hmrc_submission' => [
            'mode' => 'TEST',
            'settings' => [],
            'ct_periods' => $ct['ct_periods'],
            'selected_ct_period_id' => $ct['selected_ct_period_id'],
            'accounts_ixbrl' => ['ok' => false, 'filename' => null],
            'computations_ixbrl' => ['ok' => false, 'filename' => null],
            'latest_submission' => null,
            'history' => [],
        ],
        'hmrc_obligations' => [
            'filter' => 'all',
            'filters' => ['all' => 'All'],
            'summary' => [],
            'timeline' => [],
            'all_obligations' => [],
            'checklist' => [],
            'guidance' => [],
        ],
        'uploads' => [
            'filter' => 'all',
            'page' => 1,
        ],
        'field_mapping' => [
            'account_id' => 0,
        ],
        'company_search_term' => '',
        'company_search_results' => [],
    ]);
}

/**
 * @return array{site_context: array<string, int>, company: array<string, mixed>, accounting_period: array<string, mixed>}
 */
function card_profile_company_context(int $requestedCompanyId, int $requestedAccountingPeriodId): array
{
    $companyId = $requestedCompanyId > 0 ? $requestedCompanyId : card_profile_first_int(
        'SELECT id FROM companies ORDER BY company_name, id LIMIT 1'
    );

    $companyRow = $companyId > 0
        ? card_profile_fetch_one('SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId])
        : [];
    if ($companyRow === []) {
        $companyId = 0;
    }

    $accountingPeriodId = $requestedAccountingPeriodId;
    if ($accountingPeriodId <= 0 && $companyId > 0) {
        $accountingPeriodId = card_profile_first_int(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId]
        );
    }

    $accountingPeriod = $companyId > 0 && $accountingPeriodId > 0
        ? card_profile_fetch_one(
            'SELECT *
             FROM accounting_periods
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1',
            ['company_id' => $companyId, 'id' => $accountingPeriodId]
        )
        : [];
    if ($accountingPeriod === []) {
        $accountingPeriodId = 0;
    }

    $settings = \eel_accounts\Store\CompanySettingsStore::defaults();
    if ($companyId > 0) {
        try {
            $settings = (new \eel_accounts\Service\CompanySettingsService())->loadFromDatabase(
                new \eel_accounts\Store\CompanySettingsStore($companyId),
                $companyId,
                $accountingPeriodId
            );
        } catch (Throwable) {
            $settings = \eel_accounts\Store\CompanySettingsStore::defaults();
        }
    }

    $companyName = trim((string)($companyRow['company_name'] ?? $settings['company_name'] ?? ''));
    $companyNumber = trim((string)($companyRow['company_number'] ?? $settings['companies_house_number'] ?? ''));

    return [
        'site_context' => [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ],
        'company' => [
            'id' => $companyId,
            'name' => $companyName,
            'company_name' => $companyName,
            'number' => $companyNumber,
            'company_number' => $companyNumber,
            'valid_selected' => $companyId > 0,
            'accounting_period_id' => $accountingPeriodId,
            'accounting_period_label' => trim((string)($accountingPeriod['label'] ?? '')),
            'settings' => $settings,
        ],
        'accounting_period' => [
            'id' => $accountingPeriodId,
            'label' => trim((string)($accountingPeriod['label'] ?? '')),
            'period_start' => trim((string)($accountingPeriod['period_start'] ?? '')),
            'period_end' => trim((string)($accountingPeriod['period_end'] ?? '')),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function card_profile_ct_context(int $companyId, int $accountingPeriodId, int $requestedCtPeriodId): array
{
    $ctPeriods = [];
    if ($companyId > 0 && $accountingPeriodId > 0 && InterfaceDB::tableExists('corporation_tax_periods')) {
        $ctPeriods = InterfaceDB::fetchAll(
            'SELECT *
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY period_start ASC, id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    $ctPeriods = array_values(array_map('card_profile_normalise_ct_period', $ctPeriods));
    $selectedCtPeriodId = $requestedCtPeriodId > 0 ? $requestedCtPeriodId : (int)($ctPeriods[0]['id'] ?? 0);
    $selectedCtPeriod = [];
    foreach ($ctPeriods as $period) {
        if ((int)($period['id'] ?? 0) === $selectedCtPeriodId) {
            $selectedCtPeriod = $period;
            break;
        }
    }

    return [
        'ct_periods' => $ctPeriods,
        'selected_ct_period_id' => $selectedCtPeriod !== [] ? $selectedCtPeriodId : 0,
        'selected_ct_period' => $selectedCtPeriod,
        'selected_ct_period_helper' => $selectedCtPeriod !== []
            ? 'Showing ' . (string)$selectedCtPeriod['display_label']
            : '',
        'sync_errors' => [],
    ];
}

/**
 * @param array<string, mixed> $period
 * @return array<string, mixed>
 */
function card_profile_normalise_ct_period(array $period): array
{
    $sequence = (int)($period['display_sequence_no'] ?? $period['sequence_no'] ?? 0);
    $period['display_label'] = trim((string)($period['display_label'] ?? ''));
    if ($period['display_label'] === '') {
        $period['display_label'] = $sequence > 0 ? 'CT Period ' . $sequence : 'CT Period';
    }

    return $period;
}

function card_profile_csrf_token(): string
{
    return 'card-profile-read-only';
}

/**
 * @param list<string> $cardKeys
 * @return array{profiles: list<array<string, mixed>>, errors: list<array{card_key: string, message: string}>}
 */
function card_profile_profile_page(
    string $pageId,
    array $cardKeys,
    array $baseContext,
    RequestFramework $request,
    PageServiceFramework $services,
    ReflectionMethod $serviceResolver
): array {
    $transactionStarted = false;
    $profiles = [];
    $errors = [];

    try {
        if (!InterfaceDB::inTransaction()) {
            $transactionStarted = InterfaceDB::beginTransaction();
        }

        $renderer = new CardRendererFramework(new CardFactoryFramework());
        foreach ($cardKeys as $cardKey) {
            try {
                $className = HelperFramework::cardKeyToClassName($cardKey);
                $card = new $className();
                if (!$card instanceof CardInterfaceFramework) {
                    throw new RuntimeException('Resolved class does not implement CardInterfaceFramework.');
                }

                $profiles[] = card_profile_profile_card(
                    $card,
                    $cardKey,
                    $pageId,
                    $cardKeys,
                    $baseContext,
                    $request,
                    $services,
                    $serviceResolver,
                    $renderer
                );
            } catch (Throwable $exception) {
                $errors[] = [
                    'card_key' => $cardKey,
                    'message' => $exception->getMessage(),
                ];
            }
        }
    } finally {
        if ($transactionStarted && InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }

    return [
        'profiles' => $profiles,
        'errors' => $errors,
    ];
}

/**
 * @param list<string> $pageCardKeys
 * @return array<string, mixed>
 */
function card_profile_profile_card(
    CardInterfaceFramework $card,
    string $cardKey,
    string $pageId,
    array $pageCardKeys,
    array $baseContext,
    RequestFramework $request,
    PageServiceFramework $services,
    ReflectionMethod $serviceResolver,
    CardRendererFramework $renderer
): array {
    $context = $baseContext;
    $context['page']['page_id'] = $pageId;
    $context['page']['page_cards'] = $pageCardKeys;
    $context['page']['cards_dom_ids'] = [HelperFramework::cardDomId($pageId, $cardKey)];
    $handledContext = $card->handle($request, $services, $context, ActionResultFramework::none());
    if (is_array($handledContext)) {
        $context = $handledContext;
    }

    $cardContext = array_merge($context, [
        'services' => [],
        'service_errors' => [],
    ]);
    $serviceMs = 0;
    $serviceBreakdown = [];

    foreach ($card->services() as $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $serviceKey = trim((string)($definition['key'] ?? ''));
        if ($serviceKey === '') {
            continue;
        }

        $started = hrtime(true);
        $result = $serviceResolver->invoke($renderer, $serviceKey, $definition, $context, $services);
        $elapsed = card_profile_elapsed_ms($started);
        $serviceMs += $elapsed;
        $serviceBreakdown[] = [
            'key' => $serviceKey,
            'service' => (string)($definition['service'] ?? ''),
            'method' => (string)($definition['method'] ?? ''),
            'ms' => $elapsed,
            'status' => (string)($result['status'] ?? 'unknown'),
        ];

        $cardContext['services'][$serviceKey] = $result['data'] ?? null;
        $cardContext['service_errors'][$serviceKey] = $result['error'] ?? null;
        if (($result['error'] ?? null) !== null) {
            $cardContext['service_errors'][$serviceKey]['rendered'] = $card->handleError(
                $serviceKey,
                $cardContext['service_errors'][$serviceKey],
                $cardContext
            );
        }
    }

    $started = hrtime(true);
    $card->render($cardContext);
    $renderMs = card_profile_elapsed_ms($started);

    return [
        'card_key' => $cardKey,
        'page_id' => $pageId,
        'service_ms' => $serviceMs,
        'render_ms' => $renderMs,
        'service_count' => count($serviceBreakdown),
        'service_breakdown' => $serviceBreakdown,
    ];
}

function card_profile_elapsed_ms(int $started): int
{
    return max(0, (int)round((hrtime(true) - $started) / 1000000));
}

/**
 * @param list<array<string, mixed>> $slowRows
 * @param list<array{card_key: string, message: string}> $errors
 * @param array<string, mixed> $context
 */
function card_profile_print_report(array $slowRows, array $errors, int $thresholdMs, array $context): void
{
    $company = (array)($context['company'] ?? []);

    echo 'Card performance profile' . PHP_EOL;
    echo 'Threshold: > ' . $thresholdMs . 'ms' . PHP_EOL;
    echo 'Company ID: ' . (int)($company['id'] ?? 0) . PHP_EOL;
    echo 'Accounting period ID: ' . (int)($company['accounting_period_id'] ?? 0) . PHP_EOL;
    echo PHP_EOL;

    if ($slowRows === []) {
        echo 'No card service or render timings exceeded ' . $thresholdMs . 'ms.' . PHP_EOL;
    } else {
        echo implode("\t", ['card_key', 'page_id', 'service_ms', 'render_ms', 'slow_phase', 'services']) . PHP_EOL;
        foreach ($slowRows as $row) {
            echo implode("\t", [
                (string)$row['card_key'],
                (string)$row['page_id'],
                (string)$row['service_ms'],
                (string)$row['render_ms'],
                card_profile_slow_phase($row, $thresholdMs),
                card_profile_service_summary((array)$row['service_breakdown']),
            ]) . PHP_EOL;
        }
    }

    if ($errors === []) {
        return;
    }

    echo PHP_EOL;
    echo 'Profiling errors: ' . count($errors) . PHP_EOL;
    foreach ($errors as $error) {
        echo (string)$error['card_key'] . ': ' . preg_replace('/\s+/', ' ', (string)$error['message']) . PHP_EOL;
    }
}

/**
 * @param array<string, mixed> $row
 */
function card_profile_slow_phase(array $row, int $thresholdMs): string
{
    $phases = [];
    if ((int)($row['service_ms'] ?? 0) > $thresholdMs) {
        $phases[] = 'service';
    }
    if ((int)($row['render_ms'] ?? 0) > $thresholdMs) {
        $phases[] = 'render';
    }

    return implode(',', $phases);
}

/**
 * @param list<array<string, mixed>> $breakdown
 */
function card_profile_service_summary(array $breakdown): string
{
    $parts = [];
    foreach ($breakdown as $service) {
        $label = trim((string)($service['key'] ?? ''));
        $method = trim((string)($service['method'] ?? ''));
        if ($method !== '') {
            $label .= $label !== '' ? ':' . $method : $method;
        }

        $parts[] = $label . '=' . (int)($service['ms'] ?? 0) . 'ms'
            . (trim((string)($service['status'] ?? '')) !== 'ok' ? '[' . (string)$service['status'] . ']' : '');
    }

    return implode('; ', $parts);
}

/**
 * @param array<string, mixed> $params
 */
function card_profile_fetch_one(string $sql, array $params = []): array
{
    try {
        $row = InterfaceDB::fetchOne($sql, $params);
    } catch (Throwable) {
        return [];
    }

    return is_array($row) ? $row : [];
}

/**
 * @param array<string, mixed> $params
 */
function card_profile_first_int(string $sql, array $params = []): int
{
    try {
        $value = InterfaceDB::fetchColumn($sql, $params);
    } catch (Throwable) {
        return 0;
    }

    return max(0, (int)$value);
}
