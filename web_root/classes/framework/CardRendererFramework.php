<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CardRendererFramework
{
    /** @var array<string, array{status: string, data: mixed, error: ?array}> */
    private array $resolvedServices = [];

    public function __construct(private readonly CardFactoryFramework $cards)
    {
    }

    public function render(string $pageId, string $cardKey, array $context, PageServiceFramework $services): string
    {
        $card = $this->cards->create($cardKey);
        $domId = HelperFramework::cardDomId($pageId, $cardKey);
        $cardContext = $this->buildContextForCard($card, $context, $services);
        $body = $card->render($cardContext);
        if (trim($body) === '') {
            return '';
        }

        $helperMarkupContent = $this->renderHelperMarkupContent($card->helper($cardContext));
        $helperMarkup = $helperMarkupContent !== ''
            ? '<div class="helper">' . $helperMarkupContent . '</div>'
            : '';

        $errorSummary = $this->renderErrorSummaryMarkup((array)($cardContext['service_errors'] ?? []));
        $refreshAttributes = $this->renderRefreshAttributes($card, $cardContext);
        $showDeveloperMetadata = $this->developerOptionsEnabled();

        return '
            <section id="' . HelperFramework::escape($domId) . '" class="card" data-card-key="' . HelperFramework::escape($cardKey) . '"' . $refreshAttributes . '>
                <div class="card-header card-header-has-eyebrow">
                    <div>
                        <h2 class="card-title card-title-toggle"
                            role="button"
                            tabindex="0"
                            aria-controls="card-body-6"
                            aria-expanded="true">' . HelperFramework::escape($card->contextTitle($cardContext)) . '</h2>
                        ' . $helperMarkup . '
                    </div>
                    <div class="card-header-meta">
                        ' . $this->renderCardSizeToggle() . '
                        '
                     . ($showDeveloperMetadata ? '<p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($cardKey) . '</p>' : '')
                     . '
                        <div class="card-service-pills">'
                     . ($showDeveloperMetadata ? $this->getServicesUsedPills($card) : '')
                     . '
                        </div>
                    </div>
                </div>
                <div class="card-body">
                ' . $errorSummary . '
                ' . $body . '
                </div>
            </section>';
    }

    private function renderCardSizeToggle(): string
    {
        return '<div class="card_size">'
            . '<button class="card-size-toggle" type="button" data-card-size-toggle aria-label="Maximize card" aria-pressed="false">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="card-size-icon card-size-icon-maximize icon icon-tabler icons-tabler-outline icon-tabler-arrows-maximize" aria-hidden="true" focusable="false"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M16 4l4 0l0 4" /><path d="M14 10l6 -6" /><path d="M8 20l-4 0l0 -4" /><path d="M4 20l6 -6" /><path d="M16 20l4 0l0 -4" /><path d="M14 14l6 6" /><path d="M8 4l-4 0l0 4" /><path d="M4 4l6 6" /></svg>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="card-size-icon card-size-icon-minimize icon icon-tabler icons-tabler-outline icon-tabler-arrows-minimize" aria-hidden="true" focusable="false"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 9l4 0l0 -4" /><path d="M3 3l6 6" /><path d="M5 15l4 0l0 4" /><path d="M3 21l6 -6" /><path d="M19 9l-4 0l0 -4" /><path d="M15 9l6 -6" /><path d="M19 15l-4 0l0 4" /><path d="M15 15l6 6" /></svg>'
            . '</button>'
            . '</div>';
    }

    function getServicesUsedPills(CardInterfaceFramework $card): string
    {
        $services = [];

        foreach ($card->services() as $value) {
            if (!empty($value['service'])) {
                $services[] = $value['service'];
            }
        }

        $services = array_values(array_unique($services));

        if (empty($services)) {
            return '';
        }

        return implode(' ', array_map(
            fn($service) => HelperFramework::createPill($service),
            $services
        ));
    }

    private function developerOptionsEnabled(): bool
    {
        return (bool)AppConfigurationStore::get('developer_options', false);
    }

    public function cardInvalidationFacts(string $cardKey): array
    {
        return $this->cards->create($cardKey)->invalidationFacts();
    }

    public function buildContextForCard(
        CardInterfaceFramework $card,
        array $pageContext,
        PageServiceFramework $services
    ): array
    {
        $cardContext = array_merge(
            $pageContext,
            [
                'services' => [],
                'service_errors' => [],
            ]
        );

        foreach ($card->services() as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $serviceKey = trim((string)($definition['key'] ?? ''));
            if ($serviceKey === '') {
                continue;
            }

            $result = $this->resolveCardService($serviceKey, $definition, $pageContext, $services);
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

        return $cardContext;
    }

    private function resolveCardService(
        string $serviceKey,
        array $definition,
        array $pageContext,
        PageServiceFramework $services
    ): array
    {
        $serviceClass = trim((string)($definition['service'] ?? ''));
        $method = trim((string)($definition['method'] ?? ''));

        if ($serviceClass === '' || $method === '') {
            return $this->errorResult('invalid_definition', 'Card-defined service [' . $serviceKey . '] is invalid; service and method are required.');
        }

        try {
            $service = $services->get($serviceClass);
        } catch (Throwable $exception) {
            return $this->errorResult(
                'service_unavailable',
                'Card-defined service [' . $serviceKey . '] is unavailable; service ' . $serviceClass . ' was not resolved.'
            );
        }

        if (!method_exists($service, $method) || !is_callable([$service, $method])) {
            return $this->errorResult(
                'method_unavailable',
                'Card-defined service [' . $serviceKey . '] is invalid; method ' . $method . ' is not callable on ' . $serviceClass . '.'
            );
        }

        $reflectionMethod = new ReflectionMethod($service, $method);

        try {
            $resolvedParams = $this->resolveParams((array)($definition['params'] ?? []), $pageContext, $reflectionMethod);
        } catch (InvalidArgumentException $exception) {
            return $this->errorResult(
                'missing_param',
                'Card-defined service [' . $serviceKey . '] is invalid; ' . $exception->getMessage()
            );
        }

        $signature = $this->serviceSignature($serviceClass, $method, $resolvedParams);

        if (isset($this->resolvedServices[$signature])) {
            return $this->resolvedServices[$signature];
        }

        try {
            $data = $this->invokeServiceMethod($service, $method, $resolvedParams, $reflectionMethod);
        } catch (Throwable $exception) {
            return $this->resolvedServices[$signature] = $this->errorResult('service_error', $exception->getMessage());
        }

        if ($this->isEmptyResult($data) && (bool)(AppConfigurationStore::get('developer_options') ?? false)) {
            return $this->resolvedServices[$signature] = [
                'status' => 'no_data',
                'data' => $data,
                'error' => [
                    'type' => 'no_data',
                    'message' => 'No records returned by service.',
                ],
            ];
        }

        return $this->resolvedServices[$signature] = [
            'status' => 'ok',
            'data' => $data,
            'error' => null,
        ];
    }

    private function resolveParams(array $params, array $pageContext, ReflectionMethod $method): array
    {
        $resolved = [];

        foreach ($params as $name => $value) {
            if (is_string($value) && str_starts_with($value, ':')) {

                $contextKey = substr($value, 1);

                try {
                    $resolved[$name] = $this->resolveContextValue($pageContext, $contextKey);
                } catch (InvalidArgumentException $exception) {
                    if ($this->methodParameterHasDefault($method, (string)$name)) {
                        continue;
                    }

                    throw $exception;
                }
                
                continue;
            }

            $resolved[$name] = $value;
        }

        return $resolved;
    }

    private function invokeServiceMethod(object $service, string $method, array $resolvedParams, ReflectionMethod $reflectionMethod): mixed
    {
        if ($this->canUseNamedArguments($resolvedParams, $reflectionMethod)) {
            return $service->{$method}(...$resolvedParams);
        }

        return $service->{$method}(...array_values($resolvedParams));
    }

    private function canUseNamedArguments(array $resolvedParams, ReflectionMethod $method): bool
    {
        if ($resolvedParams === []) {
            return true;
        }

        foreach (array_keys($resolvedParams) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        $methodParameterNames = [];

        foreach ($method->getParameters() as $parameter) {
            $methodParameterNames[$parameter->getName()] = true;
        }

        foreach (array_keys($resolvedParams) as $key) {
            if (!isset($methodParameterNames[$key])) {
                return false;
            }
        }

        return true;
    }

    private function methodParameterHasDefault(ReflectionMethod $method, string $name): bool
    {
        if ($name === '') {
            return false;
        }

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter->isDefaultValueAvailable();
            }
        }

        return false;
    }

    private function resolveContextValue(array $pageContext, string $contextKey): mixed
    {
        if (array_key_exists($contextKey, $pageContext)) {
            return $pageContext[$contextKey];
        }

        $segments = array_values(array_filter(explode('.', $contextKey), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            throw new InvalidArgumentException('Missing page context value: ' . $contextKey);
        }

        $current = $pageContext;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new InvalidArgumentException('Page context value not found: ' . $contextKey);
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private function serviceSignature(string $serviceClass, string $method, array $resolvedParams): string
    {
        return $serviceClass . '::' . $method . '|' . md5((string)json_encode($resolvedParams));
    }

    private function errorResult(string $type, string $message): array
    {
        return [
            'status' => 'error',
            'data' => null,
            'error' => [
                'type' => $type,
                'message' => $message,
            ],
        ];
    }

    private function isEmptyResult(mixed $data): bool
    {
        if ($data === null) {
            return true;
        }

        if (is_array($data) && $data === []) {
            return true;
        }

        return false;
    }

    private function renderHelperMarkupContent(string|array $helper): string
    {
        if (is_array($helper)) {
            if (array_key_exists('__html', $helper)) {
                return (string)($helper['__html'] ?? '');
            }

            $lines = [];

            foreach ($helper as $item) {
                if (!is_scalar($item) && $item !== null) {
                    continue;
                }

                $line = trim((string)$item);
                if ($line !== '') {
                    $lines[] = HelperFramework::escape($line);
                }
            }

            return implode('<br>', $lines);
        }

        $helper = trim($helper);

        if ($helper === '') {
            return '';
        }

        return HelperFramework::escape($helper);
    }

    private function renderRefreshAttributes(CardInterfaceFramework $card, array $context): string
    {
        $intervalMs = $card->refreshIntervalMs($context);
        if ($intervalMs === null || $intervalMs <= 0) {
            return '';
        }

        $intervalMs = max(5000, $intervalMs);
        $facts = $card->invalidationFacts();
        $fact = trim((string)($facts[0] ?? ''));

        return ' data-card-refresh-ms="' . HelperFramework::escape((string)$intervalMs) . '"'
            . ($fact !== '' ? ' data-card-refresh-fact="' . HelperFramework::escape($fact) . '"' : '');
    }

    private function renderErrorSummaryMarkup(array $serviceErrors): string
    {
        $lines = [];

        foreach ($serviceErrors as $error) {
            if (!is_array($error) || !array_key_exists('rendered', $error)) {
                continue;
            }

            $line = trim((string)$error['rendered']);
            if ($line === '') {
                continue;
            }

            $lines[] = '<div class="helper">' . HelperFramework::escape($line) . '</div>';
        }

        if ($lines === []) {
            return '';
        }

        return '<div class="panel-soft warn">' . implode('', $lines) . '</div>';
    }
}
