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

        $chapter = Chapter::query()
            ->where('book_id', $book->id)
            ->where('slug', 'storage')
            ->first() ?: new Chapter(['book_id' => $book->id, 'slug' => 'storage']);

        $chapter->forceFill([
            'book_id' => $book->id,
            'name' => 'Storage',
            'slug' => 'storage',
            'description' => 'Inventory, stock items, suppliers, reservations, and picking.',
            'priority' => 650,
            'source_system' => $chapter->source_system ?: 'nexum',
            'source_type' => $chapter->source_type ?: 'storage-docs',
            'source_id' => $chapter->source_id ?: 'storage',
            'sync_status' => 'pending_push',
        ])->save();

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            $knowledgeArticle = Article::query()
                ->where('source_system', 'nexum')
                ->where('source_type', 'storage-docs')
                ->where('source_id', $article['slug'])
                ->first()
                ?: Article::query()
                    ->where('knowledge_book_id', $book->id)
                    ->where('knowledge_chapter_id', $chapter->id)
                    ->where('slug', $article['slug'])
                    ->first()
                ?: new Article;

            if (! $knowledgeArticle->exists) {
                $knowledgeArticle->created_by = $userId;
            }

            $knowledgeArticle->forceFill([
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
                'updated_by' => $userId,
                'source_system' => $knowledgeArticle->source_system ?: 'nexum',
                'source_type' => $knowledgeArticle->source_type ?: 'storage-docs',
                'source_id' => $knowledgeArticle->source_id ?: $article['slug'],
                'source_checksum' => sha1($markdown),
                'source_updated_at' => now(),
                'sync_status' => 'pending_push',
                'source_payload' => array_merge($knowledgeArticle->source_payload ?? [], [
                    'module' => 'Storage',
                    'generated_from' => static::class,
                    'source_file' => $article['path'],
                ]),
            ])->save();
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/Storage/Docs/knowledge');

        return [
            [
                'title' => 'Storage Inventory',
                'slug' => Str::slug('Storage Inventory'),
                'path' => $basePath.'/storage-inventory.md',
            ],
            [
                'title' => 'Storage Purchase Orders And Receiving',
                'slug' => Str::slug('Storage Purchase Orders And Receiving'),
                'path' => $basePath.'/storage-purchase-orders-receiving.md',
            ],
            [
                'title' => 'Storage Supplier Order Automation',
                'slug' => Str::slug('Storage Supplier Order Automation'),
                'path' => $basePath.'/storage-supplier-order-automation.md',
            ],
            [
                'title' => 'Storage Item Fields',
                'slug' => Str::slug('Storage Item Fields'),
                'path' => $basePath.'/storage-item-fields.md',
            ],
            [
                'title' => 'Storage Vendors And Suppliers',
                'slug' => Str::slug('Storage Vendors And Suppliers'),
                'path' => $basePath.'/storage-vendors-suppliers.md',
            ],
            [
                'title' => 'Storage Picking List',
                'slug' => Str::slug('Storage Picking List'),
                'path' => $basePath.'/storage-picking-list.md',
            ],
        ];
    }
}
