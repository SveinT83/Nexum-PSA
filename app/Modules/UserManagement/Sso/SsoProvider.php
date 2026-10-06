<?php

namespace App\Modules\UserManagement\Sso;

use Illuminate\Database\Eloquent\Model;

/** One installation-owned provider; never serialize its encrypted client credential. */
class SsoProvider extends Model
{
    protected $table = 'user_sso_providers';

    protected $guarded = [];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'enabled' => 'boolean', 'verified_at' => 'datetime'];
    }

    public static function available(): ?self
    {
        if (! config('sso.enabled', false)) {
            return null;
        }

        return static::query()->whereKey(1)->where('enabled', true)->whereNotNull('verified_at')->first();
    }

    /** Use the configured canonical origin, never an incoming Host header, for IdP redirects. */
    public static function endpoint(string $name): string
    {
        $root = rtrim((string) config('app.url'), '/');
        OidcClient::assertIssuer($root);

        return $root.route($name, [], false);
    }
}
