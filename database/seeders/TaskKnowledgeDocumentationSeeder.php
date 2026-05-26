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
 * Publishes Task domain documentation into the Knowledge module.
 */
class TaskKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'tasks',
            ],
            [
                'name' => 'Tasks',
                'description' => 'Shared internal task management, templates, dependencies, and time tracking.',
                'priority' => 450,
                'source_system' => 'nexum',
                'source_type' => 'task-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'task-docs',
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
                        'module' => 'Task',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Task/Docs/knowledge');

        return [
            [
                'title' => 'Task Overview',
                'slug' => Str::slug('Task Overview'),
                'path' => $basePath . '/task-overview.md',
            ],
            [
                'title' => 'Task Fields',
                'slug' => Str::slug('Task Fields'),
                'path' => $basePath . '/task-fields.md',
            ],
            [
                'title' => 'Task Dependencies',
                'slug' => Str::slug('Task Dependencies'),
                'path' => $basePath . '/task-dependencies.md',
            ],
            [
                'title' => 'Task Templates',
                'slug' => Str::slug('Task Templates'),
                'path' => $basePath . '/task-templates.md',
            ],
            [
                'title' => 'Task Time And Activity',
                'slug' => Str::slug('Task Time And Activity'),
                'path' => $basePath . '/task-time-and-activity.md',
            ],
        ];
    }
}
