<?php

namespace App\Modules\Contact\Support;

use App\Models\Core\User;
use Illuminate\Support\Facades\DB;

/** Canonical digest includes every authority-relevant identity/scope input, never audit or receipts. */
class ContactApiState
{
    public static function revision(bool $lock = false): string
    {
        $state = [];
        $tables = ['contacts', 'contact_emails', 'contact_phones', 'contact_addresses', 'contact_relations',
            'client_users', 'clients', 'client_sites', 'customer_portal_accounts', 'customer_portal_memberships',
            'customer_portal_invitations'];
        foreach ($tables as $table) {
            $q = DB::table($table)->orderBy('id');
            $state[$table] = ($lock ? $q->lockForUpdate() : $q)->get()->all();
        }
        $settings = DB::table('common_settings')->where('type', 'contact')->orderBy('id');
        $state['settings'] = ($lock ? $settings->lockForUpdate() : $settings)->get()->all();
        // Invitation eligibility also depends on current Contact-to-user identity.
        $users = User::query()->orderBy('id');
        $state['users'] = ($lock ? $users->lockForUpdate() : $users)->get(['id', 'contact_id', 'email', 'status'])->toArray();

        return '"'.hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)).'"';
    }

    /** A preview authorizes only this actor/token, action, target and exact submitted payload. */
    public static function previewTag(\Illuminate\Http\Request $request, ?string $revision = null): string
    {
        $body = $request->all();
        unset($body['dry_run']);
        ksort($body);
        $path = $request->path() === 'api/v1/contacts/preview' ? 'api/v1/contacts' : $request->path();
        $fingerprint = [trim($revision ?? self::revision(), '"'), $request->user()->id,
            ContactApiAccess::tokenHash($request), $request->method(), $path, $body];

        return '"'.hash_hmac('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR), (string) config('app.key')).'"';
    }
}
