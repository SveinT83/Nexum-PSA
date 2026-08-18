<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Livewire\Tech\MailSidebar;
use App\Modules\Email\Livewire\Tech\MailWorkspace;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailMailNavigationReadabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $view = Permission::findOrCreate('email.inbox_view', 'web');
        $manage = Permission::findOrCreate('email.inbox_manage', 'web');
        Role::create(['name' => 'Mail navigation readability tech'])->givePermissionTo([$view, $manage]);

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Mail navigation readability tech');
    }

    #[Test]
    public function folder_navigation_renders_authorized_provider_hierarchy_and_exact_selection(): void
    {
        $first = $this->account('tree-one@example.test');
        $second = $this->account('tree-two@example.test');
        $private = $this->account('tree-private@example.test', grant: false);

        $firstParent = $this->folder($first, 'Projects', null, selectable: false, name: 'Projects one');
        $firstChild = $this->folder($first, 'Projects.Client', 'Projects', name: 'Client one', unseen: 2);
        $firstGrandchild = $this->folder($first, 'Projects.Client.Archive', 'Projects.Client', name: 'Archive one', unseen: 4);
        $this->folder($first, 'Deleted', null, selectable: false, name: 'Deleted stale');

        $systemParent = $this->folder($first, 'System', null, selectable: false, name: 'System folders');
        $sent = $this->folder($first, 'System.Sent', 'System', role: EmailFolder::ROLE_SENT, name: 'Sent nested');
        $zero = $this->folder($first, 'Zero', null, name: 'Zero unread', unseen: 0);
        $error = $this->folder($first, 'Error', null, name: 'Unread unavailable', unseen: 7);
        $error->forceFill(['sync_status' => EmailFolder::SYNC_ERROR])->save();

        $secondParent = $this->folder($second, 'Projects', null, name: 'Projects two');
        $secondChild = $this->folder($second, 'Projects.Client', 'Projects', name: 'Client two');
        $privateParent = $this->folder($private, 'Projects', null, name: 'Private projects');
        $privateChild = $this->folder($private, 'Projects.Secret', 'Projects', name: 'Private secret');

        $component = Livewire::actingAs($this->actor)
            ->test(MailSidebar::class)
            ->assertSee('Projects one')
            ->assertSee('Projects two')
            ->assertDontSee('Sent nested')
            ->assertDontSee('Client one')
            ->assertDontSee('Client two')
            ->assertDontSee('Deleted stale')
            ->assertDontSee('Private projects')
            ->assertDontSee('Private secret')
            ->assertDontSee('Folder counts show mailbox unread, not Unread for me.')
            ->assertSeeHtml('data-mail-folder-node="'.$firstParent->id.'"')
            ->assertDontSeeHtml('id="mail-folder-select-'.$first->id.'-'.$firstParent->id.'"')
            ->assertSeeHtml('aria-controls="mail-folder-children-'.$first->id.'-'.$firstParent->id.'"')
            ->assertSeeHtml('data-mail-folder-node="'.$systemParent->id.'"')
            ->assertDontSeeHtml('data-mail-folder-node="'.$sent->id.'"')
            ->assertSeeHtml('data-mail-folder-unread-state="unknown"')
            ->assertSeeHtml('data-mail-folder-unread-state="error"')
            ->assertSee('Mailbox unread count has not been reported.')
            ->assertSee('Mailbox unread count is unavailable.')
            ->call('toggleFolderNavigationFolder', $systemParent->id)
            ->assertSee('Sent nested')
            ->call('toggleFolderNavigationFolder', $firstParent->id)
            ->assertSee('Client one')
            ->assertDontSee('Client two')
            ->assertSeeHtml('data-mail-folder-depth="1"')
            ->assertSee('2 mailbox unread messages.')
            ->assertDontSee('6 mailbox unread messages.')
            ->call('toggleFolderNavigationFolder', $firstChild->id)
            ->assertSee('Archive one')
            ->assertSeeHtml('data-mail-folder-depth="2"')
            ->call('toggleFolderNavigationFolder', $secondParent->id)
            ->assertSee('Client one')
            ->assertSee('Client two');

        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $this->actor->id,
            'email_folder_id' => $systemParent->id,
            'is_expanded' => true,
        ]);
        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $this->actor->id,
            'email_folder_id' => $firstParent->id,
            'is_expanded' => true,
        ]);

        $otherActor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherActor->assignRole('Mail navigation readability tech');
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $first->id,
            'user_id' => $otherActor->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_at' => now(),
        ]);

        Livewire::actingAs($otherActor)
            ->test(MailSidebar::class)
            ->assertDontSee('Client one')
            ->call('toggleFolderNavigationFolder', $firstParent->id)
            ->assertSee('Client one');

        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $otherActor->id,
            'email_folder_id' => $firstParent->id,
            'is_expanded' => true,
        ]);

        $fresh = Livewire::actingAs($this->actor)
            ->test(MailSidebar::class)
            ->assertSee('Sent nested')
            ->assertSee('Client one')
            ->assertSee('Client two')
            ->call('selectFolder', $firstGrandchild->id)
            ->assertSet('folderId', $firstGrandchild->id)
            ->assertSet('viewMode', 'folder')
            ->assertSee('Client one')
            ->assertSee('Archive one')
            ->assertSeeHtml('id="mail-folder-select-'.$first->id.'-'.$firstGrandchild->id.'"')
            ->assertSeeHtml('aria-current="page"');

        $fresh
            ->call('toggleFolderNavigationFolder', $firstParent->id, true)
            ->assertDontSee('Client one')
            ->assertDontSee('Archive one');

        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $this->actor->id,
            'email_folder_id' => $firstParent->id,
            'is_expanded' => false,
        ]);

        $deepLinked = Livewire::actingAs($this->actor)
            ->withQueryParams([
                'view' => 'folder',
                'folder' => $firstGrandchild->id,
            ])
            ->test(MailSidebar::class)
            ->assertSet('folderId', (string) $firstGrandchild->id)
            ->assertDontSee('Client one')
            ->assertDontSee('Archive one')
            ->assertSeeHtml('data-mail-folder-contains-current')
            ->assertSee('Contains current folder.');

        $deepLinked
            ->call('selectFolder', $firstGrandchild->id)
            ->assertSee('Client one')
            ->assertSee('Archive one')
            ->assertDontSeeHtml('data-mail-folder-contains-current');

        $fresh->call('toggleFolderNavigationFolder', $privateParent->id)
            ->assertDontSee('Private secret');

        $this->assertDatabaseMissing('email_folder_navigation_preferences', [
            'user_id' => $this->actor->id,
            'email_folder_id' => $privateParent->id,
        ]);

        $this->assertNotSame($firstParent->id, $secondParent->id);
        $this->assertNotSame($firstChild->id, $secondChild->id);
        $this->assertNotSame($privateParent->id, $privateChild->id);
        $this->assertSame(
            1,
            preg_match(
                '/<button[^>]*id="mail-folder-select-'.$first->id.'-'.$zero->id.'"[^>]*>(.*?)<\/button>/s',
                $component->html(),
                $zeroButton,
            ),
        );
        $this->assertStringNotContainsString('data-mail-folder-unread-state=', $zeroButton[1]);

        EmailAccountUserGrant::query()
            ->where('email_account_id', $first->id)
            ->where('user_id', $this->actor->id)
            ->delete();

        Livewire::actingAs($this->actor)
            ->test(MailSidebar::class)
            ->assertDontSee('Projects one')
            ->assertDontSee('Client one')
            ->assertDontSee('Sent nested')
            ->call('toggleFolderNavigationFolder', $firstParent->id, true)
            ->assertDontSee('Projects one');

        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $this->actor->id,
            'email_folder_id' => $firstParent->id,
            'is_expanded' => true,
        ]);
    }

    #[Test]
    public function friendly_subject_is_used_across_mail_ui_and_reply_presentation_without_rewriting_storage(): void
    {
        $account = $this->account('subject-readable@example.test', canSend: true);
        $folder = $this->folder($account, 'INBOX', null, role: EmailFolder::ROLE_INBOX, name: 'INBOX');
        $raw = '=?utf-8?Q?Fwd=3A_DEKKSPERTEN_DA_=28936529364=29_har_f=C3=';
        $friendly = 'Fwd: DEKKSPERTEN DA (936529364) har f';
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 4401,
            'message_id' => '<friendly-subject@example.test>',
            'subject' => $raw,
            'from_name' => 'Encoded Sender',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Readable subject body.',
        ]);
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 440,
            'imap_uid' => 4401,
            'provider_seen' => false,
        ]);

        Livewire::actingAs($this->actor)
            ->test(MailWorkspace::class)
            ->set('viewMode', 'all')
            ->assertSee($friendly)
            ->assertDontSee($raw)
            ->call('selectPlacement', $placement->id)
            ->assertSee($friendly)
            ->assertSee('Readable subject body.')
            ->call('startReply')
            ->assertSet('composerSubject', 'Re: '.$friendly);

        $this->actingAs($this->actor)
            ->get(route('tech.inbox.index'))
            ->assertOk()
            ->assertSee($friendly)
            ->assertDontSee($raw);

        $this->actingAs($this->actor)
            ->get(route('tech.inbox.show', $message))
            ->assertOk()
            ->assertSee($friendly)
            ->assertDontSee($raw);

        $this->assertSame($raw, $message->fresh()->getRawOriginal('subject'));
        $this->assertSame($raw, $message->fresh()->subject);

        $longMessage = new EmailMessage(['subject' => str_repeat('æ', 512)]);
        $this->assertSame(512, mb_strlen(app(SendEmailComposerMessage::class)->defaultReplySubject($longMessage)));
        $this->assertSame(512, mb_strlen(app(SendEmailComposerMessage::class)->defaultForwardSubject($longMessage)));

        $htmlLike = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 4402,
            'message_id' => '<html-like-subject@example.test>',
            'subject' => '=?UTF-8?Q?=3Cscript=3Ealert=281=29=3C/script=3E?=',
            'from_email' => 'sender@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $htmlLike->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 440,
            'imap_uid' => 4402,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'provider_missing_at' => null,
        ]);

        $this->actingAs($this->actor)
            ->get(route('tech.inbox.show', $htmlLike))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    public function folder_navigation_keeps_trailing_space_parent_identity_exact(): void
    {
        $account = $this->account('tree-byte-identity@example.test');
        $exactParent = $this->folder(
            $account,
            'Foo ',
            null,
            selectable: false,
            name: 'Exact trailing parent',
        );
        $trimmedSibling = $this->folder(
            $account,
            'Foo',
            null,
            selectable: false,
            name: 'Trimmed sibling parent',
        );
        $this->folder(
            $account,
            'Foo /Child',
            'Foo ',
            name: 'Exact trailing child',
        );

        Livewire::actingAs($this->actor)
            ->test(MailSidebar::class)
            ->assertSee('Exact trailing parent')
            ->assertDontSee('Trimmed sibling parent')
            ->assertDontSee('Exact trailing child')
            ->call('toggleFolderNavigationFolder', $exactParent->id)
            ->assertSee('Exact trailing child')
            ->assertSeeHtml('data-mail-folder-depth="1"')
            ->call('toggleFolderNavigationFolder', $trimmedSibling->id)
            ->assertSee('Exact trailing child');

        $this->assertDatabaseHas('email_folders', [
            'id' => $exactParent->id,
            'path' => 'Foo ',
        ]);
        $this->assertDatabaseHas('email_folders', [
            'id' => $trimmedSibling->id,
            'path' => 'Foo',
        ]);
    }

    #[Test]
    public function corrupt_or_orphaned_folder_metadata_remains_bounded_and_does_not_hide_selectable_folders(): void
    {
        $account = $this->account('tree-corrupt@example.test');
        $first = $this->folder($account, 'Cycle.A', 'Cycle.B', name: 'Cycle first');
        $second = $this->folder($account, 'Cycle.B', 'Cycle.A', name: 'Cycle second');
        $orphan = $this->folder($account, 'Orphan.Child', 'Missing.Parent', name: 'Orphan child');
        $selfParent = $this->folder($account, 'Self', 'Self', name: 'Self parent');

        Livewire::actingAs($this->actor)
            ->test(MailSidebar::class)
            ->call('selectFolder', $second->id)
            ->assertSet('folderId', $second->id)
            ->assertSee('Cycle first')
            ->assertSee('Cycle second')
            ->assertSee('Orphan child')
            ->assertSee('Self parent')
            ->assertSeeHtml('data-mail-folder-node="'.$first->id.'"')
            ->assertSeeHtml('data-mail-folder-node="'.$second->id.'"')
            ->assertSeeHtml('data-mail-folder-node="'.$orphan->id.'"')
            ->assertSeeHtml('data-mail-folder-node="'.$selfParent->id.'"');
    }

    private function account(string $address, bool $grant = true, bool $canSend = false): EmailAccount
    {
        $account = EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Mail navigation readability test account',
            'from_name' => 'Mail Readability',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => false,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'readability-test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'readability-test-secret',
            'smtp_auth_type' => 'password',
        ]);

        if ($grant) {
            EmailAccountUserGrant::query()->create([
                'email_account_id' => $account->id,
                'user_id' => $this->actor->id,
                'can_view' => true,
                'can_organize' => true,
                'can_send' => $canSend,
                'granted_at' => now(),
            ]);
        }

        return $account;
    }

    private function folder(
        EmailAccount $account,
        string $path,
        ?string $parentPath,
        bool $selectable = true,
        string $role = EmailFolder::ROLE_CUSTOM,
        ?string $name = null,
        ?int $unseen = null,
    ): EmailFolder {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $name ?: $path,
            'delimiter' => '.',
            'parent_path' => $parentPath,
            'role' => $role,
            'is_selectable' => $selectable,
            'sync_enabled' => $selectable,
            'uid_validity' => $selectable ? random_int(100, 99999) : 0,
            'unseen_count' => $unseen,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }
}
