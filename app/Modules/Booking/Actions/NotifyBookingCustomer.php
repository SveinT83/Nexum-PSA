<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Notifications\BookingRequestConfirmed;
use App\Modules\Booking\Notifications\BookingRequestDeclined;
use App\Modules\Booking\Notifications\BookingRequestReceived;
use Illuminate\Notifications\Notification as BookingNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** A durable claim separates local commit, outbound submission and uncertain outcomes. */
class NotifyBookingCustomer
{
    private const TYPES = [
        'received' => [BookingRequestReceived::class, 'customer_request_notification_sent', 'customer_requested_notification_sent_at'],
        'confirmed' => [BookingRequestConfirmed::class, 'customer_confirmation_notification_sent', 'customer_confirmation_notification_sent_at'],
        'declined' => [BookingRequestDeclined::class, 'customer_decline_notification_sent', 'customer_decline_notification_sent_at'],
    ];

    public function claim(BookingRequest $bookingRequest, string $kind): void
    {
        if (blank($bookingRequest->contact_email)) {
            return;
        }
        if (! isset(self::TYPES[$kind])) {
            throw new \LogicException('Unsupported Booking notification.');
        }
        DB::table('booking_notification_dispatches')->insertOrIgnore([
            'booking_request_id' => $bookingRequest->id, 'kind' => $kind, 'state' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function handle(BookingRequest $bookingRequest, BookingNotification $notification, string $eventType, string $timestampColumn, bool $defer = false): void
    {
        if (blank($bookingRequest->contact_email)) {
            return;
        }
        $kind = collect(self::TYPES)->search(fn ($type) => $type[1] === $eventType);
        if ($kind === false) {
            throw new \LogicException('Unsupported Booking notification.');
        }
        $this->claim($bookingRequest, $kind);
        if (! $defer) {
            $this->dispatch($bookingRequest->id, $notification);
        }
    }

    public function dispatch(int $bookingRequestId, ?BookingNotification $prepared = null): void
    {
        $ids = DB::table('booking_notification_dispatches')->where('booking_request_id', $bookingRequestId)
            ->where('state', 'pending')->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $dispatch = DB::transaction(function () use ($id) {
                $row = DB::table('booking_notification_dispatches')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->state !== 'pending') {
                    return null;
                }
                // Claim before leaving the database. A crash after this point is not blindly retried.
                DB::table('booking_notification_dispatches')->where('id', $id)->update([
                    'state' => 'unknown', 'claimed_at' => now(), 'updated_at' => now(),
                ]);

                return $row;
            });
            if (! $dispatch) {
                continue;
            }
            $bookingRequest = BookingRequest::query()->findOrFail($bookingRequestId);
            [$class, $eventType, $column] = self::TYPES[$dispatch->kind];
            try {
                Notification::route('mail', $bookingRequest->contact_email)->notify($prepared instanceof $class ? $prepared : new $class($bookingRequest));
                DB::transaction(function () use ($id, $bookingRequest, $column, $eventType) {
                    DB::table('booking_notification_dispatches')->where('id', $id)->update([
                        'state' => 'submitted', 'submitted_at' => now(), 'updated_at' => now(),
                    ]);
                    $bookingRequest->forceFill([$column => now()])->save();
                    $bookingRequest->events()->create(['type' => $eventType, 'message' => 'Customer booking email submitted; inbox receipt is unverified.']);
                });
            } catch (Throwable $exception) {
                // The transport may have accepted before throwing; retain unknown and never leak its message.
                $bookingRequest->events()->create([
                    'type' => $eventType.'_failed', 'message' => 'Customer booking email submission is unconfirmed; reconcile before retrying.',
                    'metadata' => ['outcome' => 'unknown', 'exception_type' => get_class($exception)],
                ]);
            }
        }
    }
}
