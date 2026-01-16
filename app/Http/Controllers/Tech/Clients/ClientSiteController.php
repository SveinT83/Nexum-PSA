<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\ClientSite;
use App\Models\ClientUser;

class ClientSiteController extends Controller
{

    // -----------------------------------------
    // INDEX - List all sites for a client
    // -----------------------------------------
    public function index()
    {
        // -----------------------------------------
        // Array of sidebar menu items
        // -----------------------------------------
        $sidebarMenuItems = [
            ['name' => 'Clients', 'route' => 'tech.clients.index'],
            ['name' => 'Sites', 'route' => 'tech.clients.sites.index'],
            ['name' => 'Users', 'route' => 'tech.clients.users.index'],
        ];

        // -----------------------------------------
        // Return view
        // -----------------------------------------
        return view('tech.clients.sites.index', [
            'sidebarMenuItems' => $sidebarMenuItems
        ]);

    }

    // -----------------------------------------
    // SHOW - Show a single sites for a client
    // -----------------------------------------
    public function show(ClientSite $ClientSite) {

    }

    // -----------------------------------------
    // EDIT - Edit a single sites for a client
    // -----------------------------------------
    public function edit(ClientSite $ClientSite, Client $client) {

    }

    // -----------------------------------------
    // CREATE - Create a new user for a sites
    // -----------------------------------------
    public function create(ClientSite $ClientSite) {

        return view('tech.clients.users.form', [
            'client' => $client,
        ]);
    }

    // -----------------------------------------
    // STORE - Store a new sites for a client
    // -----------------------------------------
    public function store(Client $client) {

    }

    // -----------------------------------------
    // UPDATE - Updates a single sites for a client
    // -----------------------------------------
    public function update(ClientSite $ClientSite) {

    }

    // -----------------------------------------
    // Destroy - Delete a sites
    // -----------------------------------------
    public function destroy(ClientSite $ClientSite){

    }
}
