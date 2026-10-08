<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Services\TripletexCustomerLinks;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use App\Modules\Integration\Support\TripletexAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Admin settings and explicit review surfaces; never auto-match or bulk-renumber Clients. */
class TripletexCustomerController extends Controller
{
    private function authorize(Request $request): void
    {
        app(TripletexAccess::class)->authorize($request->user());
        abort_unless($request->user()->can('client.update') && $request->user()->can('client.view'), 403);
    }

    public function index(Request $request, string $connection)
    {
        $this->authorize($request);
        $row = Integration::where('type', 'tripletex')->findOrFail($connection);
        $review = null;
        if ($request->filled('client_id') && $request->filled('customer_id')) {
            $data = $request->validate(['client_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1']]);
            $client = Client::findOrFail($data['client_id']);
            try {
                $remote = (new TripletexClient($row))->customer($data['customer_id']);
                $review = ['client' => $client, 'remote' => $remote];
            } catch (TripletexException $e) {
                throw ValidationException::withMessages(['connection' => $e->getMessage()]);
            }
        }

        return view('integration::Tech.Admin.System.Integrations.tripletex.customers', [
            'connection' => $row, 'review' => $review,
            'reviewLink' => $review ? TripletexCustomerLink::where('connection_id', $row->id)
                ->where('client_id', $review['client']->id)->where('customer_id', $review['remote']['id'])->first() : null,
            'profiles' => \Illuminate\Support\Facades\Schema::hasTable('tripletex_customer_profiles')
                ? \App\Modules\DataExchange\Models\TripletexCustomerProfile::whereIn('link_id',
                    TripletexCustomerLink::where('connection_id', $row->id)->select('id'))->get()->keyBy('link_id') : collect(),
            'links' => TripletexCustomerLink::where('connection_id', $row->id)->orderByDesc('id')->paginate(30),
        ]);
    }

    public function setting(Request $request, string $connection)
    {
        app(TripletexAccess::class)->authorize($request->user());
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']]);
        try {
            Cache::lock(TripletexCustomerNumbers::lockKey($connection), 600)->block(30, function () use ($connection, $data, $request) {
                $row = Integration::where('type', 'tripletex')->findOrFail($connection);
                // Expected setup/conflict failures belong on the settings form, including stale tabs.
                if (($row->config['version'] ?? 0) !== (int) $data['version']) {
                    throw ValidationException::withMessages(['connection' => 'Settings changed. Reload before saving.'])->status(409);
                }
                if ($data['enabled']) {
                    if (! config('tripletex.enabled')) {
                        throw ValidationException::withMessages(['connection' => 'Customer synchronization is unavailable on this server. Ask the server administrator to enable customer synchronization before turning it on here.']);
                    }
                    app(TripletexCustomerNumbers::class)->ready($row);
                    $api = new TripletexClient($row);
                    $api->verifyCompany();
                    $customers = collect($api->customers())->keyBy('id');
                    $api->supplierNumbers();
                    foreach (TripletexCustomerLink::where('connection_id', $row->id)->whereNotNull('client_id')->get() as $link) {
                        $remote = $customers->get($link->customer_id);
                        $local = Client::find($link->client_id);
                        if (! ($link->company_id === (int) $row->config['company_id'] && $link->environment === $row->config['environment']
                            && $remote && $local && ! $remote['isInactive']
                            && (string) $remote['customerNumber'] === (string) $local->client_number)) {
                            throw ValidationException::withMessages(['connection' => 'An existing customer link differs from Tripletex. Review its number before enabling synchronization.']);
                        }
                    }
                }
                DB::transaction(function () use ($row, $data, $request) {
                    $current = Integration::whereKey($row->id)->lockForUpdate()->firstOrFail();
                    $config = $current->config;
                    if (($config['version'] ?? 0) !== (int) $data['version']) {
                        throw ValidationException::withMessages(['connection' => 'Settings changed. Reload before saving.'])->status(409);
                    }
                    $config['time_sync_enabled'] ??= $current->status === 'active';
                    $config['customer_sync_enabled'] = (bool) $data['enabled'];
                    $config['version']++;
                    $current->update(['config' => $config,
                        'status' => ($config['time_sync_enabled'] || $config['customer_sync_enabled']) ? 'active' : 'disabled']);
                    activity()->causedBy($request->user())->event('tripletex.customer_sync.changed')
                        ->withProperties(['connection_id' => $current->id, 'enabled' => (bool) $data['enabled']])
                        ->log('Tripletex customer-number synchronization changed');
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages(['connection' => 'Tripletex synchronization is busy. Please try again.']);
        } catch (TripletexException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }

        return back()->with('success', $data['enabled'] ? 'Customer synchronization enabled.' : 'Customer synchronization paused. Existing numbers and links are preserved.');
    }

    public function adopt(Request $request, string $connection, TripletexCustomerLinks $links)
    {
        $this->authorize($request);
        $data = $request->validate(['client_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'expected_local' => ['present', 'nullable', 'string', 'max:255'], 'expected_remote' => ['required', 'string', 'regex:/^[1-9][0-9]{0,9}$/'],
            'accept_change' => ['sometimes', 'boolean']]);
        try {
            $links->adopt(Integration::where('type', 'tripletex')->findOrFail($connection), Client::findOrFail($data['client_id']),
                $data['customer_id'], (string) $data['expected_local'], $data['expected_remote'], $request->boolean('accept_change'));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages(['connection' => 'Tripletex synchronization is busy. Please try again.']);
        } catch (TripletexException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }

        return back()->with('success', 'Customer link and Tripletex number verified and saved.');
    }

    public function recover(Request $request, string $connection, TripletexCustomerLinks $links)
    {
        $this->authorize($request);
        $data = $request->validate(['attempt_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'expected_number' => ['required', 'string'], 'confirm_identity' => ['accepted']]);
        try {
            $links->recover(Integration::where('type', 'tripletex')->findOrFail($connection), $data['attempt_id'], $data['customer_id'], $data['expected_number']);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages(['connection' => 'Tripletex synchronization is busy. Please try again.']);
        } catch (TripletexException $e) {
            throw ValidationException::withMessages(['connection' => $e->getMessage()]);
        }

        return back()->with('success', 'Provider identity recorded. Resubmit the original Client form to finish; no new provider customer will be created.');
    }

    /** Detail is fetched only after explicit selection, never in the broad customer search list. */
    public function profile(Request $request, int $customer)
    {
        abort_unless($request->user()?->isActive() && ! $request->user()->isSystemActor()
            && $request->user()->can('client.create') && $request->user()->can('client.view'), 403);
        $connection = app(TripletexCustomerNumbers::class)->connection();
        abort_unless($connection, 409, 'Customer synchronization is paused.');
        try {
            $api = new TripletexClient($connection);
            $row = $api->customerProfile($customer);
            app(TripletexCustomerNumbers::class)->assertUsable($row);
            $values = app(\App\Modules\Integration\Services\Tripletex\CustomerProfileData::class)->prefill($row);
            if ($request->user()->can('contact.view') && ($values['user_email'] !== '' || $values['user_phone'] !== '')) {
                try {
                    $phone = fn ($value) => preg_replace('/\D/', '', (string) $value);
                    $matches = collect($api->customerContactHints($customer))->filter(fn ($contact) => data_get($contact, 'customer.id') === $customer && ! ($contact['isInactive'] ?? true)
                        && (($values['user_email'] !== '' && strcasecmp($contact['email'] ?? '', $values['user_email']) === 0)
                            || ($phone($values['user_phone']) !== '' && in_array($phone($values['user_phone']),
                                [$phone($contact['phoneNumberMobile'] ?? ''), $phone($contact['phoneNumberWork'] ?? '')], true))));
                    if ($matches->count() === 1) {
                        $contact = $matches->first();
                        $values['user_name'] = trim(($contact['firstName'] ?? '').' '.($contact['lastName'] ?? ''));
                    }
                } catch (TripletexException) {
                    // Optional contact permission/provider failure must not block billing/address prefill.
                }
            }

            return response()->json(['data' => $values])->header('Cache-Control', 'private, no-store');
        } catch (TripletexException $e) {
            return response()->json(['message' => $e->getMessage()], 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function syncProfile(Request $request, string $connection, int $link)
    {
        $this->authorize($request);
        $row = TripletexCustomerLink::where('connection_id', $connection)->findOrFail($link);
        if (app(TripletexCustomerNumbers::class)->connection()?->id !== $connection) {
            throw ValidationException::withMessages(['connection' => 'Customer synchronization is paused.']);
        }
        $result = app(\App\Modules\DataExchange\Services\SyncTripletexCustomerProfiles::class)->sync($row->id);
        $message = match ($result) {
            'synced' => 'Billing Email and Site address synchronized.',
            'busy' => 'Tripletex synchronization is busy. Please try again.',
            'paused' => 'Customer synchronization is paused.',
            default => 'Customer profile is pending or needs attention. Review its status below.',
        };

        return back()->with($result === 'synced' ? 'success' : 'profile_notice', $message);
    }

    public function bindProfileSite(Request $request, string $connection, int $link)
    {
        $this->authorize($request);
        $data = $request->validate(['site_id' => ['required', 'integer'], 'expected_site_id' => ['nullable', 'integer'],
            'confirm_site' => ['accepted']]);
        try {
            Cache::lock(TripletexCustomerNumbers::lockKey($connection), 600)->block(2, function () use ($connection, $link, $data) {
                $row = TripletexCustomerLink::where('connection_id', $connection)->whereNotNull('client_id')->findOrFail($link);
                $site = \App\Models\Clients\ClientSite::where('client_id', $row->client_id)->findOrFail($data['site_id']);
                $state = \App\Modules\DataExchange\Models\TripletexCustomerProfile::firstOrCreate(['link_id' => $row->id]);
                if ($state->pending || (int) $state->site_id !== (int) ($data['expected_site_id'] ?? 0)) {
                    throw ValidationException::withMessages(['connection' => 'The Site binding changed or has an unresolved write. Reload and reconcile before rebinding.']);
                }
                $state->update(['site_id' => $site->id, 'baseline' => null, 'status' => 'pending', 'error_code' => null]);
                activity()->causedBy(auth()->user())->event('tripletex.customer_profile.site_bound')
                    ->withProperties(['link_id' => $row->id, 'site_id' => $site->id])->log('Explicit customer profile Site binding');
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw ValidationException::withMessages(['connection' => 'Tripletex synchronization is busy. Please try again.']);
        }

        return back()->with('success', 'Site selected. The next synchronization starts from Tripletex values.');
    }

    public function lookup(Request $request)
    {
        abort_unless($request->user()?->isActive() && ! $request->user()->isSystemActor()
            && $request->user()->can('client.create') && $request->user()->can('client.view'), 403);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $row = app(TripletexCustomerNumbers::class)->connection();
        abort_unless($row, 409, 'Customer synchronization is paused.');
        try {
            $customers = (new TripletexClient($row))->customers();
            $query = mb_strtolower(trim($data['q']));
            $matches = collect($customers)->filter(fn ($c) => ($c['isInactive'] ?? true) === false
                && (str_contains(mb_strtolower($c['name'] ?? ''), $query)
                    || str_contains((string) ($c['customerNumber'] ?? ''), $query)
                    || str_contains((string) ($c['organizationNumber'] ?? ''), $query)))
                ->take(20)->map(fn ($c) => \Illuminate\Support\Arr::only($c, ['id', 'name', 'customerNumber', 'organizationNumber']))->values();

            return response()->json(['data' => $matches])->header('Cache-Control', 'private, no-store');
        } catch (TripletexException $e) {
            return response()->json(['message' => $e->getMessage()], 503)->header('Cache-Control', 'private, no-store');
        }
    }
}
