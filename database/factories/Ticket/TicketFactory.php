<?php

namespace Database\Factories\Ticket;

use App\Modules\Ticket\Actions\EnsureTicketDefaults;
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
        $defaults = app(EnsureTicketDefaults::class)->handle();

        return [
            'ticket_key' => 'TK-' . strtoupper(fake()->bothify('##??')),
            'ticket_type_id' => $defaults['type']->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'subject' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'channel' => 'web',
        ];
    }
}
