<?php

namespace App\Modules\CustomerPortal\Support;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;

class CustomerPortalContext
{
    public function __construct(
        public readonly CustomerPortalAccount $account,
        public readonly CustomerPortalMembership $membership,
        public readonly Contact $contact,
        public readonly Client $client,
        public readonly ?ClientSite $site,
    ) {
    }
}
