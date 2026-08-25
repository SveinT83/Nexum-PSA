<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailSharedDraftLock;
use RuntimeException;

class EmailSharedDraftLockedException extends RuntimeException
{
    public function __construct(
        public readonly ?EmailComposerDraft $draft,
        public readonly ?EmailSharedDraftLock $lock,
        public readonly string $safeCode = 'email_shared_draft_locked',
        string $message = 'This shared draft is locked by another editor or the lease is no longer current.',
    ) {
        parent::__construct($message);
    }
}
