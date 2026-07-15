<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactPhone;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSmsMessage;
use App\Modules\Notification\Models\NotificationSmsTemplate;
use App\Modules\System\Support\CompanyProfileSettings;

/**
 * Logs transactional SMS delivery attempts through the configured SMS channel.
 *
 * The first approved provider is dry-run only. This action still performs the
 * same consent and phone checks future real providers must pass.
 */
class SendTransactionalSms
{
    public function __construct(private readonly CompanyProfileSettings $companyProfile)
    {
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $metadata
     */
    public function handle(
        Contact $contact,
        string $templateKey,
        array $variables = [],
        ?ContactPhone $phone = null,
        ?User $actor = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): NotificationSmsMessage {
        $channel = NotificationChannel::getByDriver('sms');
        $template = NotificationSmsTemplate::query()
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();
        $phone ??= $this->preferredPhone($contact);
        $config = $channel?->config ?? [];
        $provider = (string) ($config['provider'] ?? 'dry_run');
        $senderName = (string) ($config['sender_name'] ?? 'Nexum');
        $countryCode = (string) ($config['default_country_code'] ?? '+47');
        $normalizedPhone = $this->normalizePhone($phone?->phone, $countryCode);
        $body = $template ? $this->render($template->body, $this->variables($contact, $variables)) : null;
        $failureReason = $this->failureReason($channel, $provider, $contact, $phone, $normalizedPhone, $template);
        $status = $failureReason ? NotificationSmsMessage::STATUS_BLOCKED : NotificationSmsMessage::STATUS_DRY_RUN;

        $message = NotificationSmsMessage::query()->create([
            'notification_channel_id' => $channel?->id,
            'notification_sms_template_id' => $template?->id,
            'contact_id' => $contact->id,
            'contact_phone_id' => $phone?->id,
            'actor_id' => $actor?->id,
            'provider' => $provider ?: 'dry_run',
            'status' => $status,
            'direction' => 'outbound',
            'sender_name' => $senderName,
            'recipient_phone' => $phone?->phone,
            'normalized_recipient_phone' => $normalizedPhone,
            'body' => $body,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'failure_reason' => $failureReason,
            'provider_payload' => [
                'provider' => $provider ?: 'dry_run',
                'mode' => 'dry_run',
            ],
            'metadata' => $metadata,
            'sent_at' => $status === NotificationSmsMessage::STATUS_DRY_RUN ? now() : null,
        ]);

        if ($status === NotificationSmsMessage::STATUS_DRY_RUN) {
            $message->forceFill([
                'provider_message_id' => 'dry_run-'.$message->id,
            ])->save();
        }

        return $message;
    }

    private function preferredPhone(Contact $contact): ?ContactPhone
    {
        $contact->loadMissing('phones');

        return $contact->phones
            ->sortByDesc(fn (ContactPhone $phone): int => $phone->is_primary ? 1 : 0)
            ->first();
    }

    private function failureReason(
        ?NotificationChannel $channel,
        string $provider,
        Contact $contact,
        ?ContactPhone $phone,
        ?string $normalizedPhone,
        ?NotificationSmsTemplate $template,
    ): ?string {
        if (! $channel) {
            return 'SMS channel is not configured.';
        }

        if (! $channel->is_enabled) {
            return 'SMS channel is disabled.';
        }

        if ($provider !== 'dry_run') {
            return 'Only the dry-run SMS provider is supported in this slice.';
        }

        if (! $template) {
            return 'SMS template is missing or inactive.';
        }

        if (! $phone) {
            return 'Contact has no phone number.';
        }

        if ($contact->do_not_call) {
            return 'Contact is marked do not call.';
        }

        if (! $phone->sms_allowed) {
            return 'Transactional SMS is not allowed for this phone number.';
        }

        if (! $normalizedPhone) {
            return 'Phone number is not valid for SMS delivery.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function variables(Contact $contact, array $variables): array
    {
        $profile = $this->companyProfile->get();

        return array_merge([
            'app_name' => config('app.name', 'Nexum PSA'),
            'company_name' => $profile['company_name'] ?? config('app.name', 'Nexum PSA'),
            'contact_name' => $contact->display_name,
        ], $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $body, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $body = str_replace('{{ '.$key.' }}', (string) $value, $body);
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
        }

        return $body;
    }

    private function normalizePhone(?string $phone, string $defaultCountryCode): ?string
    {
        $raw = trim((string) $phone);

        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $countryDigits = preg_replace('/\D+/', '', $defaultCountryCode) ?: '47';

        if (strlen($digits) === 8 && $countryDigits !== '') {
            $digits = $countryDigits.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }
}
