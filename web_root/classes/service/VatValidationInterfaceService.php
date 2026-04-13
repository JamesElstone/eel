<?php
declare(strict_types=1);

interface VatValidationInterfaceService
{
    public function supports(string $countryCode): bool;

    public function validate(string $countryCode, string $vatNumber): VatValidationResultService;
}
