<?php

namespace App\Modules\CustomerPortal\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalAuditEvent;
use Illuminate\Http\Request;

class RecordCustomerPortalAudit
{
    public function handle(
        string $event,
        ?CustomerPortalAccount $account = null,
        ?User $user = null,
        ?Contact $contact = null,
        ?Client $client = null,
        ?ClientSite $site = null,
        array $metadata = [],
        ?Request $request = null,
    ): CustomerPortalAuditEvent {
        return CustomerPortalAuditEvent::query()->create([
            'customer_portal_account_id' => $account?->id,
            'user_id' => $user?->id,
            'contact_id' => $contact?->id ?? $account?->contact_id,
            'client_id' => $client?->id,
            'site_id' => $site?->id,
            'event' => $event,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? (string) $request->userAgent() : null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
