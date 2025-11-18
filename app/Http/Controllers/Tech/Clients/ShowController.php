<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;

class ShowController extends Controller
{
    public function show(Client $client)
    {
        return view('Tech.clients.show', [
            'client' => $client,
        ]);
    }
}
