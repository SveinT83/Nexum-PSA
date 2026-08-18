<?php

namespace App\Modules\Email\Services;

use RuntimeException;

class EmailProviderReconciliationReadException extends RuntimeException
{
    public readonly string $safeCode;

    public function __construct(string $safeCode)
    {
        $this->safeCode = preg_match('/^[a-z0-9_.-]{1,80}$/', $safeCode) === 1
            ? $safeCode
            : 'provider_reconciliation_read_failed';

        parent::__construct('The provider could not complete a bounded reconciliation read.');
    }
}
