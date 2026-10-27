<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Clients\ClientUser;
use App\Modules\Contact\Actions\MigrateClientUsersToContacts;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Support\Facades\DB;

/** One canonical Contact workflow with a stable client/site compatibility bridge. */
class ImportNextcloudClientContact
{
    public function handle(NextcloudConnection $connection, string $name, ?string $email, string $role): ClientUser
    {
        return DB::transaction(function () use ($connection, $name, $email, $role) {
            $site = $connection->site()->where('client_id', $connection->client_id)->firstOrFail();
            $bridge = $email ? ClientUser::where('email', $email)->whereHas('site', fn ($q) => $q->where('client_id', $connection->client_id))->lockForUpdate()->first() : null;
            if (! $bridge) {
                $contact = app(StoreContact::class)->handle(['type' => 'person', 'status' => 'active', 'display_name' => $name, 'email' => $email,
                    'client_id' => $connection->client_id, 'site_id' => $site->id, 'relation_type' => $role, 'active' => true, 'created_from' => 'nextcloud_import']);
                $bridge = $contact->clientUsers()->where('client_site_id', $site->id)->firstOrFail();
            }
            if ($bridge->role !== $role || ! $bridge->active) {
                $bridge->forceFill(['role' => $role, 'active' => true])->save();
            }
            app(MigrateClientUsersToContacts::class)->migrateOne($bridge);

            return $bridge->fresh();
        });
    }
}
