<?php
declare(strict_types=1);

final class WebCardRenderer
{
    public function __construct(private readonly WebCardFactory $cards)
    {
    }

    public function render(string $pageId, string $cardKey, array $context): string
    {
        $card = $this->cards->create($pageId, $cardKey);
        $domId = FrameWorkHelper::cardDomId($pageId, $cardKey);
        $body = $card->render($context);

        return '<section id="' . FrameWorkHelper::escape($domId) . '" class="page-card" data-card-key="' . FrameWorkHelper::escape($cardKey) . '">' . $body . '</section>';
    }

    public function cardInvalidationFacts(string $pageId, string $cardKey): array
    {
        return $this->cards->create($pageId, $cardKey)->invalidationFacts();
    }
}
