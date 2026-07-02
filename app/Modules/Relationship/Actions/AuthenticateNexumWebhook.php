<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateNexumWebhook
{
    public function __construct(private readonly RecordSyncEvent $events) {}

    public function handle(Request $request): ?NexumRelationship
    {
        $token = (string) $request->header('X-Nexum-Token', '');
        $timestamp = (string) $request->header('X-Nexum-Timestamp', '');
        $signature = (string) $request->header('X-Nexum-Signature', '');

        if ($token === '' || $timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        $relationship = NexumRelationship::query()
            ->where('status', RelationshipStatus::ACTIVE)
            ->whereNotNull('inbound_token_hash')
            ->get()
            ->first(fn (NexumRelationship $candidate) => Hash::check($token, $candidate->inbound_token_hash));

        if (! $relationship) {
            return null;
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            (string) $relationship->webhook_secret_encrypted
        );

        if (! hash_equals($expected, $signature)) {
            $this->events->handle($relationship, [
                'direction' => 'inbound',
                'event_type' => 'webhook_authentication_failed',
                'outcome' => 'failed',
                'error_code' => 'invalid_signature',
                'error_message' => 'Incoming Nexum relationship signature did not match.',
                'machine_identity' => $request->ip(),
            ]);

            return null;
        }

        return $relationship;
    }
}
