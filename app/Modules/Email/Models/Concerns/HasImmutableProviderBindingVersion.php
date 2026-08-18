<?php

namespace App\Modules\Email\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait HasImmutableProviderBindingVersion
{
    public static function bootHasImmutableProviderBindingVersion(): void
    {
        static::creating(function (Model $model): void {
            if (! Schema::hasColumn($model->getTable(), 'provider_binding_version')) {
                return;
            }

            $version = $model->getAttribute('provider_binding_version');

            if ($version === null || (int) $version < 1) {
                throw new LogicException('A provider-I/O ledger requires a positive provider binding version.');
            }
        });

        static::updating(function (Model $model): void {
            if (Schema::hasColumn($model->getTable(), 'provider_binding_version')
                && $model->isDirty('provider_binding_version')
                && (! method_exists($model, 'mayChangeProviderBindingVersion')
                    || ! $model->mayChangeProviderBindingVersion())) {
                throw new LogicException('Provider binding evidence is immutable.');
            }
        });
    }
}
