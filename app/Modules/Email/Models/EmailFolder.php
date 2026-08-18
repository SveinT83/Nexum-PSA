<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailFolder extends Model
{
    protected $table = 'email_folders';

    public const ROLE_INBOX = 'inbox';

    public const ROLE_SENT = 'sent';

    public const ROLE_DRAFTS = 'drafts';

    public const ROLE_TRASH = 'trash';

    public const ROLE_ARCHIVE = 'archive';

    public const ROLE_JUNK = 'junk';

    public const ROLE_CUSTOM = 'custom';

    public const SYNC_SHADOW = 'shadow';

    public const SYNC_BASELINED = 'baselined';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_ERROR = 'error';

    protected $fillable = [
        'account_id',
        'provider',
        'path',
        'name',
        'delimiter',
        'parent_path',
        'remote_id',
        'special_use',
        'role',
        'is_selectable',
        'sync_enabled',
        'uid_validity',
        'uid_next',
        'live_start_uid',
        'active_uid_namespace_id',
        'highest_modseq',
        'exists_count',
        'unseen_count',
        'sync_status',
        'last_discovered_at',
        'last_synced_at',
        'sync_error_code',
        'sync_error_message',
    ];

    protected $casts = [
        'is_selectable' => 'boolean',
        'sync_enabled' => 'boolean',
        'uid_validity' => 'integer',
        'uid_next' => 'integer',
        'live_start_uid' => 'integer',
        'active_uid_namespace_id' => 'integer',
        'highest_modseq' => 'integer',
        'exists_count' => 'integer',
        'unseen_count' => 'integer',
        'last_discovered_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmailMailboxPlacement::class, 'email_folder_id');
    }

    public function uidNamespaces(): HasMany
    {
        return $this->hasMany(EmailFolderUidNamespace::class, 'email_folder_id');
    }

    public function activeUidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'active_uid_namespace_id');
    }

    public function remoteOperations(): HasMany
    {
        return $this->hasMany(EmailRemoteOperation::class, 'email_folder_id');
    }

    public function isInbox(): bool
    {
        return $this->role === self::ROLE_INBOX;
    }

    public static function inferRole(
        string $path,
        ?string $specialUse = null,
        ?string $delimiter = null,
    ): string {
        $specialRole = self::canonicalRole(self::normalizeRoleName((string) $specialUse));
        if ($specialRole !== null) {
            return $specialRole;
        }

        $leaf = $path;
        if (filled($delimiter) && str_contains($leaf, (string) $delimiter)) {
            $segments = explode((string) $delimiter, $leaf);
            $leaf = (string) end($segments);
        } elseif (preg_match('/[.\\\\\/]/u', $leaf)) {
            $segments = preg_split('/[.\\\\\/]+/u', $leaf) ?: [$leaf];
            $leaf = (string) end($segments);
        }

        // Provider path identity is byte-exact. In particular, a folder named
        // "Sent " is not the conventional Sent folder. Preserve established
        // common-name aliases only when the leaf has no edge whitespace.
        $edgeWhitespace = preg_match('/^\s|\s$/u', $leaf);
        if ($edgeWhitespace !== 0) {
            return self::ROLE_CUSTOM;
        }

        return self::canonicalRole(self::normalizeRoleName($leaf)) ?? self::ROLE_CUSTOM;
    }

    private static function normalizeRoleName(string $value): string
    {
        $normalized = mb_strtolower(ltrim(trim($value), '\\'));
        $normalized = preg_replace('/[\s_]+/u', '-', $normalized) ?? $normalized;

        return trim(preg_replace('/-+/u', '-', $normalized) ?? $normalized, '-');
    }

    private static function canonicalRole(string $normalized): ?string
    {
        return match ($normalized) {
            'inbox' => self::ROLE_INBOX,
            'sent', 'sent-items', 'sent-mail', 'sent-messages' => self::ROLE_SENT,
            'draft', 'drafts' => self::ROLE_DRAFTS,
            'trash', 'deleted', 'deleted-items', 'deleted-messages' => self::ROLE_TRASH,
            'archive', 'archives', 'all-mail' => self::ROLE_ARCHIVE,
            'junk', 'junk-email', 'spam' => self::ROLE_JUNK,
            default => null,
        };
    }
}
