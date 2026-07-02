<?php

namespace App\Modules\WorkContext\Tests\Feature;

use App\Models\Clients\Client;
use App\Modules\WorkContext\Actions\EnsureWorkContextDefaults;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use App\Modules\WorkContext\Models\WorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkContextModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function migration_creates_default_internal_context(): void
    {
        $this->assertDatabaseHas('work_contexts', [
            'type' => WorkContextType::INTERNAL,
            'client_id' => null,
            'name' => 'Own organization',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function ensure_defaults_creates_client_contexts_for_existing_clients(): void
    {
        $client = Client::factory()->create(['name' => 'Context Client']);

        $summary = app(EnsureWorkContextDefaults::class)->handle();

        $this->assertTrue($summary['internal']->isInternal());
        $this->assertDatabaseHas('work_contexts', [
            'type' => WorkContextType::CLIENT,
            'client_id' => $client->id,
            'name' => 'Context Client',
            'is_default' => false,
        ]);
    }

    #[Test]
    public function resolver_returns_internal_context_when_no_client_is_selected(): void
    {
        $context = app(ResolveWorkContext::class)->fromPayload([]);

        $this->assertTrue($context->isInternal());
        $this->assertNull($context->client_id);
        $this->assertTrue($context->is_default);
    }

    #[Test]
    public function resolver_returns_client_context_when_client_is_selected(): void
    {
        $client = Client::factory()->create(['name' => 'Selected Client']);

        $context = app(ResolveWorkContext::class)->fromPayload(['client_id' => (string) $client->id]);

        $this->assertTrue($context->isClient());
        $this->assertSame($client->id, $context->client_id);
        $this->assertSame('Selected Client', $context->name);
    }

    #[Test]
    public function resolver_rejects_invalid_client_selection(): void
    {
        $this->expectException(ValidationException::class);

        app(ResolveWorkContext::class)->fromPayload(['client_id' => 999999]);
    }

    #[Test]
    public function supported_context_types_are_explicit(): void
    {
        $this->assertSame([WorkContextType::INTERNAL, WorkContextType::CLIENT], WorkContextType::values());
        $this->assertTrue(WorkContextType::isSupported(WorkContextType::INTERNAL));
        $this->assertTrue(WorkContextType::isSupported(WorkContextType::CLIENT));
        $this->assertFalse(WorkContextType::isSupported('relationship'));
    }

    #[Test]
    public function client_context_names_follow_client_renames_when_defaults_are_ensured(): void
    {
        $client = Client::factory()->create(['name' => 'Old Client Name']);

        app(ResolveWorkContext::class)->client($client);

        $client->forceFill(['name' => 'New Client Name'])->save();
        app(EnsureWorkContextDefaults::class)->handle();

        $this->assertSame(
            'New Client Name',
            WorkContext::query()
                ->where('type', WorkContextType::CLIENT)
                ->where('client_id', $client->id)
                ->value('name'),
        );
    }
}
