<?php

namespace App\Modules\System\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OneResponsivePwaPlatformContractTest extends TestCase
{
    #[Test]
    public function platform_entry_shells_keep_pwa_head_and_mobile_viewport(): void
    {
        $surfaces = [
            'tech shell' => resource_path('views/layouts/default_tech.blade.php'),
            'guest shell' => resource_path('views/layouts/guest.blade.php'),
            'customer portal shell' => base_path('app/Modules/CustomerPortal/Views/layouts/portal.blade.php'),
            'booking public shell' => base_path('app/Modules/Booking/Views/layouts/public.blade.php'),
            'intake public shell' => base_path('app/Modules/Intake/Views/layouts/public.blade.php'),
            'public quote page' => base_path('app/Modules/Sales/Views/Public/quote.blade.php'),
            'public contract page' => base_path('app/Modules/Commercial/Views/Tech/cs/contracts/public/view.blade.php'),
        ];

        foreach ($surfaces as $label => $path) {
            $this->assertFileExists($path, $label);

            $source = (string) file_get_contents($path);

            $this->assertStringContainsString('@PwaHead', $source, $label);
            $this->assertMatchesRegularExpression(
                '/<meta\s+name=["\']viewport["\']\s+content=["\']width=device-width,\s*initial-scale=1(?:\.0)?["\']/',
                $source,
                $label
            );
        }
    }

    #[Test]
    public function tech_shell_keeps_one_route_layout_with_mobile_offcanvas_navigation(): void
    {
        $source = (string) file_get_contents(resource_path('views/layouts/default_tech.blade.php'));

        $this->assertStringContainsString('data-bs-target="#techMobileNav"', $source);
        $this->assertStringContainsString('offcanvas offcanvas-start d-md-none', $source);
        $this->assertStringContainsString("partials.nav.tech_nav', ['mobile' => true]", $source);
        $this->assertStringContainsString('d-none d-md-block', $source);
        $this->assertStringContainsString('@livewireScripts', $source);
    }

    #[Test]
    public function shared_service_worker_keeps_online_first_pwa_and_notification_handlers(): void
    {
        $source = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString('const CACHE_NAME = "nexum-pwa-v', $source);
        $this->assertStringContainsString('const OFFLINE_URL = "/offline.html"', $source);
        $this->assertStringContainsString('self.addEventListener("fetch"', $source);
        $this->assertStringContainsString('self.addEventListener("push"', $source);
        $this->assertStringContainsString('self.addEventListener("notificationclick"', $source);
        $this->assertStringContainsString('self.addEventListener("message"', $source);
        $this->assertStringContainsString('nexum-close-notifications', $source);
        $this->assertStringNotContainsString('caches.match(event.request)', $source);
    }
}
