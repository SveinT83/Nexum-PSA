<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Integration\Services\AiModelExecutor;
use App\Modules\Integration\Services\AiOutboundPolicyGuard;
use App\Modules\Integration\Services\AiToolContextBuilder;
use App\Modules\Integration\Services\AiUsageRecorder;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AiChatResponderAbsoluteBudgetTest extends TestCase
{
    #[Test]
    public function endpoint_fallbacks_share_remaining_monotonic_budget_and_stop_before_socket_when_exhausted(): void
    {
        $timeouts = [];
        Http::globalMiddleware(function (callable $handler) use (&$timeouts): callable {
            return function ($request, array $options) use ($handler, &$timeouts) {
                $timeouts[] = (float) ($options['timeout'] ?? 0);

                return $handler($request, $options);
            };
        });

        $responder = $this->responder();
        $agent = $this->agent();

        Http::fake([
            'https://deadline.example.test/v1/chat/completions' => function () {
                usleep(1_150_000);

                return Http::response([
                    'error' => ['message' => 'This is not a chat model.'],
                ], 404);
            },
            'https://deadline.example.test/v1/completions' => Http::response([
                'choices' => [['text' => 'Bounded fallback.']],
            ]),
        ]);

        $reply = $responder->complete(
            $agent,
            [['role' => 'user', 'content' => 'bounded']],
            timeoutSeconds: 2,
            absoluteBudgetSeconds: 2,
        );

        $this->assertSame('Bounded fallback.', $reply);
        $this->assertCount(2, $timeouts);
        $this->assertGreaterThan(1.5, $timeouts[0]);
        $this->assertGreaterThan(0.05, $timeouts[1]);
        $this->assertLessThan(1.0, $timeouts[1]);

        Http::fake([
            'https://deadline.example.test/v1/chat/completions' => function () {
                usleep(1_050_000);

                return Http::response([
                    'error' => ['message' => 'This is not a chat model.'],
                ], 404);
            },
            'https://deadline.example.test/v1/completions' => Http::response([
                'choices' => [['text' => 'Must not be reached.']],
            ]),
        ]);

        try {
            $responder->complete(
                $agent,
                [['role' => 'user', 'content' => 'exhausted']],
                timeoutSeconds: 1,
                absoluteBudgetSeconds: 1,
            );
            $this->fail('The exhausted fallback opened another provider socket.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI provider request time budget exhausted.', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertCount(3, $timeouts);
    }

    private function responder(): AiChatResponder
    {
        $policy = Mockery::mock(AiOutboundPolicyGuard::class);
        $policy->shouldReceive('prepare')
            ->twice()
            ->andReturnUsing(fn (AiAgent $agent, string $model, array $messages): array => $messages);

        $usage = Mockery::mock(AiUsageRecorder::class);
        $usage->shouldReceive('record')
            ->times(4)
            ->andReturn(new AiModelUsageEvent);

        return new AiChatResponder(
            Mockery::mock(AiToolContextBuilder::class),
            new AiModelExecutor($usage),
            $policy,
        );
    }

    private function agent(): AiAgent
    {
        $provider = new AiProvider([
            'name' => 'Deadline provider',
            'provider_key' => 'custom_openai_compatible',
            'base_url' => 'https://deadline.example.test/v1',
            'default_model' => 'deadline-model',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'deadline-test-key');

        $agent = new AiAgent([
            'name' => 'Deadline agent',
            'model' => 'deadline-model',
            'is_active' => true,
        ]);
        $agent->setRelation('provider', $provider);

        return $agent;
    }
}
