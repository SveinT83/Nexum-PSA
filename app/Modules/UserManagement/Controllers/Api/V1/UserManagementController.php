<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Actions\SendUserInvite;
use App\Modules\UserManagement\Actions\StoreUser;
use App\Modules\UserManagement\Actions\SyncUserRoles;
use App\Modules\UserManagement\Actions\UpdateUserProfile;
use App\Modules\UserManagement\Actions\UpdateUserStatus;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Resources\Api\V1\UserResource;
use App\Modules\UserManagement\Resources\Api\V1\UserRoleResource;
use App\Modules\UserManagement\Security\Support\UserSecurityAuthCutover;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Spatie\Permission\Models\Role;

#[OA\Tag(
    name: 'Users',
    description: 'API endpoints for user lifecycle, profiles, invites, and role assignment.'
)]
class UserManagementController extends Controller
{
    public function __construct(private readonly UserSecurityAuthCutover $securityCutover) {}

    /*
    |--------------------------------------------------------------------------
    | User Management API
    |--------------------------------------------------------------------------
    |
    | This controller reuses the same actions as the admin UI. User deletion is
    | intentionally not exposed; account lifecycle is managed through status.
    |
    */

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'role' => ['nullable', 'string', 'max:255', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'profile', 'preferences'])
            ->where('is_system_actor', false)
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->when($validated['q'] ?? null, function ($query, string $search): void {
                $needle = '%'.trim($search).'%';
                $query->where(function ($query) use ($needle): void {
                    $query->where('name', 'like', $needle)
                        ->orWhere('email', 'like', $needle)
                        ->orWhere('phone_work', 'like', $needle)
                        ->orWhere('phone_private', 'like', $needle);
                });
            })
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function roles(): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('permissions')
            ->select('roles.*')
            ->selectSub(
                DB::table('model_has_roles')
                    ->selectRaw('count(*)')
                    ->whereColumn('model_has_roles.role_id', 'roles.id'),
                'users_count'
            )
            ->orderBy('name')
            ->get();

        return UserRoleResource::collection($roles);
    }

    public function store(Request $request, StoreUser $storeUser): JsonResponse
    {
        $this->denyProtectedBearerMutationAfterCutover();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:'.(new User)->getTable().',email'],
            'status' => ['nullable', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'role_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'distinct', \Illuminate\Validation\Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);

        $roleIds = collect($validated['role_ids'] ?? [])
            ->when(isset($validated['role_id']), fn ($roles) => $roles->push($validated['role_id']))
            ->unique()
            ->values();

        if ($roleIds->isNotEmpty()) {
            \App\Modules\UserManagement\Support\UserManagementApiAccess::authorize($request->user(), ['user.manage_roles']);
        }

        $user = $storeUser->handle([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => $validated['status'] ?? User::STATUS_PENDING,
            'role' => $roleIds->first(),
        ]);

        if ($roleIds->count() > 1) {
            app(SyncUserRoles::class)->handle($user, Role::query()->whereIn('id', $roleIds)->get());
        }

        return UserResource::make($this->loadUser($user))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        $this->ensureHumanUser($user);
        $this->profileFor($user);

        return UserResource::make($this->loadUser($user));
    }

    public function update(Request $request, User $user, UpdateUserProfile $updateUserProfile): UserResource
    {
        $this->denyProtectedBearerMutationAfterCutover();

        $this->ensureHumanUser($user);
        $profile = $this->profileFor($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique((new User)->getTable(), 'email')->ignore($user->id)],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'work_phone' => ['nullable', 'string', 'max:50'],
            'private_phone' => ['nullable', 'string', 'max:50'],
            'phone_work' => ['nullable', 'string', 'max:50'],
            'phone_private' => ['nullable', 'string', 'max:50'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*.enabled' => ['nullable', 'boolean'],
            'working_hours.*.start' => ['nullable', 'date_format:H:i'],
            'working_hours.*.end' => ['nullable', 'date_format:H:i'],
            'availability_notes' => ['nullable', 'string', 'max:5000'],
            'profile_notes' => ['nullable', 'string', 'max:5000'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $payload = array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone_work' => $user->phone_work,
            'phone_private' => $user->phone_private,
            'timezone' => $profile->timezone ?: config('app.timezone', 'UTC'),
            'working_hours' => $profile->working_hours ?: UserProfileData::defaultWorkingHours(),
            'availability_notes' => $profile->availability_notes,
            'profile_notes' => $profile->profile_notes,
            'remove_avatar' => false,
        ], $validated);

        foreach (['work_phone' => 'phone_work', 'private_phone' => 'phone_private'] as $canonical => $alias) {
            if (array_key_exists($canonical, $validated)) {
                $payload[$alias] = $validated[$canonical];
            }
        }

        $payload['remove_avatar'] = $request->boolean('remove_avatar');

        $updateUserProfile->handle($user, $payload, $request->file('avatar'));

        return UserResource::make($this->loadUser($user->refresh()));
    }

    public function updateStatus(Request $request, User $user, UpdateUserStatus $updateUserStatus): UserResource
    {
        $this->denyProtectedBearerMutationAfterCutover();

        $this->ensureHumanUser($user);
        $validated = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        $updateUserStatus->handle($user, $validated['status']);

        return UserResource::make($this->loadUser($user->refresh()));
    }

    public function updateRoles(Request $request, User $user, SyncUserRoles $syncUserRoles): UserResource
    {
        $this->denyProtectedBearerMutationAfterCutover();

        $this->ensureHumanUser($user);
        $validated = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'distinct', \Illuminate\Validation\Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);

        $roles = Role::query()
            ->whereIn('id', $validated['role_ids'] ?? [])
            ->get();

        $syncUserRoles->handle($user, $roles);

        return UserResource::make($this->loadUser($user->refresh()));
    }

    public function sendInvite(User $user, SendUserInvite $sendUserInvite): JsonResponse
    {
        $this->ensureHumanUser($user);
        abort_unless($user->isPending(), 422, 'Only pending users can receive invitations.');

        $sendUserInvite->handle($user);

        return response()->json([
            'data' => [
                'message' => "Invitation sent to {$user->email}.",
                'delivery_status' => 'queued',
                'user' => UserResource::make($this->loadUser($user->refresh())),
            ],
        ]);
    }

    private function loadUser(User $user): User
    {
        return $user->load([
            'roles',
            'permissions',
            'profile',
            'preferences',
            'inviteTokens' => fn ($query) => $query->latest(),
        ]);
    }

    private function denyProtectedBearerMutationAfterCutover(): void
    {
        if ($this->securityCutover->isEnforced()) {
            if (! request()->hasSession() || request()->bearerToken() || ! \Illuminate\Support\Facades\Auth::guard('web')->check()) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => 'protected_mutation_denied'], 403));
            }
            \App\Modules\UserManagement\Support\UserManagementApiAccess::session(request());
            // A real first-party session still executes the existing protected owner action.
            // Bearer requests can never manufacture its session-bound execution context.
        }
    }

    private function ensureHumanUser(User $user): void
    {
        abort_if($user->isSystemActor(), 404);
    }

    private function profileFor(User $user): UserProfile
    {
        return app(\App\Modules\UserManagement\Actions\EnsureUserProfile::class)->handle($user);
    }
}
