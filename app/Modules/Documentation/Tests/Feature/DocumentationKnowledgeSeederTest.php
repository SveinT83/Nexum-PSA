<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use Database\Seeders\DocumentationKnowledgeDocumentationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentationKnowledgeSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_publishes_supplier_bootstrap_guidance_idempotently(): void
    {
        User::factory()->create();
        $this->seed(DocumentationKnowledgeDocumentationSeeder::class);
        $this->seed(DocumentationKnowledgeDocumentationSeeder::class);

        $article = Article::query()
            ->where('source_system', 'nexum')
            ->where('source_type', 'documentation-docs')
            ->where('source_id', 'supplier-bootstrap-from-purchase-imports')
            ->firstOrFail();

        $this->assertSame('Supplier Bootstrap From Purchase Imports', $article->title);
        $this->assertSame('supplier-bootstrap-from-purchase-imports', $article->slug);
        $this->assertSame('published', $article->status);
        $this->assertStringContainsString('Existing only', $article->body_markdown);
        $this->assertStringContainsString('Create active', $article->body_markdown);
        $this->assertStringContainsString('does not create Vendor, Item, Purchase Order', $article->body_markdown);
        $this->assertFalse(str_starts_with(ltrim($article->body_markdown), '#'));
        $this->assertSame(
            1,
            Article::query()
                ->where('source_system', 'nexum')
                ->where('source_type', 'documentation-docs')
                ->where('source_id', 'supplier-bootstrap-from-purchase-imports')
                ->count(),
        );
    }
}
