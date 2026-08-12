<?php

namespace App\Modules\Sales\Models;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuoteAcceptanceSnapshot extends Model
{
    protected $fillable = [
        'quote_version_id',
        'accepted_at',
        'accepted_by_name',
        'accepted_by_email',
        'source_method',
        'source_user_id',
        'portal_account_id',
        'portal_membership_id',
        'portal_contact_id',
        'selected_line_ids',
        'declined_line_ids',
        'selected_lines',
        'declined_lines',
        'acknowledgements',
        'totals',
        'customer_identity',
        'public_text_snapshot',
        'selection_payload',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'selected_line_ids' => 'array',
        'declined_line_ids' => 'array',
        'selected_lines' => 'array',
        'declined_lines' => 'array',
        'acknowledgements' => 'array',
        'totals' => 'array',
        'customer_identity' => 'array',
        'public_text_snapshot' => 'array',
        'selection_payload' => 'array',
    ];

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }

    public function conversionPlans(): HasMany
    {
        return $this->hasMany(SalesQuoteConversionPlan::class, 'acceptance_snapshot_id');
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function portalAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalAccount::class, 'portal_account_id');
    }

    public function portalMembership(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalMembership::class, 'portal_membership_id');
    }

    public function portalContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'portal_contact_id');
    }
}
