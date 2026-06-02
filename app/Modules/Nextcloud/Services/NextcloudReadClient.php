<?php

namespace App\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Calendar\Models\CalendarEvent;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NextcloudReadClient
{
    public function capabilities(NextcloudConnection $connection): array
    {
        $response = $this->ocs($connection)
            ->acceptJson()
            ->get($connection->base_url.'/ocs/v2.php/cloud/capabilities', [
                'format' => 'json',
            ]);

        $this->throwUnlessSuccessful($response, 'capabilities');

        return $response->json('ocs.data.capabilities') ?? [];
    }

    public function users(NextcloudConnection $connection): array
    {
        $response = $this->ocs($connection)
            ->acceptJson()
            ->get($connection->base_url.'/ocs/v2.php/cloud/users', [
                'format' => 'json',
            ]);

        $this->throwUnlessSuccessful($response, 'users');

        return array_values($response->json('ocs.data.users') ?? []);
    }

    public function groups(NextcloudConnection $connection): array
    {
        $response = $this->ocs($connection)
            ->acceptJson()
            ->get($connection->base_url.'/ocs/v2.php/cloud/groups', [
                'format' => 'json',
            ]);

        $this->throwUnlessSuccessful($response, 'groups');

        return array_values($response->json('ocs.data.groups') ?? []);
    }

    public function groupMembers(NextcloudConnection $connection, string $groupId): array
    {
        $response = $this->ocs($connection)
            ->acceptJson()
            ->get($connection->base_url.'/ocs/v2.php/cloud/groups/'.rawurlencode($groupId).'/users', [
                'format' => 'json',
            ]);

        $this->throwUnlessSuccessful($response, 'group members');

        return array_values($response->json('ocs.data.users') ?? []);
    }

    public function calendars(NextcloudConnection $connection): array
    {
        $username = $this->username($connection);
        $response = $this->dav($connection)
            ->withBody($this->calendarPropfindBody(), 'application/xml')
            ->send('PROPFIND', $connection->base_url.'/remote.php/dav/calendars/'.rawurlencode($username).'/', [
                'headers' => ['Depth' => '1'],
            ]);

        $this->throwUnlessSuccessful($response, 'calendars');

        return collect($this->parseMultiStatus($response->body()))
            ->filter(fn (array $entry) => $entry['is_calendar'])
            ->map(fn (array $entry) => [
                'href' => $entry['href'],
                'remote_owner' => $this->calendarOwnerFromHref($entry['href']),
                'display_name' => $entry['display_name'] ?: basename(trim($entry['href'], '/')),
                'color' => $entry['calendar_color'],
            ])
            ->values()
            ->all();
    }

    public function files(NextcloudConnection $connection, ?string $path = null): array
    {
        $username = $this->username($connection);
        $folder = '/'.trim($path ?: $connection->root_folder ?: '/', '/');
        $folder = $folder === '/' ? '' : $folder;

        $response = $this->dav($connection)
            ->withBody($this->filePropfindBody(), 'application/xml')
            ->send('PROPFIND', $connection->base_url.'/remote.php/dav/files/'.rawurlencode($username).$this->encodedPath($folder), [
                'headers' => ['Depth' => '1'],
            ]);

        $this->throwUnlessSuccessful($response, 'files');

        $entries = $this->parseMultiStatus($response->body());
        $rootHref = $entries[0]['href'] ?? null;

        return collect($entries)
            ->reject(fn (array $entry) => $rootHref && $entry['href'] === $rootHref)
            ->map(fn (array $entry) => [
                'href' => $entry['href'],
                'path' => $this->filePathFromHref($entry['href'], $username),
                'name' => $entry['display_name'] ?: basename(trim($entry['href'], '/')),
                'type' => $entry['is_collection'] ? 'folder' : 'file',
                'size' => $entry['content_length'],
                'content_type' => $entry['content_type'],
                'last_modified' => $entry['last_modified'],
            ])
            ->values()
            ->all();
    }

