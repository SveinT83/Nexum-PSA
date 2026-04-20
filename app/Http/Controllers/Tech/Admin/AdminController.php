<?php

namespace App\Http\Controllers\Tech\Admin;

use App\Http\Controllers\Controller;

class AdminController extends Controller
{
    public function index()
    {
        return view('tech.admin.index');
    }
}
