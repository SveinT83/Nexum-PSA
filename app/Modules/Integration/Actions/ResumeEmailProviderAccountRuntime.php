<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Support\Facades\DB;

final class ResumeEmailProviderAccountRuntime
{
    public function __construct(private readonly EmailProviderManagementAuthorization $authorization) {}

    public function execute(User $actor, EmailAccount $account): EmailAccount
    {
        $this->authorization->authorizeBinding($actor);
        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 60);

        if (! $providerLock) {
            throw new EmailProviderSecurityException('provider_work_not_drained');
        }

        try {
            return DB::transaction(function () use ($account): EmailAccount {
                $locked = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
                $locked->forceFill([
                    'provider_runtime_paused_at' => null,
                    'provider_runtime_drained_at' => null,
                    'provider_runtime_paused_by' => null,
                    'provider_runtime_pause_reason_code' => null,
                ])->save();

                return $locked->fresh();
            }, 3);
        } finally {
            $providerLock->release();
        }
    }
}
