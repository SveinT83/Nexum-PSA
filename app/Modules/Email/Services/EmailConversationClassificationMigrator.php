<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailMessageClassification;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailConversationClassificationMigrator
{
    public const ISSUE_NO_CONVERSATION = 'no_conversation';

    public const ISSUE_MULTIPLE_CONVERSATIONS = 'multiple_conversations';

    public const ISSUE_CONFLICTING_SOURCE = 'conflicting_source';

    public const ISSUE_CONFLICTING_EXISTING_TARGET = 'conflicting_existing_target';

    /**
     * Forward-copy unambiguous legacy classification snapshots without altering compatibility history.
     *
     * @return array{
     *     source_classifications: int,
     *     mapped_source_classifications: int,
     *     conversation_groups: int,
     *     migrated: int,
     *     already_migrated: int,
     *     issues_found: int,
     *     issues_created: int,
     *     issues_repeated: int,
     *     no_conversation: int,
     *     multiple_conversations: int,
     *     conflicting_source: int,
     *     conflicting_existing_target: int
     * }
     */
    public function migrate(): array
    {
        $report = $this->emptyReport();

        if (! $this->hasRequiredTables()) {
            return $report;
        }

        /** @var array<string, array{account_id: int, conversation_id: int, sources: array<int, array<string, mixed>>}> $groups */
        $groups = [];

        DB::table('email_message_classifications')
            ->select([
                'id',
                'account_id',
                'email_message_id',
                'category_id',
                'assigned_by',
                'assigned_at',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(250, function ($rows) use (&$groups, &$report): void {
                $sourceIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $messageIds = $rows->pluck('email_message_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $accountIds = $rows->pluck('account_id')->map(fn ($id): int => (int) $id)->unique()->all();

                $sourceModels = EmailMessageClassification::query()
                    ->with('tags')
                    ->whereKey($sourceIds)
                    ->get()
                    ->keyBy('id');

                $conversationMappings = DB::table('email_mailbox_placements as placements')
                    ->join('email_conversations as conversations', function ($join): void {
                        $join->on('conversations.id', '=', 'placements.email_conversation_id')
                            ->on('conversations.account_id', '=', 'placements.account_id');
                    })
                    ->whereIn('placements.email_message_id', $messageIds)
                    ->whereIn('placements.account_id', $accountIds)
                    ->whereNotNull('placements.email_conversation_id')
                    ->select([
                        'placements.account_id',
                        'placements.email_message_id',
                        'placements.email_conversation_id',
                    ])
                    ->distinct()
                    ->get()
                    ->groupBy(fn (object $mapping): string => $this->messageKey(
                        (int) $mapping->account_id,
                        (int) $mapping->email_message_id,
                    ));

                foreach ($rows as $row) {
                    $report['source_classifications']++;

                    /** @var EmailMessageClassification|null $sourceModel */
                    $sourceModel = $sourceModels->get((int) $row->id);

                    if (! $sourceModel) {
                        continue;
                    }

                    $source = $this->sourceRecord($sourceModel);
                    $candidateIds = collect($conversationMappings->get(
                        $this->messageKey((int) $row->account_id, (int) $row->email_message_id),
                        collect(),
                    ))
                        ->pluck('email_conversation_id')
                        ->map(fn ($id): int => (int) $id)
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    if ($candidateIds === []) {
                        $created = $this->recordIssue([
                            'fingerprint_parts' => [self::ISSUE_NO_CONVERSATION, (int) $row->id],
                            'issue_type' => self::ISSUE_NO_CONVERSATION,
                            'account_id' => (int) $row->account_id,
                            'email_message_id' => (int) $row->email_message_id,
                            'email_message_classification_id' => (int) $row->id,
                            'email_conversation_id' => null,
                            'source_classification_ids' => [(int) $row->id],
                            'candidate_conversation_ids' => [],
                            'source_snapshot' => $source['snapshot'],
                            'target_snapshot' => null,
                            'details' => [
                                'reason' => 'No account-matching durable conversation was found for the legacy message classification.',
                            ],
                        ]);
                        $this->countIssue($report, self::ISSUE_NO_CONVERSATION, $created);

                        continue;
                    }

                    if (count($candidateIds) !== 1) {
                        $created = $this->recordIssue([
                            'fingerprint_parts' => [self::ISSUE_MULTIPLE_CONVERSATIONS, (int) $row->id],
                            'issue_type' => self::ISSUE_MULTIPLE_CONVERSATIONS,
                            'account_id' => (int) $row->account_id,
                            'email_message_id' => (int) $row->email_message_id,
                            'email_message_classification_id' => (int) $row->id,
                            'email_conversation_id' => null,
                            'source_classification_ids' => [(int) $row->id],
                            'candidate_conversation_ids' => $candidateIds,
                            'source_snapshot' => $source['snapshot'],
                            'target_snapshot' => null,
                            'details' => [
                                'reason' => 'The legacy message classification maps to more than one durable conversation.',
                            ],
                        ]);
                        $this->countIssue($report, self::ISSUE_MULTIPLE_CONVERSATIONS, $created);

                        continue;
                    }

                    $report['mapped_source_classifications']++;
                    $conversationId = $candidateIds[0];
                    $groupKey = $this->conversationKey((int) $row->account_id, $conversationId);

                    $groups[$groupKey] ??= [
                        'account_id' => (int) $row->account_id,
                        'conversation_id' => $conversationId,
                        'sources' => [],
                    ];
                    $groups[$groupKey]['sources'][] = $source;
                }
            }, 'id');

        $report['conversation_groups'] = count($groups);

        foreach ($groups as $group) {
            $sourceSnapshots = collect($group['sources'])
                ->map(fn (array $source): array => $source['snapshot'])
                ->unique(fn (array $snapshot): string => $this->snapshotHash($snapshot))
                ->values();
            $sourceIds = collect($group['sources'])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

            if ($sourceSnapshots->count() !== 1) {
                $created = $this->recordIssue([
                    'fingerprint_parts' => [
                        self::ISSUE_CONFLICTING_SOURCE,
                        $group['account_id'],
                        $group['conversation_id'],
                        $sourceIds,
                    ],
                    'issue_type' => self::ISSUE_CONFLICTING_SOURCE,
                    'account_id' => $group['account_id'],
                    'email_message_id' => null,
                    'email_message_classification_id' => $sourceIds[0] ?? null,
                    'email_conversation_id' => $group['conversation_id'],
                    'source_classification_ids' => $sourceIds,
                    'candidate_conversation_ids' => [$group['conversation_id']],
                    'source_snapshot' => [
                        'classifications' => collect($group['sources'])
                            ->map(fn (array $source): array => [
                                'id' => $source['id'],
                                'email_message_id' => $source['email_message_id'],
                                'snapshot' => $source['snapshot'],
                            ])
                            ->values()
                            ->all(),
                    ],
                    'target_snapshot' => null,
                    'details' => [
                        'reason' => 'Legacy message classifications inside the conversation do not have identical category and tag snapshots.',
                    ],
                ]);
                $this->countIssue($report, self::ISSUE_CONFLICTING_SOURCE, $created);

                continue;
            }

            $result = $this->migrateGroup($group, $sourceSnapshots->first());

            if ($result['status'] === 'migrated') {
                $report['migrated']++;
            } elseif ($result['status'] === 'already_migrated') {
                $report['already_migrated']++;
            } elseif ($result['status'] === self::ISSUE_CONFLICTING_EXISTING_TARGET) {
                $this->countIssue(
                    $report,
                    self::ISSUE_CONFLICTING_EXISTING_TARGET,
                    $result['issue_created'],
                );
            }
        }

        return $report;
    }

    /**
     * @param  array{account_id: int, conversation_id: int, sources: array<int, array<string, mixed>>}  $group
     * @param  array{category_id: ?int, tag_ids: array<int, int>}  $sourceSnapshot
     * @return array{status: string, issue_created: bool}
     */
    private function migrateGroup(array $group, array $sourceSnapshot): array
    {
        return DB::transaction(function () use ($group, $sourceSnapshot): array {
            // Locking the conversation serializes first-write migration with normal classification work.
            DB::table('email_conversations')
                ->where('id', $group['conversation_id'])
                ->where('account_id', $group['account_id'])
                ->lockForUpdate()
                ->first();

            $existing = EmailConversationClassification::query()
                ->where('account_id', $group['account_id'])
                ->where('email_conversation_id', $group['conversation_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->load('category', 'tags');
                $targetSnapshot = $this->classificationSnapshot($existing);

                if ($targetSnapshot === $sourceSnapshot) {
                    return ['status' => 'already_migrated', 'issue_created' => false];
                }

                $sourceIds = collect($group['sources'])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $created = $this->recordIssue([
                    'fingerprint_parts' => [
                        self::ISSUE_CONFLICTING_EXISTING_TARGET,
                        $group['account_id'],
                        $group['conversation_id'],
                        $sourceIds,
                    ],
                    'issue_type' => self::ISSUE_CONFLICTING_EXISTING_TARGET,
                    'account_id' => $group['account_id'],
                    'email_message_id' => null,
                    'email_message_classification_id' => $sourceIds[0] ?? null,
                    'email_conversation_id' => $group['conversation_id'],
                    'source_classification_ids' => $sourceIds,
                    'candidate_conversation_ids' => [$group['conversation_id']],
                    'source_snapshot' => $sourceSnapshot,
                    'target_snapshot' => $targetSnapshot,
                    'details' => [
                        'reason' => 'The existing conversation classification differs from the unambiguous legacy snapshot and was not overwritten.',
                        'target_classification_id' => (int) $existing->id,
                        'target_source' => $existing->source,
                    ],
                ]);

                return [
                    'status' => self::ISSUE_CONFLICTING_EXISTING_TARGET,
                    'issue_created' => $created,
                ];
            }

            $sourceIds = collect($group['sources'])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $actorIds = collect($group['sources'])
                ->pluck('assigned_by')
                ->filter(fn ($id): bool => $id !== null)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            $assignedAt = collect($group['sources'])
                ->pluck('assigned_at')
                ->filter()
                ->sortByDesc(fn (string $timestamp): int => strtotime($timestamp) ?: 0)
                ->first();
            $provenance = [
                'migration' => 'email_message_classifications_to_conversations',
                'source_table' => 'email_message_classifications',
                'source_classification_ids' => $sourceIds,
                'source_event_ids' => Schema::hasTable('email_message_classification_events')
                    ? DB::table('email_message_classification_events')
                        ->whereIn('email_message_classification_id', $sourceIds)
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all()
                    : [],
                'source_message_ids' => collect($group['sources'])
                    ->pluck('email_message_id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
                'source_assignments' => collect($group['sources'])
                    ->map(fn (array $source): array => [
                        'classification_id' => $source['id'],
                        'assigned_by' => $source['assigned_by'],
                        'assigned_at' => $source['assigned_at'],
                    ])
                    ->values()
                    ->all(),
            ];

            $classification = EmailConversationClassification::query()->create([
                'account_id' => $group['account_id'],
                'email_conversation_id' => $group['conversation_id'],
                'category_id' => $sourceSnapshot['category_id'],
                // Attribute a compatibility migration only when every source names the same actor.
                'assigned_by' => $actorIds->count() === 1
                    && collect($group['sources'])->whereNotNull('assigned_by')->count() === count($group['sources'])
                        ? $actorIds->first()
                        : null,
                'assigned_at' => $assignedAt,
                'source' => EmailConversationClassification::SOURCE_COMPATIBILITY_MIGRATION,
                'provenance' => $provenance,
            ]);

            $classification->tags()->syncWithPivotValues(
                $sourceSnapshot['tag_ids'],
                ['module' => 'email'],
            );
            $classification->load('category', 'tags');

            DB::table('email_conversation_classification_events')->insert([
                'email_conversation_classification_id' => $classification->id,
                'account_id' => $group['account_id'],
                'email_conversation_id' => $group['conversation_id'],
                'actor_id' => null,
                'event_type' => 'migrated',
                'before_json' => $this->json(['category' => null, 'tags' => []]),
                'after_json' => $this->json($this->eventSnapshot($classification)),
                'metadata_json' => $this->json([
                    'migration' => 'email_message_classifications_to_conversations',
                    'source_classification_count' => count($sourceIds),
                ]),
                'provenance_json' => $this->json($provenance),
                'created_at' => now(),
            ]);

            return ['status' => 'migrated', 'issue_created' => false];
        });
    }

    /**
     * Read only tags attached to the legacy classification model. EmailMessage::tags are never read,
     * while an older missing pivot module is normalized to module=email on the new assignment.
     *
     * @return array<string, mixed>
     */
    private function sourceRecord(EmailMessageClassification $classification): array
    {
        return [
            'id' => (int) $classification->id,
            'email_message_id' => (int) $classification->email_message_id,
            'assigned_by' => $classification->assigned_by ? (int) $classification->assigned_by : null,
            'assigned_at' => $classification->assigned_at?->format('Y-m-d H:i:s'),
            'created_at' => $classification->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $classification->updated_at?->format('Y-m-d H:i:s'),
            'snapshot' => [
                'category_id' => $classification->category_id ? (int) $classification->category_id : null,
                'tag_ids' => $classification->tags
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array{category_id: ?int, tag_ids: array<int, int>}
     */
    private function classificationSnapshot(EmailConversationClassification $classification): array
    {
        return [
            'category_id' => $classification->category_id ? (int) $classification->category_id : null,
            'tag_ids' => $classification->tags
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * Match the public event shape used by normal conversation classification updates.
     *
     * @return array{category: array{id: int, name: string}|null, tags: array<int, array{id: int, name: string}>}
     */
    private function eventSnapshot(EmailConversationClassification $classification): array
    {
        return [
            'category' => $classification->category
                ? ['id' => (int) $classification->category->id, 'name' => $classification->category->name]
                : null,
            'tags' => $classification->tags
                ->sortBy('id')
                ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name])
                ->values()
                ->all(),
        ];
    }

    /**
     * Store one stable issue row and refresh only its observation data on later runs.
     * A human resolution is deliberately not reopened or overwritten by an idempotent retry.
     *
     * @param  array<string, mixed>  $issue
     */
    private function recordIssue(array $issue): bool
    {
        $fingerprint = hash('sha256', $this->json($issue['fingerprint_parts']));
        $now = now();
        $values = [
            'issue_type' => $issue['issue_type'],
            'account_id' => $issue['account_id'],
            'email_message_id' => $issue['email_message_id'],
            'email_message_classification_id' => $issue['email_message_classification_id'],
            'email_conversation_id' => $issue['email_conversation_id'],
            'source_classification_ids_json' => $this->json($issue['source_classification_ids']),
            'candidate_conversation_ids_json' => $this->json($issue['candidate_conversation_ids']),
            'source_snapshot_json' => $issue['source_snapshot'] === null ? null : $this->json($issue['source_snapshot']),
            'target_snapshot_json' => $issue['target_snapshot'] === null ? null : $this->json($issue['target_snapshot']),
            'details_json' => $this->json($issue['details']),
            'last_detected_at' => $now,
            'updated_at' => $now,
        ];

        $existingId = DB::table('email_conversation_classification_migration_issues')
            ->where('fingerprint', $fingerprint)
            ->value('id');

        if ($existingId) {
            DB::table('email_conversation_classification_migration_issues')
                ->where('id', $existingId)
                ->update($values);

            return false;
        }

        DB::table('email_conversation_classification_migration_issues')->insert([
            'fingerprint' => $fingerprint,
            'status' => 'open',
            ...$values,
            'first_detected_at' => $now,
            'created_at' => $now,
        ]);

        return true;
    }

    /**
     * @param  array<string, int>  $report
     */
    private function countIssue(array &$report, string $issueType, bool $created): void
    {
        $report['issues_found']++;
        $report[$issueType]++;
        $report[$created ? 'issues_created' : 'issues_repeated']++;
    }

    private function hasRequiredTables(): bool
    {
        return Schema::hasTable('email_message_classifications')
            && Schema::hasTable('email_mailbox_placements')
            && Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')
            && Schema::hasTable('email_conversations')
            && Schema::hasTable('taggables')
            && Schema::hasTable('email_conversation_classifications')
            && Schema::hasTable('email_conversation_classification_events')
            && Schema::hasTable('email_conversation_classification_migration_issues');
    }

    /**
     * @return array<string, int>
     */
    private function emptyReport(): array
    {
        return [
            'source_classifications' => 0,
            'mapped_source_classifications' => 0,
            'conversation_groups' => 0,
            'migrated' => 0,
            'already_migrated' => 0,
            'issues_found' => 0,
            'issues_created' => 0,
            'issues_repeated' => 0,
            self::ISSUE_NO_CONVERSATION => 0,
            self::ISSUE_MULTIPLE_CONVERSATIONS => 0,
            self::ISSUE_CONFLICTING_SOURCE => 0,
            self::ISSUE_CONFLICTING_EXISTING_TARGET => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotHash(array $snapshot): string
    {
        return hash('sha256', $this->json($snapshot));
    }

    private function messageKey(int $accountId, int $messageId): string
    {
        return $accountId.':'.$messageId;
    }

    private function conversationKey(int $accountId, int $conversationId): string
    {
        return $accountId.':'.$conversationId;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
