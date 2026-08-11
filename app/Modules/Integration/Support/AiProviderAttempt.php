<?php

namespace App\Modules\Integration\Support;

use Illuminate\Http\Client\Response;

final class AiProviderAttempt
{
    public function __construct(
        public readonly Response $response,
        public readonly AiModelResult $result,
    ) {}
}
