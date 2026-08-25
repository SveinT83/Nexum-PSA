<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailOutboundSubmission;
use RuntimeException;

class EmailSubmissionConflictException extends RuntimeException
{
    public function __construct(
        public readonly EmailOutboundSubmission $submission,
        string $message = 'This Mail draft snapshot already has a send submission.',
    ) {
        parent::__construct($message);
    }
}
