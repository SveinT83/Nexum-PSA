<?php

namespace App\Console\Commands;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Throwable;

class BootstrapAdminCommand extends Command
{
    protected $signature = 'nexum:bootstrap-admin';

    protected $description = 'Interactively create the first human Nexum Superuser';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error(
                'Administrator bootstrap requires an interactive local console; no changes were made.',
            );

            return self::FAILURE;
        }

        if (! $this->superuserRoleExists()) {
            $this->error(
                'The Superuser role is not installed. Run the role seeder before administrator bootstrap.',
            );

            return self::FAILURE;
        }

        if ($this->humanSuperuserExists()) {
            $this->error('A human Superuser already exists; no changes were made.');

            return self::FAILURE;
        }

        $name = $this->ask('Administrator name');
        $email = $this->ask('Administrator email');
        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Confirm password');

        $name = is_string($name) ? trim($name) : '';
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        $validator = null;

        try {
            $validator = Validator::make([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'string',
                    'email:rfc',
                    'max:255',
                    Rule::unique(User::class, 'email'),
                ],
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(16)
                        ->letters()
                        ->mixedCase()
                        ->numbers()
                        ->symbols(),
                ],
            ]);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return self::FAILURE;
            }

            try {
                $created = DB::transaction(function () use ($name, $email, &$password): bool {
                    // Lock the role row so two concurrent bootstrap processes
                    // cannot independently conclude that no human Superuser exists.
                    $superuserRole = Role::query()
                        ->where('name', 'Superuser')
                        ->where('guard_name', 'web')
                        ->lockForUpdate()
                        ->first();

                    if ($superuserRole === null) {
                        throw new \RuntimeException('bootstrap_role_missing');
                    }

                    if ($this->humanSuperuserExists()) {
                        return false;
                    }

                    $passwordHash = Hash::make($password);

                    try {
                        $user = new User([
                            'name' => $name,
                            'email' => $email,
                            'password' => $passwordHash,
                            'status' => User::STATUS_ACTIVE,
                            'is_system_actor' => false,
                        ]);
                        $user->email_verified_at = now();
                        $user->saveOrFail();

                        UserProfile::query()->create([
                            'user_id' => $user->id,
                            'timezone' => config('app.timezone', 'UTC'),
                            'working_hours' => UserProfileData::defaultWorkingHours(),
                        ]);

                        $user->assignRole($superuserRole);

                        if (! $user->hasRole($superuserRole)) {
                            throw new \RuntimeException('bootstrap_role_assignment_failed');
                        }
                    } finally {
                        $this->destroyString($passwordHash);
                    }

                    return true;
                }, 3);
            } catch (Throwable) {
                $this->error(
                    'Administrator bootstrap failed safely. No account was created; '
                    .'review the database and role setup before retrying.',
                );

                return self::FAILURE;
            }
        } finally {
            unset($validator);
            $this->destroyString($password);
            $this->destroyString($passwordConfirmation);
        }

        if (! $created) {
            $this->error('A human Superuser already exists; no changes were made.');

            return self::FAILURE;
        }

        $this->info('Initial human Superuser created. No credentials were printed.');

        return self::SUCCESS;
    }

    private function superuserRoleExists(): bool
    {
        return Role::query()
            ->where('name', 'Superuser')
            ->where('guard_name', 'web')
            ->exists();
    }

    private function humanSuperuserExists(): bool
    {
        return User::query()
            ->where('is_system_actor', false)
            ->whereHas('roles', static fn ($query) => $query
                ->where('name', 'Superuser')
                ->where('guard_name', 'web'))
            ->exists();
    }

    private function destroyString(?string &$value): void
    {
        if (! is_string($value)) {
            $value = null;

            return;
        }

        if ($value !== '') {
            sodium_memzero($value);
        }

        $value = null;
    }
}
