<?php

namespace App\Modules\CustomField\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Actions\SyncRepositoryKnowledgeDocs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The generic source publisher must include CustomField, not leave its handbook orphaned. */
class CustomFieldKnowledgeSourceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customfield_sources_publish_exact_internal_articles_and_repeat_without_duplicates(): void
    {
        User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $sync = app(SyncRepositoryKnowledgeDocs::class);
        $summary = $sync->handle(['CustomField']);
        $this->assertSame(['chapters' => 1, 'articles' => 2, 'skipped' => 0, 'modules' => ['CustomField']], $summary);
        foreach (['custom-field-api', 'custom-fields-core'] as $slug) {
            $article = Article::where('source_type', 'repository-docs')->where('source_id', 'custom-fields/'.$slug)->firstOrFail();
            $this->assertSame('internal', $article->visibility);
            $this->assertSame(trim(file_get_contents(app_path('Modules/CustomField/Docs/knowledge/'.$slug.'.md'))), trim($article->body_markdown));
            $this->assertNotNull($article->published_revision_id);
        }
        $sync->handle(['CustomField']);
        $this->assertSame(2, Article::where('source_type', 'repository-docs')->where('source_id', 'like', 'custom-fields/%')->count());
    }
}