    public function calendarEvents(NextcloudConnection $connection, string $calendarHref, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $response = $this->dav($connection)
            ->withBody($this->calendarQueryBody($from, $to), 'application/xml')
            ->send('REPORT', $connection->base_url.$this->normalizeHref($calendarHref), [
                'headers' => ['Depth' => '1'],
            ]);

        $this->throwUnlessSuccessful($response, 'calendar events');

        return collect($this->parseCalendarMultiStatus($response->body()))
            ->map(fn (array $entry) => array_merge($entry, [
                'event' => $this->parseVevent($entry['calendar_data'] ?? ''),
            ]))
            ->filter(fn (array $entry) => ! empty($entry['event']['uid']))
            ->values()
            ->all();
    }

    public function putCalendarEvent(NextcloudConnection $connection, string $calendarHref, CalendarEvent $event): ?string
    {
        $uid = $event->external_uid ?: $event->uuid;
        $href = rtrim($this->normalizeHref($calendarHref), '/').'/'.rawurlencode($uid).'.ics';
        $response = $this->dav($connection)
            ->withBody($this->calendarEventBody($event, $uid), 'text/calendar; charset=utf-8')
            ->put($connection->base_url.$href);

        $this->throwUnlessSuccessful($response, 'calendar event write');

        return trim((string) $response->header('ETag'), '"') ?: null;
    }

    private function ocs(NextcloudConnection $connection)
    {
        return Http::withBasicAuth($this->username($connection), $this->password($connection))
            ->withHeaders(['OCS-APIRequest' => 'true'])
            ->timeout(20);
    }

    private function dav(NextcloudConnection $connection)
    {
        return Http::withBasicAuth($this->username($connection), $this->password($connection))
            ->withHeaders(['OCS-APIRequest' => 'true'])
            ->timeout(20);
    }

    private function username(NextcloudConnection $connection): string
    {
        if (! $connection->service_username) {
            throw new RuntimeException('Missing service username.');
        }

        return $connection->service_username;
    }

    private function password(NextcloudConnection $connection): string
    {
        if (! $connection->service_password) {
            throw new RuntimeException('Missing service app password.');
        }

        return $connection->service_password;
    }

    private function throwUnlessSuccessful(Response $response, string $operation): void
    {
        if (! $response->successful() && $response->status() !== 207) {
            throw new RuntimeException("Nextcloud {$operation} request failed with HTTP {$response->status()}.");
        }
    }

    private function normalizeHref(string $href): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $path = parse_url($href, PHP_URL_PATH) ?: '/';

