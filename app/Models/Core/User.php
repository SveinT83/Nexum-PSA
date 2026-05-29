<?php

namespace App\Models\Core;

use Database\Factories\UserFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, TwoFactorAuthenticatable;

    /*
   |--------------------------------------------------------------------------
   | Constants
   |--------------------------------------------------------------------------
   */
    const STATUS_PENDING = 'PENDING_INVITE';
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_DISABLED = 'DISABLED';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    */
    protected $fillable = [
        'contact_id',
        'name',
        'email',
        'phone_work',
        'phone_private',
        'password',
        'status',
    ];

    /*
    |--------------------------------------------------------------------------
    | Default Attributes
    |--------------------------------------------------------------------------
    */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    /*
    |--------------------------------------------------------------------------
    | Hidden Attributes
    |--------------------------------------------------------------------------
    */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function getTable()
    {
        return env('AUTH_USER_TABLE', 'user_management');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */
    public function isActive()
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDisabled()
    {
        return $this->status === self::STATUS_DISABLED;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function inviteTokens()
    {
        return $this->hasMany(\App\Modules\UserManagement\Models\InviteToken::class);
    }

    public function preferences()
    {
        return $this->hasOne(\App\Modules\UserManagement\Models\UserPreference::class);
    }

    public function contact()
    {
        return $this->belongsTo(\App\Modules\Contact\Models\Contact::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Two-Factor Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a TOTP code against the user's stored two-factor secret.
     *
     * Wraps the logic from Fortify's TwoFactorAuthenticatable trait for
     * convenient use in controllers and middleware.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (empty($this->two_factor_secret)) {
            return false;
        }

        $secret = decrypt($this->two_factor_secret);
        $google2fa = new \PragmaRX\Google2FA\Google2FA();

        return $google2fa->verifyKey($secret, $code, 8); // 8 = window of 4 steps each way
    }

    /**
     * Check whether the user has confirmed their 2FA setup
     * (i.e. they have successfully verified a TOTP code after enabling).
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }
}
