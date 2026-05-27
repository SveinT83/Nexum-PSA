<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiChatMessage;
use App\Modules\Integration\Models\AiSystemSetting;

class AiChatCleanup
{
    /**
     * Apply retention settings and return a small operational summary.
     */
    public function run(): array
    {
        $settings = AiSystemSetting::current();

        if (! $settings->cleanup_enabled) {
            return $this->storeSummary($settings, [
                'enabled' => false,
                'deleted_old_chats' => 0,
                'deleted_empty_chats' => 0,
                'expired_pending_messages' => 0,
            ]);
        }

        $expiredPendingMessages = AiChatMessage::query()
            ->where('role', 'assistant')
            ->where('metadata->status', 'pending')
            ->where('created_at', '<', now()->subHours($settings->delete_failed_pending_after_hours))
            ->update([
                'body' => 'AI response expired before completion.',
                'metadata' => ['status' => 'failed', 'reason' => 'cleanup_expired'],
                'updated_at' => now(),
            ]);

        $deletedEmptyChats = AiChat::query()
            ->whereDoesntHave('messages')
            ->where('created_at', '<', now()->subDays($settings->delete_empty_chats_after_days))
            ->delete();

        $deletedOldChats = AiChat::query()
            ->where('updated_at', '<', now()->subDays($settings->chat_retention_days))
            ->delete();

        return $this->storeSummary($settings, [
            'enabled' => true,
            'deleted_old_chats' => $deletedOldChats,
            'deleted_empty_chats' => $deletedEmptyChats,
            'expired_pending_messages' => $expiredPendingMessages,
        ]);
    }

    private function storeSummary(AiSystemSetting $settings, array $summary): array
    {
        $summary['ran_at'] = now()->toDateTimeString();

        $settings->forceFill([
            'last_cleanup_at' => now(),
            'last_cleanup_summary' => $summary,
        ])->save();

        return $summary;
    }
}
