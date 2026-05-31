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
 * Publishes Commercial domain documentation into the Knowledge module.
 */
class CommercialKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'commercial',
            ],
            [
                'name' => 'Commercial',
                'description' => 'Services, packages, costs, contracts, time rates, and SLA inheritance.',
                'priority' => 600,
                'source_system' => 'nexum',
                'source_type' => 'commercial-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'commercial-docs',
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
                        'module' => 'Commercial',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Commercial/Docs/knowledge');

        return [
            [
                'title' => 'Service Catalogue',
                'slug' => Str::slug('Service Catalogue'),
                'path' => $basePath . '/service-catalogue.md',
            ],
            [
                'title' => 'Package Catalogue',
                'slug' => Str::slug('Package Catalogue'),
                'path' => $basePath . '/package-catalogue.md',
            ],
            [
                'title' => 'Cost Catalogue',
                'slug' => Str::slug('Cost Catalogue'),
                'path' => $basePath . '/cost-catalogue.md',
            ],
            [
                'title' => 'Time Rates',
                'slug' => Str::slug('Time Rates'),
                'path' => $basePath . '/time-rates.md',
            ],
            [
                'title' => 'SLA Inheritance',
                'slug' => Str::slug('SLA Inheritance'),
                'path' => $basePath . '/sla-inheritance.md',
            ],
            [
                'title' => 'Contract System',
                'slug' => Str::slug('Contract System'),
                'path' => $basePath . '/contract-system.md',
            ],
        ];
    }
}
