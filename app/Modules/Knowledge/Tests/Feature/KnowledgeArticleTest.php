<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the Knowledge module's route-level article workflow.
 *
 * These tests cover the non-Livewire fallback routes. The Livewire component
 * delegates to the same StoreArticle and UpdateArticle actions, so this gives
 * coverage for the shared persistence path once the test database driver is
 * available.
 */
class KnowledgeArticleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        $this->tech = User::create([
            'name' => 'Knowledge Tech',
            'email' => 'knowledge-tech@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_create_article(): void
    {
        $this->actingAs($this->tech);

        $response = $this->post(route('tech.knowledge.store'), [
            'title' => 'VPN Setup',
            'body_markdown' => '# VPN Setup',
            'visibility' => 'internal',
            'status' => 'published',
            'next_review_at' => now()->addYear()->format('Y-m-d'),
        ]);

        $article = Article::firstOrFail();

        $response->assertRedirect(route('tech.knowledge.show', $article));
        $this->assertSame($this->tech->id, $article->owner_id);
        $this->assertSame($this->tech->id, $article->created_by);
        $this->assertNotEmpty($article->slug);
        $this->assertNotEmpty($article->body_html);
    }

    #[Test]
    public function showing_article_increments_view_count(): void
    {
        $this->actingAs($this->tech);

        $article = Article::create([
            'title' => 'Password Reset',
            'slug' => 'password-reset',
            'body_markdown' => 'Reset steps',
            'body_html' => 'Reset steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
        ]);

        $this->get(route('tech.knowledge.show', $article))->assertOk();

        $this->assertSame(1, $article->fresh()->view_count);
    }
}
