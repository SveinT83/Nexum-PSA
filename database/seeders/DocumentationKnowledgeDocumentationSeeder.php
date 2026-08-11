<?php

namespace Database\Seeders;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Publishes Documentation domain guidance into the Knowledge workspace.
 */
class DocumentationKnowledgeDocumentationSeeder extends Seeder
{
    public function run(RenderArticleBody $renderer): void
    {
        $book = Book::query()->firstOrCreate(
            ['slug' => 'bookstack-book-nexum-psa-339'],
            [
                'name' => 'Nexum PSA',
                'description' => 'Nexum PSA product documentation.',
                'priority' => 100,
                'source_system' => 'nexum',
                'source_type' => 'product-docs',
                'sync_status' => 'pending',
            ],
        );

        $chapter = Chapter::query()
            ->where('book_id', $book->id)
            ->where('slug', 'documentation')
            ->first() ?: new Chapter(['book_id' => $book->id, 'slug' => 'documentation']);

        $chapter->forceFill([
            'book_id' => $book->id,
            'name' => 'Documentation',
            'slug' => 'documentation',
            'description' => 'Structured documentation, partner registers, and shipping carrier profiles.',
            'priority' => 600,
            'source_system' => $chapter->source_system ?: 'nexum',
            'source_type' => $chapter->source_type ?: 'documentation-docs',
            'source_id' => $chapter->source_id ?: 'documentation',
            'sync_status' => 'pending_push',
        ])->save();

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $definition) {
            $markdown = trim(file_get_contents($definition['path']));
            $article = Article::query()
                ->where('source_system', 'nexum')
                ->where('source_type', 'documentation-docs')
                ->where('source_id', $definition['source_id'])
                ->first()
                ?: Article::query()
                    ->where('knowledge_book_id', $book->id)
                    ->where('knowledge_chapter_id', $chapter->id)
                    ->where('slug', $definition['slug'])
                    ->first()
                ?: new Article;

            if (! $article->exists) {
                $article->created_by = $userId;
            }

            $article->forceFill([
                'title' => $definition['title'],
                'slug' => $definition['slug'],
                'body_markdown' => $markdown,
                'body_html' => $renderer->handle($markdown),
                'visibility' => 'internal',
                'status' => 'published',
                'owner_id' => $userId,
                'knowledge_book_id' => $book->id,
                'knowledge_chapter_id' => $chapter->id,
                'priority' => ($index + 1) * 10,
                'updated_by' => $userId,
                'source_system' => $article->source_system ?: 'nexum',
                'source_type' => $article->source_type ?: 'documentation-docs',
                'source_id' => $article->source_id ?: $definition['source_id'],
                'source_checksum' => sha1($markdown),
                'source_updated_at' => now(),
                'sync_status' => 'pending_push',
                'source_payload' => array_merge($article->source_payload ?? [], [
                    'module' => 'Documentation',
                    'generated_from' => static::class,
                    'source_file' => $definition['path'],
                ]),
            ])->save();
        }
    }

    /** @return array<int, array<string, string>> */
    private function articles(): array
    {
        $basePath = app_path('Modules/Documentation/Docs/knowledge');

        return [
            [
                'title' => 'Documentation Overview',
                'slug' => Str::slug('Documentation Overview'),
                'source_id' => 'documentation-overview',
                'path' => $basePath.'/documentation-overview.md',
            ],
            [
                'title' => 'Shipping Carriers',
                'slug' => Str::slug('Shipping Carriers'),
                'source_id' => 'shipping-carriers',
                'path' => $basePath.'/shipping-carriers.md',
            ],
            [
                'title' => 'Supplier Bootstrap From Purchase Imports',
                'slug' => Str::slug('Supplier Bootstrap From Purchase Imports'),
                'source_id' => 'supplier-bootstrap-from-purchase-imports',
                'path' => $basePath.'/supplier-bootstrap-from-purchase-imports.md',
            ],
        ];
    }
}
