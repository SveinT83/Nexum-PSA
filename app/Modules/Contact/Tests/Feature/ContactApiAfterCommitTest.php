<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Contact\Models\ContactApiCommand;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real outer commit: a failed callback must retain one encrypted unknown outcome, never repeat it. */
class ContactApiAfterCommitTest extends TestCase
{
    use ContactApiContractAssertions;
    use DatabaseTruncation;

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function after_commit_failure_keeps_an_unknown_receipt_and_never_repeats_the_committed_effect(): void
    {
        Role::findOrCreate('Tech');
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('Tech');
        foreach (['contact.view', 'contact.create', 'contact.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $actor->givePermissionTo($permission);
        }
        $this->withToken($actor->createToken('after-commit-test', ['contacts.read', 'contacts.create', 'contacts.update', 'contacts.operations.read'])->plainTextToken);
        ContactApiCommand::created(function (): void {
            DB::afterCommit(function (): void {
                throw new \RuntimeException('Simulated post-commit delivery callback failure.');
            });
        });
        try {
            $body = ['display_name' => 'Retained local effect', 'email' => 'unknown@example.test'];
            $headers = ['Idempotency-Key' => 'unknown-outcome-command'];
            $first = $this->postJson('/api/v1/contacts', $body, $headers)->assertStatus(202)->assertJsonPath('state', 'unknown');
            $this->postJson('/api/v1/contacts', $body, $headers)->assertStatus(202)->assertJsonPath('replayed', true);
            $this->assertDatabaseCount('contacts', 1);
            $this->assertDatabaseCount('contact_api_commands', 1);
            $this->getJson('/api/v1/contacts/operations/'.$first->json('operation_id'))->assertOk()->assertJsonPath('data.state', 'unknown');
        } finally {
            ContactApiCommand::flushEventListeners();
        }
    }
}
