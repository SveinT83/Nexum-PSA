<?php

namespace App\Modules\Signal\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SignalSettings
{
    private const TYPE = 'signal';

    private const NAME = 'settings';

    public const SEVERITY_OPTIONS = [
        'info' => 'Info',
        'warning' => 'Warning',
        'error' => 'Error',
        'critical' => 'Critical',
    ];

    public const STATUS_OPTIONS = [
        'new' => 'New',
        'open' => 'Open',
        'review' => 'Review',
        'archived' => 'Archived',
    ];

    public const DEFAULT_AI_CLASSIFICATION_PROMPT = <<<'PROMPT'
You are the Nexum PSA Signal classifier for inbound operational events.

You receive one source payload from Nexum PSA. Use only that payload. Do not invent clients, contacts, people, vendors, URLs, assets, facts, or email addresses.

Return ONLY compact JSON with this shape:
{
  "decision": "signal|not_signal",
  "signal_type": "one allowed signal type or null",
  "severity": "info|warning|error|critical",
  "confidence": 0-100,
  "summary": "short grounded summary",
  "recipient_email": "email from evidence or null",
  "payload": {
    "vendor": "vendor from evidence or null",
    "reason": "short grounded reason"
  }
}

Choose not_signal when the payload is a normal human support request, ordinary customer reply, unclear message, or when evidence is insufficient.
PROMPT;

    public const DEFAULT_ALLOWED_AI_SIGNAL_TYPES = [
        'hard_bounce',
        'soft_bounce',
        'auto_reply',
        'out_of_office',
        'unsubscribe_request',
        'vendor_notification',
        'security_alert',
        'monitoring_alert',
        'backup_alert',
        'license_notice',
        'billing_notice',
    ];

    public const DEFAULT_STOP_TICKET_ROUTING_TYPES = [
        'hard_bounce',
        'soft_bounce',
        'auto_reply',
        'out_of_office',
        'unsubscribe_request',
        'vendor_notification',
    ];

    public const DEFAULTS = [
        'ai_classification_enabled' => false,
        'ai_min_confidence' => 80,
        'ai_source_domains' => ['email'],
        'ai_allowed_signal_types' => self::DEFAULT_ALLOWED_AI_SIGNAL_TYPES,
        'ai_stop_ticket_routing_types' => self::DEFAULT_STOP_TICKET_ROUTING_TYPES,
        'ai_classification_prompt' => self::DEFAULT_AI_CLASSIFICATION_PROMPT,
    ];

    public function get(): array
    {
        $payload = [];

        if ($this->settingsTableExists()) {
            $setting = CommonSetting::query()
                ->where('type', self::TYPE)
                ->where('name', self::NAME)
                ->first();

            $payload = json_decode($setting?->json ?: '[]', true) ?: [];
        }

        return $this->normalize($payload);
    }

    public function update(array $payload): array
    {
        $settings = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Signal automation settings for AI classification and ticket-routing guardrails.',
                'value' => $settings['ai_classification_enabled'] ? 'ai-enabled' : 'ai-disabled',
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function normalize(array $payload): array
    {
        $settings = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $settings['ai_classification_enabled'] = (bool) $settings['ai_classification_enabled'];
        $settings['ai_min_confidence'] = max(0, min(100, (int) $settings['ai_min_confidence']));
        $settings['ai_source_domains'] = $this->normalizeKeyList($settings['ai_source_domains'], self::DEFAULTS['ai_source_domains']);
        $settings['ai_allowed_signal_types'] = $this->normalizeKeyList($settings['ai_allowed_signal_types'], self::DEFAULT_ALLOWED_AI_SIGNAL_TYPES);
        $settings['ai_stop_ticket_routing_types'] = $this->normalizeKeyList($settings['ai_stop_ticket_routing_types'], self::DEFAULT_STOP_TICKET_ROUTING_TYPES);
        $settings['ai_classification_prompt'] = trim((string) $settings['ai_classification_prompt']) ?: self::DEFAULT_AI_CLASSIFICATION_PROMPT;

        return $settings;
    }

    public function listToText(array $values): string
    {
        return implode("\n", $values);
    }

    private function normalizeKeyList(mixed $value, array $fallback): array
    {
        $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        $items = collect($items ?: [])
            ->map(fn (mixed $item): string => str($item)->trim()->lower()->replace([' ', '-'], '_')->toString())
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $items === [] ? $fallback : $items;
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('common_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
