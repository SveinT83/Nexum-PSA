<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderNavigationPreference;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailFolderNavigationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function folder_navigation_preferences_are_isolated_by_user_and_folder(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstAccount = $this->account('navigation-first@example.test');
        $secondAccount = $this->account('navigation-second@example.test');
        $firstFolder = $this->folder($firstAccount, 'Projects');
        $secondFolder = $this->folder($secondAccount, 'Projects');

        // Independent upserts mirror two devices changing different mailbox
        // branches without replacing one shared JSON preference document.
        EmailFolderNavigationPreference::query()->updateOrCreate(
            ['user_id' => $firstUser->id, 'email_folder_id' => $firstFolder->id],
            ['is_expanded' => true],
        );
        EmailFolderNavigationPreference::query()->updateOrCreate(
            ['user_id' => $firstUser->id, 'email_folder_id' => $secondFolder->id],
            ['is_expanded' => false],
        );
        EmailFolderNavigationPreference::query()->updateOrCreate(
            ['user_id' => $secondUser->id, 'email_folder_id' => $firstFolder->id],
            ['is_expanded' => false],
        );

        EmailFolderNavigationPreference::query()->updateOrCreate(
            ['user_id' => $firstUser->id, 'email_folder_id' => $firstFolder->id],
            ['is_expanded' => false],
        );

        $firstPreference = EmailFolderNavigationPreference::query()
            ->where('user_id', $firstUser->id)
            ->where('email_folder_id', $firstFolder->id)
            ->firstOrFail();

        $this->assertFalse($firstPreference->is_expanded);
        $this->assertTrue($firstPreference->user->is($firstUser));
        $this->assertTrue($firstPreference->folder->is($firstFolder));
        $this->assertDatabaseCount('email_folder_navigation_preferences', 3);
        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $firstUser->id,
            'email_folder_id' => $secondFolder->id,
            'is_expanded' => false,
        ]);
        $this->assertDatabaseHas('email_folder_navigation_preferences', [
            'user_id' => $secondUser->id,
            'email_folder_id' => $firstFolder->id,
            'is_expanded' => false,
        ]);
    }

    #[Test]
    public function database_rejects_duplicate_user_and_folder_preferences(): void
    {
        $user = User::factory()->create();
        $folder = $this->folder($this->account('navigation-unique@example.test'), 'Projects');

        EmailFolderNavigationPreference::query()->create([
            'user_id' => $user->id,
            'email_folder_id' => $folder->id,
            'is_expanded' => true,
        ]);

        try {
            EmailFolderNavigationPreference::query()->create([
                'user_id' => $user->id,
                'email_folder_id' => $folder->id,
                'is_expanded' => false,
            ]);

            $this->fail('The database accepted a duplicate folder navigation preference.');
        } catch (QueryException) {
            $this->assertDatabaseCount('email_folder_navigation_preferences', 1);
        }
    }

    #[Test]
    public function folder_preferences_cascade_but_live_authority_prevents_hard_user_deletion(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $account = $this->account('navigation-cascade@example.test');
        $deletedFolder = $this->folder($account, 'Deleted branch');
        $retainedFolder = $this->folder($account, 'Retained branch');

        $folderPreference = EmailFolderNavigationPreference::query()->create([
            'user_id' => $firstUser->id,
            'email_folder_id' => $deletedFolder->id,
            'is_expanded' => true,
        ]);
        $userPreference = EmailFolderNavigationPreference::query()->create([
            'user_id' => $firstUser->id,
            'email_folder_id' => $retainedFolder->id,
            'is_expanded' => false,
        ]);
        $retainedPreference = EmailFolderNavigationPreference::query()->create([
            'user_id' => $secondUser->id,
            'email_folder_id' => $retainedFolder->id,
            'is_expanded' => true,
        ]);

        $deletedFolder->delete();

        $this->assertDatabaseMissing('email_folder_navigation_preferences', ['id' => $folderPreference->id]);
        $this->assertDatabaseHas('email_folder_navigation_preferences', ['id' => $userPreference->id]);

        try {
            $firstUser->delete();
            $this->fail('Expected durable live-authority evidence to prevent hard user deletion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('email_folder_navigation_preferences', ['id' => $userPreference->id]);
        $this->assertDatabaseHas('email_folder_navigation_preferences', ['id' => $retainedPreference->id]);
    }

    private function account(string $address): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => $address,
            'description' => 'Folder navigation preference test account',
            'from_name' => 'Navigation Preference',
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
            'imap_secret' => 'navigation-preference-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'navigation-preference-secret',
            'smtp_auth_type' => 'password',
        ]);
    }

    private function folder(EmailAccount $account, string $path): EmailFolder
    {
        return EmailFolder::query()->create([
            'account_id' => $account->id,
            'path' => $path,
            'name' => $path,
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
    }
}
