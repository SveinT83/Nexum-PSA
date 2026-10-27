<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiModelUsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiTelemetryApiTest extends TestCase
{
    use \App\Modules\Integration\Tests\Support\ValidatesAiTelemetryResponses;
    use RefreshDatabase;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo('integration.ai_manage');
        $this->token = $this->actor->createToken('Isolated cost read', ['integration.ai_telemetry.read'])->plainTextToken;
        Http::preventStrayRequests();
    }

    private function api(string $path)
    {
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($this->token)->getJson('/api/v1/integrations/ai/'.$path);
        $this->validateAiTelemetryResponse('GET', $path, $response);

        return $response;
    }

    private function event(array $extra = []): AiModelUsageEvent
    {
        return AiModelUsageEvent::create($extra + [
            'execution_id' => (string) Str::uuid(), 'attempt_number' => 1,
            'domain' => 'integration', 'feature_key' => 'cost.fixture', 'operation_key' => 'read',
            'requested_model' => 'cost-fixture', 'endpoint_kind' => 'fixture', 'status' => 'success',
            'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 1, 'usage_source' => 'unavailable',
        ]);
    }

    #[Test]
    public function list_detail_and_cards_read_back_exact_nullable_decimal_evidence(): void
    {
        $event = $this->event(['provider_reported_cost' => '0.000000000001', 'cost_currency' => 'NOK', 'total_tokens' => 0]);
        $this->event(['domain' => 'task']);
        $list = $this->api('telemetry?domain=integration&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.cost.amount', '0.000000000001')
            ->assertJsonPath('data.0.cost.source', 'provider_reported')->assertJsonPath('data.0.tokens.total', 0)
            ->assertJsonPath('summary.currencies.NOK.known_subtotal', '0.000000000001');
        $detail = $this->api('telemetry/'.$event->id)->assertOk()->assertJsonPath('data.id', $event->id);
        $this->assertSame($list->json('data.0'), $detail->json('data'));
        $this->api('rate-cards')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertCount(2, AiModelUsageEvent::all());
        Http::assertNothingSent();
    }

    #[Test]
    public function unknown_and_reported_zero_are_different_and_json_never_invents_currency(): void
    {
        $unknown = $this->event();
        $this->api('telemetry/'.$unknown->id)->assertOk()->assertJsonPath('data.cost.amount', null)
            ->assertJsonPath('data.tokens.total', null)->assertJsonPath('data.cost.state', 'unknown');
        $zero = $this->event(['provider_reported_cost' => '0']);
        $this->api('telemetry/'.$zero->id)->assertOk()->assertJsonPath('data.cost.amount', '0.000000000000')
            ->assertJsonPath('data.cost.currency', null)->assertJsonPath('data.cost.state', 'unknown_currency');
        $this->api('telemetry')->assertOk()->assertJsonPath('summary.unknown_cost_attempts', 1)
            ->assertJsonPath('summary.unknown_currency_attempts', 1)->assertJsonPath('summary.complete', false);
    }

    #[Test]
    public function fresh_native_permission_and_scope_are_both_required(): void
    {
        $this->token = $this->actor->createToken('Wrong scope', ['integration.bookstack.read'])->plainTextToken;
        $this->api('telemetry')->assertForbidden();
        $this->token = $this->actor->createToken('Wildcard', ['*'])->plainTextToken;
        $this->actor->revokePermissionTo('integration.ai_manage');
        $this->api('telemetry')->assertForbidden();
        $this->actor->givePermissionTo('integration.ai_manage');
        $this->api('telemetry')->assertOk();
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->api('telemetry')->assertForbidden();
        Http::assertNothingSent();
    }

    #[Test]
    public function unsupported_fields_invalid_filters_pagination_and_unknown_targets_are_rejected(): void
    {
        foreach (['telemetry?per_page=101', 'telemetry?page=0', 'telemetry?execution_id=no',
            'telemetry?provider_secret=x', 'rate-cards?per_page=0', 'rate-cards?domain=x'] as $path) {
            $this->api($path)->assertUnprocessable();
        }
        $this->api('telemetry/'.Str::uuid())->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/integrations/ai/telemetry')->assertUnauthorized();
    }

    #[Test]
    public function ui_keeps_unknown_currency_and_tiny_cost_visible(): void
    {
        $event = $this->event(['provider_reported_cost' => '0.000000000001', 'cost_currency' => 'NOK']);
        $this->actingAs($this->actor)->get(route('tech.admin.system.integrations.ai.telemetry.index'))
            ->assertOk()->assertSee('0.000000000001 NOK')->assertSee('Unknown');
        $this->actingAs($this->actor)->get(route('tech.admin.system.integrations.ai.telemetry.show', $event))
            ->assertOk()->assertSee('0.000000000001 NOK')->assertSee('provider_reported');
    }

    #[Test]
    public function rates_preserve_decimal_strings_and_provider_payloads_are_never_exported(): void
    {
        $provider = \App\Modules\Integration\Models\AiProvider::create([
            'name' => 'Rate fixture', 'provider_key' => 'ollama', 'base_url' => 'https://rates.example.test',
            'default_model' => 'fixture', 'status' => 'active']);
        $card = \App\Modules\Integration\Models\AiModelRateCard::create([
            'name' => 'NOK fixture', 'ai_provider_id' => $provider->id, 'model_pattern' => 'fixture',
            'currency' => 'NOK', 'effective_from' => now()->subDay(), 'is_active' => true]);
        $card->rates()->create(['metric' => 'input_token', 'rate' => '0.000000000001', 'unit_quantity' => 1]);
        $this->api('rate-cards')->assertOk()->assertJsonPath('data.0.currency', 'NOK')
            ->assertJsonPath('data.0.rates.0.rate', '0.000000000001');
        $event = $this->event(['provider_usage' => ['raw' => 'SENSITIVE-TELEMETRY-CANARY'],
            'provider_request_id' => 'SENSITIVE-TELEMETRY-CANARY', 'error_category' => 'SENSITIVE-TELEMETRY-CANARY']);
        $detail = $this->api('telemetry/'.$event->id)->assertOk();
        $this->assertStringNotContainsString('SENSITIVE-TELEMETRY-CANARY', $detail->getContent());
    }

    #[Test]
    public function expired_and_revoked_tokens_have_no_read_authority(): void
    {
        $token = $this->actor->createToken('Expired', ['*'], now()->subMinute());
        $this->token = $token->plainTextToken;
        $this->api('telemetry')->assertUnauthorized();
        $token = $this->actor->createToken('Revoked', ['*']);
        $this->token = $token->plainTextToken;
        $token->accessToken->delete();
        $this->api('telemetry')->assertUnauthorized();
    }
}
