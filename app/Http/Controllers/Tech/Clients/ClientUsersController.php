<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Clients\Client;

class ClientUsersController extends Controller
{
    // -----------------------------------------
    // INDEX - List all users for a client
    // -----------------------------------------
    public function index()
    {

    }

    // -----------------------------------------
    // SHOW - Show a single user for a client
    // -----------------------------------------
    public function show(User $user) {

    }

    // -----------------------------------------
    // EDIT - Edit a single user for a client
    // -----------------------------------------
    public function edit(User $user, Site $site, Client $client) {

    }

    // -----------------------------------------
    // CREATE - Create a new user for a client
    // -----------------------------------------
    public function create(Client $client) {

        return view('tech.clients.users.form', [
            'client' => $client,
        ]);
    }

    // -----------------------------------------
    // STORE - Store a new user for a client
    // -----------------------------------------
    public function store() {

    }

    // -----------------------------------------
    // UPDATE - Updates a single user for a client
    // -----------------------------------------
    public function update(User $user) {

    }

}
