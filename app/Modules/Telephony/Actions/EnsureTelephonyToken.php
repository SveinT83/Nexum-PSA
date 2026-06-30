<?php

namespace App\Modules\Telephony\Actions;

use App\Models\Core\User;
use App\Modules\Telephony\Models\TelephonyToken;
use Illuminate\Support\Str;

class EnsureTelephonyToken
{
    public function handle(User $user): TelephonyToken
    {
        return TelephonyToken::query()->firstOrCreate(
            ['user_id' => $user->id],
            $this->newTokenAttributes()
        );
    }

    public function rotate(User $user): TelephonyToken
    {
        $token = $this->handle($user);
        $token->forceFill(array_merge($this->newTokenAttributes(), [
            'rotated_at' => now(),
        ]))->save();

        return $token->refresh();
    }

    public function findByPlainToken(string $plainToken): ?TelephonyToken
    {
        return TelephonyToken::query()
            ->with('user')
            ->where('token_hash', $this->hash($plainToken))
            ->first();
    }

    private function newTokenAttributes(): array
    {
        do {
            $plain = Str::random(48);
            $hash = $this->hash($plain);
        } while (TelephonyToken::query()->where('token_hash', $hash)->exists());

        return [
            'token_hash' => $hash,
            'token_value' => $plain,
        ];
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
