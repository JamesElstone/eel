<?php
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
        $body = $card->render($this->buildCardContext($card, $context, $services));

        return '<section id="' . HelperFramework::escape($domId) . '" class="page-card" data-card-key="' . HelperFramework::escape($cardKey) . '">' . $body . '</section>';
    }

    public function cardInvalidationFacts(string $cardKey): array
    {
        return $this->cards->create($cardKey)->invalidationFacts();
    }

    private function buildCardContext(
        CardInterfaceFramework $card,
        array $pageContext,
        PageServiceFramework $services
    ): array
    {
        $cardContext = [
            'page' => $pageContext,
            'services' => [],
            'service_errors' => [],
        ];

        foreach ($card->services() as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $serviceKey = trim((string)($definition['key'] ?? ''));
            if ($serviceKey === '') {
                continue;
            }

            $result = $this->resolveCardService($definition, $pageContext, $services);
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
        array $definition,
        array $pageContext,
        PageServiceFramework $services
    ): array
    {
        $serviceClass = trim((string)($definition['service'] ?? ''));
        $method = trim((string)($definition['method'] ?? ''));

        if ($serviceClass === '' || $method === '') {
            return $this->errorResult('invalid_definition', 'Card service definitions must include service and method.');
        }

        try {
            $resolvedParams = $this->resolveParams((array)($definition['params'] ?? []), $pageContext);
        } catch (InvalidArgumentException $exception) {
            return $this->errorResult('missing_param', $exception->getMessage());
        }

        $signature = $this->serviceSignature($serviceClass, $method, $resolvedParams);

        if (isset($this->resolvedServices[$signature])) {
            return $this->resolvedServices[$signature];
        }

        try {
            $service = $services->get($serviceClass);
        } catch (Throwable $exception) {
            return $this->resolvedServices[$signature] = $this->errorResult('service_unavailable', $exception->getMessage());
        }

        if (!method_exists($service, $method) || !is_callable([$service, $method])) {
            return $this->resolvedServices[$signature] = $this->errorResult(
                'method_unavailable',
                'The requested service method could not be called.'
            );
        }

        try {
            $data = $service->{$method}(...array_values($resolvedParams));
        } catch (Throwable $exception) {
            return $this->resolvedServices[$signature] = $this->errorResult('service_error', $exception->getMessage());
        }

        if ($this->isEmptyResult($data)) {
            return $this->resolvedServices[$signature] = [
                'status' => 'no_data',
                'data' => $data,
                'error' => [
                    'type' => 'no_data',
                    'message' => 'The service returned no data.',
                ],
            ];
        }

        return $this->resolvedServices[$signature] = [
            'status' => 'ok',
            'data' => $data,
            'error' => null,
        ];
    }

    private function resolveParams(array $params, array $pageContext): array
    {
        $resolved = [];

        foreach ($params as $name => $value) {
            if (is_string($value) && str_starts_with($value, ':')) {
                $contextKey = substr($value, 1);

                if (!array_key_exists($contextKey, $pageContext)) {
                    throw new InvalidArgumentException('Missing page context value: ' . $contextKey);
                }

                $resolved[$name] = $pageContext[$contextKey];
                continue;
            }

            $resolved[$name] = $value;
        }

        return $resolved;
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
}

