<?php
declare(strict_types=1);

namespace eel_accounts\Contract;

interface CharityRegistryClientInterface
{
    /** @return array{success:bool,records:list<array<string,mixed>>,errors:list<string>,response_sha256:string} */
    public function lookup(string $registrationNumber): array;
}
