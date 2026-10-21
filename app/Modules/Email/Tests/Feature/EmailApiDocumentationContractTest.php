<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Support\EmailApiDocumentation;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/** Prevent new Mail routes, write fields and token ceilings from disappearing from Swagger. */
class EmailApiDocumentationContractTest extends TestCase
{
    #[Test]
    public function every_registered_mail_operation_has_exactly_one_swagger_contract_and_ability_ceiling(): void
    {
        $documented = [];
        foreach ((new ReflectionClass(EmailApiDocumentation::class))->getMethods() as $method) {
            foreach ($method->getAttributes() as $attribute) {
                $operation = $attribute->newInstance();
                $http = strtoupper(class_basename($operation));
                $key = $http.' '.$operation->path;
                $this->assertArrayNotHasKey($key, $documented);
                $documented[$key] = $operation;
            }
        }
        $registered = [];
        $catalog = app(ApiAbilityCatalog::class)->values();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/email/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $http) {
                $key = $http.' /'.$route->uri();
                $registered[] = $key;
                $this->assertArrayHasKey($key, $documented);
                $abilities = [];
                foreach ($route->gatherMiddleware() as $middleware) {
                    if (str_contains($middleware, 'CheckAbilities:')) {
                        $abilities = explode(',', explode(':', $middleware, 2)[1]);
                    }
                }
                $this->assertNotEmpty($abilities, $key);
                $this->assertSame($abilities, $documented[$key]->x['required-abilities']);
                foreach ($abilities as $ability) {
                    $this->assertContains($ability, $catalog, $key);
                }
                foreach (is_array($documented[$key]->parameters) ? $documented[$key]->parameters : [] as $parameter) {
                    if ($parameter->in === 'path') {
                        $this->assertTrue($parameter->required);
                    }
                }
            }
        }
        $this->assertEqualsCanonicalizing($registered, array_keys($documented));
        $this->assertGreaterThan(100, count($registered));
    }

    #[Test]
    public function dangerous_and_fenced_requests_document_required_inputs_and_write_only_credentials(): void
    {
        $contracts = collect(json_decode(file_get_contents(base_path('app/Modules/Email/Docs/email-api-contracts.json')), true, 512, JSON_THROW_ON_ERROR))
            ->keyBy(fn ($row) => $row['method'].' '.$row['path']);
        $send = $contracts['POST /api/v1/email/mailbox/drafts/{draft}/send']['request_schema'];
        $this->assertContains('version', $send['required']);
        $this->assertContains('idempotency_key', $send['required']);
        $ack = $contracts['POST /api/v1/email/mailbox/live/acknowledge']['request_schema'];
        $this->assertContains('receipt', $ack['required']);
        $account = $contracts['PUT /api/v1/email/admin/accounts/{account}']['request_schema'];
        $this->assertTrue($account['properties']['imap_secret']['writeOnly']);
        $this->assertNotContains('imap_secret', $account['required']);
        $lease = $contracts['POST /api/v1/email/mailbox/shared-drafts/{draft}/send']['request_schema'];
        foreach (['lease_token', 'fencing_token', 'content_version', 'source_version', 'version', 'idempotency_key'] as $field) {
            $this->assertContains($field, $lease['required']);
        }
        $this->assertSame(202, $contracts['POST /api/v1/email/mailbox/accounts/{account}/refresh']['status']);
    }

    #[Test]
    public function browser_routes_and_livewire_interfaces_require_a_reviewed_api_parity_mapping(): void
    {
        $manifest = json_decode(file_get_contents(base_path('app/Modules/Email/Docs/mail-browser-api-parity.json')), true, 512, JSON_THROW_ON_ERROR);
        $mapped = [];
        foreach ($manifest['browser_routes'] as $entry) {
            $mapped[] = $entry['method'].' '.ltrim($entry['path'], '/');
            $this->assertNotEmpty($entry['api'], $entry['name']);
            foreach ($entry['api'] as $target) {
                $route = Route::getRoutes()->getByName($target['name']);
                $this->assertNotNull($route, $entry['name']);
                $this->assertSame(ltrim($target['path'], '/'), $route->uri());
                $this->assertContains($target['method'], $route->methods());
            }
        }
        $current = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')
                || ! str_contains($route->getActionName(), 'App\\Modules\\Email\\Controllers\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $current[] = $method.' '.$route->uri();
            }
        }
        $this->assertEqualsCanonicalizing($mapped, $current);
        $actual = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app/Modules/Email/Livewire')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/\bpublic\s+function\s+(\w+)\s*\(/', file_get_contents($file->getPathname()), $matches);
            $methods = array_values(array_unique($matches[1]));
            if ($methods === []) {
                continue;
            }
            sort($methods);
            $actual[str_replace(base_path().'/', '', $file->getPathname())] = $methods;
        }
        ksort($actual);
        $expected = $manifest['livewire_public_interfaces'];
        ksort($expected);
        $this->assertSame($expected, $actual, 'Review API parity when a browser/public Livewire interface changes.');
    }

    #[Test]
    public function operator_projection_keeps_progress_evidence_and_removes_credentials_claims_and_paths(): void
    {
        $project = app(\App\Modules\Email\Support\EmailApiProjection::class);
        $this->assertSame([42 => 'canonical', 53 => 'legacy'], $project->value(collect([42 => 'canonical', 53 => 'legacy'])));
        $run = (new \App\Modules\Email\Models\EmailProviderReconciliationRun)->forceFill([
            'id' => 42, 'status' => 'running', 'final_summary_status' => 'running',
            'final_summary_error_count' => 3, 'provider_credential_version' => 7,
            'idempotency_key' => 'private-key', 'automation_claim_token' => 'private-claim',
            'error_message' => 'private diagnostic',
        ]);
        $data = $project->value($run);
        $this->assertSame(3, $data['final_summary_error_count']);
        $this->assertSame(7, $data['provider_credential_version']);
        foreach (['idempotency_key', 'automation_claim_token', 'error_message', 'folder_path'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $event = (new \App\Modules\Email\Models\EmailMailboxAccessEvent)->forceFill(['id' => 9,
            'metadata_json' => ['operation' => 'content_view', 'authorization' => 'private-bearer',
                'nested' => ['raw_path' => 'private-path', 'password' => 'private-password', 'count' => 2]]]);
        $safe = $project->value($event);
        $this->assertSame('content_view', $safe['metadata_json']['operation']);
        $this->assertSame(['count' => 2], $safe['metadata_json']['nested']);
        $this->assertArrayNotHasKey('authorization', $safe['metadata_json']);
    }
}
