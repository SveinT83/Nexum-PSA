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
 * Publishes Storage domain documentation into the Knowledge module.
 */
class StorageKnowledgeDocumentationSeeder extends Seeder
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
                'slug' => 'storage',
            ],
            [
                'name' => 'Storage',
                'description' => 'Inventory, stock items, suppliers, reservations, and picking.',
                'priority' => 650,
                'source_system' => 'nexum',
                'source_type' => 'storage-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'storage-docs',
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
                        'module' => 'Storage',
                        'generated_from' => static::class,
                        'source_file' => $article['path'],
                    ],
                ],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Storage/Docs/knowledge');

        return [
            [
                'title' => 'Storage Inventory',
                'slug' => Str::slug('Storage Inventory'),
                'path' => $basePath . '/storage-inventory.md',
            ],
            [
                'title' => 'Storage Item Fields',
                'slug' => Str::slug('Storage Item Fields'),
                'path' => $basePath . '/storage-item-fields.md',
            ],
            [
                'title' => 'Storage Vendors And Suppliers',
                'slug' => Str::slug('Storage Vendors And Suppliers'),
                'path' => $basePath . '/storage-vendors-suppliers.md',
            ],
            [
                'title' => 'Storage Picking List',
                'slug' => Str::slug('Storage Picking List'),
                'path' => $basePath . '/storage-picking-list.md',
            ],
        ];
    }
}