            return $path.(parse_url($href, PHP_URL_QUERY) ? '?'.parse_url($href, PHP_URL_QUERY) : '');
        }

        return '/'.ltrim($href, '/');
    }

    private function encodedPath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '/';
        }

        return '/'.collect(explode('/', $path))
            ->map(fn (string $segment) => rawurlencode($segment))
            ->implode('/').'/';
    }

    private function calendarOwnerFromHref(?string $href): ?string
    {
        if (! $href || ! str_contains($href, '/calendars/')) {
            return null;
        }

        $parts = explode('/', trim($href, '/'));
        $index = array_search('calendars', $parts, true);

        return $index !== false ? ($parts[$index + 1] ?? null) : null;
    }

    private function filePathFromHref(?string $href, string $username): string
    {
        if (! $href || ! str_contains($href, '/files/')) {
            return '/';
        }

        $path = rawurldecode(parse_url($href, PHP_URL_PATH) ?: $href);
        $prefix = '/remote.php/dav/files/'.$username;

        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    private function calendarPropfindBody(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <cs:getctag />
    <cs:calendar-color />
  </d:prop>
</d:propfind>
XML;
    }

    private function filePropfindBody(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:displayname />
    <d:resourcetype />
    <d:getcontentlength />
    <d:getcontenttype />
    <d:getlastmodified />
    <oc:fileid />
  </d:prop>
</d:propfind>
XML;
    }

    private function calendarQueryBody(?Carbon $from = null, ?Carbon $to = null): string
    {
        $from ??= now()->subYear();
        $to ??= now()->addYears(2);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><d:getetag /><c:calendar-data /></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            .'<c:time-range start="'.$from->copy()->utc()->format('Ymd\THis\Z').'" end="'.$to->copy()->utc()->format('Ymd\THis\Z').'" />'
            .'</c:comp-filter></c:comp-filter></c:filter>'
            .'</c:calendar-query>';
    }

    private function calendarEventBody(CalendarEvent $event, string $uid): string
    {
        $timezone = $this->normalizeTimezone($event->timezone ?: 'UTC');
        $startsAt = $event->starts_at->copy()->timezone($timezone);
        $endsAt = $event->ends_at->copy()->timezone($timezone);
        $stamp = now()->utc()->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Nexum PSA//Calendar//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:'.$this->escapeIcs($uid),
            'DTSTAMP:'.$stamp,
            'CREATED:'.$event->created_at->copy()->utc()->format('Ymd\THis\Z'),
            'LAST-MODIFIED:'.$event->updated_at->copy()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escapeIcs($event->title),
            $event->all_day
                ? 'DTSTART;VALUE=DATE:'.$startsAt->format('Ymd')
                : 'DTSTART;TZID='.$this->escapeIcs($timezone).':'.$startsAt->format('Ymd\THis'),
            $event->all_day
                ? 'DTEND;VALUE=DATE:'.$endsAt->format('Ymd')
                : 'DTEND;TZID='.$this->escapeIcs($timezone).':'.$endsAt->format('Ymd\THis'),
            'STATUS:'.strtoupper($event->status ?: 'confirmed'),
            'TRANSP:'.($event->transparency === 'free' ? 'TRANSPARENT' : 'OPAQUE'),
            'CLASS:'.($event->isPrivate() ? 'PRIVATE' : 'PUBLIC'),
        ];

        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION', 'meeting_url' => 'URL'] as $field => $name) {
            if (filled($event->{$field})) {
                $lines[] = $name.':'.$this->escapeIcs($event->{$field});
            }
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private function parseCalendarMultiStatus(string $xml): array
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $items = [];

        foreach ($xpath->query('//*[local-name()="response"]') as $response) {
            if (! $response instanceof DOMElement) {
                continue;
            }

            $items[] = [
                'href' => $this->nodeValue($xpath, $response, '*[local-name()="href"]'),
                'etag' => trim((string) $this->nodeValue($xpath, $response, './/*[local-name()="getetag"]'), '"'),
                'calendar_data' => $this->nodeValue($xpath, $response, './/*[local-name()="calendar-data"]'),
            ];
        }

        return $items;
    }

    private function parseVevent(string $ics): array
    {
        $lines = $this->unfoldIcsLines($ics);
        $event = [];
        $insideEvent = false;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $insideEvent = true;
                continue;
            }

            if ($line === 'END:VEVENT') {
                break;
            }

            if (! $insideEvent || ! str_contains($line, ':')) {
                continue;
            }

            [$nameAndParams, $value] = explode(':', $line, 2);
            [$name, $params] = $this->parseIcsNameAndParams($nameAndParams);
            $key = strtolower(str_replace('-', '_', $name));

            $event[$key] = match ($name) {
                'DTSTART', 'DTEND' => $this->parseIcsDate($value, $params),
                'SUMMARY', 'DESCRIPTION', 'LOCATION', 'URL' => $this->unescapeIcs($value),
                'CLASS', 'STATUS', 'TRANSP', 'UID' => $value,
                default => $event[$key] ?? $value,
            };
        }

        return $event;
    }

    private function unfoldIcsLines(string $ics): array
    {
        $ics = preg_replace("/\r\n[ \t]/", '', $ics) ?? $ics;

        return array_values(array_filter(preg_split("/\r\n|\n|\r/", $ics) ?: []));
    }

    private function parseIcsNameAndParams(string $nameAndParams): array
    {
        $segments = explode(';', $nameAndParams);
        $name = strtoupper(array_shift($segments) ?: '');
        $params = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '=')) {
                [$key, $value] = explode('=', $segment, 2);
                $params[strtoupper($key)] = $value;
            }
        }

        return [$name, $params];
    }

    private function parseIcsDate(string $value, array $params): array
    {
        $timezone = $this->normalizeTimezone($params['TZID'] ?? 'UTC');
        $allDay = ($params['VALUE'] ?? null) === 'DATE' || preg_match('/^\d{8}$/', $value);
        $date = $allDay
            ? Carbon::createFromFormat('Ymd', $value, $timezone)->startOfDay()
            : (str_ends_with($value, 'Z')
                ? Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')
                : Carbon::createFromFormat('Ymd\THis', $value, $timezone));

        return [
            'value' => $date,
            'timezone' => $timezone,
            'all_day' => (bool) $allDay,
        ];
    }

    private function normalizeTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone === '') {
            return 'UTC';
        }

        $windowsToIana = [
            'W. Europe Standard Time' => 'Europe/Oslo',
            'Central Europe Standard Time' => 'Europe/Budapest',
            'Central European Standard Time' => 'Europe/Warsaw',
            'Romance Standard Time' => 'Europe/Paris',
            'GMT Standard Time' => 'Europe/London',
            'Greenwich Standard Time' => 'Atlantic/Reykjavik',
            'FLE Standard Time' => 'Europe/Helsinki',
            'E. Europe Standard Time' => 'Europe/Chisinau',
            'Russian Standard Time' => 'Europe/Moscow',
            'UTC' => 'UTC',
            'Coordinated Universal Time' => 'UTC',
            'Eastern Standard Time' => 'America/New_York',
            'Central Standard Time' => 'America/Chicago',
            'Mountain Standard Time' => 'America/Denver',
            'Pacific Standard Time' => 'America/Los_Angeles',
        ];

        if (isset($windowsToIana[$timezone])) {
            return $windowsToIana[$timezone];
        }

        if (in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return 'UTC';
    }

    private function escapeIcs(?string $value): string
    {
        return str_replace(["\\", "\n", "\r", ';', ','], ['\\\\', '\\n', '', '\\;', '\\,'], (string) $value);
    }

    private function unescapeIcs(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }

    private function parseMultiStatus(string $xml): array
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $responses = $xpath->query('//*[local-name()="response"]');
        $items = [];

        foreach ($responses as $response) {
            if (! $response instanceof DOMElement) {
                continue;
            }

            $items[] = [
                'href' => $this->nodeValue($xpath, $response, '*[local-name()="href"]'),
                'display_name' => $this->nodeValue($xpath, $response, './/*[local-name()="displayname"]'),
                'calendar_color' => $this->nodeValue($xpath, $response, './/*[local-name()="calendar-color"]'),
                'content_length' => (int) ($this->nodeValue($xpath, $response, './/*[local-name()="getcontentlength"]') ?: 0),
                'content_type' => $this->nodeValue($xpath, $response, './/*[local-name()="getcontenttype"]'),
                'last_modified' => $this->nodeValue($xpath, $response, './/*[local-name()="getlastmodified"]'),
                'is_calendar' => $xpath->query('.//*[local-name()="calendar"]', $response)->length > 0,
                'is_collection' => $xpath->query('.//*[local-name()="collection"]', $response)->length > 0,
            ];
        }

        return $items;
    }

    private function nodeValue(DOMXPath $xpath, DOMElement $context, string $query): ?string
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node ? trim($node->textContent) : null;
    }
}
