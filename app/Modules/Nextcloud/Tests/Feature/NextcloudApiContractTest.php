<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Runtime methods/scopes and static typed documentation must remain an exact set. */
class NextcloudApiContractTest extends TestCase
{
    #[Test]
    public function every_full_and_legacy_operation_has_typed_swagger_and_real_scopes(): void
    {
        $doc = json_decode(file_get_contents(getenv('NEXTCLOUD_API_SWAGGER_PATH') ?: storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $actual = [];
        $new = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/nextcloud/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $actual[] = $key;
                $op = $doc['paths']['/'.$route->uri()][strtolower($method)] ?? null;
                $this->assertIsArray($op, $key);
                $this->assertNotEmpty($op['security']);
                preg_match_all('/\{([^}]+)\}/', $route->uri(), $matches);
                $this->assertEqualsCanonicalizing($matches[1], collect($op['parameters'] ?? [])->where('in', 'path')->pluck('name')->all());
                if (str_contains($route->getName(), 'nextcloud.full.')) {
                    $new++;
                    foreach ($route->defaults['nextcloud_abilities'] as $ability) {
                        $this->assertContains($ability, app(ApiAbilityCatalog::class)->values());
                        $this->assertContains($ability, $op['x-required-scopes']);
                    }
                    foreach ([401, 403, 404, 409, 412, 422, 428, 502, 503] as $status) {
                        $this->assertArrayHasKey($status, $op['responses']);
                    }
                    if ($route->defaults['nextcloud_key_required']) {
                        $this->assertTrue(collect($op['parameters'])->firstWhere('name', 'Idempotency-Key')['required']);
                    }
                }
            }
        }
        $published = [];
        foreach ($doc['paths'] as $path => $item) {
            if (! str_starts_with($path, '/api/v1/nextcloud/')) {
                continue;
            }
            foreach ($item as $method => $op) {
                if (in_array($method, ['get', 'post', 'patch', 'delete', 'put'], true)) {
                    $published[] = $method.' '.$path;
                }
            }
        }
        $this->assertCount(50, $actual);
        $this->assertSame(36, $new);
        $this->assertEqualsCanonicalizing($actual, $published);
        $this->assertArrayNotHasKey('service_password', $doc['components']['schemas']['NextcloudApiConnection']['properties']);
        $this->assertArrayNotHasKey('talk_bot_secret', $doc['components']['schemas']['NextcloudApiTalk']['properties']);
        $web = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'tech/admin/system/nextcloud'));
        $this->assertCount(19, $web);
    }
}
