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
 * Publishes System administration documentation into the Knowledge module.
 */
class SystemKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'system-administration',
            ],
            [
                'name' => 'System Administration',
                'description' => 'Operational setup for platform-level administration.',
                'priority' => 900,
                'source_system' => 'nexum',
                'source_type' => 'system-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $article) {
            $path = app_path('Modules/System/Docs/knowledge/'.$article['file']);
            $markdown = trim(file_get_contents($path));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'system-docs',
                    'source_id' => $article['source_id'],
                ],
                [
                    'title' => $article['title'],
                    'slug' => Str::slug($article['title']),
                    'body_markdown' => $markdown,
                    'body_html' => $renderer->handle($markdown),
                    'visibility' => 'internal',
                    'status' => 'published',
                    'owner_id' => $userId,
                    'knowledge_book_id' => $book->id,
                    'knowledge_chapter_id' => $chapter->id,
                    'priority' => $article['priority'],
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'source_checksum' => sha1($markdown),
                    'source_updated_at' => now(),
                    'sync_status' => 'pending',
                    'source_payload' => [
                        'module' => 'System',
                        'generated_from' => static::class,
                        'source_file' => $path,
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        return [
            [
                'source_id' => 'queues-and-workers',
                'title' => 'Queues And Workers',
                'file' => 'queues-and-workers.md',
                'priority' => 10,
            ],
            [
                'source_id' => 'company-profile-and-branding',
                'title' => 'Company Profile And Branding',
                'file' => 'company-profile-and-branding.md',
                'priority' => 20,
            ],
        ];
    }
}
