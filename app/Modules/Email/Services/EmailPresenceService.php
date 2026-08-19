<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMailDraftLock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmailPresenceService
{
    const LOCK_DURATION_MINUTES = 5;

    /**
     * Try to acquire a lock for a conversation.
     */
    public function acquireLock(int $conversationId, int $userId, string $sessionId, ?string $versionHash = null): ?EmailMailDraftLock
    {
        return DB::transaction(function () use ($conversationId, $userId, $sessionId, $versionHash) {
            $lock = EmailMailDraftLock::where('conversation_id', $conversationId)
                ->lockForUpdate()
                ->first();

            if ($lock) {
                if ($lock->isExpired() || $lock->user_id === $userId) {
                    $lock->update([
                        'user_id' => $userId,
                        'session_id' => $sessionId,
                        'expires_at' => Carbon::now()->addMinutes(self::LOCK_DURATION_MINUTES),
                        'version_hash' => $versionHash ?? $lock->version_hash,
                    ]);
                    return $lock;
                }
                return null; // Locked by someone else
            }

            return EmailMailDraftLock::create([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'expires_at' => Carbon::now()->addMinutes(self::LOCK_DURATION_MINUTES),
                'version_hash' => $versionHash,
            ]);
        });
    }

    /**
     * Renew an existing lock.
     */
    public function renewLock(int $conversationId, int $userId, string $sessionId): bool
    {
        $updated = EmailMailDraftLock::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->update([
                'expires_at' => Carbon::now()->addMinutes(self::LOCK_DURATION_MINUTES),
            ]);

        return $updated > 0;
    }

    /**
     * Release a lock.
     */
    public function releaseLock(int $conversationId, int $userId, string $sessionId): void
    {
        EmailMailDraftLock::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->delete();
    }

    /**
     * Check if a conversation is locked by someone else.
     */
    public function getActiveLock(int $conversationId): ?EmailMailDraftLock
    {
        $lock = EmailMailDraftLock::where('conversation_id', $conversationId)
            ->with('user')
            ->first();

        if ($lock && $lock->isExpired()) {
            $lock->delete();
            return null;
        }

        return $lock;
    }

    /**
     * Verify that a user still holds the lock before a critical action (like sending).
     */
    public function verifyLock(int $conversationId, int $userId, ?string $versionHash = null): bool
    {
        $lock = EmailMailDraftLock::where('conversation_id', $conversationId)
            ->first();

        if (!$lock || $lock->isExpired()) {
            return true; // No active lock, anyone can act (or we should have acquired it)
        }

        if ($lock->user_id !== $userId) {
            return false; // Locked by someone else
        }

        if ($versionHash && $lock->version_hash && $lock->version_hash !== $versionHash) {
            return false; // Stale content
        }

        return true;
    }
}
