<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailCanonicalReadMode;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves only common content after the caller has authorized an exact source placement. It never
 * returns a canonical ID externally and never changes the source occurrence used by workflow state.
 */
final class EmailCanonicalContentResolver
{
    private ?bool $canonicalSchemaAvailable = null;

    /** @var array<int,string> */
    private array $modeCache = [];

    /** @var array<int,EmailCanonicalMessageSource|null> */
    private array $mappingCache = [];

    /** @var array<int,EmailCanonicalMessage|null> */
    private array $canonicalCache = [];

    /** @var array<int,string> */
    private array $sourceStateCache = [];

    /** @var array<int,bool> */
    private array $storedProjectionParityCache = [];

    /** @var list<string> */
    private const PROJECTED_FIELDS = [
        'message_id',
        'subject',
        'from_name',
        'from_email',
        'to_json',
        'cc_json',
        'headers_json',
        'in_reply_to',
        'references',
        'received_at',
        'size_bytes',
        'is_oversize',
        'body_html_sanitized',
        'body_text',
        'raw_path',
        'attachments_count',
        'checksum_sha1',
    ];

    public function __construct(private readonly EmailCanonicalCutoverEvidence $evidence) {}

    public function resolve(
        EmailMailboxPlacement $placement,
        ?EmailMessage $source = null,
        bool $verifyFiles = false,
    ): EmailCanonicalContentResolution {
        $source ??= EmailMessage::query()->findOrFail($placement->email_message_id);
        $mode = $this->modeForAccount((int) $placement->account_id);
        if ($mode === EmailCanonicalReadMode::MODE_LEGACY
            || (int) $source->id !== (int) $placement->email_message_id
            || (int) $source->account_id !== (int) $placement->account_id) {
            return new EmailCanonicalContentResolution($source, $source, $mode, false, false);
        }

        $mapping = $this->mapping((int) $source->id);
        $canonical = $mapping ? $this->canonical((int) $mapping->canonical_email_message_id) : null;
        $parity = $mapping
            && $canonical
            && $canonical->status === EmailCanonicalMessage::STATUS_ACTIVE
            && (int) $placement->canonical_email_message_id === (int) $canonical->id
            && $this->hasCurrentParity($source, $mapping, $canonical, $verifyFiles);

        if ($mode !== EmailCanonicalReadMode::MODE_CANONICAL || ! $parity) {
            return new EmailCanonicalContentResolution(
                $source,
                $source,
                $mode,
                false,
                ! $parity,
            );
        }

        $projected = clone $source;
        $attributes = $projected->getAttributes();
        foreach (self::PROJECTED_FIELDS as $field) {
            $attributes[$field] = $canonical->getRawOriginal($field);
        }
        $projected->setRawAttributes($attributes, true);

        return new EmailCanonicalContentResolution($projected, $source, $mode, true, false);
    }

    public function modeForAccount(int $accountId): string
    {
        if (isset($this->modeCache[$accountId])) {
            return $this->modeCache[$accountId];
        }
        if (! $this->canonicalSchemaAvailable()) {
            return $this->modeCache[$accountId] = EmailCanonicalReadMode::MODE_LEGACY;
        }

        $mode = EmailCanonicalReadMode::query()
            ->where('email_account_id', $accountId)
            ->value('mode');

        return $this->modeCache[$accountId] = in_array($mode, EmailCanonicalReadMode::MODES, true)
            ? $mode
            : EmailCanonicalReadMode::MODE_LEGACY;
    }

