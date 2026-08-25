<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailComposerDraft;
use RuntimeException;

class EmailDraftConflictException extends RuntimeException
{
    public function __construct(
        public readonly ?EmailComposerDraft $currentDraft,
        string $message = 'The Mail draft changed after this client loaded it.',
    ) {
        parent::__construct($message);
    }
}
