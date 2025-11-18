<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EmailRetentionPurgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public int $months = 24) {}

    public function handle(): void
    {
        $cutoff = now()->subMonths($this->months);

        EmailMessage::query()
            ->where('received_at', '<', $cutoff)
            ->chunkById(100, function ($messages) {
                foreach ($messages as $msg) {
                    // Delete attachments files
                    foreach ($msg->attachments as $att) {
                        if ($att->disk === 'local' && $att->path) {
                            Storage::disk('local')->delete($att->path);
                        }
                        $att->delete();
                    }
                    // Delete raw .eml
                    if ($msg->raw_path && Storage::disk('local')->exists($msg->raw_path)) {
                        Storage::disk('local')->delete($msg->raw_path);
                    }
                    $msg->delete();
                }
            });
    }
}
