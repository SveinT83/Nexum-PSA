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
 * Publishes Ticket domain documentation into the Knowledge module.
 */
class TicketKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'tickets',
            ],
            [
                'name' => 'Tickets',
                'description' => 'Ticket creation, email, workflows, SLA, assignment, merging, time, cost, and settings.',
                'priority' => 400,
                'source_system' => 'nexum',
                'source_type' => 'ticket-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'ticket-docs',
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
                        'module' => 'Ticket',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Ticket/Docs/knowledge');

        return [
            [
                'title' => 'Ticket Overview',
                'slug' => Str::slug('Ticket Overview'),
                'path' => $basePath . '/ticket-overview.md',
            ],
            [
                'title' => 'Ticket Lifecycle And Workflows',
                'slug' => Str::slug('Ticket Lifecycle And Workflows'),
                'path' => $basePath . '/ticket-lifecycle-workflows.md',
            ],
            [
                'title' => 'Ticket Email And Communication',
                'slug' => Str::slug('Ticket Email And Communication'),
                'path' => $basePath . '/ticket-email-communication.md',
            ],
            [
                'title' => 'Ticket Rules And Assignment',
                'slug' => Str::slug('Ticket Rules And Assignment'),
                'path' => $basePath . '/ticket-rules-assignment.md',
            ],
            [
                'title' => 'Ticket SLA',
                'slug' => Str::slug('Ticket SLA'),
                'path' => $basePath . '/ticket-sla.md',
            ],
            [
                'title' => 'Ticket Merge',
                'slug' => Str::slug('Ticket Merge'),
                'path' => $basePath . '/ticket-merge.md',
            ],
            [
                'title' => 'Ticket Time Registration',
                'slug' => Str::slug('Ticket Time Registration'),
                'path' => $basePath . '/time-registration.md',
            ],
            [
                'title' => 'Ticket Storage Cost Reservations',
                'slug' => Str::slug('Ticket Storage Cost Reservations'),
                'path' => $basePath . '/storage-cost-reservations.md',
            ],
            [
                'title' => 'Ticket Admin Settings',
                'slug' => Str::slug('Ticket Admin Settings'),
                'path' => $basePath . '/ticket-admin-settings.md',
            ],
            [
                'title' => 'Ticket Technical Operations',
                'slug' => Str::slug('Ticket Technical Operations'),
                'path' => $basePath . '/ticket-technical-operations.md',
            ],
        ];
    }
}
