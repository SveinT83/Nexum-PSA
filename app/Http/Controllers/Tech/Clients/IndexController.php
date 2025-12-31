<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::query();

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%");
                $q->orWhere('org_no', 'like', "%$search%");
                $q->orWhere('billing_email', 'like', "%$search%");
            });
        }

        $clients = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('tech.clients.index', [
            'clients' => $clients,
            'search' => $search,
        ]);
    }
}
