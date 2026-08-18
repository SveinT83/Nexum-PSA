<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_conversations')) {
            Schema::create('email_conversations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnDelete();
                $table->string('conversation_key', 160);
                $table->string('status', 40)->default('active')->index();
                $table->string('subject', 512)->nullable();
                $table->foreignId('first_email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
                $table->foreignId('latest_email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
                $table->foreignId('latest_email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
                $table->unsignedInteger('message_count')->default(0);
                $table->unsignedInteger('active_placement_count')->default(0);
                $table->unsignedInteger('provider_unread_count')->default(0);
                $table->boolean('has_attachments')->default(false);
                $table->timestamp('first_message_at')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['account_id', 'conversation_key'], 'email_conversations_account_key_unique');
                $table->index(['account_id', 'status'], 'email_conversations_account_status_index');
                $table->index(['account_id', 'last_message_at'], 'email_conversations_account_last_message_index');
            });
        }

        if (! Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            Schema::table('email_mailbox_placements', function (Blueprint $table): void {
                $table->foreignId('email_conversation_id')
                    ->nullable()
                    ->after('email_message_id')
                    ->constrained('email_conversations')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('email_ticket_conversation_links')
            && ! Schema::hasColumn('email_ticket_conversation_links', 'email_conversation_id')) {
            Schema::table('email_ticket_conversation_links', function (Blueprint $table): void {
                $table->foreignId('email_conversation_id')
                    ->nullable()
                    ->after('account_id')
                    ->constrained('email_conversations')
                    ->nullOnDelete();
            });
        }

        $this->backfillConversations();
        $this->backfillConversationLinks();
    }

    public function down(): void
    {
        if (Schema::hasTable('email_ticket_conversation_links')
            && Schema::hasColumn('email_ticket_conversation_links', 'email_conversation_id')) {
            Schema::table('email_ticket_conversation_links', function (Blueprint $table): void {
                $table->dropForeign(['email_conversation_id']);
                $table->dropColumn('email_conversation_id');
            });
        }

        if (Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            Schema::table('email_mailbox_placements', function (Blueprint $table): void {
                $table->dropForeign(['email_conversation_id']);
                $table->dropColumn('email_conversation_id');
            });
        }

        Schema::dropIfExists('email_conversations');
    }

    private function backfillConversations(): void
    {
        if (! Schema::hasTable('email_messages')
            || ! Schema::hasTable('email_mailbox_placements')
            || ! Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            return;
        }

        DB::table('email_mailbox_placements')
            ->join('email_messages', 'email_messages.id', '=', 'email_mailbox_placements.email_message_id')
            ->select([
                'email_mailbox_placements.id as placement_id',
                'email_mailbox_placements.account_id',
                'email_mailbox_placements.email_message_id',
                'email_mailbox_placements.created_at as placement_created_at',
                'email_messages.message_id',
                'email_messages.in_reply_to',
                'email_messages.references',
                'email_messages.from_email',
                'email_messages.subject',
                'email_messages.received_at',
                'email_messages.created_at as message_created_at',
            ])
            ->orderBy('email_mailbox_placements.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $conversationKey = $this->conversationKey($row);
                    $conversationId = $this->conversationIdFor($row, $conversationKey);

                    DB::table('email_mailbox_placements')
                        ->where('id', $row->placement_id)
                        ->update([
                            'email_conversation_id' => $conversationId,
                            'updated_at' => now(),
                        ]);
                }
            });

        DB::table('email_mailbox_placements')
            ->whereNotNull('email_conversation_id')
            ->distinct()
            ->pluck('email_conversation_id')
            ->each(fn ($conversationId): int => $this->refreshConversationAggregate((int) $conversationId));
    }

    private function backfillConversationLinks(): void
    {
        if (! Schema::hasTable('email_ticket_conversation_links')
            || ! Schema::hasColumn('email_ticket_conversation_links', 'email_conversation_id')) {
            return;
        }

        DB::table('email_ticket_conversation_links')
            ->join(
                'email_mailbox_placements',
                'email_mailbox_placements.id',
                '=',
                'email_ticket_conversation_links.email_mailbox_placement_id',
            )
            ->whereNull('email_ticket_conversation_links.email_conversation_id')
            ->whereNotNull('email_mailbox_placements.email_conversation_id')
            ->select([
                'email_ticket_conversation_links.id',
                'email_mailbox_placements.email_conversation_id',
            ])
            ->orderBy('email_ticket_conversation_links.id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('email_ticket_conversation_links')
                        ->where('id', $row->id)
                        ->update([
                            'email_conversation_id' => $row->email_conversation_id,
                            'updated_at' => now(),
                        ]);
                }
            });

        DB::table('email_ticket_conversation_links')
            ->whereNull('email_conversation_id')
            ->whereNotNull('account_id')
            ->whereNotNull('conversation_key')
            ->select(['id', 'account_id', 'conversation_key'])
            ->orderBy('id')
            ->chunk(500, function ($links): void {
                foreach ($links as $link) {
                    $conversationId = DB::table('email_conversations')
                        ->where('account_id', $link->account_id)
                        ->where('conversation_key', $link->conversation_key)
                        ->value('id');

                    if (! $conversationId) {
                        continue;
                    }

                    DB::table('email_ticket_conversation_links')
                        ->where('id', $link->id)
                        ->update([
                            'email_conversation_id' => $conversationId,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    private function conversationIdFor(object $row, string $conversationKey): int
    {
        $existingId = DB::table('email_conversations')
            ->where('account_id', $row->account_id)
            ->where('conversation_key', $conversationKey)
            ->value('id');

        if ($existingId) {
            return (int) $existingId;
        }

        return (int) DB::table('email_conversations')->insertGetId([
            'account_id' => $row->account_id,
            'conversation_key' => $conversationKey,
            'status' => 'active',
            'subject' => Str::limit((string) $row->subject, 512, ''),
            'first_email_message_id' => $row->email_message_id,
            'latest_email_message_id' => $row->email_message_id,
            'latest_email_mailbox_placement_id' => $row->placement_id,
            'first_message_at' => $row->received_at ?: $row->message_created_at,
            'last_message_at' => $row->received_at ?: $row->message_created_at,
            'metadata' => json_encode(['source' => 'mail_header_projection']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function refreshConversationAggregate(int $conversationId): int
    {
        $placements = DB::table('email_mailbox_placements')
            ->join('email_messages', 'email_messages.id', '=', 'email_mailbox_placements.email_message_id')
            ->where('email_mailbox_placements.email_conversation_id', $conversationId)
            ->select([
                'email_mailbox_placements.id as placement_id',
                'email_mailbox_placements.email_message_id',
                'email_mailbox_placements.folder_path',
                'email_mailbox_placements.local_state',
                'email_mailbox_placements.provider_seen',
                'email_mailbox_placements.created_at as placement_created_at',
                'email_messages.subject',
                'email_messages.received_at',
                'email_messages.created_at as message_created_at',
            ])
            ->get();

        if ($placements->isEmpty()) {
            return DB::table('email_conversations')
                ->where('id', $conversationId)
                ->update([
                    'message_count' => 0,
                    'active_placement_count' => 0,
                    'provider_unread_count' => 0,
                    'has_attachments' => false,
                    'first_email_message_id' => null,
                    'latest_email_message_id' => null,
                    'latest_email_mailbox_placement_id' => null,
                    'first_message_at' => null,
                    'last_message_at' => null,
                    'updated_at' => now(),
                ]);
        }

        $first = $placements->sortBy(fn (object $row): string => $this->sortValue($row))->first();
        $latest = $placements->sortByDesc(fn (object $row): string => $this->sortValue($row))->first();
        $messageIds = $placements->pluck('email_message_id')->filter()->unique();
        $hasAttachments = DB::table('email_attachments')
            ->whereIn('message_id', $messageIds)
            ->exists();

        return DB::table('email_conversations')
            ->where('id', $conversationId)
            ->update([
                'status' => 'active',
                'subject' => Str::limit((string) $latest->subject, 512, ''),
                'first_email_message_id' => $first->email_message_id,
                'latest_email_message_id' => $latest->email_message_id,
                'latest_email_mailbox_placement_id' => $latest->placement_id,
                'message_count' => $messageIds->count(),
                'active_placement_count' => $placements->where('local_state', 'active')->count(),
                'provider_unread_count' => $placements
                    ->where('local_state', 'active')
                    ->where('provider_seen', false)
                    ->count(),
                'has_attachments' => $hasAttachments,
                'first_message_at' => $first->received_at ?: $first->message_created_at,
                'last_message_at' => $latest->received_at ?: $latest->message_created_at,
                'metadata' => json_encode(array_filter([
                    'source' => 'mail_header_projection',
                    'latest_folder_path' => $latest->folder_path,
                ])),
                'updated_at' => now(),
            ]);
    }

    private function conversationKey(object $row): string
    {
        $references = preg_split('/\s+/', (string) $row->references) ?: [];
        $identifier = collect([$row->in_reply_to])
            ->merge($references)
            ->push($row->message_id)
            ->map(fn (?string $value): string => $this->normalizeMessageIdentifier($value))
            ->first(fn (string $value): bool => $value !== '');

        if ($identifier) {
            return 'msg:'.hash('sha256', $identifier);
        }

        $receivedDate = $row->received_at
            ? date('Y-m-d', strtotime((string) $row->received_at))
            : ($row->message_created_at ? date('Y-m-d', strtotime((string) $row->message_created_at)) : 'unknown-date');

        $fallback = Str::lower((string) $row->from_email).'|'
            .Str::lower((string) $row->subject).'|'
            .$receivedDate;

        return 'fallback:'.hash('sha256', $fallback);
    }

    private function normalizeMessageIdentifier(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return Str::of($value)->trim()->trim('<>')->lower()->toString();
    }

    private function sortValue(object $row): string
    {
        $date = $row->received_at ?: $row->message_created_at ?: $row->placement_created_at;
        $timestamp = $date ? strtotime((string) $date) : 0;

        return sprintf('%020d.%020d', $timestamp ?: 0, (int) $row->placement_id);
    }
};
