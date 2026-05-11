<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(): View
    {
        return view('sales::Tech.Sales.index');
    }

    public function create(): View
    {
        return view('sales::Tech.Sales.create');
    }

    public function show(string $sale): View
    {
        return view('sales::Tech.Sales.show', [
            'sale' => $sale,
        ]);
    }
}