    /** Preload one bounded workspace/API page without per-message mode/mapping queries. */
    public function prime(iterable $placements): void
    {
        $placements = collect($placements)->filter(fn ($placement): bool => $placement instanceof EmailMailboxPlacement);
        $accountIds = $placements->pluck('account_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $sourceIds = $placements->pluck('email_message_id')->map(fn ($id): int => (int) $id)->unique()->all();
        if ($accountIds !== []) {
            $modes = $this->canonicalSchemaAvailable()
                ? EmailCanonicalReadMode::query()->whereIn('email_account_id', $accountIds)->get()->keyBy('email_account_id')
                : collect();
            foreach ($accountIds as $accountId) {
                $mode = $modes->get($accountId)?->mode;
                $this->modeCache[$accountId] = in_array($mode, EmailCanonicalReadMode::MODES, true)
                    ? $mode
                    : EmailCanonicalReadMode::MODE_LEGACY;
            }
        }
        if ($sourceIds === []) {
            return;
        }

        // Expansion deploys may run the new application before the additive migration. They must
        // remain ordinary legacy reads and must not query any not-yet-created canonical table.
        if (! $this->canonicalSchemaAvailable()
            || collect($accountIds)->every(
                fn (int $accountId): bool => ($this->modeCache[$accountId] ?? EmailCanonicalReadMode::MODE_LEGACY)
                    === EmailCanonicalReadMode::MODE_LEGACY,
            )) {
            return;
        }

        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $sourceIds)
            ->get();
        foreach ($sourceIds as $sourceId) {
            $this->mappingCache[$sourceId] = $mappings->firstWhere('source_email_message_id', $sourceId);
        }
        $canonicals = EmailCanonicalMessage::query()
            ->whereKey($mappings->pluck('canonical_email_message_id'))
            ->with(['attachments', 'rootSource.account:id,address', 'rootSource.attachments'])
            ->get();
        foreach ($canonicals as $canonical) {
            $this->canonicalCache[(int) $canonical->id] = $canonical;
        }
        $rootIds = $canonicals->pluck('root_source_email_message_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $rootMappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $rootIds)
            ->get();
        foreach ($rootIds as $rootId) {
            $this->mappingCache[$rootId] = $rootMappings->firstWhere('source_email_message_id', $rootId);
        }
    }

    private function hasCurrentParity(
        EmailMessage $source,
        EmailCanonicalMessageSource $mapping,
        EmailCanonicalMessage $canonical,
        bool $verifyFiles,
    ): bool {
        $source->loadMissing(['account:id,address', 'attachments']);
        $root = $canonical->rootSource;
        $rootMapping = $root ? $this->mapping((int) $root->id) : null;
        if (! $root || ! $rootMapping
            || (int) $rootMapping->canonical_email_message_id !== (int) $canonical->id
            || ! hash_equals(
                (string) $mapping->source_state_hash,
                $this->sourceStateHash($source),
            )
            || ! hash_equals(
                (string) $rootMapping->source_state_hash,
                $this->sourceStateHash($root),
            )
            || ! hash_equals((string) $mapping->strict_evidence_hash, (string) $canonical->strict_evidence_hash)
            || ! $this->storedProjectionMatches($canonical)) {
            return false;
        }

        if (! $verifyFiles) {
            return true;
        }

        $sourceSnapshot = $this->evidence->forMessage($source);
        if (! hash_equals((string) $mapping->strict_evidence_hash, (string) $sourceSnapshot['strict_evidence_hash'])) {
            return false;
        }
        $rootSnapshot = $this->evidence->forMessage($root);

        return hash_equals((string) $canonical->strict_evidence_hash, (string) $rootSnapshot['strict_evidence_hash'])
            && hash_equals((string) $canonical->root_projection_hash, (string) $rootSnapshot['root_projection_hash']);
    }

    private function mapping(int $sourceId): ?EmailCanonicalMessageSource
    {
        if (! array_key_exists($sourceId, $this->mappingCache)) {
            $this->mappingCache[$sourceId] = EmailCanonicalMessageSource::query()
                ->where('source_email_message_id', $sourceId)
                ->first();
        }

        return $this->mappingCache[$sourceId];
    }

    private function canonical(int $canonicalId): ?EmailCanonicalMessage
    {
        if (! array_key_exists($canonicalId, $this->canonicalCache)) {
            $this->canonicalCache[$canonicalId] = EmailCanonicalMessage::query()
                ->with(['attachments', 'rootSource.account:id,address', 'rootSource.attachments'])
                ->find($canonicalId);
        }

        return $this->canonicalCache[$canonicalId];
    }

    private function sourceStateHash(EmailMessage $source): string
    {
        return $this->sourceStateCache[(int) $source->id]
            ??= $this->evidence->storedSourceStateHash($source);
    }

    private function storedProjectionMatches(EmailCanonicalMessage $canonical): bool
    {
        return $this->storedProjectionParityCache[(int) $canonical->id]
            ??= hash_equals(
                (string) $canonical->root_projection_hash,
                $this->evidence->storedProjectionHash($canonical),
            );
    }

    /**
     * Treat any partially deployed schema as legacy. MySQL DDL is not guaranteed to be atomic,
     * so checking only the mode table is insufficient during an interrupted expansion deploy.
     */
    private function canonicalSchemaAvailable(): bool
    {
        return $this->canonicalSchemaAvailable ??= Schema::hasTable('email_canonical_read_modes')
            && Schema::hasTable('email_canonical_message_sources')
            && Schema::hasTable('email_canonical_messages')
            && Schema::hasTable('email_canonical_message_attachments')
            && Schema::hasColumn('email_mailbox_placements', 'canonical_email_message_id');
    }
}
