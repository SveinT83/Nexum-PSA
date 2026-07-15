<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\LeadIntelligence\Models\MarketingSuppressionEntry;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use Illuminate\Support\Str;

class MarketingSuppressionGuard
{
    public function reasonForRecipient(MarketingCampaignRecipient $recipient, array $settings): ?string
    {
        $recipient->loadMissing('contact');

        if (! filled($recipient->email)) {
            return 'Recipient email is missing.';
        }

        if ($recipient->contact?->do_not_email) {
            return 'Contact is marked do not email.';
        }

        if (($settings['consent_mode'] ?? 'opt_out') === 'explicit_opt_in' && $recipient->contact && ! $recipient->contact->marketing_consent) {
            return 'Contact has not explicitly opted in to marketing email.';
        }

        return $this->reasonForTarget(
            $recipient->email,
            $recipient->contact_id,
            $recipient->client_id,
        );
    }

    public function reasonForTarget(?string $email, ?int $contactId = null, ?int $clientId = null): ?string
    {
        $email = $this->normalizeEmail($email);
        $domain = $email !== '' && str_contains($email, '@') ? Str::after($email, '@') : null;

        if ($email === '' && ! $domain && ! $contactId && ! $clientId) {
            return null;
        }

        $entry = MarketingSuppressionEntry::query()
            ->where(function ($query) use ($email, $domain, $contactId, $clientId): void {
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }

                if ($domain) {
                    $query->orWhereRaw('LOWER(domain) = ?', [$domain]);
                }

                if ($contactId) {
                    $query->orWhere('contact_id', $contactId);
                }

                if ($clientId) {
                    $query->orWhere('client_id', $clientId);
                }
            })
            ->first();

        if (! $entry) {
            return null;
        }

        return $entry->reason ?: 'Recipient is suppressed.';
    }

    public function suppressRecipient(MarketingCampaignRecipient $recipient, string $source, ?string $reason = null): MarketingSuppressionEntry
    {
        $email = $this->normalizeEmail($recipient->email);

        return MarketingSuppressionEntry::query()->updateOrCreate(
            [
                'email' => $email !== '' ? $email : null,
                'contact_id' => $recipient->contact_id,
                'client_id' => $recipient->client_id,
            ],
            [
                'domain' => $email !== '' && str_contains($email, '@') ? Str::after($email, '@') : null,
                'reason' => $reason ?: 'Suppressed by marketing event.',
                'source' => $source,
                'suppressed_at' => now(),
            ],
        );
    }

    private function normalizeEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }
}
