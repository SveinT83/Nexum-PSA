<?php

namespace App\Console\Commands;

use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Services\SyncTripletexCustomerProfiles;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Laravel\Telescope\Telescope;

/** A bounded fair scan shares the connector account lease and honours the GUI pause. */
class SyncTripletexCustomers extends Command
{
    protected $signature = 'tripletex:sync-customers';

    protected $description = 'Reconcile linked customer Billing Email and Site addresses; never contacts.';

    public function handle(TripletexCustomerNumbers $numbers, SyncTripletexCustomerProfiles $sync): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        if (! Schema::hasTable('tripletex_customer_profiles') || ! ($connection = $numbers->connection())) {
            return self::SUCCESS;
        }
        $started = microtime(true);
        $links = TripletexCustomerLink::query()
            ->leftJoin('tripletex_customer_profiles as profiles', 'profiles.link_id', '=', 'tripletex_customer_links.id')
            ->where('connection_id', $connection->id)->where('tripletex_customer_links.status', 'linked')
            ->whereNotNull('client_id')->whereNotNull('customer_id')
            ->orderBy('profiles.checked_at')->orderBy('tripletex_customer_links.id')
            ->limit(20)->pluck('tripletex_customer_links.id');
        foreach ($links as $id) {
            if (microtime(true) - $started >= 210) {
                break;
            }
            $sync->sync($id);
        }
        $this->info('Customer billing/address scan completed. Review integration profile status for exceptions.');

        return self::SUCCESS;
    }
}
