<?php

namespace App\Http\Controllers\Tech\Admin\Settings\Email;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ConfigController extends Controller
{
    public function index()
    {
        // Placeholder: load config from DB/settings service later.
        $config = [
            'poll_interval' => 1,
            'concurrency' => 3,
            'batch_size' => 20,
            'delete_on_success' => true,
            'size_limit_mb' => 25,
            'retention_months' => 24,
        ];
        return view('Tech.admin.settings.email.config.index', compact('config'));
    }

    public function update(Request $request)
    {
        // TODO: persist settings to DB or config store.
        return redirect()->route('tech.admin.settings.email.config');
    }
}
