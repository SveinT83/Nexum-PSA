<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingApiCommand;
use App\Modules\Booking\Support\BookingApiAccess;
use App\Modules\Booking\Support\BookingApiInput;
use App\Modules\Calendar\Support\CalendarBookingWriteBoundary;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Encrypted command snapshots are local commit evidence; current dispatch evidence is a separate read. */
class ExecuteBookingApiCommand
{
    public const AUTHORITY = [
        'setting.create' => ['booking.settings.write', 'booking.manage'],
        'setting.update' => ['booking.settings.write', 'booking.manage'],
        'setting.toggle' => ['booking.settings.write', 'booking.manage'],
        'request.confirm' => ['booking.requests.review', 'booking.request_review'],
        'request.decline' => ['booking.requests.review', 'booking.request_review'],
    ];

    public function handle(Request $request, string $operation, callable $write, ?int $publicSettingId = null): array
    {
        $public = $publicSettingId !== null;
        abort_unless(config($public ? 'booking.api_public_submissions_enabled' : 'booking.api_staff_writes_enabled'), 503, 'Booking API mutation execution is disabled.');
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match($public ? '/^[A-Za-z0-9._:-]{32,200}$/' : '/^[A-Za-z0-9._:-]{16,200}$/', $key), 422, 'Supply a sufficiently long Idempotency-Key (32 characters public, 16 staff; maximum 200).');
        $actor = $public ? null : BookingApiAccess::actor($request);
        $token = $public ? 'public' : BookingApiAccess::tokenHash($request);
        $identity = BookingApiInput::revision([$actor?->id, $token, $publicSettingId, $key]);
        $payload = $request->all();
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };
        $hash = BookingApiInput::revision([$operation, $request->path(), $canonical($payload)]);
        $command = CalendarBookingWriteBoundary::run(function () use ($request, $operation, $write, $actor, $token, $identity, $hash, $public) {
            if (! $public) {
                $actor = BookingApiAccess::actor($request);
                BookingApiAccess::authorize($actor, self::AUTHORITY[$operation][1]);
                abort_unless($actor->tokenCan(self::AUTHORITY[$operation][0]), 403, 'Missing operation ability.');
            }
            $prior = BookingApiCommand::query()->where('identity_hash', $identity)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $hash) && $prior->operation === $operation, 409, 'Idempotency-Key was already used for different input.');

                return $prior;
            }
            $result = $write();
            $command = BookingApiCommand::query()->create([
                'id' => (string) Str::uuid(), 'identity_hash' => $identity, 'actor_id' => $actor?->id,
                'token_hash' => $token, 'operation' => $operation, 'request_hash' => $hash,
                'booking_request_id' => $result['booking_request_id'] ?? null,
                'result' => $result['data'],
            ]);
            if ($actor) {
                app(DomainApiAudit::class)->record($actor, 'booking', 'booking.api.'.$operation, $command,
                    [], ['command_applied' => true], ['idempotency_key_hash' => $identity, 'request_fingerprint' => $hash]);
            }

            return $command;
        });
        // Domain actions deferred only the transport; local mutation/claim/result are now committed.
        if ($command->booking_request_id) {
            app(NotifyBookingCustomer::class)->dispatch((int) $command->booking_request_id);
        }

        return $public ? ['data' => $command->result] : ['data' => $command->result, 'command_id' => $command->id];
    }
}
