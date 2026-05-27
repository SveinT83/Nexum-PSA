<?php

namespace App\Modules\UserManagement\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InviteToken extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a new invite token for a user.
     *
     * Invalidates any previous pending tokens for the same user before
     * creating a fresh one.
     */
    public static function generateFor(User $user, int $expiresHours = 72): self
    {
        // Mark any existing pending tokens as used (invalidate them)
        static::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return static::create([
            'user_id' => $user->id,
            'token' => Str::random(48),
            'expires_at' => now()->addHours($expiresHours),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether this token can still be used to accept an invite.
     */
    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Mark the token as used.
     */
    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }
}