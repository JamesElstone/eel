<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _test extends BasePageFramework
{
    private const PRESETS = [
        'alpha' => [
            'title' => 'Alpha handoff',
            'status' => 'Ready',
            'summary' => 'A starter payload showing how one card can seed context for the next.',
            'items' => ['Scope agreed', 'Dependencies mapped', 'Next action prepared'],
        ],
        'beta' => [
            'title' => 'Beta handoff',
            'status' => 'Needs review',
            'summary' => 'A second payload proving the consumer card updates from the same shared page context.',
            'items' => ['Assumptions listed', 'Open questions flagged', 'Review requested'],
        ],
        'gamma' => [
            'title' => 'Gamma handoff',
            'status' => 'Complete',
            'summary' => 'A final payload with a different shape of message but the same framework contract.',
            'items' => ['Context published', 'Consumer refreshed', 'Debug view available'],
        ],
    ];

    public function id(): string
    {
        return 'test';
    }

    public function title(): string
    {
        return 'Test';
    }

    public function subtitle(): string
    {
        return 'A small framework demo showing shared page context flowing between cards.';
    }

    public function services(): array
    {
        return [CompanyAccountService::class];
    }

    public function cards(): array
    {
        return [
            'test_source',
            'test_target',
            'otp_status_test',
            'anti_fraud_test',
            'hmrc_anti_fraud_test',
            'context_dump',
        ];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
        if ($request->action() === 'run-hmrc-antifraud-test') {
            return $this->runHmrcAntiFraudTest($request);
        }

        if ($request->action() !== 'set-test-context') {
            return ActionResultFramework::none();
        }

        $preset = $this->normalisePreset((string)$request->input('preset', 'alpha'));
        $note = $this->normaliseNote((string)$request->input('note', ''));

        return ActionResultFramework::success(
            ['test.context'],
            [[
                'type' => 'success',
                'message' => 'Shared test context updated.',
            ]],
            [
                'preset' => $preset,
                'note' => $note,
            ]
        );
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $companyAccountService = $services->get(CompanyAccountService::class);
        $preset = $this->normalisePreset((string)$request->input('preset', 'alpha'));
        $note = $this->normaliseNote((string)$request->input('note', ''));
        $companyId = (int)$request->input('company_id', 0);
        $pageCards = $this->cards();
        $sharedCardContext = $this->buildSharedCardContext($preset, $note);

        return [
            'page_id' => 'test',
            'page_cards' => $pageCards,
            'service_class' => get_class($companyAccountService),
            'company_id' => $companyId,
            'hmrc_mode' => $this->resolveHmrcMode((int)($request->input('company_id', 0) ?: ($request->query('company_id', 0) ?: 0))),
            'selected_preset' => $preset,
            'preset_options' => $this->presetOptions(),
            'note' => $note,
            'shared_demo_context' => $sharedCardContext,
            'hmrc_antifraud_test_result' => $actionResult->query()['hmrc_antifraud_test_result'] ?? null,
            'cards_dom_ids' => array_map(
                static fn(string $cardKey): string => HelperFramework::cardDomId('test', $cardKey),
                $pageCards
            ),
            'last_action_success' => $actionResult->isSuccess(),
        ];
    }

    private function buildSharedCardContext(string $preset, string $note): array
    {
        $presetData = self::PRESETS[$preset];

        return [
            'preset' => $preset,
            'title' => $presetData['title'],
            'status' => $presetData['status'],
            'summary' => $presetData['summary'],
            'note' => $note,
            'items' => $presetData['items'],
            'provided_by' => 'test_source',
            'consumed_by' => 'test_target',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function presetOptions(): array
    {
        return [
            'alpha' => 'Alpha',
            'beta' => 'Beta',
            'gamma' => 'Gamma',
        ];
    }

    private function normalisePreset(string $preset): string
    {
        $preset = strtolower(trim($preset));

        return array_key_exists($preset, self::PRESETS) ? $preset : 'alpha';
    }

    private function normaliseNote(string $note): string
    {
        $note = trim($note);

        if ($note === '') {
            return 'No extra note supplied.';
        }

        return mb_substr($note, 0, 200);
    }

    private function runHmrcAntiFraudTest(RequestFramework $request): ActionResultFramework
    {
        $companyId = $request->companyId();

        if ($companyId <= 0) {
            return new ActionResultFramework(
                false,
                ['test.antifraud'],
                [[
                    'type' => 'error',
                    'message' => 'Select a company before running the HMRC anti-fraud test.',
                ]],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => false,
                        'error' => 'No company selected.',
                    ],
                ]
            );
        }

        $hmrcMode = $this->resolveHmrcMode($companyId);

        try {
            $validatorConfig = HmrcOutbound::antiFraudValidatorConfig($hmrcMode);
            $outbound = new HmrcOutbound($validatorConfig);
            $afHeaders = AntiFraudService::instance()->getAntiFraudHeaders();
            $govHeaders = AntiFraudService::instance()->buildGovHeaders();
            $response = $outbound->validateAntiFraudHeaders($govHeaders);
            $body = json_decode((string)($response['body'] ?? ''), true);

            return ActionResultFramework::success(
                ['test.antifraud'],
                [[
                    'type' => 'success',
                    'message' => 'HMRC anti-fraud validator request completed.',
                ]],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => true,
                        'company_id' => $companyId,
                        'hmrc_mode' => $hmrcMode,
                        'af_headers' => $afHeaders,
                        'gov_headers' => $govHeaders,
                        'status_code' => (int)($response['status_code'] ?? 0),
                        'headers' => (array)($response['headers'] ?? []),
                        'body' => is_array($body) ? $body : (string)($response['body'] ?? ''),
                    ],
                ]
            );
        } catch (Throwable $exception) {
            return new ActionResultFramework(
                false,
                ['test.antifraud'],
                [[
                    'type' => 'error',
                    'message' => 'HMRC anti-fraud validator request failed: ' . $exception->getMessage(),
                ]],
                [
                    'hmrc_antifraud_test_result' => [
                        'success' => false,
                        'company_id' => $companyId,
                        'hmrc_mode' => $hmrcMode,
                        'error' => $exception->getMessage(),
                        'af_headers' => AntiFraudService::instance()->getAntiFraudHeaders(),
                        'gov_headers' => AntiFraudService::instance()->buildGovHeaders(),
                    ],
                ]
            );
        }
    }

    private function resolveHmrcMode(int $companyId): string
    {
        if ($companyId <= 0) {
            return 'TEST';
        }

        return HelperFramework::normaliseEnvironmentMode(
            (string)(new CompanySettingsStore($companyId))->get('hmrc_mode', 'TEST')
        );
    }
}

