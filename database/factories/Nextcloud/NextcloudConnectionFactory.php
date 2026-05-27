<?php

namespace Database\Factories\Nextcloud;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Nextcloud\Models\NextcloudConnection>
 */
class NextcloudConnectionFactory extends Factory
{
    protected $model = NextcloudConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Nextcloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_READ_ONLY,
            'is_active' => true,
            'is_default' => false,
            'base_url' => 'https://nextcloud.' . fake()->domainName(),
            'service_username' => fake()->userName(),
            'service_password' => fake()->password(16),
            'sync_interval_minutes' => 15,
            'root_folder' => '/',
            'documents_folder' => '/Documents',
        ];
    }
}