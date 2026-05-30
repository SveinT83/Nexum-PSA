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
 * Publishes Contact domain migration documentation into Knowledge.
 */
class ContactKnowledgeDocumentationSeeder extends Seeder
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
            ->where('slug', 'contacts')
            ->first() ?: new Chapter(['book_id' => $book->id, 'slug' => 'contacts']);

        $chapter->forceFill([
            'book_id' => $book->id,
            'name' => 'Contacts',
            'slug' => 'contacts',
            'description' => 'Canonical contact identity and migration strategy.',
            'priority' => 320,
            'source_system' => $chapter->source_system ?: 'nexum',
            'source_type' => $chapter->source_type ?: 'contact-docs',
            'source_id' => $chapter->source_id ?: 'contacts',
            'sync_status' => 'pending_push',
        ])->save();

        $userId = User::query()->value('id');
        $path = app_path('Modules/Contact/Docs/knowledge/contact-domain-overview.md');
        $markdown = trim(file_get_contents($path));
        $article = Article::query()
            ->where('source_system', 'nexum')
            ->where('source_type', 'contact-docs')
            ->where('source_id', 'contact-domain-overview')
            ->first()
            ?: Article::query()
                ->where('knowledge_book_id', $book->id)
                ->where('knowledge_chapter_id', $chapter->id)
                ->where('slug', Str::slug('Contact Domain Overview'))
                ->first()
            ?: new Article;

        if (! $article->exists) {
            $article->created_by = $userId;
        }

        $article->forceFill([
            'title' => 'Contact Domain Overview',
            'slug' => Str::slug('Contact Domain Overview'),
            'body_markdown' => $markdown,
            'body_html' => $renderer->handle($markdown),
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $userId,
            'knowledge_book_id' => $book->id,
            'knowledge_chapter_id' => $chapter->id,
            'priority' => 10,
            'updated_by' => $userId,
            'source_system' => $article->source_system ?: 'nexum',
            'source_type' => $article->source_type ?: 'contact-docs',
            'source_id' => $article->source_id ?: 'contact-domain-overview',
            'source_checksum' => sha1($markdown),
            'source_updated_at' => now(),
            'sync_status' => 'pending_push',
            'source_payload' => array_merge($article->source_payload ?? [], [
                'module' => 'Contact',
                'generated_from' => static::class,
                'source_file' => $path,
            ]),
        ])->save();
    }
}
