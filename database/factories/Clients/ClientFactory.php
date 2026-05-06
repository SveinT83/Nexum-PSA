<?php

namespace Database\Factories\Clients;

use App\Models\Clients\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'client_number' => $this->faker->unique()->randomNumber(5),
            'org_no' => $this->faker->unique()->numerify('#########'),
            'billing_email' => $this->faker->companyEmail(),
            'notes' => $this->faker->paragraph(),
            'active' => true,
        ];
    }
}
