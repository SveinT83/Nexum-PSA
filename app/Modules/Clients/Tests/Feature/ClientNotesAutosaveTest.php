<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Clients\Livewire\Tech\ClientNotesAutosave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClientNotesAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        Permission::findOrCreate('client.update', 'web');
    }

    #[Test]
    public function authorized_user_can_autosave_notes_and_receives_server_confirmation(): void
    {
        $this->user->givePermissionTo('client.update');
        $client = Client::factory()->create([
            'notes' => 'Original notes',
        ]);

        $this->actingAs($this->user);

        Livewire::test(ClientNotesAutosave::class, ['client' => $client])
            ->assertSet('canEdit', true)
            ->assertSet('saveState', 'idle')
            ->assertSeeHtml('wire:model.live.debounce.750ms="notes"')
            ->assertSeeHtml('wire:dirty')
            ->assertSeeHtml('wire:loading')
            ->set('notes', 'Confirmed updated notes')
            ->assertSet('saveState', 'saved')
            ->assertHasNoErrors('notes')
            ->assertSee('Saved');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'notes' => 'Confirmed updated notes',
        ]);
    }

    #[Test]
    public function blank_notes_are_stored_as_null(): void
    {
        $this->user->givePermissionTo('client.update');
        $client = Client::factory()->create([
            'notes' => 'Remove this note',
        ]);

        $this->actingAs($this->user);

        Livewire::test(ClientNotesAutosave::class, ['client' => $client])
            ->set('notes', '')
            ->assertSet('saveState', 'saved');

        $this->assertNull($client->refresh()->notes);
    }

    #[Test]
    public function user_without_update_permission_sees_read_only_notes_and_cannot_force_a_save(): void
    {
        $client = Client::factory()->create([
            'notes' => 'Read-only client note',
        ]);

        $this->actingAs($this->user);

        $component = Livewire::test(ClientNotesAutosave::class, ['client' => $client])
            ->assertSet('canEdit', false)
            ->assertSee('Read-only client note')
            ->assertDontSeeHtml('<textarea')
            ->assertDontSeeHtml('wire:model.live.debounce.750ms="notes"');

        $component
            ->set('notes', 'Tampered note')
            ->assertForbidden();

        $this->assertSame('Read-only client note', $client->refresh()->notes);
    }

    #[Test]
    public function deleted_client_produces_an_error_without_a_false_saved_state(): void
    {
        $this->user->givePermissionTo('client.update');
        $client = Client::factory()->create([
            'notes' => 'Existing note',
        ]);

        $this->actingAs($this->user);

        $component = Livewire::test(ClientNotesAutosave::class, ['client' => $client]);
        $client->delete();

        $component
            ->set('notes', 'Text retained after deletion')
            ->assertSet('notes', 'Text retained after deletion')
            ->assertSet('saveState', 'error')
            ->assertHasErrors('notes')
            ->assertSee('Notes could not be saved')
            ->assertDontSee('Saved');
    }
}
