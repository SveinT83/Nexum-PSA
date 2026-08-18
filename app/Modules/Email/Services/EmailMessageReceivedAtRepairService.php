<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageReceivedAtRepair;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailMessageReceivedAtRepairService
{
    public function __construct(
        private readonly RecoverEmailSmartInboxSuggestionsAfterReceivedAtRepair $recoverSuggestions,
    ) {}

    /**
     * Inspect or apply the exact scope snapshotted by migration 121200.
     * Preview mode performs no writes; apply mode never calls an email provider.
     *
     * @return array<string, mixed>
     */
    public function run(bool $apply = false): array
    {
        $this->assertReady();

        $result = [
            'mode' => $apply ? 'apply' : 'preview',
            'scoped' => 0,
            'pending' => 0,
            'already_repaired' => 0,
            'repairable' => 0,
            'repaired' => 0,
            'unchanged' => 0,
            'unresolved' => 0,
            'sources' => [],
            'candidates' => [],
            'issues' => [],
            'recovered_suggestions' => 0,
            'recovered_suggestion_ids' => [],
        ];
        $recoveryScope = [];

        EmailMessageReceivedAtRepair::query()
            ->orderBy('id')
            ->chunkById(200, function ($repairs) use ($apply, &$result, &$recoveryScope): void {
                foreach ($repairs as $repair) {
                    $result['scoped']++;

                    if ($repair->status === EmailMessageReceivedAtRepair::STATUS_REPAIRED) {
                        $result['already_repaired']++;
                        $recoveryScope[] = (int) $repair->email_message_id;

                        if ($repair->evidence_source) {
                            $this->increment($result['sources'], (string) $repair->evidence_source);
                        }

                        foreach ($this->issuesForLedger($repair) as $issue) {
                            $this->increment($result['issues'], $issue);
                        }

                        continue;
                    }

                    $result['pending']++;
                    $outcome = $apply
                        ? $this->applyOne((int) $repair->id)
                        : $this->previewOne($repair);
                    $resolution = $outcome['resolution'];

                    foreach ($resolution['issues'] as $issue) {
                        $this->increment($result['issues'], $issue);
                    }

                    if ($resolution['target'] === null) {
                        if ($resolution['candidate_source']) {
                            $this->increment($result['candidates'], $resolution['candidate_source']);
                        }

                        $result['unresolved']++;

                        continue;
                    }

                    $result['repairable']++;
                    $this->increment($result['sources'], $resolution['source']);

                    if (! $apply) {
                        continue;
                    }

                    if ($outcome['status'] === EmailMessageReceivedAtRepair::STATUS_REPAIRED) {
                        $result['repaired']++;
                        $result['unchanged'] += $outcome['unchanged'] ? 1 : 0;
                        $recoveryScope[] = (int) $repair->email_message_id;
                    } else {
                        $result['unresolved']++;
                    }
                }
            });

        ksort($result['sources']);
        ksort($result['candidates']);
        ksort($result['issues']);

        if ($apply && $recoveryScope !== []) {
            $windows = EmailMessageReceivedAtRepair::query()
                ->whereIn('email_message_id', array_values(array_unique($recoveryScope)))
                ->where('status', EmailMessageReceivedAtRepair::STATUS_REPAIRED)
                ->whereNotNull('observed_received_at')
                ->whereNotNull('repaired_at')
                ->get(['email_message_id', 'observed_received_at', 'repaired_at'])
                ->mapWithKeys(fn (EmailMessageReceivedAtRepair $repair): array => [
                    (int) $repair->email_message_id => [
                        'observed_at' => $repair->observed_received_at,
                        'repaired_at' => $repair->repaired_at,
                    ],
                ])
                ->all();
            $recovery = $this->recoverSuggestions->handle($windows);
            $result['recovered_suggestion_ids'] = $recovery['recovered_suggestion_ids'];
            $result['recovered_suggestions'] = count($recovery['recovered_suggestion_ids']);

            foreach ($recovery['attributed_counts'] as $messageId => $count) {
                EmailMessageReceivedAtRepair::query()
                    ->where('email_message_id', $messageId)
                    ->increment('smart_suggestions_recovered', $count);
            }
        }

        return $result;
    }

    /** @return array{status: string, unchanged: bool, resolution: array<string, mixed>} */
    private function applyOne(int $repairId): array
    {
        return DB::transaction(function () use ($repairId): array {
            $repair = EmailMessageReceivedAtRepair::query()
                ->lockForUpdate()
                ->findOrFail($repairId);
            $message = EmailMessage::withTrashed()
                ->whereKey($repair->email_message_id)
                ->lockForUpdate()
                ->first();
            $resolution = $message
                ? $this->resolve($message)
                : $this->unresolvedResolution('message_missing', ['message_missing']);
            $now = now();

            if ($resolution['target'] === null || ! $message) {
                $repair->forceFill([
                    'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                    'evidence_source' => null,
                    'evidence_fingerprint' => $resolution['evidence_fingerprint'],
                    'repaired_received_at' => null,
                    'candidate_received_at' => $this->formatDatabaseDate($resolution['candidate']),
                    'candidate_source' => $resolution['candidate_source'],
                    'reason_code' => $resolution['reason_code'],
                    'last_checked_at' => $now,
                ])->save();

                return [
                    'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                    'unchanged' => false,
                    'resolution' => $resolution,
                ];
            }

            $target = $this->formatDatabaseDate($resolution['target']);
            $current = $this->formatDatabaseDate($message->received_at);
            $observed = $this->formatDatabaseDate($repair->observed_received_at);
            $unchanged = $current === $target;

            if (! $unchanged && $current !== $observed) {
                $resolution['issues'][] = 'message_changed_since_repair_snapshot';
                $repair->forceFill([
                    'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                    'evidence_source' => null,
                    'evidence_fingerprint' => $resolution['evidence_fingerprint'],
                    'repaired_received_at' => null,
                    'candidate_received_at' => $target,
                    'candidate_source' => $resolution['source'],
                    'reason_code' => 'message_changed_since_repair_snapshot',
                    'last_checked_at' => $now,
                ])->save();

                return [
                    'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                    'unchanged' => false,
                    'resolution' => $resolution,
                ];
            }

            if (! $unchanged) {
                $update = DB::table('email_messages')->where('id', $message->id);
                $observed === null
                    ? $update->whereNull('received_at')
                    : $update->where('received_at', $observed);

                if ($update->update(['received_at' => $target]) !== 1) {
                    $resolution['issues'][] = 'message_changed_during_repair';
                    $repair->forceFill([
                        'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                        'evidence_source' => null,
                        'evidence_fingerprint' => $resolution['evidence_fingerprint'],
                        'repaired_received_at' => null,
                        'candidate_received_at' => $target,
                        'candidate_source' => $resolution['source'],
                        'reason_code' => 'message_changed_during_repair',
                        'last_checked_at' => $now,
                    ])->save();

                    return [
                        'status' => EmailMessageReceivedAtRepair::STATUS_UNRESOLVED,
                        'unchanged' => false,
                        'resolution' => $resolution,
                    ];
                }
            }

            $repair->forceFill([
                'status' => EmailMessageReceivedAtRepair::STATUS_REPAIRED,
                'repaired_received_at' => $target,
                'evidence_source' => $resolution['source'],
                'evidence_fingerprint' => $resolution['evidence_fingerprint'],
                'candidate_received_at' => null,
                'candidate_source' => null,
                'reason_code' => $resolution['reason_code'],
                'last_checked_at' => $now,
                'repaired_at' => $now,
            ])->save();

            return [
                'status' => EmailMessageReceivedAtRepair::STATUS_REPAIRED,
                'unchanged' => $unchanged,
                'resolution' => $resolution,
            ];
        });
    }

    /** @return array{status: string, unchanged: bool, resolution: array<string, mixed>} */
    private function previewOne(EmailMessageReceivedAtRepair $repair): array
    {
        $message = EmailMessage::withTrashed()->find($repair->email_message_id);
        $resolution = $message
            ? $this->resolve($message)
            : $this->unresolvedResolution('message_missing', ['message_missing']);

        return [
            'status' => $resolution['target'] === null
                ? EmailMessageReceivedAtRepair::STATUS_UNRESOLVED
                : EmailMessageReceivedAtRepair::STATUS_PENDING,
            'unchanged' => false,
            'resolution' => $resolution,
        ];
    }

    /** @return array<string, mixed> */
    private function resolve(EmailMessage $message): array
    {
        $header = $this->headerDate($message);

        if ($header['target'] instanceof CarbonImmutable) {
            return $this->resolution(
                $message,
                $header['target'],
                EmailMessageReceivedAtRepair::SOURCE_HEADER_DATE,
                'header_date_recovered',
                [],
                ['header_value_hash' => hash('sha256', $header['raw'])],
            );
        }

        $issues = $header['issue'] ? [$header['issue']] : [];
        $boundary = $this->conversationBoundary($message);
        $issues = array_values(array_unique(array_merge($issues, $boundary['issues'])));

        if ($boundary['target'] instanceof CarbonImmutable) {
            $reason = $header['issue']
                ? 'conversation_boundary_after_'.$header['issue']
                : 'conversation_boundary_recovered';

            return $this->resolution(
                $message,
                $boundary['target'],
                EmailMessageReceivedAtRepair::SOURCE_CONVERSATION_BOUNDARY,
                $reason,
                $issues,
                ['conversation_ids' => $boundary['conversation_ids']],
            );
        }

        if ($message->created_at) {
            $issues[] = 'local_ingest_created_at_requires_review';
            $reason = match (true) {
                in_array('header_date_implausible_future', $issues, true) => 'local_ingest_candidate_after_header_date_implausible_future',
                in_array('header_date_invalid', $issues, true) => 'local_ingest_candidate_after_header_date_invalid',
                in_array('conversation_boundary_conflict', $issues, true) => 'local_ingest_candidate_after_conversation_boundary_conflict',
                default => 'local_ingest_created_at_requires_review',
            };

            return $this->candidateResolution(
                $message,
                CarbonImmutable::instance($message->created_at)->utc(),
                EmailMessageReceivedAtRepair::CANDIDATE_LOCAL_INGEST_CREATED_AT,
                $reason,
                array_values(array_unique($issues)),
                [],
            );
        }

        $issues[] = 'created_at_missing';

        return $this->unresolvedResolution(
            'received_at_evidence_unavailable',
            array_values(array_unique($issues)),
        );
    }

    /** @return array{target: CarbonImmutable|null, raw: string, issue: string|null} */
    private function headerDate(EmailMessage $message): array
    {
        $headers = is_array($message->headers_json) ? $message->headers_json : [];
        $value = null;

        foreach ($headers as $name => $candidate) {
            if (mb_strtolower(trim((string) $name)) !== 'date') {
                continue;
            }

            $values = is_array($candidate) ? $candidate : [$candidate];
            $value = collect($values)
                ->first(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '');
            break;
        }

        if (! is_scalar($value) || trim((string) $value) === '') {
            return ['target' => null, 'raw' => '', 'issue' => null];
        }

        $raw = trim((string) $value);

        $hasDateShape = preg_match('/\b\d{1,2}[\s-]+[A-Za-z]{3,9}[\s-]+(?:19|20)\d{2}\b/u', $raw)
            || preg_match('/\b[A-Za-z]{3,9}[\s-]+\d{1,2},?[\s-]+(?:19|20)\d{2}\b/u', $raw)
            || preg_match('/\b(?:19|20)\d{2}[-\/]\d{1,2}[-\/]\d{1,2}\b/u', $raw)
            || preg_match('/\b[A-Za-z]{3,9}\s+\d{1,2}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:19|20)\d{2}\b/u', $raw);

        if (mb_strlen($raw) > 500 || ! $hasDateShape || ! str_contains($raw, ':')) {
            return ['target' => null, 'raw' => $raw, 'issue' => 'header_date_invalid'];
        }

        try {
            // Match ImapClient::normalizeDate(): retain the provider parser's
            // wall-clock representation instead of converting time zones.
            // RFC 5322 permits a trailing timezone comment such as `(CEST)`;
            // PHP otherwise reports it as a harmless duplicate timezone.
            $parseable = preg_replace('/\s+\([A-Za-z]{1,10}\)\s*$/u', '', $raw) ?: $raw;
            $parsed = new \DateTimeImmutable($parseable);
            $parseErrors = \DateTimeImmutable::getLastErrors();

            if (is_array($parseErrors)
                && (($parseErrors['warning_count'] ?? 0) > 0 || ($parseErrors['error_count'] ?? 0) > 0)) {
                return ['target' => null, 'raw' => $raw, 'issue' => 'header_date_invalid'];
            }

            $target = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $parsed->format('Y-m-d H:i:s'),
                'UTC',
            );
        } catch (\Throwable) {
            return ['target' => null, 'raw' => $raw, 'issue' => 'header_date_invalid'];
        }

        if (! $target || $target->year < 1970) {
            return ['target' => null, 'raw' => $raw, 'issue' => 'header_date_invalid'];
        }

        if ($target->greaterThan(CarbonImmutable::now('UTC')->addDays(2))
            || ($message->created_at
                && $target->greaterThan(CarbonImmutable::instance($message->created_at)->utc()->addDays(2)))) {
            return ['target' => null, 'raw' => $raw, 'issue' => 'header_date_implausible_future'];
        }

        return ['target' => $target, 'raw' => $raw, 'issue' => null];
    }

    /** @return array{target: CarbonImmutable|null, conversation_ids: array<int, int>, issues: array<int, string>} */
    private function conversationBoundary(EmailMessage $message): array
    {
        $conversations = EmailConversation::query()
            ->where('account_id', $message->account_id)
            ->where(function ($query) use ($message): void {
                $query
                    ->where('first_email_message_id', $message->id)
                    ->orWhere('latest_email_message_id', $message->id);
            })
            ->orderBy('id')
            ->get([
                'id',
                'first_email_message_id',
                'latest_email_message_id',
                'first_message_at',
                'last_message_at',
            ]);
        $candidates = collect();

        foreach ($conversations as $conversation) {
            if ((int) $conversation->first_email_message_id === (int) $message->id
                && $conversation->first_message_at) {
                $candidates->push($this->formatDatabaseDate($conversation->first_message_at));
            }

            if ((int) $conversation->latest_email_message_id === (int) $message->id
                && $conversation->last_message_at) {
                $candidates->push($this->formatDatabaseDate($conversation->last_message_at));
            }
        }

        $unique = $candidates->filter()->unique()->values();

        if ($unique->count() !== 1) {
            return [
                'target' => null,
                'conversation_ids' => $conversations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'issues' => $unique->count() > 1 ? ['conversation_boundary_conflict'] : [],
            ];
        }

        return [
            'target' => CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $unique->first(), 'UTC'),
            'conversation_ids' => $conversations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'issues' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function resolution(
        EmailMessage $message,
        CarbonImmutable $target,
        string $source,
        string $reasonCode,
        array $issues,
        array $evidence,
    ): array {
        $date = $this->formatDatabaseDate($target);

        return [
            'target' => $target,
            'source' => $source,
            'candidate' => null,
            'candidate_source' => null,
            'reason_code' => $reasonCode,
            'issues' => $issues,
            'evidence_fingerprint' => hash('sha256', json_encode([
                'message_id' => (int) $message->id,
                'source' => $source,
                'received_at' => $date,
                'evidence' => $evidence,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string, mixed> */
    private function candidateResolution(
        EmailMessage $message,
        CarbonImmutable $candidate,
        string $candidateSource,
        string $reasonCode,
        array $issues,
        array $evidence,
    ): array {
        $date = $this->formatDatabaseDate($candidate);

        return [
            'target' => null,
            'source' => null,
            'candidate' => $candidate,
            'candidate_source' => $candidateSource,
            'reason_code' => $reasonCode,
            'issues' => $issues,
            'evidence_fingerprint' => hash('sha256', json_encode([
                'message_id' => (int) $message->id,
                'candidate_source' => $candidateSource,
                'candidate_received_at' => $date,
                'evidence' => $evidence,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string, mixed> */
    private function unresolvedResolution(string $reasonCode, array $issues): array
    {
        return [
            'target' => null,
            'source' => null,
            'candidate' => null,
            'candidate_source' => null,
            'reason_code' => $reasonCode,
            'issues' => $issues,
            'evidence_fingerprint' => null,
        ];
    }

    private function assertReady(): void
    {
        if (! Schema::hasTable('email_message_received_at_repairs')
            || ! Schema::hasColumn('email_messages', 'received_at')) {
            throw new \RuntimeException('Run Mail migration 121200 before received-at repair.');
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $column = DB::selectOne(<<<'SQL'
            SELECT EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'email_messages'
              AND COLUMN_NAME = 'received_at'
            LIMIT 1
        SQL);

        if ($column && str_contains(mb_strtolower((string) $column->EXTRA), 'on update')) {
            throw new \RuntimeException('Refusing repair while received_at still has ON UPDATE CURRENT_TIMESTAMP.');
        }
    }

    private function formatDatabaseDate(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s');
    }

    /** @return array<int, string> */
    private function issuesForLedger(EmailMessageReceivedAtRepair $repair): array
    {
        $issues = [];
        $reason = (string) $repair->reason_code;

        foreach (['header_date_invalid', 'header_date_implausible_future', 'conversation_boundary_conflict'] as $issue) {
            if (str_contains($reason, $issue)) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
}
