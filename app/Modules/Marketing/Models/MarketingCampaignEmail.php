<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Email\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaignEmail extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'email_template_id',
        'name',
        'template_snapshot_name',
        'sequence_order',
        'status',
        'scheduled_at',
        'delay_minutes',
        'subject_override',
        'subject_snapshot',
        'body_html_snapshot',
        'body_text_snapshot',
        'variables_snapshot',
        'metadata',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'variables_snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MarketingCampaignRecipient::class);
    }

    public function hasSnapshotContent(): bool
    {
        return filled($this->subject_snapshot)
            || filled($this->body_html_snapshot)
            || filled($this->body_text_snapshot);
    }

    public function displayName(): string
    {
        return $this->name
            ?: $this->template_snapshot_name
            ?: $this->template?->name
            ?: 'Campaign email '.$this->sequence_order;
    }

    public function sourceTemplateName(): ?string
    {
        return $this->template_snapshot_name ?: $this->template?->name;
    }

    public function effectiveSubject(): ?string
    {
        return $this->subject_snapshot
            ?: $this->subject_override
            ?: $this->template?->subject;
    }

    public function effectiveBodyHtml(): ?string
    {
        return $this->body_html_snapshot ?: $this->template?->body_html;
    }

    public function effectiveBodyText(): ?string
    {
        return $this->body_text_snapshot ?: $this->template?->body_text;
    }

    public function renderableTemplate(): ?EmailTemplate
    {
        if (! $this->hasSnapshotContent()) {
            return $this->template;
        }

        return new EmailTemplate([
            'scope' => 'marketing',
            'key' => 'marketing_campaign_email_'.$this->id,
            'name' => $this->displayName(),
            'subject' => $this->effectiveSubject() ?? '',
            'body_html' => $this->effectiveBodyHtml(),
            'body_text' => $this->effectiveBodyText(),
            'variables' => $this->variables_snapshot ?: (array) $this->template?->variables,
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
