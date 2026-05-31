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
 * Publishes Economy domain documentation into the Knowledge module.
 */
class EconomyKnowledgeDocumentationSeeder extends Seeder
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

        $chapter = Chapter::query()->updateOrCreate(
            [
                'book_id' => $book->id,
                'slug' => 'economy',
            ],
            [
                'name' => 'Economy',
                'description' => 'Internal order generation, source-domain billing preparation, and economy settings.',
                'priority' => 640,
                'source_system' => 'nexum',
                'source_type' => 'economy-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'economy-docs',
                    'source_id' => $article['slug'],
                ],
                [
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'body_markdown' => $markdown,
                    'body_html' => $renderer->handle($markdown),
                    'visibility' => 'internal',
                    'status' => 'published',
                    'owner_id' => $userId,
                    'knowledge_book_id' => $book->id,
                    'knowledge_chapter_id' => $chapter->id,
                    'priority' => ($index + 1) * 10,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'source_checksum' => sha1($markdown),
                    'source_updated_at' => now(),
                    'sync_status' => 'pending',
                    'source_payload' => [
                        'module' => 'Economy',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Economy/Docs/knowledge');

        return [
            [
                'title' => 'Economy Orders',
                'slug' => Str::slug('Economy Orders'),
                'path' => $basePath . '/economy-orders.md',
            ],
            [
                'title' => 'Economy Settings',
                'slug' => Str::slug('Economy Settings'),
                'path' => $basePath . '/economy-settings.md',
            ],
            [
                'title' => 'Economy Orders Plan',
                'slug' => Str::slug('Economy Orders Plan'),
                'path' => $basePath . '/economy-orders-plan.md',
            ],
        ];
    }
}
