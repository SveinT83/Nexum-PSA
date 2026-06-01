<?php

namespace Database\Seeders;

use App\Models\Core\User;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use App\Modules\Knowledge\Support\KnowledgeDocumentationPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Publishes System administration documentation into the Knowledge module.
 */
class SystemKnowledgeDocumentationSeeder extends Seeder
{
    public function run(RenderArticleBody $renderer): void
    {
        $publisher = app(KnowledgeDocumentationPublisher::class);
        $book = $publisher->book();

        $chapter = $publisher->chapter($book, 'system-administration', [
            'name' => 'System Administration',
            'description' => 'Operational setup for platform-level administration.',
            'priority' => 900,
            'source_type' => 'system-docs',
        ]);

        $userId = User::query()->value('id');

        foreach ($this->articles() as $article) {
            $path = app_path('Modules/System/Docs/knowledge/'.$article['file']);
            $markdown = trim(file_get_contents($path));

            $publisher->article(
                $renderer,
                $book,
                $chapter,
                $userId,
                'system-docs',
                $article['source_id'],
                $article['title'],
                Str::slug($article['title']),
                $markdown,
                $article['priority'],
                'System',
                $path,
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
