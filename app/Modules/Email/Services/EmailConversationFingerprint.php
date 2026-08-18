<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Support\Str;

class EmailConversationFingerprint
{
    public const LEGACY_SCHEMA_VERSION = 'email.conversation_fingerprint.v1';

    public const SCHEMA_VERSION = 'email.conversation_fingerprint.v2';

    public function __construct(
        private readonly EmailSmartInboxSuggestionIdentity $identity,
    ) {}

    /**
     * Hash every active message visible through this account-conversation
     * projection. Only the final digest and source database IDs leave this
     * service; subjects, bodies, participants, and header identities do not.
     *
     * @return array{fingerprint: string, source_message_ids: array<int, int>, schema_version: string}
     */
    public function forConversation(
        EmailConversation $conversation,
        ?string $schemaVersion = null,
    ): array {
        $schemaVersion ??= self::SCHEMA_VERSION;

        if (! in_array($schemaVersion, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException("Unsupported Mail conversation fingerprint schema [{$schemaVersion}].");
        }

        $conversation = EmailConversation::query()
            ->whereKey($conversation->getKey())
            ->where('account_id', $conversation->account_id)
            ->firstOrFail();

        $messageQuery = EmailMessage::query()
            ->whereHas('placements', function ($placements) use ($conversation): void {
                $placements
                    ->where('account_id', $conversation->account_id)
                    ->where('email_conversation_id', $conversation->id)
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE);
            })
            ->orderBy('id');

        if ($schemaVersion === self::SCHEMA_VERSION) {
            $messageQuery->with(['attachments' => function ($attachments): void {
                $attachments
                    ->select([
                        'id',
                        'message_id',
                        'filename',
                        'content_type',
                        'size_bytes',
                        'is_inline',
                        'cid',
                        'checksum_sha1',
                    ])
                    ->orderBy('id');
            }]);
        }

        $messages = $messageQuery->get([
            'id',
            'message_id',
            'subject',
            'from_name',
            'from_email',
            'to_json',
            'cc_json',
            'received_at',
            'body_text',
            'body_html_sanitized',
            'checksum_sha1',
            'attachments_count',
            'updated_at',
        ]);

        $identities = $messages->map(function (EmailMessage $message) use ($schemaVersion): array {
            // The inner content checksum prevents the outer fingerprint input
            // from exposing normalized mail content to a caller or durable row.
            $contentIdentity = [
                'subject' => $this->boundedText($message->subject, 500),
                'from_name' => $this->boundedText($message->from_name, 180),
                'from_email' => mb_strtolower(trim((string) $message->from_email)),
                'to' => $message->to_json ?? [],
                'cc' => $message->cc_json ?? [],
                'body_text' => (string) $message->body_text,
                'body_html_sanitized' => (string) $message->body_html_sanitized,
                'stored_checksum_sha1' => strtolower(trim((string) $message->checksum_sha1)),
                'attachments_count' => (int) $message->attachments_count,
            ];

            if ($schemaVersion === self::SCHEMA_VERSION) {
                // Count alone is not authoritative: attachment recovery can
                // add or correct durable rows without changing that counter.
                // Hash semantic metadata as an order-independent multiset;
                // storage disk/path and local row IDs are not mail content.
                $contentIdentity['attachments_metadata'] = $message->attachments
                    ->map(function ($attachment): array {
                        $contentType = mb_strtolower(trim((string) $attachment->content_type));
                        $cid = mb_strtolower(trim((string) $attachment->cid));
                        $checksum = strtolower(trim((string) $attachment->checksum_sha1));

                        return [
                            'filename' => $this->boundedText($attachment->filename, 512),
                            'content_type' => $contentType !== '' ? $contentType : null,
                            'size_bytes' => $attachment->size_bytes === null
                                ? null
                                : (int) $attachment->size_bytes,
                            'is_inline' => (bool) $attachment->is_inline,
                            'cid' => $cid !== '' ? $cid : null,
                            'checksum_sha1' => $checksum !== '' ? $checksum : null,
                        ];
                    })
                    ->sortBy(fn (array $metadata): string => $this->identity->checksum($metadata))
                    ->values()
                    ->all();
            }

            $contentChecksum = $this->identity->checksum($contentIdentity);

            $identity = [
                'database_id' => (int) $message->id,
                'message_identity_hash' => hash(
                    'sha256',
                    mb_strtolower(trim((string) $message->message_id)),
                ),
                'content_hash' => $contentChecksum,
                'received_at' => $message->received_at?->format('Y-m-d\TH:i:s.uP'),
            ];

            // v1 included an ORM bookkeeping timestamp, so derived projection
            // and local-state updates could falsely invalidate suggestions.
            // Preserve it only to validate and recover existing v1 rows.
            if ($schemaVersion === self::LEGACY_SCHEMA_VERSION) {
                $identity['updated_at'] = $message->updated_at?->format('Y-m-d\TH:i:s.uP');
            }

            return $identity;
        })->values()->all();

        return [
            'fingerprint' => $this->identity->checksum([
                'schema_version' => $schemaVersion,
                'account_id' => (int) $conversation->account_id,
                'conversation_id' => (int) $conversation->id,
                'messages' => $identities,
            ]),
            'source_message_ids' => $messages->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            'schema_version' => $schemaVersion,
        ];
    }

    private function boundedText(mixed $value, int $limit): string
    {
        return Str::limit(trim((string) $value), $limit, '');
    }
}
