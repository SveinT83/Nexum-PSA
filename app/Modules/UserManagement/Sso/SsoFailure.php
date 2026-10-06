<?php

namespace App\Modules\UserManagement\Sso;

use RuntimeException;

/** Deliberately carries no upstream body, token, credential or inner exception. */
class SsoFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Work account sign-in could not be completed. Please retry or contact an administrator.');
    }
}
