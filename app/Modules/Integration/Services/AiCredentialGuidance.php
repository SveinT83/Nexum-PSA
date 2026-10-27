<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use Illuminate\Support\Facades\Route;

/** Resolve present authorized guidance separately from credential detection and future Vault ownership. */
class AiCredentialGuidance
{
    public function message(?User $actor): string
    {
        $base = 'Credentials cannot be submitted through AI chat. The input was discarded. ';
        if ($actor?->isActive() && $actor->can('integration.ai_manage')
            && Route::has('tech.admin.system.integrations.ai.index')) {
            return $base.'For AI provider keys, use the encrypted credential field in Admin > Integrations > AI. '
                .'For other credentials, use the appropriate authorized secure credential form or ask an administrator.';
        }

        return $base.'Use the appropriate authorized secure credential form or ask an administrator.';
    }
}
