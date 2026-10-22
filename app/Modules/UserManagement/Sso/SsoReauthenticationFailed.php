<?php

namespace App\Modules\UserManagement\Sso;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Fixed feedback for an authenticated account owner; never contains submitted credentials. */
class SsoReauthenticationFailed extends HttpException
{
    public function __construct(bool $passwordRejected)
    {
        parent::__construct(403, $passwordRejected
            ? 'Your current Nexum password was not accepted. Use the password for this Nexum installation.'
            : 'Your Nexum authenticator code was not accepted. Use a fresh, unused code for this Nexum installation. If you no longer have that entry, open Security / 2FA.');
    }
}
