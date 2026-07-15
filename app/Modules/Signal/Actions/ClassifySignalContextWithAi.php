<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Signal\Support\SignalSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ClassifySignalContextWithAi
{
    private const TIMEOUT_SECONDS = 90;

    public function __construct(
        private readonly AiChatResponder $responder,
        private readonly SignalSettings $settings,
    ) {
    }

    public function handle(array $context, ?array $settings = null): ?array
    {
        $settings = $settings ? $this->settings->normalize($settings) : $this->settings->get();

        if (! (bool) $settings['ai_classification_enabled']) {
            return null;
        }

        $sourceDomain = Str::lower((string) ($context['source_domain'] ?? ''));
        if (! in_array($sourceDomain, $settings['ai_source_domains'], true)) {
            return null;
        }

        $agent = $this->agent();

        if (! $agent) {
            return null;
        }

        try {
            $reply = $this->responder->complete($agent, [
                ['role' => 'system', 'content' => $this->systemPrompt($settings)],
                ['role' => 'user', 'content' => json_encode($this->context($context, $settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ], self::TIMEOUT_SECONDS);

            return $this->sanitize($this->decodeJson($reply), $settings, $reply);
        } catch (\Throwable) {
            return null;
        }
    }

    private function agent(): ?AiAgent
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->first(fn (AiAgent $agent): bool => in_array('signal', $agent->default_domains ?? [], true));
    }

    private function systemPrompt(array $settings): string
    {
        return trim((string) $settings['ai_classification_prompt'])."\n\nAllowed signal types: ".implode(', ', $settings['ai_allowed_signal_types']).".";
    }

    private function context(array $context, array $settings): array
    {
        return [
            'allowed_signal_types' => $settings['ai_allowed_signal_types'],
            'minimum_confidence' => $settings['ai_min_confidence'],
            'source' => Arr::only($context, [
                'source_domain',
                'source_type',
                'source_id',
                'subject',
                'from_email',
                'from_name',
                'to',
                'headers',
                'body_text',
                'received_at',
            ]),
        ];
    }

    private function decodeJson(string $reply): array
    {
        $json = trim($reply);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/', '', (string) $json);
        }

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('AI did not return valid JSON content.');
        }

        return $decoded;
    }

    private function sanitize(array $payload, array $settings, string $rawReply): ?array
    {
        $decision = Str::lower(trim((string) ($payload['decision'] ?? 'not_signal')));
        $signalType = str($payload['signal_type'] ?? '')->trim()->lower()->replace([' ', '-'], '_')->toString();
        $confidence = max(0, min(100, (int) ($payload['confidence'] ?? 0)));

        if ($decision !== 'signal' || ! in_array($signalType, $settings['ai_allowed_signal_types'], true) || $confidence < (int) $settings['ai_min_confidence']) {
            return null;
        }

        $severity = Str::lower(trim((string) ($payload['severity'] ?? 'info')));
        if (! array_key_exists($severity, SignalSettings::SEVERITY_OPTIONS)) {
            $severity = 'info';
        }

        $recipientEmail = trim((string) ($payload['recipient_email'] ?? ''));
        if ($recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            $recipientEmail = '';
        }

        $summary = Str::limit(trim((string) ($payload['summary'] ?? 'AI classified inbound signal.')), 255, '');
        $aiPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];

        return [
            'signal_type' => $signalType,
            'severity' => $severity,
            'confidence' => $confidence,
            'summary' => $summary,
            'recipient_email' => $recipientEmail !== '' ? Str::lower($recipientEmail) : null,
            'payload' => [
                ...Arr::only($aiPayload, ['vendor', 'reason', 'category', 'reference']),
                'classifier' => 'signal-ai-v1',
                'ai_reason' => Str::limit(trim((string) ($aiPayload['reason'] ?? $payload['reason'] ?? '')), 500, ''),
                'ai_raw_reply' => Str::limit($rawReply, 5000, ''),
            ],
        ];
    }
}
