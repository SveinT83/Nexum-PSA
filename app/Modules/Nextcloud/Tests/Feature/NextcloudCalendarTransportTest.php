<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NextcloudCalendarTransportTest extends TestCase
{
    private function event(array $extra = []): CalendarEvent
    {
        return new CalendarEvent($extra + [
            'uuid' => 'local-uuid', 'title' => 'Norwegian æøå', 'starts_at' => Carbon::parse('2026-10-23T10:00:00Z'),
            'ends_at' => Carbon::parse('2026-10-23T11:00:00Z'), 'timezone' => 'Europe/Oslo',
            'created_at' => now(), 'updated_at' => now(), 'visibility' => 'default',
        ]);
    }

    private function write(CalendarEvent $event): void
    {
        // These timestamps are not mass-assignable in Calendar; set explicitly for an unsaved fixture.
        $event->created_at = now();
        $event->updated_at = now();
        $connection = new NextcloudConnection([
            'base_url' => 'https://cloud.example.test', 'service_username' => 'svc', 'service_password' => 'fake',
            'mode' => 'sync', 'is_active' => true,
        ]);
        app(NextcloudReadClient::class)->putCalendarEvent($connection, '/remote.php/dav/calendars/svc/work/', $event);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 201, ['ETag' => '"v2"'])]);
    }

    #[Test]
    public function new_resource_uses_if_none_match(): void
    {
        $this->write($this->event());
        Http::assertSent(fn ($r) => $r->header('If-None-Match') === ['*']);
    }

    #[Test]
    public function update_preserves_existing_resource_path_and_etag_precondition(): void
    {
        $href = '/remote.php/dav/calendars/svc/work/provider-resource.ics';
        $this->write($this->event(['external_event_id' => $href, 'external_uid' => 'remote-uid', 'external_etag' => 'v1']));
        Http::assertSent(fn ($r) => $r->url() === 'https://cloud.example.test'.$href && $r->header('If-Match') === ['"v1"']);
    }

    #[Test]
    public function confidential_class_is_not_reduced_to_private(): void
    {
        $this->write($this->event(['visibility' => 'confidential']));
        Http::assertSent(fn ($r) => str_contains($r->body(), 'CLASS:CONFIDENTIAL'));
    }

    #[Test]
    public function timed_event_is_written_with_unambiguous_utc_instants(): void
    {
        $this->write($this->event());
        Http::assertSent(fn ($r) => str_contains($r->body(), 'DTSTART:20261023T100000Z'));
    }

    #[Test]
    public function utf8_lines_are_folded_without_splitting_characters(): void
    {
        $this->write($this->event(['title' => str_repeat('æøå ', 40)]));
        Http::assertSent(function ($r) {
            foreach (explode("\r\n", $r->body()) as $line) {
                if (strlen($line) > 75 || ! mb_check_encoding($line, 'UTF-8')) {
                    return false;
                }
            }

            return true;
        });
    }

    #[Test]
    public function foreign_resource_path_is_refused_before_http(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside its mapped collection');
        $this->write($this->event([
            'external_event_id' => '/remote.php/dav/calendars/svc/private/private.ics', 'external_etag' => 'v1',
        ]));
    }

    #[Test]
    public function missing_etag_on_existing_resource_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires the current ETag');
        $this->write($this->event(['external_event_id' => '/remote.php/dav/calendars/svc/work/existing.ics']));
    }
}
