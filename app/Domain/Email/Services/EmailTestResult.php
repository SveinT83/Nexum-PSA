<?php

namespace App\Domain\Email\Services;

class EmailTestResult
{
    public bool $imap_ok = false;
    public bool $smtp_ok = false;

    public ?float $imap_ms = null;
    public ?float $smtp_ms = null;

    public ?string $imap_error_code = null;
    public ?string $imap_error_message = null;

    public ?string $smtp_error_code = null;
    public ?string $smtp_error_message = null;

    public function overall(): string
    {
        if ($this->imap_ok && $this->smtp_ok) return 'OK';
        if (!$this->imap_ok && !$this->smtp_ok) return 'Error';
        return 'Warning';
    }
}
