<?php

namespace App\Modules\Integration\Contracts;

interface InspectsTlsCertificates
{
    /** @return array<string, mixed> */
    public function inspect(string $hostname): array;
}
