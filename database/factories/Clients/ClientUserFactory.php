<?php

namespace Database\Factories\Clients;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    public function definition(): array
    {
        return [
            'client_site_id' => ClientSite::factory(),
            'role' => 'Daglig leder',
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'active' => true,
        ];
    }
}
