<?php

namespace App\Modules\Relationship\Tests\Feature;

use App\Modules\Relationship\Support\RelationshipTelemetryRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Prove private headers/results never become secondary telemetry or rendered failure material. */
class RelationshipPrivacyTest extends TestCase
{
    private function entry(string $type, array $content): IncomingEntry
    {
        $entry = new IncomingEntry($content);
        $entry->type = $type;

        return $entry;
    }

    #[Test]
    public function sensitive_request_and_rotation_receipt_context_is_dropped_by_the_actual_provider_filter(): void
    {
        $original = Telescope::$filterUsing;
        $request = app('request');
        try {
            (new \App\Providers\TelescopeServiceProvider($this->app))->register();
            $filter = Telescope::$filterUsing[array_key_last(Telescope::$filterUsing)];
            foreach (['/api/v1/relationships/operations/one', '/api/v1/nexum/relationships/tickets',
                '/tech/admin/system/relationships/1/rotate-secrets', '/api/v1/tickets/TD-1/relationships/escalate'] as $path) {
                $this->app->instance('request', Request::create($path, 'POST'));
                $this->assertFalse($filter($this->entry(EntryType::REQUEST, ['response_status' => 500,
                    'headers' => ['X-Nexum-Token' => 'synthetic-secret'], 'response' => '{"inbound_token":"synthetic-result"}'])));
            }
        } finally {
            Telescope::$filterUsing = $original;
            $this->app->instance('request', $request);
        }
    }

    #[Test]
    public function background_transport_entries_and_credential_ledger_queries_are_discarded(): void
    {
        $this->app->instance('request', Request::create('/unrelated'));
        $this->assertFalse(RelationshipTelemetryRedactor::sanitize($this->entry(EntryType::CLIENT_REQUEST, [
            'uri' => 'https://synthetic.example.test/api/v1/nexum/relationships/documentation',
            'headers' => ['X-Nexum-Token' => 'synthetic-secret'], 'response' => 'synthetic-secret',
        ])));
        foreach (['nexum_relationships', 'relationship_api_commands', 'relationship_outbound_claims'] as $table) {
            $this->assertFalse(RelationshipTelemetryRedactor::sanitize($this->entry(EntryType::QUERY, [
                'sql' => 'insert into '.$table.' values (?)', 'bindings' => ['synthetic-ciphertext'],
            ])));
        }
        $this->assertFalse(RelationshipTelemetryRedactor::sanitize($this->entry(EntryType::MODEL, [
            'model' => 'App\\Modules\\Relationship\\Models\\NexumRelationship:1',
        ])));
    }

    #[Test]
    public function nested_headers_and_json_results_are_redacted_without_changing_safe_diagnostics(): void
    {
        $this->app->instance('request', Request::create('/unrelated'));
        $entry = $this->entry(EntryType::REQUEST, ['headers' => ['X-Nexum-Token' => 'synthetic-header',
            'x-nexum-signature' => 'synthetic-signature'], 'result' => ['outbound_token' => 'synthetic-outbound',
                'inbound_token_hash' => 'synthetic-hash', 'webhook_secret_encrypted' => 'synthetic-ciphertext'],
            'response' => '{"data":{"inbound_token":"synthetic-result"}}', 'safe_count' => 7]);
        $this->assertTrue(RelationshipTelemetryRedactor::sanitize($entry));
        $encoded = json_encode($entry->content);
        $this->assertStringNotContainsString('synthetic-', $encoded);
        $this->assertSame(7, $entry->content['safe_count']);
        $this->assertSame('[REDACTED]', $entry->content['headers']['X-Nexum-Token']);
    }

    #[Test]
    public function api_failures_are_generic_no_store_and_report_only_the_exception_class(): void
    {
        Log::spy();
        Route::post('/api/v1/relationships/privacy-contract', function () {
            throw new \RuntimeException('synthetic-private-request-or-ciphertext');
        })->middleware('api');
        $response = $this->postJson('/api/v1/relationships/privacy-contract', ['outbound_token' => 'synthetic-secret'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'Relationship operation failed.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('synthetic-', $response->getContent());
        $response->assertJsonMissingPath('trace')->assertJsonMissingPath('exception');
        Log::shouldHaveReceived('error')->with('Relationship operation failed.', ['exception_class' => \RuntimeException::class])->once();
    }

    #[Test]
    public function invalid_browser_forms_preserve_safe_input_without_flashing_connection_credentials(): void
    {
        Route::post('/tech/admin/system/relationships/privacy-form', function (Request $request) {
            $request->validate(['name' => 'required|string']);
        })->middleware('web');
        $response = $this->from('/tech/admin/system/relationships/create')->post('/tech/admin/system/relationships/privacy-form', [
            'safe_label' => 'preserved', 'outbound_token' => 'synthetic-outbound',
            'inbound_token' => 'synthetic-inbound', 'webhook_secret' => 'synthetic-signing',
        ])->assertRedirect()->assertSessionHasErrors('name');
        $response->assertSessionHas('_old_input.safe_label', 'preserved');
        foreach (['outbound_token', 'inbound_token', 'webhook_secret'] as $key) {
            $response->assertSessionMissing('_old_input.'.$key);
        }
    }
}
