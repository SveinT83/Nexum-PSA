<?php

namespace Database\Factories\Clients;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientSiteFactory extends Factory
{
    protected $model = ClientSite::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Main Site',
            'address' => $this->faker->streetAddress(),
            'zip' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'country' => 'Norway',
            'is_default' => true,
        ];
    }
}
