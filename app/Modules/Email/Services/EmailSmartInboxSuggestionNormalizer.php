<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailSmartInboxSuggestionNormalizer
{
    /**
     * Convert the governed summary schema into an allowlist of independent,
     * review-only effects. Unknown keys and unknown Taxonomy labels disappear.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<int, int>  $sourceMessageIds
     * @param  array<int, string>  $forbiddenStrings
     * @param  int|null  $accountId  Account boundary used to resolve provider folders without trusting AI text.
     * @param  int|null  $sourceFolderId  Folder that must never be offered as its own cleanup target.
     * @return array<int, array{effect_type: string, proposal: array<string, mixed>, explanation: string|null, confidence: float|null}>
     */
    public function fromSummary(
        array $summary,
        array $sourceMessageIds,
        array $forbiddenStrings = [],
        ?int $accountId = null,
        ?int $sourceFolderId = null,
    ): array {
        $suggestions = [[
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            'proposal' => $this->reviewSummaryProposal($summary, $forbiddenStrings),
            'explanation' => 'Governed Mail AI generated this informational conversation review.',
            'confidence' => null,
        ]];

        foreach (array_slice((array) ($summary['action_items'] ?? []), 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->plainText($item['text'] ?? '', 255, $forbiddenStrings);
            if ($title === '') {
                continue;
            }

            $suggestions[] = [
                'effect_type' => EmailSmartInboxSuggestion::EFFECT_CREATE_TASK,
                'proposal' => [
                    'title' => $title,
                    'owner_hint' => $this->nullablePlainText($item['owner'] ?? null, 180, $forbiddenStrings),
                    'due_at_hint' => $this->nullablePlainText($item['due_at'] ?? null, 120, $forbiddenStrings),
                    'source_message_id' => $this->sourceMessageId($item['source_message_id'] ?? null, $sourceMessageIds),
                ],
                'explanation' => 'Governed Mail AI identified an editable conversation action item.',
                'confidence' => null,
            ];
        }

        foreach (array_slice((array) ($summary['suggested_labels'] ?? []), 0, 20) as $label) {
            if (! is_array($label)) {
                continue;
            }

            $type = Str::lower(trim((string) ($label['type'] ?? '')));
            $name = $this->plainText($label['label'] ?? '', 191, $forbiddenStrings);
            if ($name === '') {
                continue;
            }

            $sourceMessageId = $this->sourceMessageId($label['source_message_id'] ?? null, $sourceMessageIds);
            $explanation = $this->nullablePlainText($label['reason'] ?? null, 1000, $forbiddenStrings);
            $confidence = $this->normalizeConfidence($label['confidence'] ?? null);

            if (in_array($type, ['category', 'email_category'], true)) {
                $category = $this->categoryByLabel($name);

                if ($category) {
                    $suggestions[] = [
                        'effect_type' => EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY,
                        'proposal' => [
                            'category_id' => (int) $category->id,
                            'category_name' => $this->plainText($category->name, 191),
                            'source_message_id' => $sourceMessageId,
                        ],
                        'explanation' => $explanation ?: 'Governed Mail AI matched an existing active Email category.',
                        'confidence' => $confidence,
                    ];
                }
            }

            if (in_array($type, ['tag', 'email_tag'], true)) {
                $tag = $this->tagByLabel($name);

                if ($tag) {
                    $suggestions[] = [
                        'effect_type' => EmailSmartInboxSuggestion::EFFECT_APPLY_TAG,
                        'proposal' => [
                            'tag_id' => (int) $tag->id,
                            'tag_name' => $this->plainText($tag->name, 191),
                            'source_message_id' => $sourceMessageId,
                        ],
                        'explanation' => $explanation ?: 'Governed Mail AI matched an existing active tag.',
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        if ($accountId) {
            foreach (array_slice((array) ($summary['cleanup_suggestions'] ?? []), 0, 20) as $cleanup) {
                if (! is_array($cleanup)) {
                    continue;
                }

                $candidate = $this->cleanupCandidate(
                    $cleanup,
                    $sourceMessageIds,
                    $forbiddenStrings,
                    $accountId,
                    $sourceFolderId,
                );

                if ($candidate) {
                    $suggestions[] = $candidate;
                }
            }
        }

        return collect($suggestions)
            ->unique(fn (array $suggestion): string => $suggestion['effect_type'].'|'.json_encode($suggestion['proposal']))
            ->values()
            ->all();
    }

    /**
     * Corrections use the same bounded schema as generation. This prevents a
     * correction endpoint from becoming a raw prompt/provider-payload store.
     *
     * @param  array<string, mixed>  $proposal
     * @param  array<int, int>  $sourceMessageIds
     * @param  array<int, string>  $forbiddenStrings
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function correctionProposal(
        string $effectType,
        array $proposal,
        array $sourceMessageIds,
        array $forbiddenStrings = [],
        ?int $accountId = null,
        ?int $sourceFolderId = null,
    ): array {
        return match ($effectType) {
            EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY => $this->reviewSummaryProposal(
                $proposal,
                $forbiddenStrings,
            ),
            EmailSmartInboxSuggestion::EFFECT_CREATE_TASK => $this->taskCorrection(
                $proposal,
                $sourceMessageIds,
                $forbiddenStrings,
            ),
            EmailSmartInboxSuggestion::EFFECT_APPLY_CATEGORY => $this->categoryCorrection(
                $proposal,
                $sourceMessageIds,
            ),
            EmailSmartInboxSuggestion::EFFECT_APPLY_TAG => $this->tagCorrection(
                $proposal,
                $sourceMessageIds,
            ),
            EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER => $this->cleanupCorrection(
                $effectType,
                $proposal,
                $sourceMessageIds,
                $accountId,
                $sourceFolderId,
            ),
            default => throw ValidationException::withMessages([
                'effect_type' => 'This Smart Inbox effect cannot be corrected.',
            ]),
        };
    }

    /** @param array<int, string> $forbiddenStrings */
    public function normalizeExplanation(mixed $value, array $forbiddenStrings = []): ?string
    {
        return $this->nullablePlainText($value, 1000, $forbiddenStrings);
    }

    public function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max(0, min(1, (float) $value)), 4);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, string>  $forbiddenStrings
     * @return array<string, mixed>
     */
    private function reviewSummaryProposal(array $summary, array $forbiddenStrings): array
    {
        $urgency = Str::lower(trim((string) ($summary['urgency'] ?? 'unknown')));

        return [
            'summary' => $this->plainText($summary['summary'] ?? '', 1200, $forbiddenStrings),
            'key_points' => $this->plainTextList($summary['key_points'] ?? [], 12, 500, $forbiddenStrings),
            'questions' => $this->plainTextList($summary['questions'] ?? [], 12, 500, $forbiddenStrings),
            'urgency' => in_array($urgency, ['low', 'normal', 'high', 'unknown'], true) ? $urgency : 'unknown',
            'reply_needed' => (bool) ($summary['reply_needed'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, int>  $sourceMessageIds
     * @param  array<int, string>  $forbiddenStrings
     * @return array<string, mixed>
     */
    private function taskCorrection(array $proposal, array $sourceMessageIds, array $forbiddenStrings): array
    {
        $title = $this->plainText($proposal['title'] ?? $proposal['text'] ?? '', 255, $forbiddenStrings);

        if ($title === '') {
            throw ValidationException::withMessages(['proposal.title' => 'A proposed Task title is required.']);
        }

        return [
            'title' => $title,
            'owner_hint' => $this->nullablePlainText($proposal['owner_hint'] ?? $proposal['owner'] ?? null, 180, $forbiddenStrings),
            'due_at_hint' => $this->nullablePlainText($proposal['due_at_hint'] ?? $proposal['due_at'] ?? null, 120, $forbiddenStrings),
            'source_message_id' => $this->sourceMessageId($proposal['source_message_id'] ?? null, $sourceMessageIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, int>  $sourceMessageIds
     * @return array<string, mixed>
     */
    private function categoryCorrection(array $proposal, array $sourceMessageIds): array
    {
        $category = Category::query()
            ->active()
            ->where('type', Category::TYPE_EMAIL)
            ->find($proposal['category_id'] ?? 0);

        if (! $category) {
            throw ValidationException::withMessages([
                'proposal.category_id' => 'Select an existing active Email category.',
            ]);
        }

        return [
            'category_id' => (int) $category->id,
            'category_name' => $this->plainText($category->name, 191),
            'source_message_id' => $this->sourceMessageId($proposal['source_message_id'] ?? null, $sourceMessageIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, int>  $sourceMessageIds
     * @return array<string, mixed>
     */
    private function tagCorrection(array $proposal, array $sourceMessageIds): array
    {
        $tag = Tag::query()->where('active', true)->find($proposal['tag_id'] ?? 0);

        if (! $tag) {
            throw ValidationException::withMessages([
                'proposal.tag_id' => 'Select an existing active tag.',
            ]);
        }

        return [
            'tag_id' => (int) $tag->id,
            'tag_name' => $this->plainText($tag->name, 191),
            'source_message_id' => $this->sourceMessageId($proposal['source_message_id'] ?? null, $sourceMessageIds),
        ];
    }

    /**
     * Turn one untrusted provider response item into a proposal containing
     * only server-resolved folder identity and bounded review text.
     *
     * @param  array<string, mixed>  $cleanup
     * @param  array<int, int>  $sourceMessageIds
     * @param  array<int, string>  $forbiddenStrings
     * @return array{effect_type: string, proposal: array<string, mixed>, explanation: string|null, confidence: float|null}|null
     */
    private function cleanupCandidate(
        array $cleanup,
        array $sourceMessageIds,
        array $forbiddenStrings,
        int $accountId,
        ?int $sourceFolderId,
    ): ?array {
        $type = Str::lower(trim((string) ($cleanup['type'] ?? '')));
        $effectType = match ($type) {
            'archive', 'archive_mail' => EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
            'move', 'move_to_folder' => EmailSmartInboxSuggestion::EFFECT_MOVE_TO_FOLDER,
            default => null,
        };

        if (! $effectType) {
            return null;
        }

        $folder = $this->cleanupFolder(
            $cleanup['target_folder_id'] ?? null,
            $accountId,
            $sourceFolderId,
            $effectType,
        );

        if (! $folder) {
            return null;
        }

        return [
            'effect_type' => $effectType,
            'proposal' => $this->cleanupProposal(
                $folder,
                $this->sourceMessageId($cleanup['source_message_id'] ?? null, $sourceMessageIds),
            ),
            'explanation' => $this->nullablePlainText($cleanup['reason'] ?? null, 1000, $forbiddenStrings)
                ?: ($effectType === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL
                    ? 'Governed Mail AI proposed a reversible provider Archive action.'
                    : 'Governed Mail AI proposed a reversible provider folder move.'),
            'confidence' => $this->normalizeConfidence($cleanup['confidence'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<int, int>  $sourceMessageIds
     * @return array<string, mixed>
     */
    private function cleanupCorrection(
        string $effectType,
        array $proposal,
        array $sourceMessageIds,
        ?int $accountId,
        ?int $sourceFolderId,
    ): array {
        if (! $accountId) {
            throw ValidationException::withMessages([
                'proposal.target_folder_id' => 'The mailbox context for this cleanup target is unavailable.',
            ]);
        }

        $folder = $this->cleanupFolder(
            $proposal['target_folder_id'] ?? null,
            $accountId,
            $sourceFolderId,
            $effectType,
        );

        if (! $folder) {
            throw ValidationException::withMessages([
                'proposal.target_folder_id' => 'Select an existing active provider folder from this mailbox.',
            ]);
        }

        return $this->cleanupProposal(
            $folder,
            $this->sourceMessageId($proposal['source_message_id'] ?? null, $sourceMessageIds),
        );
    }

    private function cleanupFolder(
        mixed $folderId,
        int $accountId,
        ?int $sourceFolderId,
        string $effectType,
    ): ?EmailFolder {
        if (! is_numeric($folderId) || (int) $folderId < 1) {
            return null;
        }

        return EmailFolder::query()
            ->whereKey((int) $folderId)
            ->where('account_id', $accountId)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->when($sourceFolderId, fn ($query) => $query->whereKeyNot($sourceFolderId))
            ->when(
                $effectType === EmailSmartInboxSuggestion::EFFECT_ARCHIVE_MAIL,
                fn ($query) => $query->where('role', EmailFolder::ROLE_ARCHIVE),
            )
            ->first();
    }

    /** @return array{target_folder_id: int, target_folder_name: string, target_folder_path: string, source_message_id: int|null} */
    private function cleanupProposal(EmailFolder $folder, ?int $sourceMessageId): array
    {
        return [
            'target_folder_id' => (int) $folder->id,
            'target_folder_name' => $this->plainText($folder->name, 191),
            'target_folder_path' => $this->plainText($folder->path, 500),
            'source_message_id' => $sourceMessageId,
        ];
    }

    private function categoryByLabel(string $label): ?Category
    {
        $slug = Str::slug($label);

        return Category::query()
            ->active()
            ->where('type', Category::TYPE_EMAIL)
            ->get(['id', 'name', 'slug'])
            ->first(fn (Category $category): bool => mb_strtolower(trim((string) $category->name)) === mb_strtolower($label)
                || ($slug !== '' && mb_strtolower((string) $category->slug) === mb_strtolower($slug)));
    }

    private function tagByLabel(string $label): ?Tag
    {
        $slug = Str::slug($label);

        return Tag::query()
            ->where('active', true)
            ->get(['id', 'name', 'slug'])
            ->first(fn (Tag $tag): bool => mb_strtolower(trim((string) $tag->name)) === mb_strtolower($label)
                || ($slug !== '' && mb_strtolower((string) $tag->slug) === mb_strtolower($slug)));
    }

    /**
     * @param  array<int, int>  $allowedIds
     */
    private function sourceMessageId(mixed $value, array $allowedIds): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return in_array($id, $allowedIds, true) ? $id : null;
    }

    /**
     * @param  array<int, string>  $forbiddenStrings
     * @return array<int, string>
     */
    private function plainTextList(mixed $value, int $limit, int $itemLimit, array $forbiddenStrings): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => $this->plainText($item, $itemLimit, $forbiddenStrings))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    /** @param array<int, string> $forbiddenStrings */
    private function nullablePlainText(mixed $value, int $limit, array $forbiddenStrings = []): ?string
    {
        $text = $this->plainText($value, $limit, $forbiddenStrings);

        return $text !== '' ? $text : null;
    }

    /** @param array<int, string> $forbiddenStrings */
    private function plainText(mixed $value, int $limit, array $forbiddenStrings = []): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        foreach ($forbiddenStrings as $forbidden) {
            $forbidden = trim($forbidden);

            if ($forbidden !== '') {
                $text = str_ireplace($forbidden, '[attachment omitted]', $text);
            }
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $limit, '');
    }
}
