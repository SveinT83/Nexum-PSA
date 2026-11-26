<?php

namespace App\Modules\Clients\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Exact route/contract/Swagger equality catches omitted methods and untyped new surfaces. */
class ClientsApiContractTest extends TestCase
{
    #[Test]
    public function every_registered_client_api_method_has_an_exact_typed_contract(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-25-clients-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! str_contains($route->getActionName(), 'Modules\\Clients\\')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' /'.$route->uri();
            }
        }
        $expected = [];
        foreach ($manifest['operations'] as $op) {
            $expected[] = $op['method'].' '.$op['path'];
            if (isset($op['media_types'])) {
                $this->assertNotEmpty($op['media_types']);
            } else {
                $this->assertArrayHasKey($op['response'], $manifest['schemas']);
            }
            if ($op['request']) {
                $this->assertArrayHasKey($op['request'], $manifest['schemas']);
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->assertCount(count(array_unique($expected)), $actual);
    }

    #[Test]
    public function client_creation_contract_explains_number_authority_and_preserves_legacy_header_optionality(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $swagger = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $swagger['paths']['/api/v1/clients']['post'];
        $key = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');
        // The gateway requires a key; the official API preserves legacy simple-create compatibility.
        $this->assertFalse($key['required']);
        $this->assertContains('name', $swagger['components']['schemas']['ClientApiCreateInput']['required']);
        $this->assertStringContainsString('omit client_number', $operation['summary']);
        $this->assertStringContainsString('primary_contact.email', $operation['summary']);
        $input = $swagger['components']['schemas']['ClientApiCreateInput']['properties'];
        $this->assertStringContainsString('MANUAL', $input['billing_email']['description']);
        $this->assertStringContainsString('isPrivateIndividual=true', $input['client_format_id']['description']);
        $this->assertStringContainsString('tripletex.invoiceSendMethod', $swagger['components']['schemas']['ClientApiError']['properties']['errors']['description']);
        $lookup = $swagger['paths']['/api/v1/clients/context/tripletex/customers']['get'];
        $search = collect($lookup['parameters'])->firstWhere('name', 'q');
        $this->assertSame('string', $search['schema']['type']);
        $this->assertSame(2, $search['schema']['minLength']);
        $this->assertSame(100, $search['schema']['maxLength']);
        $this->assertStringContainsString('tripletex_customer_id', $lookup['summary']);
        $this->assertStringContainsString('fresh Idempotency-Key', $operation['summary']);
        foreach (['client_number', 'tripletex_request_key', 'tripletex_customer_id', 'billing_email'] as $field) {
            $this->assertNotEmpty($swagger['components']['schemas']['ClientApiCreateInput']['properties'][$field]['description']);
        }
    }

    #[Test]
    public function generated_swagger_contains_every_exact_method_and_response_schema(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-25-clients-api-contract.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $swagger = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['operations'] as $op) {
            $operation = $swagger['paths'][$op['path']][strtolower($op['method'])] ?? null;
            $this->assertIsArray($operation, $op['method'].' '.$op['path']);
            $this->assertSame($op['operationId'], $operation['operationId']);
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status >= 200 && (int) $status < 300) {
                    if (isset($op['media_types'])) {
                        foreach ($op['media_types'] as $type) {
                            $this->assertSame('string', $response['content'][$type]['schema']['type']);
                            $this->assertSame('binary', $response['content'][$type]['schema']['format']);
                        }
                    } else {
                        $this->assertSame('#/components/schemas/'.($op['responses'][(string) $status] ?? $op['response']), $response['content']['application/json']['schema']['$ref']);
                    }
                }
            }
        }
        foreach (array_keys($manifest['schemas']) as $name) {
            $this->assertArrayHasKey($name, $swagger['components']['schemas']);
            $this->assertEquals($manifest['schemas'][$name], $swagger['components']['schemas'][$name], $name);
        }
    }
}
