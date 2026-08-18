<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailUnreadHandoverItem;
use App\Modules\Email\Models\EmailUnreadHandoverRun;
use Illuminate\Support\Facades\DB;

class ExpireEmailUnreadHandoverPreviews
{
    public function handle(EmailAccount $account, int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));

        return DB::transaction(function () use ($account, $limit): int {
            $runs = EmailUnreadHandoverRun::query()
                ->where('email_account_id', $account->id)
                ->where('status', EmailUnreadHandoverRun::STATUS_PREVIEWED)
                ->where('preview_expires_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();
            $expired = 0;

            foreach ($runs as $run) {
                if ($run->status !== EmailUnreadHandoverRun::STATUS_PREVIEWED
                    || $run->preview_expires_at->isFuture()) {
                    continue;
                }

                $items = EmailUnreadHandoverItem::query()
                    ->where('email_unread_handover_run_id', $run->id)
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    if ($item->status === EmailUnreadHandoverItem::STATUS_PREVIEWED) {
                        $item->forceFill([
                            'status' => EmailUnreadHandoverItem::STATUS_STALE,
                            'error_code' => 'preview_expired',
                        ])->save();
                    }
                }

                $run->forceFill([
                    'status' => EmailUnreadHandoverRun::STATUS_EXPIRED,
                    'failed_count' => $items->count(),
                    'finished_at' => now(),
                    'error_code' => 'preview_expired',
                    'error_message' => 'The unread handover preview expired before confirmation.',
                ])->save();
                $expired++;
            }

            return $expired;
        });
    }
}
