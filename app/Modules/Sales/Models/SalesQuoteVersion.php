<?php

namespace App\Modules\Sales\Models;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuoteVersion extends Model
{
    protected $fillable = [
        'quote_id',
        'version_number',
        'status',
        'secure_token',
        'title',
        'intro_text',
        'scope_text',
        'assumptions_text',
        'exclusions_text',
        'next_steps_text',
        'internal_note',
        'expires_at',
        'subtotal_ex_vat',
        'discount_total_ex_vat',
        'vat_total',
        'total_ex_vat',
        'total_inc_vat',
        'margin_amount',
        'margin_percent',
        'snapshots',
        'pdf_snapshot_disk',
        'pdf_snapshot_path',
        'pdf_snapshot_sha256',
        'sent_at',
        'viewed_at',
        'accepted_at',
        'accepted_by_name',
        'accepted_ip',
        'accepted_ua',
        'accepted_method',
        'accepted_by_user_id',
        'accepted_ticket_message_id',
        'acceptance_metadata',
        'portal_accepted_account_id',
        'portal_accepted_membership_id',
        'portal_accepted_contact_id',
        'rejected_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'subtotal_ex_vat' => 'decimal:2',
        'discount_total_ex_vat' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'total_ex_vat' => 'decimal:2',
        'total_inc_vat' => 'decimal:2',
        'margin_amount' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'snapshots' => 'array',
        'acceptance_metadata' => 'array',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(SalesQuote::class, 'quote_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesQuoteLine::class, 'quote_version_id')->orderBy('section')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function portalAcceptedAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalAccount::class, 'portal_accepted_account_id');
    }

    public function portalAcceptedMembership(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalMembership::class, 'portal_accepted_membership_id');
    }

    public function portalAcceptedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'portal_accepted_contact_id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
