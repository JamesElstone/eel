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
    echo 'Page performance profile is only available from the command line.' . PHP_EOL;
    return;
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$_GET = [];
$_POST = [];
$_REQUEST = [];
$_FILES = [];
$_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

$options = page_profile_options($argv ?? []);
if (!empty($options['help'])) {
    page_profile_usage();
    exit(0);
}

$thresholdMs = (int)$options['threshold_ms'];
$services = page_profile_page_services();
$request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], []);
$serviceResolver = new ReflectionMethod(CardRendererFramework::class, 'resolveCardService');
$serviceResolver->setAccessible(true);
$pageKeys = page_profile_page_keys((string)$options['page']);
$baseContext = page_profile_base_context(
    (int)$options['company_id'],
    (int)$options['accounting_period_id'],
    (int)$options['ct_period_id']
);

$slowRows = [];
$errors = [];

foreach ($pageKeys as $pageKey) {
    try {
        $profile = page_profile_profile_page($pageKey, $baseContext, $request, $services, $serviceResolver);
        if (page_profile_is_slow($profile, $thresholdMs)) {
            $slowRows[] = $profile;
        }
    } catch (Throwable $exception) {
        $errors[] = [
            'page_id' => $pageKey,
            'message' => $exception->getMessage(),
        ];
    }
}

page_profile_print_report($slowRows, $errors, $thresholdMs, $baseContext);

/**
 * @return array{threshold_ms: int, company_id: int, accounting_period_id: int, ct_period_id: int, page: string, help: bool}
 */
function page_profile_options(array $argv): array
{
    $options = [
        'threshold_ms' => 100,
        'company_id' => 0,
        'accounting_period_id' => 0,
        'ct_period_id' => 0,
        'page' => '',
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        $argument = trim((string)$argument);
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        foreach (['threshold-ms', 'company-id', 'accounting-period-id', 'ct-period-id', 'page'] as $name) {
            $prefix = '--' . $name . '=';
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }

            $key = str_replace('-', '_', $name);
            $value = substr($argument, strlen($prefix));
            $options[$key] = $key === 'page'
                ? strtolower(str_replace('-', '_', trim($value)))
                : max(0, (int)$value);
        }
    }

    $options['threshold_ms'] = max(0, (int)$options['threshold_ms']);

    return $options;
}

function page_profile_usage(): void
{
    echo 'Usage: php web_root/tests/profile_page_performance.php [--threshold-ms=100] [--company-id=ID] [--accounting-period-id=ID] [--ct-period-id=ID] [--page=page_id]' . PHP_EOL;
    echo 'Profiles every page by loading its cards, running card handle(), resolving declared card services, and calling render().' . PHP_EOL;
}

function page_profile_page_services(): PageServiceFramework
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
function page_profile_page_keys(string $requestedPage): array
{
    $files = glob(APP_PAGES . '*.php');
    if ($files === false) {
        return [];
    }

    sort($files);
    $keys = [];
    foreach ($files as $file) {
        $pageKey = basename($file, '.php');
        if (!preg_match('/^[a-z0-9_]+$/', $pageKey)) {
            continue;
        }

        if ($requestedPage !== '' && $pageKey !== $requestedPage) {
            continue;
        }

        $keys[] = $pageKey;
    }

    return $keys;
}

/**
 * @return array<string, mixed>
 */
