<?php

namespace App\Modules\UserManagement\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** One reader and writer for the existing role-based MFA enforcement settings. */
class UserTwoFactorPolicy
{
    public function read(): array
    {
        $enabled = DB::table('common_settings')->where('name', 'enforce_two_factor')->value('value');
        $roles = DB::table('common_settings')->where('name', 'enforce_two_factor_roles')->value('json');

        return ['enforce_two_factor' => in_array($enabled, ['1', 'true'], true),
            'enforce_two_factor_roles' => json_decode($roles ?: '[]', true) ?: [],
            'roles' => Role::where('guard_name', 'web')->orderBy('name')->pluck('name')->all()];
    }

    public function update(Request $request): array
    {
        $data = $request->validate(['enforce_two_factor' => ['nullable', 'boolean'],
            'enforce_two_factor_roles' => ['nullable', 'array'],
            'enforce_two_factor_roles.*' => ['string', 'exists:roles,name']]);
        DB::transaction(function () use ($data): void {
            DB::table('common_settings')->updateOrInsert(['name' => 'enforce_two_factor'],
                ['type' => 'security', 'description' => 'Require two-factor authentication for selected roles.',
                    'value' => ! empty($data['enforce_two_factor']) ? '1' : '0', 'json' => null]);
            DB::table('common_settings')->updateOrInsert(['name' => 'enforce_two_factor_roles'],
                ['type' => 'security', 'description' => 'Role names that must use two-factor authentication when enforcement is enabled.',
                    'value' => null, 'json' => json_encode($data['enforce_two_factor_roles'] ?? [])]);
        });

        return $this->read();
    }
}
