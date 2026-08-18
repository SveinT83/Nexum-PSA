<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationClassification;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageClassification;
use App\Modules\Email\Services\EmailConversationClassificationMigrator;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailConversationClassificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1000;

    #[Test]
    public function deterministic_legacy_classification_migrates_with_taxonomy_tags_and_provenance(): void
    {
        $account = $this->createAccount('deterministic');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'deterministic');
        $message = $this->createMessage($account, 'deterministic');
        $this->createPlacement($message, $conversation, $folder);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $category = $this->createEmailCategory('Security');
        $classificationTag = $this->createTag('Needs review');
        $legacy = $this->createLegacyClassification(
            $account,
            $message,
            $category,
            [$classificationTag],
            $actor,
        );

        DB::table('email_message_classification_events')->insert([
            'email_message_classification_id' => $legacy->id,
            'account_id' => $account->id,
            'email_message_id' => $message->id,
            'actor_id' => $actor->id,
            'event_type' => 'updated',
            'before_json' => json_encode(['category' => null, 'tags' => []], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['category' => 'Security', 'tags' => ['Needs review']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $legacyEventId = (int) DB::table('email_message_classification_events')->value('id');

        $report = app(EmailConversationClassificationMigrator::class)->migrate();

        $this->assertSame(1, $report['source_classifications']);
        $this->assertSame(1, $report['mapped_source_classifications']);
        $this->assertSame(1, $report['migrated']);
        $this->assertSame(0, $report['issues_found']);

        $target = EmailConversationClassification::query()
            ->with('tags')
            ->where('account_id', $account->id)
            ->where('email_conversation_id', $conversation->id)
            ->firstOrFail();

        $this->assertSame($category->id, $target->category_id);
        $this->assertSame([$classificationTag->id], $target->tags->pluck('id')->all());
        $this->assertSame(EmailConversationClassification::SOURCE_COMPATIBILITY_MIGRATION, $target->source);
        $this->assertSame([$legacy->id], $target->provenance['source_classification_ids']);
        $this->assertSame([$legacyEventId], $target->provenance['source_event_ids']);
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $classificationTag->id,
            'taggable_type' => $target->getMorphClass(),
            'taggable_id' => $target->id,
            'module' => 'email',
        ]);

        $event = DB::table('email_conversation_classification_events')
            ->where('email_conversation_classification_id', $target->id)
            ->where('event_type', 'migrated')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(['category' => null, 'tags' => []], json_decode($event->before_json, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([$legacy->id], data_get(json_decode($event->provenance_json, true, flags: JSON_THROW_ON_ERROR), 'source_classification_ids'));
        $this->assertSame(1, EmailMessageClassification::query()->whereKey($legacy->id)->count());
        $this->assertSame(1, DB::table('email_message_classification_events')->where('id', $legacyEventId)->count());
    }

    #[Test]
    public function identical_snapshots_on_several_messages_migrate_once_per_conversation(): void
    {
        $account = $this->createAccount('identical');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'identical');
        $category = $this->createEmailCategory('Operations');
        $tag = $this->createTag('Follow up');
        $firstMessage = $this->createMessage($account, 'identical-first');
        $secondMessage = $this->createMessage($account, 'identical-second');
        $this->createPlacement($firstMessage, $conversation, $folder);
        $this->createPlacement($secondMessage, $conversation, $folder);
        $first = $this->createLegacyClassification($account, $firstMessage, $category, [$tag]);
        $second = $this->createLegacyClassification($account, $secondMessage, $category, [$tag]);

        $report = app(EmailConversationClassificationMigrator::class)->migrate();

        $this->assertSame(2, $report['source_classifications']);
        $this->assertSame(2, $report['mapped_source_classifications']);
        $this->assertSame(1, $report['conversation_groups']);
        $this->assertSame(1, $report['migrated']);
        $this->assertSame(1, EmailConversationClassification::query()->count());

        $target = EmailConversationClassification::query()->with('tags')->firstOrFail();
        $this->assertSame([$tag->id], $target->tags->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            $target->provenance['source_classification_ids'],
        );
        $this->assertSame(2, EmailMessageClassification::query()->count());
    }

    #[Test]
    public function conflicting_source_snapshots_create_one_stable_issue_and_no_target(): void
    {
        $account = $this->createAccount('source-conflict');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'source-conflict');
        $firstMessage = $this->createMessage($account, 'source-conflict-first');
        $secondMessage = $this->createMessage($account, 'source-conflict-second');
        $this->createPlacement($firstMessage, $conversation, $folder);
        $this->createPlacement($secondMessage, $conversation, $folder);
        $firstCategory = $this->createEmailCategory('First category');
        $secondCategory = $this->createEmailCategory('Second category');
        $this->createLegacyClassification($account, $firstMessage, $firstCategory);
        $this->createLegacyClassification($account, $secondMessage, $secondCategory);

        $firstReport = app(EmailConversationClassificationMigrator::class)->migrate();
        $issue = DB::table('email_conversation_classification_migration_issues')->first();

        $this->assertSame(0, $firstReport['migrated']);
        $this->assertSame(1, $firstReport['conflicting_source']);
        $this->assertSame(1, $firstReport['issues_created']);
        $this->assertNotNull($issue);
        $this->assertSame(EmailConversationClassificationMigrator::ISSUE_CONFLICTING_SOURCE, $issue->issue_type);
        $this->assertSame('open', $issue->status);
        $this->assertSame(0, EmailConversationClassification::query()->count());

        $secondReport = app(EmailConversationClassificationMigrator::class)->migrate();
        $repeated = DB::table('email_conversation_classification_migration_issues')->first();

        $this->assertSame(1, $secondReport['conflicting_source']);
        $this->assertSame(0, $secondReport['issues_created']);
        $this->assertSame(1, $secondReport['issues_repeated']);
        $this->assertSame(1, DB::table('email_conversation_classification_migration_issues')->count());
        $this->assertSame($issue->id, $repeated->id);
        $this->assertSame($issue->fingerprint, $repeated->fingerprint);
        $this->assertSame(2, EmailMessageClassification::query()->count());
    }

    #[Test]
    public function rerunning_a_successful_migration_is_idempotent(): void
    {
        $account = $this->createAccount('idempotent');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'idempotent');
        $message = $this->createMessage($account, 'idempotent');
        $this->createPlacement($message, $conversation, $folder);
        $tag = $this->createTag('Idempotent tag');
        $this->createLegacyClassification($account, $message, null, [$tag]);

        $first = app(EmailConversationClassificationMigrator::class)->migrate();
        $second = app(EmailConversationClassificationMigrator::class)->migrate();

        $this->assertSame(1, $first['migrated']);
        $this->assertSame(0, $second['migrated']);
        $this->assertSame(1, $second['already_migrated']);
        $this->assertSame(1, EmailConversationClassification::query()->count());
        $this->assertSame(1, DB::table('email_conversation_classification_events')->count());
        $this->assertSame(1, DB::table('taggables')
            ->where('taggable_type', EmailConversationClassification::class)
            ->where('taggable_id', EmailConversationClassification::query()->value('id'))
            ->count());
    }

    #[Test]
    public function an_existing_conflicting_target_is_reported_and_never_overwritten(): void
    {
        $account = $this->createAccount('target-conflict');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'target-conflict');
        $message = $this->createMessage($account, 'target-conflict');
        $this->createPlacement($message, $conversation, $folder);
        $sourceCategory = $this->createEmailCategory('Legacy category');
        $targetCategory = $this->createEmailCategory('Manual category');
        $sourceTag = $this->createTag('Legacy tag');
        $targetTag = $this->createTag('Manual tag');
        $this->createLegacyClassification($account, $message, $sourceCategory, [$sourceTag]);
        $target = EmailConversationClassification::query()->create([
            'account_id' => $account->id,
            'email_conversation_id' => $conversation->id,
            'category_id' => $targetCategory->id,
            'source' => EmailConversationClassification::SOURCE_MANUAL,
        ]);
        $target->tags()->syncWithPivotValues([$targetTag->id], ['module' => 'email']);

        $report = app(EmailConversationClassificationMigrator::class)->migrate();

        $this->assertSame(0, $report['migrated']);
        $this->assertSame(1, $report['conflicting_existing_target']);
        $this->assertSame(1, $report['issues_created']);
        $target->refresh()->load('tags');
        $this->assertSame($targetCategory->id, $target->category_id);
        $this->assertSame([$targetTag->id], $target->tags->pluck('id')->all());
        $this->assertSame(EmailConversationClassification::SOURCE_MANUAL, $target->source);
        $this->assertDatabaseHas('email_conversation_classification_migration_issues', [
            'issue_type' => EmailConversationClassificationMigrator::ISSUE_CONFLICTING_EXISTING_TARGET,
            'account_id' => $account->id,
            'email_conversation_id' => $conversation->id,
        ]);
    }

    #[Test]
    public function missing_and_multiple_conversation_mappings_are_reported_without_guessing(): void
    {
        $account = $this->createAccount('mapping-issues');
        $inbox = $this->createFolder($account);
        $archive = $this->createFolder($account, 'Archive');
        $category = $this->createEmailCategory('Mapping category');

        $unmappedMessage = $this->createMessage($account, 'unmapped');
        $this->createLegacyClassification($account, $unmappedMessage, $category);

        $ambiguousMessage = $this->createMessage($account, 'ambiguous');
        $firstConversation = $this->createConversation($account, 'ambiguous-first');
        $secondConversation = $this->createConversation($account, 'ambiguous-second');
        $this->createPlacement($ambiguousMessage, $firstConversation, $inbox);
        $this->createPlacement($ambiguousMessage, $secondConversation, $archive);
        $this->createLegacyClassification($account, $ambiguousMessage, $category);

        $report = app(EmailConversationClassificationMigrator::class)->migrate();

        $this->assertSame(1, $report['no_conversation']);
        $this->assertSame(1, $report['multiple_conversations']);
        $this->assertSame(2, $report['issues_found']);
        $this->assertSame(2, $report['issues_created']);
        $this->assertSame(0, EmailConversationClassification::query()->count());
        $this->assertEqualsCanonicalizing(
            [
                EmailConversationClassificationMigrator::ISSUE_NO_CONVERSATION,
                EmailConversationClassificationMigrator::ISSUE_MULTIPLE_CONVERSATIONS,
            ],
            DB::table('email_conversation_classification_migration_issues')->pluck('issue_type')->all(),
        );
        $this->assertSame(2, EmailMessageClassification::query()->count());
    }

    #[Test]
    public function legacy_message_level_tags_are_not_promoted_to_the_conversation(): void
    {
        $account = $this->createAccount('message-tag-boundary');
        $folder = $this->createFolder($account);
        $conversation = $this->createConversation($account, 'message-tag-boundary');
        $message = $this->createMessage($account, 'message-tag-boundary');
        $this->createPlacement($message, $conversation, $folder);
        $classificationTag = $this->createTag('Classification tag');
        $messageRoutingTag = $this->createTag('Legacy routing fact');
        $this->createLegacyClassification($account, $message, null, [$classificationTag]);
        $message->tags()->syncWithPivotValues([$messageRoutingTag->id], ['module' => 'email']);

        app(EmailConversationClassificationMigrator::class)->migrate();

        $target = EmailConversationClassification::query()->with('tags')->firstOrFail();
        $this->assertSame([$classificationTag->id], $target->tags->pluck('id')->all());
        $this->assertFalse($target->tags->contains('id', $messageRoutingTag->id));
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $messageRoutingTag->id,
            'taggable_type' => $message->getMorphClass(),
            'taggable_id' => $message->id,
        ]);
    }

    private function createAccount(string $key): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $key.'@example.test',
            'description' => 'Classification migration test account',
            'from_name' => 'Migration Test',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $key.'@example.test',
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $key.'@example.test',
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    private function createFolder(EmailAccount $account, string $path = 'INBOX'): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => $path === 'INBOX' ? EmailFolder::ROLE_INBOX : EmailFolder::ROLE_ARCHIVE,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $this->sequence++,
        ]);
    }

    private function createConversation(EmailAccount $account, string $key): EmailConversation
    {
        return EmailConversation::query()->create([
            'account_id' => $account->id,
            'conversation_key' => 'test:'.$key,
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => 'Migration '.$key,
        ]);
    }

    private function createMessage(EmailAccount $account, string $key): EmailMessage
    {
        $uid = $this->sequence++;

        return EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<'.$key.'-'.$uid.'@example.test>',
            'subject' => 'Migration '.$key,
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Classification migration test body.',
        ]);
    }

    private function createPlacement(
        EmailMessage $message,
        EmailConversation $conversation,
        EmailFolder $folder,
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'email_conversation_id' => $conversation->id,
            'account_id' => $conversation->account_id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $this->sequence++,
        ]);
    }

    /**
     * @param  array<int, Tag>  $tags
     */
    private function createLegacyClassification(
        EmailAccount $account,
        EmailMessage $message,
        ?Category $category,
        array $tags = [],
        ?User $actor = null,
    ): EmailMessageClassification {
        $classification = EmailMessageClassification::query()->create([
            'account_id' => $account->id,
            'email_message_id' => $message->id,
            'category_id' => $category?->id,
            'assigned_by' => $actor?->id,
            'assigned_at' => now(),
        ]);

        $classification->tags()->syncWithPivotValues(
            collect($tags)->pluck('id')->all(),
            ['module' => 'email'],
        );

        return $classification;
    }

    private function createEmailCategory(string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'type' => Category::TYPE_EMAIL,
            'is_active' => true,
        ]);
    }

    private function createTag(string $name): Tag
    {
        return Tag::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'active' => true,
        ]);
    }
}