function page_profile_base_context(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
{
    $company = page_profile_company_context($companyId, $accountingPeriodId);
    $companyId = (int)$company['company']['id'];
    $accountingPeriodId = (int)$company['company']['accounting_period_id'];
    $ct = page_profile_ct_context($companyId, $accountingPeriodId, $ctPeriodId);
    $csrfToken = page_profile_csrf_token();

    return array_merge($company, [
        'auth' => [
            'user_id' => 0,
            'role_id' => 0,
        ],
        'page' => [
            'page_id' => 'page_profile',
            'page_cards' => [],
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
function page_profile_company_context(int $requestedCompanyId, int $requestedAccountingPeriodId): array
{
    $companyId = $requestedCompanyId > 0 ? $requestedCompanyId : page_profile_first_int(
        'SELECT id FROM companies ORDER BY company_name, id LIMIT 1'
    );

    $companyRow = $companyId > 0
        ? page_profile_fetch_one('SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId])
        : [];
    if ($companyRow === []) {
        $companyId = 0;
    }

    $accountingPeriodId = $requestedAccountingPeriodId;
    if ($accountingPeriodId <= 0 && $companyId > 0) {
        $accountingPeriodId = page_profile_first_int(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId]
        );
    }

    $accountingPeriod = $companyId > 0 && $accountingPeriodId > 0
        ? page_profile_fetch_one(
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
function page_profile_ct_context(int $companyId, int $accountingPeriodId, int $requestedCtPeriodId): array
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

    $ctPeriods = array_values(array_map('page_profile_normalise_ct_period', $ctPeriods));
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
function page_profile_normalise_ct_period(array $period): array
{
    $sequence = (int)($period['display_sequence_no'] ?? $period['sequence_no'] ?? 0);
    $period['display_label'] = trim((string)($period['display_label'] ?? ''));
    if ($period['display_label'] === '') {
        $period['display_label'] = $sequence > 0 ? 'CT Period ' . $sequence : 'CT Period';
    }

    return $period;
}

function page_profile_csrf_token(): string
{
    return 'page-profile-read-only';
}

/**
 * @return array<string, mixed>
 */
function page_profile_profile_page(
    string $pageKey,
    array $baseContext,
    RequestFramework $request,
    PageServiceFramework $services,
    ReflectionMethod $serviceResolver
): array {
    $transactionStarted = false;
    $totalStarted = hrtime(true);

    try {
        if (!InterfaceDB::inTransaction()) {
            $transactionStarted = InterfaceDB::beginTransaction();
        }

        $loadStarted = hrtime(true);
        $pageClass = HelperFramework::pageKeyToClassName($pageKey);
        if (!class_exists($pageClass)) {
            throw new RuntimeException('Page class could not be loaded: ' . $pageClass);
        }

        $page = new $pageClass();
        if (!$page instanceof PageInterfaceFramework) {
            throw new RuntimeException('Resolved class does not implement PageInterfaceFramework.');
        }

        $cardKeys = page_profile_page_card_keys($page);
        $cards = [];
        foreach ($cardKeys as $cardKey) {
            $className = HelperFramework::cardKeyToClassName($cardKey);
            if (!class_exists($className)) {
                throw new RuntimeException('Card class could not be loaded: ' . $className);
            }

            $card = new $className();
            if (!$card instanceof CardInterfaceFramework) {
                throw new RuntimeException('Resolved card does not implement CardInterfaceFramework: ' . $className);
            }

            $cards[$cardKey] = $card;
        }
        $loadMs = page_profile_elapsed_ms($loadStarted);

        $context = $baseContext;
        $context['page']['page_id'] = $page->id();
        $context['page']['page_cards'] = $cardKeys;
        $context['page']['cards_dom_ids'] = array_map(
            static fn(string $cardKey): string => HelperFramework::cardDomId($page->id(), $cardKey),
            $cardKeys
        );

        $handleStarted = hrtime(true);
        foreach ($cards as $card) {
            $handledContext = $card->handle($request, $services, $context, ActionResultFramework::none());
            if (is_array($handledContext)) {
                $context = $handledContext;
            }
        }
        $handleMs = page_profile_elapsed_ms($handleStarted);

        $renderer = new CardRendererFramework(new CardFactoryFramework());
        $serviceMs = 0;
        $renderMs = 0;
        $cardProfiles = [];

        foreach ($cards as $cardKey => $card) {
            $cardProfile = page_profile_profile_card($card, (string)$cardKey, $context, $services, $serviceResolver, $renderer);
            $serviceMs += (int)$cardProfile['service_ms'];
            $renderMs += (int)$cardProfile['render_ms'];
            $cardProfiles[] = $cardProfile;
        }

        return [
            'page_id' => $page->id(),
            'card_count' => count($cards),
            'load_ms' => $loadMs,
            'handle_ms' => $handleMs,
            'service_ms' => $serviceMs,
            'render_ms' => $renderMs,
            'total_ms' => page_profile_elapsed_ms($totalStarted),
            'cards' => $cardProfiles,
        ];
    } finally {
        if ($transactionStarted && InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

/**
 * @return list<string>
 */
function page_profile_page_card_keys(PageInterfaceFramework $page): array
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
 * @return array<string, mixed>
 */
function page_profile_profile_card(
    CardInterfaceFramework $card,
    string $cardKey,
    array $pageContext,
    PageServiceFramework $services,
    ReflectionMethod $serviceResolver,
    CardRendererFramework $renderer
): array {
    $cardContext = array_merge($pageContext, [
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
        $result = $serviceResolver->invoke($renderer, $serviceKey, $definition, $pageContext, $services);
        $elapsed = page_profile_elapsed_ms($started);
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
    $renderMs = page_profile_elapsed_ms($started);

    return [
        'card_key' => $cardKey,
        'service_ms' => $serviceMs,
        'render_ms' => $renderMs,
        'total_ms' => $serviceMs + $renderMs,
        'service_breakdown' => $serviceBreakdown,
    ];
}

function page_profile_is_slow(array $profile, int $thresholdMs): bool
{
    foreach (['load_ms', 'handle_ms', 'service_ms', 'render_ms', 'total_ms'] as $key) {
        if ((int)($profile[$key] ?? 0) > $thresholdMs) {
            return true;
        }
    }

    return false;
}

function page_profile_elapsed_ms(int $started): int
{
    return max(0, (int)round((hrtime(true) - $started) / 1000000));
}

/**
 * @param list<array<string, mixed>> $slowRows
 * @param list<array{page_id: string, message: string}> $errors
 * @param array<string, mixed> $context
 */
function page_profile_print_report(array $slowRows, array $errors, int $thresholdMs, array $context): void
{
    $company = (array)($context['company'] ?? []);

    echo 'Page performance profile' . PHP_EOL;
    echo 'Threshold: > ' . $thresholdMs . 'ms' . PHP_EOL;
    echo 'Company ID: ' . (int)($company['id'] ?? 0) . PHP_EOL;
    echo 'Accounting period ID: ' . (int)($company['accounting_period_id'] ?? 0) . PHP_EOL;
    echo PHP_EOL;

    if ($slowRows === []) {
        echo 'No page timings exceeded ' . $thresholdMs . 'ms.' . PHP_EOL;
    } else {
        echo implode("\t", ['page_id', 'cards', 'load_ms', 'handle_ms', 'service_ms', 'render_ms', 'total_ms', 'slow_phase', 'slowest_cards']) . PHP_EOL;
        foreach ($slowRows as $row) {
            echo implode("\t", [
                (string)$row['page_id'],
                (string)$row['card_count'],
                (string)$row['load_ms'],
                (string)$row['handle_ms'],
                (string)$row['service_ms'],
                (string)$row['render_ms'],
                (string)$row['total_ms'],
                page_profile_slow_phase($row, $thresholdMs),
                page_profile_slowest_cards((array)$row['cards']),
            ]) . PHP_EOL;
        }
    }

    if ($errors === []) {
        return;
    }

    echo PHP_EOL;
    echo 'Profiling errors: ' . count($errors) . PHP_EOL;
    foreach ($errors as $error) {
        echo (string)$error['page_id'] . ': ' . preg_replace('/\s+/', ' ', (string)$error['message']) . PHP_EOL;
    }
}

/**
 * @param array<string, mixed> $row
 */
function page_profile_slow_phase(array $row, int $thresholdMs): string
{
    $phases = [];
    foreach (['load', 'handle', 'service', 'render', 'total'] as $phase) {
        if ((int)($row[$phase . '_ms'] ?? 0) > $thresholdMs) {
            $phases[] = $phase;
        }
    }

    return implode(',', $phases);
}

/**
 * @param list<array<string, mixed>> $cards
 */
function page_profile_slowest_cards(array $cards): string
{
    usort($cards, static function (array $left, array $right): int {
        return (int)($right['total_ms'] ?? 0) <=> (int)($left['total_ms'] ?? 0);
    });

    $parts = [];
    foreach (array_slice($cards, 0, 3) as $card) {
        $parts[] = (string)$card['card_key']
            . '(s:' . (int)($card['service_ms'] ?? 0)
            . ',r:' . (int)($card['render_ms'] ?? 0)
            . ')';
    }

    return implode('; ', $parts);
}

/**
 * @param array<string, mixed> $params
 */
function page_profile_fetch_one(string $sql, array $params = []): array
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
function page_profile_first_int(string $sql, array $params = []): int
{
    try {
        $value = InterfaceDB::fetchColumn($sql, $params);
    } catch (Throwable) {
        return 0;
    }

    return max(0, (int)$value);
}
