<?php

namespace App\Models\Core;

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
        'name',
        'email',
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
}
