<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Services\BookStack\BookStackClient;
use Illuminate\Http\Client\Request;
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
        Sleep::assertSequence([
            Sleep::for(2)->seconds(),
            Sleep::for(4)->seconds(),
            Sleep::for(8)->seconds(),
        ]);
    }

    #[Test]
    public function client_uses_retry_after_when_it_exceeds_exponential_backoff(): void
    {
        $attempts = 0;

        Http::fake(function (Request $request) use (&$attempts) {
            $this->assertSame('https://docs.example.test/api/pages/42', $request->url());
            $attempts++;

            return $attempts === 1
                ? Http::response(['message' => 'Too Many Attempts'], 429, ['Retry-After' => '7'])
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
        Sleep::assertSequence([
            Sleep::for(7)->seconds(),
        ]);
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
}
