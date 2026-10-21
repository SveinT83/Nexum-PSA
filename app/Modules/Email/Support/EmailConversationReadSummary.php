<?php

namespace App\Modules\Email\Support;

use App\Modules\Email\Models\EmailConversationActionItem as Item;
use App\Modules\Email\Models\EmailConversationActionRun as Run;
use App\Modules\Email\Models\EmailRemoteOperation as Remote;
use OpenApi\Attributes as OA;

/** Read-only live result projection shared by the ordinary reader and API. */
#[OA\Schema(
    schema: 'EmailConversationReadSummary',
    type: 'object',
    required: ['status', 'message_count', 'read_count', 'already_read_count', 'pending_count',
        'provider_pending_count', 'provider_failed_count', 'type', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'string',
            enum: ['previewed', 'applying', 'applied', 'partial', 'stale', 'failed', 'cancelled']),
        new OA\Property(property: 'message_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'read_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'already_read_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'pending_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'provider_pending_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'provider_failed_count', type: 'integer', minimum: 0),
        new OA\Property(property: 'type', type: 'string', enum: ['success', 'info', 'warning']),
        new OA\Property(property: 'message', type: 'string'),
    ],
)]
final class EmailConversationReadSummary
{
    public static function forRun(Run $run): array
    {
        $run->loadMissing('items.remoteOperation');
        $personal = $run->items->where('personal_selected', true);
        $count = $personal->count();
        $read = $personal->whereIn('personal_status', [Item::PERSONAL_APPLIED, Item::PERSONAL_UNCHANGED])->count();
        $pending = $personal->where('personal_status', Item::PERSONAL_PENDING)->count();
        $providerPending = 0;
        $providerFailed = 0;
        foreach ($run->items->where('provider_selected', true) as $item) {
            $status = $item->provider_status;
            if ($item->email_remote_operation_id) {
                $status = match ($item->remoteOperation?->status) {
                    Remote::STATUS_SUCCEEDED => Item::PROVIDER_SUCCEEDED,
                    Remote::STATUS_PENDING, Remote::STATUS_RUNNING => Item::PROVIDER_PENDING,
                    default => Item::PROVIDER_FAILED,
                };
            }
            $providerPending += $status === Item::PROVIDER_PENDING ? 1 : 0;
            $providerFailed += in_array($status, [Item::PROVIDER_FAILED, Item::PROVIDER_DENIED,
                Item::PROVIDER_STALE, Item::PROVIDER_CONFLICTED], true) ? 1 : 0;
        }

        $type = 'success';
        $message = "Marked all {$count} messages in this conversation as read for you.";
        if ($run->status === Run::STATUS_PREVIEWED) {
            $type = 'info';
            $message = "Ready to mark {$count} messages as read. No read state has changed.";
        } elseif (in_array($run->status, [Run::STATUS_STALE, Run::STATUS_FAILED, Run::STATUS_CANCELLED], true)) {
            $type = 'warning';
            $message = 'This conversation snapshot expired or changed. Open the menu for a fresh snapshot.';
        } elseif ($pending > 0) {
            $type = 'info';
            $message = "Marked {$read} of {$count} messages as read for you. Remaining messages are queued.";
        } elseif ($read < $count) {
            $type = 'warning';
            $message = "Marked {$read} of {$count} messages as read for you. Some messages could not be updated.";
        } elseif ($providerFailed > 0) {
            $type = 'warning';
            $message .= ' Some mail server updates failed or are no longer authorized.';
        } elseif ($providerPending > 0) {
            $type = 'info';
            $message .= ' Mail server updates are pending.';
        }

        return [
            'status' => $run->status,
            'message_count' => $count,
            'read_count' => $read,
            'already_read_count' => $personal->where('personal_status', Item::PERSONAL_UNCHANGED)->count(),
            'pending_count' => $pending,
            'provider_pending_count' => $providerPending,
            'provider_failed_count' => $providerFailed,
            'type' => $type,
            'message' => $message,
        ];
    }
}
