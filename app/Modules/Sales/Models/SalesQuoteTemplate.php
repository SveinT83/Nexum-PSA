<?php

namespace App\Modules\Sales\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesQuoteTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'template_key',
        'name',
        'description',
        'is_active',
        'target_type',
        'customer_segment',
        'intro_text',
        'scope_text',
        'assumptions_text',
        'exclusions_text',
        'next_steps_text',
        'seller_checklist',
        'approval_policy_hints',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'seller_checklist' => 'array',
        'approval_policy_hints' => 'array',
    ];

    public function optionGroups(): HasMany
    {
        return $this->hasMany(SalesQuoteTemplateOptionGroup::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuoteTemplateLine::class, 'template_id')->orderBy('section')->orderBy('sort_order')->orderBy('id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(SalesQuoteTemplateAcknowledgement::class, 'template_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function snapshot(): array
    {
        $this->loadMissing(['optionGroups', 'lines.optionGroup', 'acknowledgements']);

        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'name' => $this->name,
            'description' => $this->description,
            'target_type' => $this->target_type,
            'customer_segment' => $this->customer_segment,
            'seller_checklist' => $this->seller_checklist ?: [],
            'approval_policy_hints' => $this->approval_policy_hints ?: [],
            'option_groups' => $this->optionGroups->map(fn (SalesQuoteTemplateOptionGroup $group): array => $group->snapshot())->values()->all(),
            'lines' => $this->lines->map(fn (SalesQuoteTemplateLine $line): array => $line->snapshot())->values()->all(),
            'acknowledgements' => $this->acknowledgements->map(fn (SalesQuoteTemplateAcknowledgement $acknowledgement): array => $acknowledgement->snapshot())->values()->all(),
            'snapshotted_at' => now()->toISOString(),
        ];
    }
}
