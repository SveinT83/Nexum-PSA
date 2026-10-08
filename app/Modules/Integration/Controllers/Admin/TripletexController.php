<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use App\Modules\Integration\Support\TripletexAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TripletexController extends Controller
{
    public function __construct(private TripletexAccess $access) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user());

        return view('integration::Tech.Admin.System.Integrations.tripletex.index', [
            'connections' => Integration::where('type', 'tripletex')->orderBy('name')->get(),
            'workers' => \App\Models\Core\User::where('status', \App\Models\Core\User::STATUS_ACTIVE)->where('is_system_actor', false)->orderBy('name')->get(['id', 'name']),
            'syncStates' => \App\Modules\DataExchange\Models\TripletexWorkdaySyncState::where('status', 'attention')->orderByDesc('updated_at')->limit(30)->get(),
        ]);
    }

    public function save(Request $request, ?string $connection = null)
    {
        $this->access->authorize($request->user());
        if (! $connection) {
            return $this->persistConnection($request);
        }

        return \Illuminate\Support\Facades\Cache::lock(\App\Modules\DataExchange\Services\SyncTripletexWorkdays::lockKey($connection), 300)
            ->block(30, fn () => $this->persistConnection($request, $connection));
    }

    private function persistConnection(Request $request, ?string $connection = null)
    {
        $this->access->authorize($request->user());
        $secret = $request->input('refresh_token');
        // Remove before framework validation can flash old input to the session.
        $request->request->remove('refresh_token');
        $request->json()->remove('refresh_token');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'environment' => ['required', 'in:test,production'],
            'company_id' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
        ]);
        if ($secret !== null && $secret !== ''
            && (! is_string($secret) || strlen($secret) < 20 || strlen($secret) > 8000)) {
            return back()->withErrors(['refresh_token' => 'Enter a valid API token.']);
        }
        try {
            DB::transaction(function () use ($connection, $data, $secret, $request) {
                if (! $connection) {
                    abort_if(Integration::where('type', 'tripletex')->exists(), 409,
                        'A Tripletex connection already exists. Edit the existing connection.');
                }
                $row = $connection
                    ? Integration::where('type', 'tripletex')->whereKey($connection)->lockForUpdate()->firstOrFail()
                    : new Integration(['type' => 'tripletex']);
                $config = $row->config ?? [];
                abort_unless((int) ($config['version'] ?? 0) === (int) $data['version'], 409, 'Connection changed. Reload before saving.');
                if (isset($config['verified_company_id'])) {
                    abort_unless((int) $config['company_id'] === (int) $data['company_id']
                        && $config['environment'] === $data['environment'], 409,
                        'A verified connection cannot change company or environment.');
                }
                if (! $row->exists && ! $secret) {
                    abort(422, 'An API token is required for a new connection.');
                }
                $config['customer_sync_enabled'] = false;
                $config['time_sync_enabled'] = false;
                $config['company_id'] = (int) $data['company_id'];
                $config['environment'] = $data['environment'];
                $config['version'] = (int) ($config['version'] ?? 0) + 1;
                if ($secret || ! $row->exists) {
                    $config['write_contract_verified'] = false;
                    unset($config['read_verified_at']);
                }
                $row->fill(['name' => $data['name'], 'status' => 'disabled', 'config' => $config,
                    'is_healthy' => false, 'last_error' => null]);
                if ($secret) {
                    $row->setSecret('refresh_token', $secret);
                }
                $row->save();
                activity()->causedBy($request->user())->event('tripletex.connection.saved')
                    ->withProperties(['connection_id' => $row->id, 'version' => $config['version']])
                    ->log('Tripletex connection settings saved');
            });
        } catch (UniqueConstraintViolationException $e) {
            // A competing first setup can win after the existence check.
            if (! $connection && Integration::where('type', 'tripletex')->exists()) {
                abort(409, 'A Tripletex connection already exists. Edit the existing connection.');
            }
            throw $e;
        }

        return redirect()->route('tech.admin.system.integrations.tripletex.index')
            ->with('success', 'Connection saved. Verify company access before use.');
    }

    public function verify(Request $request, string $connection)
    {
        $this->access->authorize($request->user());
        $row = $this->connection($connection);
        $version = (int) ($row->config['version'] ?? 0);
        try {
            $verified = (new TripletexClient($row))->verifyCompany();
        } catch (TripletexException $e) {
            return back()->withErrors(['connection' => $e->getMessage()]);
        }
        DB::transaction(function () use ($row, $version, $verified, $request) {
            $current = Integration::whereKey($row->id)->lockForUpdate()->firstOrFail();
            $config = $current->config;
            abort_unless((int) $config['version'] === $version, 409, 'Connection changed during verification.');
            $config['verified_company_id'] = $verified['company_id'];
            $config['read_verified_at'] = now()->toIso8601String();
            $config['version']++;
            $current->update(['config' => $config, 'is_healthy' => true, 'last_error' => null]);
            activity()->causedBy($request->user())->event('tripletex.connection.verified')
                ->withProperties(['connection_id' => $row->id, 'company_id' => $verified['company_id']])
                ->log('Tripletex company identity verified');
        });

        return back()->with('success', 'Company identity verified. The time synchronization setting is unchanged.');
    }

    public function candidates(Request $request, string $connection)
    {
        $this->access->authorize($request->user());
        $row = $this->connection($connection);
        $client = new TripletexClient($row);
        try {
            $company = $client->verifyCompany();
            $candidates = ['employees' => $client->employees(), 'activities' => $client->activities(),
                'projects' => $client->projects()];
        } catch (TripletexException $e) {
            return back()->withErrors(['connection' => $e->getMessage()]);
        }

        DB::transaction(function () use ($row, $candidates) {
            $current = Integration::whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless(($current->config['version'] ?? 0) === ($row->config['version'] ?? 0), 409, 'Connection changed. Reload and try again.');
            $config = $current->config;
            $config['time_catalog'] = $candidates;
            $config['version']++;
            $current->update(['config' => $config]);
        });

        return view('integration::Tech.Admin.System.Integrations.tripletex.candidates', [
            'connection' => $row, 'company' => $company, 'candidates' => $candidates,
        ]);
    }

    /** Off and the worker share a lock: after this response no older worker can apply more changes. */
    public function syncSetting(Request $request, string $connection)
    {
        $this->access->authorize($request->user());
        $data = $request->validate(['version' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean']]);
        \Illuminate\Support\Facades\Cache::lock(\App\Modules\DataExchange\Services\SyncTripletexWorkdays::lockKey($connection), 300)
            ->block(30, function () use ($request, $connection, $data) {
                DB::transaction(function () use ($request, $connection, $data) {
                    $row = Integration::where('type', 'tripletex')->whereKey($connection)->lockForUpdate()->firstOrFail();
                    $config = $row->config;
                    abort_unless((int) $config['version'] === (int) $data['version'], 409, 'Settings changed. Reload before changing synchronization.');
                    if ($data['enabled']) {
                        abort_unless(config('tripletex.enabled') && config('tripletex.writes_enabled')
                            && app(\App\Modules\Workday\Support\WorkdaySettings::class)->enabled()
                            && ! empty($config['read_verified_at']) && ($config['write_contract_verified'] ?? false)
                            && ! empty($config['time_mappings']), 422, 'Verify the connection, employee mapping and runtime before enabling synchronization.');
                    }
                    $config['version']++;
                    $config['time_sync_enabled'] = (bool) $data['enabled'];
                    $row->update(['status' => ($data['enabled'] || ($config['customer_sync_enabled'] ?? false)) ? 'active' : 'disabled', 'config' => $config]);
                    activity()->causedBy($request->user())->event('tripletex.time_sync.changed')
                        ->withProperties(['connection_id' => $row->id, 'enabled' => (bool) $data['enabled'], 'version' => $config['version']])
                        ->log('Tripletex time synchronization setting changed');
                });
            });

        return back()->with('success', $data['enabled'] ? 'Time synchronization enabled.' : 'Time synchronization paused. Saved time and pending changes are kept.');
    }

    public function mapping(Request $request, string $connection)
    {
        $this->access->authorize($request->user());
        $data = $request->validate(['version' => ['required', 'integer'], 'user_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'], 'activity_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        DB::transaction(function () use ($request, $connection, $data) {
            $row = Integration::where('type', 'tripletex')->whereKey($connection)->lockForUpdate()->firstOrFail();
            $config = $row->config;
            abort_unless((int) $config['version'] === (int) $data['version'], 409, 'Settings changed. Reload before saving.');
            abort_unless($row->status !== 'active' || ! ($config['time_sync_enabled'] ?? true), 409, 'Pause time synchronization before changing mappings.');
            $user = \App\Models\Core\User::findOrFail($data['user_id']);
            abort_unless($user->status === \App\Models\Core\User::STATUS_ACTIVE && ! $user->isSystemActor(), 422, 'Select an active employee.');
            abort_unless(in_array((int) $data['employee_id'], array_column($config['time_catalog']['employees'] ?? [], 'id'), true)
                && in_array((int) $data['activity_id'], array_column($config['time_catalog']['activities'] ?? [], 'id'), true), 422, 'Read employees and activities before choosing a mapping.');
            foreach ($config['time_mappings'] ?? [] as $id => $mapping) {
                abort_if((int) $id !== (int) $data['user_id'] && (int) $mapping['employee_id'] === (int) $data['employee_id'], 422, 'This Tripletex employee is already mapped.');
            }
            abort_if(\App\Modules\DataExchange\Models\TripletexWorkdaySyncState::where('connection_id', $row->id)->where('user_id', $user->id)->exists(),
                409, 'An established mapping cannot be reassigned. Its synchronization history must be preserved.');
            $config['time_mappings'][(string) $user->id] = ['employee_id' => (int) $data['employee_id'],
                'activity_id' => (int) $data['activity_id'], 'start_date' => $data['start_date'],
                'timezone' => app(\App\Modules\UserManagement\Actions\UserWorkPlan::class)->read($user)['timezone']];
            $config['version']++;
            $row->update(['config' => $config]);
            activity()->causedBy($request->user())->event('tripletex.time_mapping.saved')
                ->withProperties(['connection_id' => $row->id, 'user_id' => $user->id, 'employee_id' => (int) $data['employee_id']])
                ->log('Tripletex employee mapping saved');
        });

        return back()->with('success', 'Employee mapping saved. Synchronization remains paused.');
    }

    private function connection(string $id): Integration
    {
        return Integration::where('type', 'tripletex')->whereKey($id)->firstOrFail();
    }
}
