<?php

namespace App\Domain\Email\Jobs;

/*
|--------------------------------------------------------------------------
| Legacy queue job bridge
|--------------------------------------------------------------------------
|
| Serialized queue jobs store their original class name. Before Email moved
| into app/Modules/Email, queued StoreInboundMessage jobs used this namespace.
| Keeping this bridge lets old jobs already sitting in the queue finish while
| all newly dispatched jobs continue using the module-owned implementation.
|
*/
class StoreInboundMessage extends \App\Modules\Email\Jobs\StoreInboundMessage
{
}
