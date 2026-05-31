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
 * Publishes Nextcloud integration documentation into the Knowledge module.
 */
class NextcloudKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'nextcloud',
            ],
            [
                'name' => 'Nextcloud',
                'description' => 'Nextcloud connections, users, groups, calendars, folders, Talk bot delivery, and future SSO planning.',
                'priority' => 500,
                'source_system' => 'nexum',
                'source_type' => 'nextcloud-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'nextcloud-docs',
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
                        'module' => 'Nextcloud',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Nextcloud/Docs/knowledge');

        return [
            [
                'title' => 'Nextcloud Overview',
                'slug' => Str::slug('Nextcloud Overview'),
                'path' => $basePath . '/01-overview.md',
            ],
            [
                'title' => 'Nextcloud Admin Setup',
                'slug' => Str::slug('Nextcloud Admin Setup'),
                'path' => $basePath . '/02-admin-setup.md',
            ],
            [
                'title' => 'Nextcloud Users Groups And Calendars',
                'slug' => Str::slug('Nextcloud Users Groups And Calendars'),
                'path' => $basePath . '/03-users-groups-calendars.md',
            ],
            [
                'title' => 'Nextcloud Talk Bot',
                'slug' => Str::slug('Nextcloud Talk Bot'),
                'path' => $basePath . '/05-talk-bot.md',
            ],
            [
                'title' => 'Nextcloud SSO Future Plan',
                'slug' => Str::slug('Nextcloud SSO Future Plan'),
                'path' => $basePath . '/04-sso-future-plan.md',
            ],
        ];
    }
}
