<?php
declare(strict_types=1);

interface VatValidationServiceInterface
{
    public function supports(string $countryCode): bool;

    public function validate(string $countryCode, string $vatNumber): VatValidationResult;
}
