<?php

namespace App\Modules\Calendar\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Contract drift is a release defect: every frozen workflow must retain a scoped typed method. */
class CalendarApiContractTest extends TestCase
{
    public function test_registered_methods_scopes_and_generated_openapi_cover_every_approved_workflow(): void
    {
        $manifest = json_decode(file_get_contents(base_path('docs/plans/2026-10-24-calendar-api-contract.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(25, $manifest['rows']);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! preg_match('~^api/v1/(calendars$|calendar/|users/me/(calendar-preferences|work-plan)$|nextcloud/calendar/)~', $route->uri())) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = strtolower($method).' /'.$route->uri();
                $this->assertArrayNotHasKey($key, $actual, 'Duplicate Calendar method '.$key);
                $actual[$key] = $route;
            }
        }
        $expected = collect($manifest['operations'])->mapWithKeys(fn ($op) => [$op['verb'].' '.$op['path'] => $op])->all();
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($actual), 'Calendar API route inventory drift');
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($expected as $key => $op) {
            $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.$op['scope'], $actual[$key]->gatherMiddleware(), $key);
            $documented = $spec['paths'][$op['path']][$op['verb']] ?? null;
            $this->assertNotNull($documented, 'Undocumented method '.$key);
            $this->assertSame([$op['scope']], $documented['x-required-scopes'] ?? null, 'Missing scope '.$key);
            if ($op['input']) {
                $this->assertSame('#/components/schemas/'.$op['input'], $documented['requestBody']['content']['application/json']['schema']['$ref'] ?? null, 'Missing typed request '.$key);
            }
            $this->assertNotEmpty($documented['responses'], $key);
        }
        foreach ($manifest['rows'] as $row => $operations) {
            $this->assertNotEmpty($operations, $row);
            foreach ($operations as $key) {
                $this->assertArrayHasKey($key, $expected, 'Uncovered workflow '.$row);
            }
        }
        foreach (['complete', 'recurrence_limited', 'current_page', 'total', 'last_page'] as $field) {
            $this->assertArrayHasKey($field, $spec['components']['schemas']['CalendarApiEventList']['properties']['meta']['properties']);
        }
        $this->assertStringNotContainsString('Route::', file_get_contents(app_path('Modules/Calendar/api.php')));
    }
}
