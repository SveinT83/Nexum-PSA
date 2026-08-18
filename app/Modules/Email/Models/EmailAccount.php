<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Integration\Models\EmailProviderConnection;

class EmailAccount extends Model
{
    protected $table = 'email_accounts';

    public const KIND_PERSONAL = 'personal';

    public const KIND_SHARED = 'shared';

    public const KIND_SYSTEM = 'system';

    public const KINDS = [
        self::KIND_SHARED => 'Shared mailbox',
        self::KIND_PERSONAL => 'Personal mailbox',
        self::KIND_SYSTEM => 'System mailbox',
    ];

    public const DEFAULT_SCOPES = [
        'system' => 'System notifications',
        'tickets' => 'Tickets',
        'sales' => 'Sales',
        'marketing' => 'Marketing',
        'alerts' => 'Alerts',
    ];

    protected $fillable = [
        'address', 'description', 'from_name',
        'account_kind', 'owner_id', 'is_active', 'is_global_default', 'defaults_for',
        'ticket_ingress_enabled', 'delete_policy',
        'provider_integration_id', 'provider_credential_source', 'provider_binding_version',
        'provider_bound_at', 'provider_bound_by',
        // IMAP
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_secret', 'imap_auth_type',
        'imap_uid_validity', 'imap_live_start_uid', 'imap_live_cursor_initialized_at',

        // SMTP
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_secret', 'smtp_auth_type',
        // Health
        'last_test_at', 'last_test_result', 'last_error_code', 'last_error_message',
        'last_successful_fetch_at', 'last_successful_send_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_global_default' => 'boolean',
        'defaults_for' => 'array',
        'ticket_ingress_enabled' => 'boolean',
        'owner_id' => 'integer',
        'imap_uid_validity' => 'integer',
        'imap_live_start_uid' => 'integer',
        'imap_live_cursor_initialized_at' => 'datetime',
        'last_test_at' => 'datetime',
        'last_successful_fetch_at' => 'datetime',
        'last_successful_send_at' => 'datetime',
        'provider_binding_version' => 'integer',
        'provider_bound_at' => 'datetime',
        'provider_runtime_paused_at' => 'datetime',
        'provider_runtime_drained_at' => 'datetime',
    ];

    protected $hidden = [
        'imap_host', 'imap_username', 'imap_secret',
        'smtp_host', 'smtp_username', 'smtp_secret',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'account_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(EmailFolder::class, 'account_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmailMailboxPlacement::class, 'account_id');
    }

    public function uidNamespaces(): HasMany
    {
        return $this->hasMany(EmailFolderUidNamespace::class, 'account_id');
    }

    public function historicalImportRuns(): HasMany
    {
        return $this->hasMany(EmailHistoricalImportRun::class, 'account_id');
    }

    public function cursorRebaselineRuns(): HasMany
    {
        return $this->hasMany(EmailCursorRebaselineRun::class, 'account_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(EmailConversation::class, 'account_id');
    }

    public function remoteOperations(): HasMany
    {
        return $this->hasMany(EmailRemoteOperation::class, 'account_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function userGrants(): HasMany
    {
        return $this->hasMany(EmailAccountUserGrant::class, 'email_account_id');
    }

    public function mailboxDelegations(): HasMany
    {
        return $this->hasMany(EmailMailboxDelegation::class, 'email_account_id');
    }

    public function breakGlassAccesses(): HasMany
    {
        return $this->hasMany(EmailBreakGlassAccess::class, 'email_account_id');
    }

    public function mailboxAccessEvents(): HasMany
    {
        return $this->hasMany(EmailMailboxAccessEvent::class, 'email_account_id');
    }

    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(EmailRule::class, 'email_rule_accounts', 'email_account_id', 'email_rule_id')
            ->withTimestamps();
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(EmailProviderConnection::class, 'provider_integration_id');
    }

    public function usesIntegrationProvider(): bool
    {
        return $this->provider_credential_source === 'integration';
    }

    public function isPersonal(): bool
    {
        return $this->account_kind === self::KIND_PERSONAL;
    }

    public function isShared(): bool
    {
        return $this->account_kind === self::KIND_SHARED;
    }

    public function isSystem(): bool
    {
        return $this->account_kind === self::KIND_SYSTEM;
    }

    public function allowsTicketIngress(): bool
    {
        return ! $this->isPersonal() && (bool) $this->ticket_ingress_enabled;
    }
}
