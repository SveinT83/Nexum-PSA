<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivewireFrontendRuntimeTest extends TestCase
{
    #[Test]
    public function application_javascript_does_not_start_a_second_alpine_runtime(): void
    {
        $applicationScript = file_get_contents(resource_path('js/app.js'));
        $authenticatedLayout = file_get_contents(resource_path('views/layouts/default_tech.blade.php'));

        $this->assertIsString($applicationScript);
        $this->assertIsString($authenticatedLayout);
        $this->assertStringNotContainsString("from 'alpinejs'", $applicationScript);
        $this->assertStringNotContainsString('Alpine.start()', $applicationScript);
        $this->assertStringContainsString(
            '@livewireScripts',
            $authenticatedLayout,
            'The authenticated layout must load Livewire 3, which provides the Alpine runtime.',
        );
    }
}
