<?php

return [
    App\Modules\Email\Providers\EmailServiceProvider::class,
    App\Modules\Integration\Providers\IntegrationServiceProvider::class,
    App\Modules\System\Providers\RuntimeHealthServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Modules\Notification\Providers\NotificationServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Modules\UserManagement\Providers\SsoServiceProvider::class,
];
