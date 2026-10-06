<?php

namespace App\Modules\Integration\Services\Tripletex;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\TripletexException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Telescope\Telescope;
use Throwable;

/** An allowlisted, company-bound client. Reconciliation owns retries; this client never retries writes. */
final class TripletexClient
{
    private ?string $session = null;

    private ?int $sessionExpires = null;

    private bool $identityVerified = false;

    public function __construct(#[\SensitiveParameter] private readonly Integration $connection) {}

    public function companyId(): int
    {
        $id = $this->connection->config['company_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new TripletexException('company_not_configured');
        }

        return $id;
    }

    public function verifyCompany(): array
    {
        $identity = $this->value($this->send('GET', '/token/session/%3EwhoAmI', [], false));
        if ((int) data_get($identity, 'company.id', $identity['companyId'] ?? null) !== $this->companyId()) {
            throw new TripletexException('company_identity_mismatch');
        }
        $this->identityVerified = true;

        // Metadata only; employee details and token entitlements are not a general-purpose log.
        return ['company_id' => $this->companyId()];
    }

    public function employees(): array
    {
        return $this->pages('/employee', ['fields' => 'id,firstName,lastName']);
    }

    public function activities(): array
    {
        return $this->pages('/activity', ['fields' => 'id,name']);
    }

    public function projects(): array
    {
        return $this->pages('/project', ['fields' => 'id,name']);
    }

    /** Full bounded listing or exception. A partial scan can never be interpreted as deletion. */
    public function entries(string $from, string $to, int $employeeId): array
    {
        $this->date($from);
        $this->date($to);
        if ($to <= $from || $employeeId < 1 || (strtotime($to) - strtotime($from)) > 32 * 86400) {
            throw new TripletexException('invalid_scan_window');
        }

        return $this->pages('/timesheet/entry', [
            'dateFrom' => $from, 'dateTo' => $to, 'employeeId' => $employeeId,
            'fields' => 'id,version,employee(id),activity(id),project(id),date,hours,comment,locked',
        ]);
    }

    public function entry(int $id): array
    {
        $this->positiveId($id);

        return $this->value($this->send('GET', '/timesheet/entry/'.$id));
    }

    /** Callers must durably record intent before invoking this method; a timeout is ambiguous. */
    public function createEntry(#[\SensitiveParameter] array $entry): array
    {
        $this->assertWrites();
        $payload = $this->payload($entry);
        $created = $this->value($this->send('POST', '/timesheet/entry', ['json' => $payload]));
        $id = $created['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new TripletexException('write_outcome_unknown');
        }
        $readback = $this->entry($id);
        $this->assertReadback($readback, $payload);

        return $readback;
    }

    public function updateEntry(int $id, int $expectedVersion, #[\SensitiveParameter] array $entry): array
    {
        $this->assertWrites();
        $before = $this->writable($id, $expectedVersion);
        $payload = $this->payload($entry);
        // Full owned fields are supplied; absent optional project/comment are explicit removals.
        $this->send('PUT', '/timesheet/entry/'.$id, ['json' => $payload + [
            'id' => $id, 'version' => $before['version'],
        ]]);
        $readback = $this->entry($id);
        $this->assertReadback($readback, $payload);

        return $readback;
    }

    /** Provider DELETE concurrency must be proven by the pilot before enabling live writes. */
    public function deleteEntry(int $id, int $expectedVersion): void
    {
        $this->assertWrites();
        $before = $this->writable($id, $expectedVersion);
        $this->send('DELETE', '/timesheet/entry/'.$id, ['query' => ['version' => $expectedVersion]]);
        try {
            $this->entry($id);
        } catch (TripletexException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }
            // A 404 alone may reflect revoked access: require a fresh company read and full list.
            $this->verifyCompany();
            $date = $before['date'];
            $next = date('Y-m-d', strtotime($date.' +1 day'));
            $rows = $this->entries($date, $next, (int) data_get($before, 'employee.id'));
            if (collect($rows)->contains(fn ($row) => (int) $row['id'] === $id)) {
                throw new TripletexException('delete_readback_failed');
            }

            return;
        }
        throw new TripletexException('delete_readback_failed');
    }

    private function writable(int $id, int $version): array
    {
        $row = $this->entry($id);
        if (! array_key_exists('locked', $row) || $row['locked'] !== false) {
            throw new TripletexException('entry_locked_or_unknown');
        }
        if (! isset($row['version']) || $row['version'] !== $version) {
            throw new TripletexException('entry_changed');
        }

        return $row;
    }

    private function assertWrites(): void
    {
        $current = $this->connection->exists ? $this->connection->fresh() : $this->connection;
        if (! $current || ! config('tripletex.enabled') || ! config('tripletex.writes_enabled')
            || $current->status !== 'active'
            || ! ($current->config['write_contract_verified'] ?? false)) {
            throw new TripletexException('writes_not_activated');
        }
    }

    private function pages(string $path, array $query): array
    {
        $all = [];
        $seen = [];
        $expectedTotal = null;
        for ($page = 0; $page < 50; $page++) {
            $body = $this->send('GET', $path, ['query' => $query + [
                'from' => $page * 200, 'count' => 200, 'sorting' => 'id',
            ]])->json();
            if (! is_array($body) || ! is_array($body['values'] ?? null)
                || ! array_is_list($body['values']) || count($body['values']) > 200) {
                throw new TripletexException('incomplete_provider_list');
            }
            $total = $body['fullResultSize'] ?? null;
            if (! is_int($total) || $total < 0 || ($expectedTotal !== null && $total !== $expectedTotal)) {
                throw new TripletexException('unstable_provider_list');
            }
            $expectedTotal = $total;
            foreach ($body['values'] as $row) {
                $id = $row['id'] ?? null;
                if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                    throw new TripletexException('duplicate_or_invalid_provider_identity');
                }
                $seen[$id] = true;
                $all[] = $row;
            }
            if (count($all) === $total) {
                return $all;
            }
            if (count($body['values']) < 200 || count($all) > $total) {
                throw new TripletexException('incomplete_provider_list');
            }
        }
        throw new TripletexException('provider_list_limit_reached');
    }

    private function send(string $method, string $path, #[\SensitiveParameter] array $options = [], bool $checkIdentity = true): Response
    {
        if ($checkIdentity && (! $this->identityVerified || ($this->sessionExpires ?? 0) <= time())) {
            $this->verifyCompany();
        }
        $token = $this->sessionToken();
        $response = $this->transport($method, $path, $options, $token);
        // Retry only authenticated reads after expiry; never repeat an ambiguous write.
        if ($response->status() === 401 && $method === 'GET') {
            $this->session = null;
            $this->identityVerified = false;
            if ($checkIdentity) {
                $this->verifyCompany();
            }
            $response = $this->transport($method, $path, $options, $this->sessionToken());
        }
        if (! $response->successful()) {
            throw new TripletexException('request_failed', $response->status());
        }

        return $response;
    }

    private function sessionToken(): string
    {
        if ($this->session && ($this->sessionExpires ?? 0) > time()) {
            return $this->session;
        }
        $secret = $this->connection->getSecret('refresh_token');
        if (! is_string($secret) || $secret === '') {
            throw new TripletexException('credential_missing_or_unreadable');
        }
        $response = $this->transport('POST', '/token/session/:createFromRefreshToken', [
            'json' => ['refreshToken' => $secret, 'ttlSeconds' => 600],
        ]);
        if (! $response->successful()) {
            throw new TripletexException('session_creation_failed', $response->status());
        }
        $value = $response->json('value.token');
        if (! is_string($value) || $value === '') {
            throw new TripletexException('invalid_session_response');
        }
        $this->session = $value;
        $this->sessionExpires = time() + 540;

        return $value;
    }

    private function transport(string $method, string $path, #[\SensitiveParameter] array $options, #[\SensitiveParameter] ?string $token = null): Response
    {
        $base = match ($this->connection->config['environment'] ?? null) {
            'production' => 'https://tripletex.no/v2',
            'test' => 'https://api-test.tripletex.tech/v2',
            default => throw new TripletexException('environment_not_configured'),
        };
        if ($this->connection->type !== 'tripletex') {
            throw new TripletexException('wrong_connection_type');
        }
        // Never follow a redirect with a credential. Disable verbose tracing and telemetry copies.
        $call = function () use ($base, $method, $path, $options, $token) {
            $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false, 'verify' => true]);
            if ($token !== null) {
                $request = $request->withBasicAuth('0', $token);
            }

            return $request->send($method, $base.$path, $options);
        };
        try {
            return class_exists(Telescope::class) ? Telescope::withoutRecording($call) : $call();
        } catch (Throwable) {
            // No chained exception: HTTP exceptions may contain URLs, headers or submitted material.
            throw new TripletexException($method === 'GET' ? 'transport_failed' : 'write_outcome_unknown');
        }
    }

    private function value(Response $response): array
    {
        $value = $response->json('value');
        if (! is_array($value)) {
            throw new TripletexException('invalid_provider_response');
        }

        return $value;
    }

    private function payload(#[\SensitiveParameter] array $row): array
    {
        if (array_diff(array_keys($row), ['employee', 'activity', 'project', 'date', 'hours', 'comment'])) {
            throw new TripletexException('unsupported_entry_fields');
        }
        foreach (['employee', 'activity'] as $field) {
            $this->positiveId((int) data_get($row, $field.'.id'));
        }
        $this->date($row['date'] ?? '');
        if (! is_numeric($row['hours'] ?? null) || ! is_finite((float) $row['hours'])
            || $row['hours'] <= 0 || $row['hours'] > 24) {
            throw new TripletexException('invalid_duration');
        }
        $project = data_get($row, 'project.id');
        if ($project !== null) {
            $this->positiveId((int) $project);
        }
        $comment = $row['comment'] ?? '';
        if (! is_string($comment) || mb_strlen($comment) > 2000) {
            throw new TripletexException('invalid_comment');
        }

        return ['employee' => ['id' => (int) $row['employee']['id']],
            'activity' => ['id' => (int) $row['activity']['id']],
            'project' => $project === null ? null : ['id' => (int) $project],
            'date' => $row['date'], 'hours' => (float) $row['hours'], 'comment' => $comment];
    }

    private function assertReadback(#[\SensitiveParameter] array $row, #[\SensitiveParameter] array $sent): void
    {
        foreach (['employee.id', 'activity.id', 'project.id', 'date', 'comment'] as $field) {
            if ((string) data_get($row, $field, '') !== (string) data_get($sent, $field, '')) {
                throw new TripletexException('write_readback_mismatch');
            }
        }
        if (! isset($row['hours']) || (string) $row['hours'] !== (string) $sent['hours']) {
            throw new TripletexException('duration_readback_mismatch');
        }
    }

    private function positiveId(int $id): void
    {
        if ($id < 1) {
            throw new TripletexException('invalid_provider_id');
        }
    }

    private function date(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw new TripletexException('invalid_work_date');
        }
    }
}
