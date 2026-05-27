<?php

namespace Database\Factories\Ticket;

use App\Models\Clients\Client;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\Ticket\Models\Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'ticket_key' => 'TK-' . strtoupper(fake()->bothify('##??')),
            'subject' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'channel' => 'web',
        ];
    }
}