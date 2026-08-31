<?php

namespace App\Modules\Email\Models;

use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Support\EmailSubjectPresenter;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class EmailMessage extends Model
{
    use SoftDeletes;

    protected $table = 'email_messages';

    private static ?bool $subjectSearchColumnAvailable = null;

    protected $fillable = [
        'account_id', 'mailbox', 'imap_uid', 'imap_uid_validity', 'message_id', 'subject',
        'from_name', 'from_email', 'to_json', 'cc_json', 'headers_json',
        'in_reply_to', 'references', 'received_at', 'size_bytes', 'is_oversize',
        'state', 'labels_json', 'body_html_sanitized', 'body_text',
        'raw_path', 'attachments_count', 'checksum_sha1', 'ticket_id',
    ];

    protected $hidden = [
        'subject_search',
    ];

    protected $casts = [
        'to_json' => 'array',
        'cc_json' => 'array',
        'headers_json' => 'array',
        'labels_json' => 'array',
        'received_at' => 'datetime',
        'is_oversize' => 'boolean',
        'attachments_count' => 'integer',
        'imap_uid_validity' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $message): void {
            // Keep pre-migration workers compatible during a staged deploy.
            if (! self::hasSubjectSearchColumn()) {
                return;
            }

            if ($message->exists
                && ! $message->isDirty('subject')
                && ! $message->isDirty('subject_search')) {
                return;
            }

            $rawSubject = $message->getAttribute('subject');
            $message->setAttribute(
                'subject_search',
                EmailSubjectPresenter::present($rawSubject === null ? null : (string) $rawSubject),
            );
        });
    }

    /**
     * Stored inbound HTML may have been written by older sanitizer versions.
     * Re-sanitize on read so UI/API consumers never receive active email HTML.
     */
    public function getBodyHtmlSanitizedAttribute(?string $value): ?string
    {
        return HtmlSanitizer::sanitize($value);
    }

    /**
     * Return a safe, friendly presentation of historical or malformed MIME
     * encoded words without changing the persisted identity-bearing subject.
     */
    public function displaySubject(): ?string
    {
        return EmailSubjectPresenter::present($this->subject);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmailMailboxPlacement::class, 'email_message_id');
    }

    public function canonicalSource(): HasOne
    {
        return $this->hasOne(EmailCanonicalMessageSource::class, 'source_email_message_id');
    }

    public function latestPlacement(): HasOne
    {
        return $this->hasOne(EmailMailboxPlacement::class, 'email_message_id')
            ->latestOfMany();
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(EmailMessageUserState::class, 'email_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class, 'message_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(EmailMessageClassification::class, 'email_message_id');
    }

    public function ticketConversationLinks(): HasMany
    {
        return $this->hasMany(EmailTicketConversationLink::class, 'email_message_id');
    }

    public function ticketCorrelationConflict(): HasOne
    {
        return $this->hasOne(EmailTicketCorrelationConflict::class, 'email_message_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }

    /**
     * Keep legacy Inbox routes tied to an active provider occurrence whose
     * durable folder projection is authoritatively classified as Inbox.
     * Message.mailbox is intentionally not authority after MOVE or rename.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeProviderInbox(Builder $query): Builder
    {
        if (! Schema::hasTable('email_mailbox_placements')
            || ! Schema::hasTable('email_folders')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($placements): void {
            $placements
                ->selectRaw('1')
                ->from('email_mailbox_placements as inbox_provider_placements')
                ->join(
                    'email_folders as inbox_provider_folders',
                    'inbox_provider_folders.id',
                    '=',
                    'inbox_provider_placements.email_folder_id',
                )
                ->whereColumn(
                    'inbox_provider_placements.email_message_id',
                    'email_messages.id',
                )
                ->whereColumn(
                    'inbox_provider_placements.account_id',
                    'email_messages.account_id',
                )
                ->whereColumn(
                    'inbox_provider_folders.account_id',
                    'email_messages.account_id',
                )
                ->where('inbox_provider_folders.role', EmailFolder::ROLE_INBOX)
                ->where(
                    'inbox_provider_placements.local_state',
                    EmailMailboxPlacement::LOCAL_ACTIVE,
                )
                ->whereNull('inbox_provider_placements.provider_missing_at');
        });
    }

    /**
     * Require one provider occurrence for this message/account that is visible.
     * The placement, rather than legacy message mailbox/UID columns, remains
     * authoritative after a provider MOVE or folder rename.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeWithActiveProviderPlacement(Builder $query): Builder
    {
        if (! Schema::hasTable('email_mailbox_placements')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereExists(function ($placements): void {
            $placements
                ->selectRaw('1')
                ->from('email_mailbox_placements as active_provider_placements')
                ->whereColumn(
                    'active_provider_placements.email_message_id',
                    'email_messages.id',
                )
                ->whereColumn(
                    'active_provider_placements.account_id',
                    'email_messages.account_id',
                )
                ->where(
                    'active_provider_placements.local_state',
                    EmailMailboxPlacement::LOCAL_ACTIVE,
                )
                ->whereNull('active_provider_placements.provider_missing_at');
        });
    }

    /**
     * Check an active occurrence for a route-bound message. When a placement
     * is supplied, that exact placement must be the active occurrence; another
     * active source cannot authorize a hidden target route parameter.
     */
    public function hasActiveProviderPlacement(
        ?EmailMailboxPlacement $placement = null,
    ): bool {
        if (! Schema::hasTable('email_mailbox_placements')) {
            return false;
        }

        if ($placement) {
            return (int) $placement->id > 0
                && (int) $placement->email_message_id === (int) $this->id
                && (int) $placement->account_id === (int) $this->account_id
                && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
                && $placement->provider_missing_at === null;
        }

        return $this->placements()
            ->where('account_id', $this->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->exists();
    }

    public function isActiveProviderInboxMessage(): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->providerInbox()
            ->exists();
    }

    /**
     * Apply the shared Mail/Inbox/API text search as one parenthesized clause.
     * The derived subject value improves decoded-word matching without changing
     * the raw subject returned to callers or used by routing and identity.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeSearchText(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $hasSubjectSearch = self::hasSubjectSearchColumn();

        return $query->where(function (Builder $search) use ($hasSubjectSearch, $like): void {
            $search->whereRaw("subject LIKE ? ESCAPE '!'", [$like]);

            if ($hasSubjectSearch) {
                $search->orWhereRaw("subject_search LIKE ? ESCAPE '!'", [$like]);
            }

            $search
                ->orWhereRaw("from_name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("from_email LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("body_text LIKE ? ESCAPE '!'", [$like]);
        });
    }

    private static function hasSubjectSearchColumn(): bool
    {
        if (self::$subjectSearchColumnAvailable === true) {
            return true;
        }

        $available = Schema::hasColumn(
            (new self)->getTable(),
            'subject_search',
        );

        // Cache only success so a worker started before the migration discovers
        // the new column without a restart. A rollback after success still
        // requires the normal worker restart because true remains cached.
        if ($available) {
            self::$subjectSearchColumnAvailable = true;
        }

        return $available;
    }
}
