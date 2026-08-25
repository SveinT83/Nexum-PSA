<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailComposerDraft;

class EmailDraftFence
{
    public function issue(EmailComposerDraft $draft): string
    {
        return $this->tokenForVersion($draft, max(1, (int) $draft->version));
    }

    public function version(EmailComposerDraft $draft, ?string $token): int
    {
        $candidate = trim((string) $token);
        $currentVersion = max(1, (int) $draft->version);

        if (hash_equals($this->tokenForVersion($draft, $currentVersion), $candidate)) {
            return $currentVersion;
        }

        // Successful cleanup increments the terminal draft once. Accepting
        // that exact prior token lets an idempotent repeated send recover the
        // already-accepted submission without making the token reversible.
        if ($draft->status === EmailComposerDraft::STATUS_SENT
            && $currentVersion > 1
            && hash_equals($this->tokenForVersion($draft, $currentVersion - 1), $candidate)) {
            return $currentVersion - 1;
        }

        throw new EmailDraftConflictException($draft->fresh());
    }

    /** Validate one explicitly recorded historical version without widening normal writes. */
    public function matchesVersion(EmailComposerDraft $draft, ?string $token, int $version): bool
    {
        return $version > 0
            && hash_equals($this->tokenForVersion($draft, $version), trim((string) $token));
    }

    private function tokenForVersion(EmailComposerDraft $draft, int $version): string
    {
        $evidence = implode('|', [
            (string) $draft->public_id,
            (string) $draft->generation_id,
            (string) $version,
        ]);

        return 'edf1_'.hash_hmac('sha256', 'email-private-draft-fence|'.$evidence, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return is_string($decoded) ? $decoded : $key;
        }

        return $key;
    }
}
