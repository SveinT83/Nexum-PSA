<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Actions\SendUserInvite;
use App\Modules\UserManagement\Actions\StoreUser;
use App\Modules\UserManagement\Actions\UpdateUserProfile;
use App\Modules\UserManagement\Actions\UpdateUserStatus;
use App\Modules\UserManagement\Resources\Api\V1\UserResource;
use App\Modules\UserManagement\Resources\Api\V1\UserRoleResource;
use App\Modules\UserManagement\Models\UserProfile;
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
    /*
    |--------------------------------------------------------------------------
    | User Management API
    |--------------------------------------------------------------------------
    |
    | This controller reuses the same actions as the admin UI. User deletion is
    | intentionally not exposed; account lifecycle is managed through status.
    |
    */

    #[OA\Get(path: '/api/v1/users', operationId: 'getUsers', summary: 'List users', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'role', in: 'query', schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'Paginated user list')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'role' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['roles', 'profile', 'preferences'])
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

    #[OA\Get(path: '/api/v1/users/roles', operationId: 'getUserRoles', summary: 'List roles for user assignment', security: [['bearerAuth' => []]], tags: ['Users'], responses: [new OA\Response(response: 200, description: 'Role list')])]
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

    #[OA\Post(path: '/api/v1/users', operationId: 'createUser', summary: 'Create a user', security: [['bearerAuth' => []]], tags: ['Users'], responses: [new OA\Response(response: 201, description: 'User created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(Request $request, StoreUser $storeUser): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:'.(new User())->getTable().',email'],
            'status' => ['nullable', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $roleIds = collect($validated['role_ids'] ?? [])
            ->when(isset($validated['role_id']), fn ($roles) => $roles->push($validated['role_id']))
            ->unique()
            ->values();

        $user = $storeUser->handle([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'status' => $validated['status'] ?? User::STATUS_PENDING,
            'role' => $roleIds->first(),
        ]);

        if ($roleIds->count() > 1) {
            $user->syncRoles(Role::query()->whereIn('id', $roleIds)->get());
        }

        return UserResource::make($this->loadUser($user))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/users/{user}', operationId: 'getUser', summary: 'View a user', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'User')])]
    public function show(User $user): UserResource
    {
        return UserResource::make($this->loadUser($user));
    }

    #[OA\Patch(path: '/api/v1/users/{user}', operationId: 'updateUser', summary: 'Update a user profile', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'User updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(Request $request, User $user, UpdateUserProfile $updateUserProfile): UserResource
    {
        $profile = $this->profileFor($user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique((new User())->getTable(), 'email')->ignore($user->id)],
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

        $payload['remove_avatar'] = $request->boolean('remove_avatar');

        $updateUserProfile->handle($user, $payload);

        return UserResource::make($this->loadUser($user->refresh()));
    }

    #[OA\Post(path: '/api/v1/users/{user}/status', operationId: 'updateUserStatus', summary: 'Update user status', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'User status updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateStatus(Request $request, User $user, UpdateUserStatus $updateUserStatus): UserResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([User::STATUS_PENDING, User::STATUS_ACTIVE, User::STATUS_DISABLED])],
        ]);

        $updateUserStatus->handle($user, $validated['status']);

        return UserResource::make($this->loadUser($user->refresh()));
    }

    #[OA\Post(path: '/api/v1/users/{user}/roles', operationId: 'updateUserRoles', summary: 'Replace user roles', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'User roles updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateRoles(Request $request, User $user): UserResource
    {
        $validated = $request->validate([
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $roles = Role::query()
            ->whereIn('id', $validated['role_ids'] ?? [])
            ->get();

        $user->syncRoles($roles);

        return UserResource::make($this->loadUser($user->refresh()));
    }

    #[OA\Post(path: '/api/v1/users/{user}/invite', operationId: 'sendUserInvite', summary: 'Send or resend a user invite', security: [['bearerAuth' => []]], tags: ['Users'], parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Invite queued'), new OA\Response(response: 422, description: 'Only pending users can receive invites')])]
    public function sendInvite(User $user, SendUserInvite $sendUserInvite): JsonResponse
    {
        abort_unless($user->isPending(), 422, 'Only pending users can receive invitations.');

        $sendUserInvite->handle($user);

        return response()->json([
            'data' => [
                'message' => "Invitation sent to {$user->email}.",
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

    private function profileFor(User $user): UserProfile
    {
        $profile = UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $user->phone_work,
                'private_phone' => $user->phone_private,
                'timezone' => config('app.timezone', 'UTC'),
                'working_hours' => UserProfileData::defaultWorkingHours(),
            ]
        );

        if (empty($profile->working_hours)) {
            $profile->forceFill(['working_hours' => UserProfileData::defaultWorkingHours()])->save();
        }

        return $profile;
    }
}
