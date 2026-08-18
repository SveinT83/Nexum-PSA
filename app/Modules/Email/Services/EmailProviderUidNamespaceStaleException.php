<?php

namespace App\Modules\Email\Services;

use RuntimeException;

class EmailProviderUidNamespaceStaleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider UID namespace changed before the mailbox operation.');
    }
}
