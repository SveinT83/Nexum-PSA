<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClientTimeUsageController extends Controller
{
    public function update(Client $client, string $source, int $entry, Request $request, CalculateClientTimebankBalances $balances, TicketTimeRateOptions $ticketRates): RedirectResponse
    {
        app(\App\Modules\Clients\Actions\CorrectClientTimeUsage::class)->handle($client, $source, $entry, $request, $balances, $ticketRates);

        return redirect()->route('tech.clients.show', ['client' => $client->id, 'tab' => 'time-usage'])
            ->with('status', 'Time usage updated.');
    }
}
