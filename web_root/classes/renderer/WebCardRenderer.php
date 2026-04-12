<?php
declare(strict_types=1);

final class WebCardRenderer
{
    public function __construct(private readonly WebCardFactory $cards)
    {
    }

    public function render(string $pageId, string $cardKey, array $context): string
    {
        $card = $this->cards->create($cardKey);
        $domId = FrameworkHelper::cardDomId($pageId, $cardKey);
        $body = $card->render($context);

        return '<section id="' . FrameworkHelper::escape($domId) . '" class="page-card" data-card-key="' . FrameworkHelper::escape($cardKey) . '">' . $body . '</section>';
    }

    public function cardInvalidationFacts(string $cardKey): array
    {
        return $this->cards->create($cardKey)->invalidationFacts();
    }
}
