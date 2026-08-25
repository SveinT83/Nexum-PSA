<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookStackClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    #[Test]
    public function client_retries_three_times_before_returning_a_successful_response(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $this->assertSame('https://docs.example.test/api/pages/42', $request->url());
            $attempts++;

            return $attempts <= 3
                ? Http::response(['message' => 'Too Many Attempts'], 429)
                : Http::response(['id' => 42, 'name' => 'Recovered'], 200);
        });

        $client = new BookStackClient(
            'https://docs.example.test',
            'token-id',
            'token-secret',
            requestDelaySeconds: 0,
            maxRetries: 3,
        );

        $page = $client->readPage(42);

        $this->assertSame(42, $page['id']);
        $this->assertSame(4, $attempts);
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds > 14.9 && $duration->totalSeconds <= 15, 1);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds > 29.9 && $duration->totalSeconds <= 30, 1);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds > 59.9 && $duration->totalSeconds <= 60, 1);
    }

    #[Test]
    public function client_uses_retry_after_when_it_exceeds_exponential_backoff(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $this->assertSame('https://docs.example.test/api/pages/42', $request->url());
            $attempts++;

            return $attempts === 1
                ? Http::response(['message' => 'Too Many Attempts'], 429, ['Retry-After' => '45'])
                : Http::response(['id' => 42], 200);
        });

        $client = new BookStackClient(
            'https://docs.example.test',
            'token-id',
            'token-secret',
            requestDelaySeconds: 0,
            maxRetries: 1,
        );

        $this->assertSame(42, $client->readPage(42)['id']);
        $this->assertSame(2, $attempts);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds > 44.9 && $duration->totalSeconds <= 45, 1);
    }

    #[Test]
    public function connection_test_reports_an_exhausted_rate_limit_without_throwing(): void
    {
        Http::fake([
            'https://docs.example.test/api/books*' => Http::response([
                'message' => 'Too Many Attempts',
            ], 429),
        ]);

        $client = new BookStackClient(
            'https://docs.example.test',
            'token-id',
            'token-secret',
            requestDelaySeconds: 0,
            maxRetries: 0,
        );

        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('rate limit exceeded after 1 attempt', $result['message']);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    #[Test]
    public function separate_clients_share_one_connection_request_pace(): void
    {
        Http::fake([
            'https://docs.example.test/api/books*' => Http::response(['data' => []], 200),
        ]);

        $first = new BookStackClient(
            'https://docs.example.test',
            'token-id',
            'token-secret',
        );
        $second = new BookStackClient(
            'https://docs.example.test',
            'token-id',
            'token-secret',
        );

        $this->assertTrue($first->testConnection()['success']);
        $this->assertTrue($second->testConnection()['success']);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds > 0.9 && $duration->totalSeconds <= 1, 1);
    }
}
