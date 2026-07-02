<?php

namespace App\Modules\Relationship\Actions;

class RelationshipContentChecksum
{
    public function handle(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
