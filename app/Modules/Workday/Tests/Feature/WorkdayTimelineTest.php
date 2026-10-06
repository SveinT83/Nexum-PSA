<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\UserManagement\Support\UserProfileData;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Queries\WorkdayEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkdayTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'version' => 0, 'retention_years' => 3])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        $this->actingAs($this->worker, 'web');
        $hours = UserProfileData::defaultWorkingHours();
        $hours['thursday'] = ['enabled' => true, 'start' => '09:00', 'end' => '16:00'];
        app(UserWorkPlan::class)->update($this->worker, ['revision' => app(UserWorkPlan::class)->read($this->worker)['revision'],
            'timezone' => 'Europe/Oslo', 'working_hours' => $hours]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function save(array $ranges, int $version = 0, string $date = '2026-10-01'): array
    {
        return app(MutateWorkday::class)->handle($this->worker, 'draft', $date,
            ['version' => $version, 'timezone' => 'Europe/Oslo', 'description' => 'Synthetic calendar work',
                'intervals' => $ranges, 'breaks' => []], (string) Str::uuid(), 'ui')['data'];
    }

    public function test_today_is_active_and_month_navigation_never_creates_a_day(): void
    {
        $page = $this->get('/tech/workdays')->assertOk()->assertSee('Hourly workday')
            ->assertSee('Previous month')->assertSee('Next month')->assertSee('aria-current="date"', false)
            ->assertSee('id="workday-entry-modal"', false)->assertSee('data-bs-dismiss="modal"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="workday-entry-modal"]//form')->length);
        $this->assertSame(0, $xpath->query('//section[@aria-label="Hourly workday"]//form[not(ancestor::*[@id="workday-entry-modal"])]')->length);
        $this->assertStringContainsString('reopenEditor\\u0022:false', $xpath->query('//section[@aria-label="Hourly workday"]')->item(0)->getAttribute('x-data'));
        $this->assertSame('2026-10-01', $page->viewData('day')['work_date']);
        $calendar = $page->viewData('calendar');
        $selected = collect($calendar['weeks'])->flatten(1)->where('selected', true)->sole();
        $this->assertTrue($selected['today']);
        $this->assertSame(['start' => '2026-10-01T09:00+02:00', 'end' => '2026-10-01T10:00+02:00'],
            $page->viewData('timeline')['selection']);
        $this->get('/tech/workdays?work_date=2026-10-01&month=2026-11')->assertOk()
            ->assertViewHas('calendar', fn ($value) => $value['label'] === 'November 2026')
            ->assertViewHas('day', fn ($value) => $value['work_date'] === '2026-10-01');
        $this->get('/tech/workdays?month=invalid')->assertRedirect();
        $this->assertSame(0, Workday::count());
    }

    public function test_saving_nine_to_ten_selects_ten_to_eleven_and_keeps_both_registered_hours(): void
    {
        $first = ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T10:00', 'description' => 'First hour'];
        $day = $this->save([$first]);
        $page = $this->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('First hour');
        $selection = $page->viewData('timeline')['selection'];
        $this->assertSame('2026-10-01T10:00+02:00', $selection['start']);
        $this->assertSame('2026-10-01T11:00+02:00', $selection['end']);
        $this->assertSame(60, $page->viewData('day')['current']['snapshot']['actual_minutes']);
        $this->put('/tech/workdays/2026-10-01/draft', [
            'version' => 1, 'timezone' => 'Europe/Oslo', 'description' => 'Synthetic calendar work',
            'intervals' => [$first, $selection + ['description' => 'Second hour']], 'breaks' => [],
            'request_key' => (string) Str::uuid(), 'editor_index' => 1,
        ])->assertRedirect();
        $after = $this->get('/tech/workdays/'.$day['id'])->assertOk();
        $this->assertSame(120, $after->viewData('day')['current']['snapshot']['actual_minutes']);
        $this->assertSame('2026-10-01T11:00+02:00', $after->viewData('timeline')['selection']['start']);
        $this->assertCount(2, $after->viewData('day')['current']['snapshot']['intervals']);
        $this->assertNull($after->viewData('day')['confirmed']);
        $this->assertSame(1, Workday::count());
    }

    public function test_partial_and_short_free_ranges_never_overlap_registered_work_or_extend_the_plan(): void
    {
        $day = $this->save([
            ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:30'],
            ['start' => '2026-10-01T10:00', 'end' => '2026-10-01T15:30'],
        ]);
        $editor = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01');
        $this->assertSame(['start' => '2026-10-01T09:30+02:00', 'end' => '2026-10-01T10:00+02:00'], $editor['timeline']['selection']);
        $day = $this->save([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T15:30']], $day['version']);
        $this->assertSame('2026-10-01T16:00+02:00', app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01')['timeline']['selection']['end']);
        $this->save([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T16:00']], $day['version']);
        $this->assertNull(app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01')['timeline']['selection']);
    }

    public function test_a_legacy_confirmed_day_is_editable_through_the_effective_save_form(): void
    {
        $day = $this->save([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T10:00']]);
        $action = app(MutateWorkday::class);
        $preview = $action->handle($this->worker, 'preview', $day['id'], ['version' => 1], (string) Str::uuid(), 'ui');
        $confirmed = $action->handle($this->worker, 'confirm', $day['id'], [
            'version' => 1, 'preview_token' => $preview['preview']['token'], 'confirmed' => true], (string) Str::uuid(), 'ui');
        $page = $this->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('Save interval')->assertSee('id="workday-entry-modal"', false);
        $this->assertSame('2026-10-01T10:00+02:00', $page->viewData('timeline')['selection']['start']);
        $action->handle($this->worker, 'correction', $day['id'], [
            'version' => $confirmed['data']['version'], 'reason' => 'Synthetic missing hour'], (string) Str::uuid(), 'ui');
        $this->assertSame('2026-10-01T10:00+02:00', app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01')['timeline']['selection']['start']);
    }

    public function test_adjacent_overnight_time_is_reserved_and_unknown_plan_has_no_default_hour(): void
    {
        $this->save([['start' => '2026-09-30T22:00', 'end' => '2026-10-01T10:00']], 0, '2026-09-30');
        $editor = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-01');
        $this->assertSame('2026-10-01T10:00+02:00', $editor['timeline']['selection']['start']);
        $row = collect($editor['timeline']['rows'])->firstWhere('label', '09:00');
        $this->assertTrue($row['reserved']);
        $this->assertSame([], $row['choices']);
        $unknown = app(WorkdayEditor::class)->forDate($this->worker, '2026-09-29');
        $this->assertNull($unknown['timeline']['selection']);
        $this->assertCount(24, $unknown['timeline']['rows']);
    }

    public function test_repeated_clock_hour_has_two_distinct_rows_and_exact_one_hour_selection(): void
    {
        $hours = UserProfileData::defaultWorkingHours();
        $hours['sunday'] = ['enabled' => true, 'start' => '01:00', 'end' => '04:00'];
        app(UserWorkPlan::class)->update($this->worker, ['revision' => app(UserWorkPlan::class)->read($this->worker)['revision'],
            'timezone' => 'Europe/Oslo', 'working_hours' => $hours]);
        $editor = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-25');
        $rows = collect($editor['timeline']['rows'])->where('label', '02:00')->values();
        $this->assertCount(2, $rows);
        $this->assertSame('+02:00', $rows[0]['offset']);
        $this->assertSame('+01:00', $rows[1]['offset']);
        $this->save([['start' => '2026-10-25T01:00+02:00', 'end' => '2026-10-25T02:00+02:00']], 0, '2026-10-25');
        $selection = app(WorkdayEditor::class)->forDate($this->worker, '2026-10-25')['timeline']['selection'];
        $this->assertSame('2026-10-25T02:00+02:00', $selection['start']);
        $this->assertSame('2026-10-25T02:00+01:00', $selection['end']);
    }

    public function test_minute_blocks_are_proportional_and_editing_preserves_other_work_and_rejects_overlap(): void
    {
        $day = $this->save([
            ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:45', 'description' => 'Synthetic 45-minute meeting'],
            ['start' => '2026-10-01T11:00', 'end' => '2026-10-01T12:30', 'description' => 'Synthetic 90-minute work'],
        ]);
        $page = $this->get('/tech/workdays/'.$day['id'])->assertOk()
            ->assertSee('data-duration-minutes="45"', false)->assertSee('height: 72px', false)
            ->assertSee('data-duration-minutes="90"', false)->assertSee('height: 144px', false);
        $blocks = $page->viewData('timeline')['blocks'];
        $this->assertCount(2, $blocks);
        $this->assertSame(540, $blocks[0]['top']);
        $this->assertSame(45, $blocks[0]['minutes']);
        $this->assertSame(90, $blocks[1]['minutes']);
        $this->assertSame('2026-10-01T09:45+02:00', $page->viewData('timeline')['selection']['start']);
        $this->assertSame('2026-10-01T10:45+02:00', $page->viewData('timeline')['selection']['end']);

        // Optional synthetic static fixture for browser layout/Alpine checks; never exports real user data.
        if ($directory = getenv('WORKDAY_VISUAL_FIXTURE_DIR')) {
            $body = view('workday::Tech.date-navigation', $page->original->getData())->render()
                .view('workday::Tech.timeline-editor', $page->original->getData())->render();
            file_put_contents($directory.'/body.html', $body);
        }

        $valid = ['version' => 1, 'timezone' => 'Europe/Oslo', 'description' => 'Synthetic calendar work',
            'intervals' => [
                ['start' => '2026-10-01T09:15', 'end' => '2026-10-01T10:00', 'description' => 'Edited meeting'],
                ['start' => '2026-10-01T11:00', 'end' => '2026-10-01T12:30', 'description' => 'Synthetic 90-minute work'],
            ], 'request_key' => (string) Str::uuid(), 'editor_index' => 0];
        $this->put('/tech/workdays/2026-10-01/draft', $valid)->assertRedirect();
        $saved = $this->get('/tech/workdays/'.$day['id'])->assertOk();
        $this->assertSame(135, $saved->viewData('day')['current']['snapshot']['actual_minutes']);
        $this->assertSame(555, $saved->viewData('timeline')['blocks'][0]['top']);
        $this->assertSame(90, $saved->viewData('timeline')['blocks'][1]['minutes']);
        $this->assertSame(2, $saved->viewData('day')['version']);

        $invalid = $valid;
        $invalid['version'] = 2;
        $invalid['intervals'][0]['end'] = '2026-10-01T11:15';
        $invalid['request_key'] = (string) Str::uuid();
        $failed = $this->put('/tech/workdays/2026-10-01/draft', $invalid)->assertStatus(422)
            ->assertSee('must not overlap')->assertSee('Edited meeting');
        $document = new \DOMDocument;
        @$document->loadHTML($failed->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertStringContainsString('must not overlap', $xpath->query('//*[@id="workday-entry-modal"]//*[@role="alert"]')->item(0)->textContent);
        $this->assertStringContainsString('reopenEditor\\u0022:true', $xpath->query('//section[@aria-label="Hourly workday"]')->item(0)->getAttribute('x-data'));
        if ($directory = getenv('WORKDAY_VISUAL_FIXTURE_DIR')) {
            file_put_contents($directory.'/validation.html', $failed->getContent());
        }
        $this->assertSame(2, Workday::where('uuid', $day['id'])->value('version'));
        $this->assertFalse(session()->has('_old_input'));
    }
}
