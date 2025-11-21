<?php

namespace App\Http\Controllers\Tech\CS\Services;

use App\Http\Controllers\Controller;
use App\Models\CS\Services\Services;
use Illuminate\Http\Request;

class IndexController extends Controller
{
    public function index(Request $request)
    {
        $query = Services::query();

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%");
                $q->orWhere('sku', 'like', "%$search%");
            });
        }

        $services = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('Tech.cs.services.index', [
            'services' => $services,
            'search' => $search,
        ]);
    }
}
