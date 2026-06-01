<?php

namespace Database\Seeders;

use App\Models\Core\User;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use App\Modules\Knowledge\Support\KnowledgeDocumentationPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Publishes Ticket domain documentation into the Knowledge module.
 */
class TicketKnowledgeDocumentationSeeder extends Seeder
{
    public function run(RenderArticleBody $renderer): void
    {
        $publisher = app(KnowledgeDocumentationPublisher::class);
        $book = $publisher->book();

        $chapter = $publisher->chapter($book, 'tickets', [
            'name' => 'Tickets',
            'description' => 'Ticket creation, email, workflows, SLA, assignment, merging, time, cost, and settings.',
            'priority' => 400,
            'source_type' => 'ticket-docs',
        ]);

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            $publisher->article(
                $renderer,
                $book,
                $chapter,
                $userId,
                'ticket-docs',
                $article['slug'],
                $article['title'],
                $article['slug'],
                $markdown,
                ($index + 1) * 10,
                'Ticket',
                $article['path'],
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
