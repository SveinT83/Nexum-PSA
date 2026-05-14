<?php

namespace App\Domain\Email\Jobs;

/*
|--------------------------------------------------------------------------
| Legacy queue job bridge
|--------------------------------------------------------------------------
|
| Serialized jobs created before Email moved into app/Modules/Email still
| reference this namespace. Keep the bridge until old queue payloads age out.
|
*/
class FetchImapAccount extends \App\Modules\Email\Jobs\FetchImapAccount
{
}
