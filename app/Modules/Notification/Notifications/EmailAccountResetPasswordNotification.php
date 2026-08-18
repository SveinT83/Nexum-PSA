<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Routes password reset delivery through the same source-strict Email account
 * boundary as every other Nexum notification.
 */
class EmailAccountResetPasswordNotification extends ResetPassword implements EmailAccountMailNotification
{
    use RoutesEmailThroughAccount;

    public function __construct(#[\SensitiveParameter] $token)
    {
        parent::__construct($token);
        $this->freezeEmailAccountMailSnapshot('system');
    }

    public function via($notifiable)
    {
        return [$this->emailAccountMailChannel('system')];
    }
}
