<?php
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
            'context_dump',
        ];
    }

    protected function handlePageAction(
        RequestFramework $request,
        PageServiceFramework $services
    ): ActionResultFramework
    {
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
            'selected_preset' => $preset,
            'preset_options' => $this->presetOptions(),
            'note' => $note,
            'shared_demo_context' => $sharedCardContext,
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
}

