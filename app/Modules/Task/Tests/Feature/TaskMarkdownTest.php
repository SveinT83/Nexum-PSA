<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Task\Actions\StoreTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskMarkdownTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function task_detail_formats_markdown_without_rewriting_the_source(): void
    {
        Role::firstOrCreate(['name' => 'Tech']);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('Tech');
        $actor->givePermissionTo(['task.view', 'task.create', 'task.update']);
        $source = "Første linje\nAndre linje\n\n**Viktig**\n\n- Punkt én\n- Punkt to";
        $task = app(StoreTask::class)->handle(['title' => 'Markdown fixture', 'description' => $source], $actor);

        $this->actingAs($actor)->get(route('tech.tasks.show', $task))
            ->assertOk()->assertSee('<strong>Viktig</strong>', false)
            ->assertSee('Første linje<br', false)->assertSee('<li>Punkt én</li>', false);
        $this->assertSame($source, $task->fresh()->description);
        $this->assertStringContainsString('<strong>Viktig</strong>',
            view('task::components.quick-view-modal', ['task' => $task])->render());
        $this->actingAs($actor)->get(route('tech.tasks.edit', $task))->assertOk()
            ->assertSee('**Viktig**')->assertSee('Markdown supported');
        \Laravel\Sanctum\Sanctum::actingAs($actor, ['tasks.read']);
        $this->getJson(route('api.v1.tasks.show', $task))->assertOk()
            ->assertJsonPath('data.description', $source);
    }
}
