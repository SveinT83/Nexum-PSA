<?php

namespace App\Modules\Contact\Models;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'status',
        'display_name',
        'first_name',
        'last_name',
        'organization_name',
        'job_title',
        'preferred_language',
        'communication_language',
        'timezone',
        'do_not_call',
        'do_not_email',
        'marketing_consent',
        'metadata',
    ];

    protected $casts = [
        'do_not_call' => 'boolean',
        'do_not_email' => 'boolean',
        'marketing_consent' => 'boolean',
        'metadata' => 'array',
    ];

    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(ContactPhone::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    public function relations(): HasMany
    {
        return $this->hasMany(ContactRelation::class);
    }

    public function externalRefs(): HasMany
    {
        return $this->hasMany(ContactExternalRef::class);
    }

    public function clientUser(): HasOne
    {
        return $this->hasOne(ClientUser::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(\App\Modules\Taxonomy\Models\Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }
}
