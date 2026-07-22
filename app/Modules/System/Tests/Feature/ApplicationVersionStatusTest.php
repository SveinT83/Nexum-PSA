<?php

namespace App\Modules\System\Tests\Feature;

use App\Models\Core\User;
use App\Modules\System\Controllers\Admin\AdminDashboardController;
use App\Modules\System\Controllers\Admin\ApplicationVersionStatusController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationVersionStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $commit = '1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');

        config()->set('app.version', '0.2.0-beta');
        config()->set('app.commit', $this->commit);
        config()->set('app.update.branch', 'Dev');
        config()->set('app.update.github_repository', 'SveinT83/Nexum-PSA');
        config()->set('app.update.github_token', null);
        config()->set('app.update.include_prereleases', true);
        Cache::flush();
    }

    #[Test]
    public function admin_routes_and_view_are_owned_by_the_system_module(): void
    {
        $adminRoute = Route::getRoutes()->getByName('tech.admin.index');
        $statusRoute = Route::getRoutes()->getByName('tech.admin.system.version-status');

        $this->assertSame(AdminDashboardController::class, $adminRoute->getControllerClass());
        $this->assertSame(ApplicationVersionStatusController::class, $statusRoute->getControllerClass());

        Http::preventStrayRequests();

        $this->actingAs($this->admin)
            ->get(route('tech.admin.index'))
            ->assertOk()
            ->assertViewIs('system::Admin.index')
            ->assertSee('v0.2.0-beta')
            ->assertSee('Commit 1111111')
            ->assertSee(route('tech.admin.system.version-status'), false)
            ->assertSee('Checking GitHub');

        Http::assertNothingSent();
    }

    #[Test]
    public function status_reports_a_new_release_and_commits_behind_the_update_branch(): void
    {
        $this->fakeSuccessfulGitHubStatus();

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('installed_version', '0.2.0-beta')
            ->assertJsonPath('installed_commit_short', '1111111')
            ->assertJsonPath('latest_release.version', '0.3.0-beta.1')
            ->assertJsonPath('release_status', 'update_available')
            ->assertJsonPath('comparison_status', 'behind')
            ->assertJsonPath('commits_behind', 8)
            ->assertJsonPath('commits_ahead', 0)
            ->assertJsonPath('github_available', true)
            ->assertJsonPath('stale', false);
    }

    #[Test]
    public function legacy_release_name_is_used_when_the_tag_is_not_semantic(): void
    {
        Http::fake([
            'https://api.github.com/repos/SveinT83/Nexum-PSA/releases*' => Http::response([
                $this->release('Beta2', 'v0.2.0-beta', false),
            ]),
            'https://api.github.com/repos/SveinT83/Nexum-PSA/compare/*' => Http::response([
                'status' => 'identical',
                'ahead_by' => 0,
                'behind_by' => 0,
            ]),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('latest_release.version', '0.2.0-beta')
            ->assertJsonPath('release_status', 'current')
            ->assertJsonPath('comparison_status', 'current');
    }

    #[Test]
    public function status_is_cached_between_admin_requests(): void
    {
        $this->fakeSuccessfulGitHubStatus();

        $this->actingAs($this->admin)->getJson(route('tech.admin.system.version-status'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('tech.admin.system.version-status'))->assertOk();

        Http::assertSentCount(2);
    }

    #[Test]
    public function missing_commit_skips_comparison_without_guessing_a_count(): void
    {
        config()->set('app.commit', null);
        Cache::flush();
        Http::fake([
            'https://api.github.com/repos/SveinT83/Nexum-PSA/releases*' => Http::response([
                $this->release('v0.3.0-beta.1', 'v0.3.0-beta.1', true),
            ]),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('installed_commit', null)
            ->assertJsonPath('comparison_status', 'commit_unknown')
            ->assertJsonPath('commits_behind', null);

        Http::assertSentCount(1);
    }

    #[Test]
    public function github_failure_returns_an_available_admin_response(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response(['message' => 'Unavailable'], 503),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('release_status', 'unknown')
            ->assertJsonPath('comparison_status', 'unknown')
            ->assertJsonPath('github_available', false)
            ->assertJsonPath('stale', false);
    }

    #[Test]
    public function user_without_system_view_permission_cannot_read_version_status(): void
    {
        Permission::create(['name' => 'system.view']);
        $technicianRole = Role::create(['name' => 'Technician']);
        $technician = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $technician->assignRole($technicianRole);

        $this->actingAs($technician)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertForbidden();
    }

    #[Test]
    public function stale_successful_status_is_retained_when_a_refresh_fails(): void
    {
        Http::fake([
            'https://api.github.com/repos/SveinT83/Nexum-PSA/releases*' => Http::sequence()
                ->push([
                    $this->release('v0.3.0-beta.1', 'v0.3.0-beta.1', true),
                ])
                ->push(['message' => 'Unavailable'], 503),
            'https://api.github.com/repos/SveinT83/Nexum-PSA/compare/*' => Http::sequence()
                ->push([
                    'status' => 'ahead',
                    'ahead_by' => 8,
                    'behind_by' => 0,
                ])
                ->push(['message' => 'Unavailable'], 503),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('stale', false);

        $freshKey = $this->statusCacheKey('fresh');
        $this->assertTrue(Cache::has($freshKey));
        $this->assertTrue(Cache::forget($freshKey));
        $this->assertFalse(Cache::has($freshKey));

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.version-status'))
            ->assertOk()
            ->assertJsonPath('latest_release.version', '0.3.0-beta.1')
            ->assertJsonPath('commits_behind', 8)
            ->assertJsonPath('github_available', false)
            ->assertJsonPath('stale', true);
    }

    private function statusCacheKey(string $suffix): string
    {
        $signature = hash('sha256', implode('|', [
            (string) config('app.version'),
            (string) config('app.commit'),
            (string) config('app.update.branch'),
            (string) config('app.update.github_repository'),
            config('app.update.include_prereleases') ? 'prerelease' : 'stable',
        ]));

        return 'nexum:application-version-status:'.$signature.':'.$suffix;
    }

    private function fakeSuccessfulGitHubStatus(): void
    {
        Http::fake([
            'https://api.github.com/repos/SveinT83/Nexum-PSA/releases*' => Http::response([
                $this->release('v0.3.0-beta.1', 'v0.3.0-beta.1', true),
                $this->release('Beta2', 'v0.2.0-beta', false),
            ]),
            'https://api.github.com/repos/SveinT83/Nexum-PSA/compare/*' => Http::response([
                'status' => 'ahead',
                'ahead_by' => 8,
                'behind_by' => 0,
            ]),
        ]);
    }

    private function release(string $tag, string $name, bool $prerelease): array
    {
        return [
            'tag_name' => $tag,
            'name' => $name,
            'html_url' => 'https://github.com/SveinT83/Nexum-PSA/releases/tag/'.$tag,
            'published_at' => '2026-07-16T08:00:00Z',
            'draft' => false,
            'prerelease' => $prerelease,
        ];
    }
}
