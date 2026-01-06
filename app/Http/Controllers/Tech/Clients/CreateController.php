<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\CS\Requests\Tech\Clients\StoreClientRequest;
use App\Models\Client;
use App\Models\ClientSite;
use App\Models\ClientUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CreateController extends Controller
{
    public function create(): View
    {
        // Foreslå neste kundenummer (5 siffer)
        $maxNumber = Client::query()->max('client_number');
        $suggestedClientNumber = str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);

        // Enkle oppslagslister (kan flyttes til config eller DB senere)
        $roles = ['Daglig leder', 'Innehaver', 'IT-kontakt', 'Økonomi', 'Annet'];
        $countries = ['NO' => 'Norway', 'SE' => 'Sweden', 'DK' => 'Denmark'];

        return view('tech.clients.create', [
            'suggestedClientNumber' => $suggestedClientNumber,
            'roles' => $roles,
            'countries' => $countries,
        ]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            // Create client
            $client = Client::query()->create([
                'name' => $data['name'],
                'client_number' => $data['client_number'],
                'org_no' => $data['org_no'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'active' => $data['active'] ?? true,
            ]);

            // Create default site
            $site = ClientSite::query()->create([
                'client_id' => $client->id,
                'name' => $data['site_name'],
                'is_default' => true,
            ]);

            // Create default site user
            ClientUser::query()->create([
                'client_site_id' => $site->id,
                'user_id' => null,
                'role' => $data['user_role'] ?? null,
                'name' => $data['user_name'],
                'email' => $data['user_email'] ?? null,
                'phone' => $data['user_phone'] ?? null,
                'is_default_for_site' => true,
                'is_default_for_client' => true,
                'active' => true,
            ]);
        });

        return redirect()->route('tech.clients.index')->with('status', 'Client created');
    }
}
