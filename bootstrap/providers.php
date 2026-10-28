<?php

return [
    App\Modules\Email\Providers\EmailServiceProvider::class,
    App\Modules\Integration\Providers\IntegrationServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Modules\Notification\Providers\NotificationServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Modules\UserManagement\Providers\SsoServiceProvider::class,
];
