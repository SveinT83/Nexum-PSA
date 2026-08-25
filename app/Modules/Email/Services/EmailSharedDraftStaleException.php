<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailComposerDraft;
use RuntimeException;

class EmailSharedDraftStaleException extends RuntimeException
{
    public function __construct(
        public readonly EmailComposerDraft $draft,
        public readonly string $safeCode = 'email_shared_draft_source_stale',
        string $message = 'The conversation or sender context changed. Preview and confirm a rebase before sending.',
    ) {
        parent::__construct($message);
    }
}
