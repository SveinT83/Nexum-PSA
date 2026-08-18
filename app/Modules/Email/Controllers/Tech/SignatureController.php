<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Services\EmailSignatureRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    public function update(Request $request, EmailSignatureRenderer $signatures): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'signature_name' => ['nullable', 'string', 'max:120'],
            'signature_body_html' => ['nullable', 'string', 'max:20000'],
            'use_on_compose' => ['nullable', 'boolean'],
            'use_on_reply' => ['nullable', 'boolean'],
            'use_on_reply_all' => ['nullable', 'boolean'],
            'use_on_forward' => ['nullable', 'boolean'],
            'use_default_template' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'name' => $validated['signature_name'] ?? null,
            'use_on_compose' => $request->boolean('use_on_compose'),
            'use_on_reply' => $request->boolean('use_on_reply'),
            'use_on_reply_all' => $request->boolean('use_on_reply_all'),
            'use_on_forward' => $request->boolean('use_on_forward'),
            'use_default_template' => $request->boolean('use_default_template'),
        ];

        if (array_key_exists('signature_body_html', $validated)) {
            $payload['body_html'] = $validated['signature_body_html'];
        }

        $signatures->update($user, $payload);

        return back()->with('success', 'Mail signature updated.');
    }
}
