<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMessage;

final readonly class EmailCanonicalContentResolution
{
    public function __construct(
        public EmailMessage $message,
        public EmailMessage $source,
        public string $mode,
        public bool $usedCanonical,
        public bool $driftDetected,
    ) {}
}
