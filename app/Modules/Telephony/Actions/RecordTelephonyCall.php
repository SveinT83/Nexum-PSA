<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Telephony\Models\TelephonyCall;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RecordTelephonyCall
{
    public function __construct(
        private readonly EnsureTelephonyToken $tokens,
        private readonly NormalizePhoneNumber $normalizePhone,
        private readonly ResolveTelephonyCallerContext $callerContext,
    ) {
    }

    public function handle(string $plainToken, Request $request): TelephonyCall
    {
        $token = $this->tokens->findByPlainToken($plainToken);
        abort_unless($token && $token->user?->isActive(), 404);

        $payload = $this->payload($request);
        $rawNumber = $this->firstFilled($payload, [
            'caller',
            'caller_number',
            'callerNumber',
            'phone',
            'number',
            'from',
            'no',
            'msisdn',
        ]);
        $normalizedNumber = $this->normalizePhone->handle($rawNumber);
        $provider = Str::slug($this->firstFilled($payload, ['provider', 'provider_profile', 'profile']) ?: 'generic');
        $providerCallId = $this->firstFilled($payload, ['provider_call_id', 'call_id', 'callId', 'callid']);
        $answeredAt = $this->dateTime($this->firstFilled($payload, ['answered_at', 'answeredAt'])) ?? now();
        $startedAt = $this->dateTime($this->firstFilled($payload, ['started_at', 'startedAt', 'start_time']));
        $context = $this->callerContext->handle($normalizedNumber);
        $providerCallKey = $providerCallId ? hash('sha256', $provider.'|'.$providerCallId) : null;
        $fallbackNumber = $normalizedNumber ?: (filled($rawNumber) ? (string) $rawNumber : null);
        $fallbackFingerprint = ($providerCallKey || ! $fallbackNumber) ? null : $this->fallbackFingerprint(
            (int) $token->user_id,
            $fallbackNumber,
            $answeredAt
        );

        $call = $this->existingCall($providerCallKey, $fallbackFingerprint) ?: new TelephonyCall();
        $notes = $this->firstFilled($payload, ['note', 'notes']);

        $call->forceFill([
            'provider_profile' => $provider ?: 'generic',
            'provider_call_id' => $providerCallId,
            'provider_call_key' => $providerCallKey,
            'fallback_fingerprint' => $fallbackFingerprint,
            'direction' => $this->firstFilled($payload, ['direction']) ?: 'inbound',
            'caller_number_raw' => $rawNumber,
            'caller_number_normalized' => $normalizedNumber,
            'called_number' => $this->firstFilled($payload, ['called_number', 'calledNumber', 'to']),
            'answered_by_user_id' => $token->user_id,
            'contact_id' => $context['contact']?->id,
            'client_user_id' => $context['client_user']?->id,
            'client_id' => $context['client']?->id,
            'site_id' => $context['site']?->id,
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'status' => $call->status ?: 'open',
            'notes' => filled($notes) ? $notes : $call->notes,
            'is_test' => $this->boolean($this->firstFilled($payload, ['is_test', 'test'])),
            'raw_payload' => [
                'query' => $request->query->all(),
                'form' => $request->request->all(),
                'json' => $request->isJson() ? $request->json()->all() : [],
            ],
            'metadata' => array_filter([
                'intake_token_id' => $token->id,
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ]),
        ])->save();

        $token->forceFill(['last_used_at' => now()])->save();

        return $call->fresh(['answeredBy', 'contact.emails', 'contact.phones', 'clientUser.site.client', 'client', 'site', 'linkedTicket']);
    }

    private function existingCall(?string $providerCallKey, ?string $fallbackFingerprint): ?TelephonyCall
    {
        if ($providerCallKey) {
            $call = TelephonyCall::query()->where('provider_call_key', $providerCallKey)->first();

            if ($call) {
                return $call;
            }
        }

        if ($fallbackFingerprint) {
            return TelephonyCall::query()->where('fallback_fingerprint', $fallbackFingerprint)->first();
        }

        return null;
    }

    private function fallbackFingerprint(int $userId, string $number, Carbon $answeredAt): string
    {
        $bucket = (int) floor($answeredAt->timestamp / 300);

        return hash('sha256', $userId.'|'.$number.'|'.$bucket);
    }

    private function payload(Request $request): array
    {
        return array_merge(
            $request->query->all(),
            $request->request->all(),
            $request->isJson() ? $request->json()->all() : []
        );
    }

    private function firstFilled(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($payload[$key] ?? null)) {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function dateTime(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function boolean(?string $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
