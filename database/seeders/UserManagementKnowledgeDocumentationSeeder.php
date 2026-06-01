<?php

namespace Database\Seeders;

use App\Models\Core\User;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use App\Modules\Knowledge\Support\KnowledgeDocumentationPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Publishes User Management and technician profile documentation into Knowledge.
 */
class UserManagementKnowledgeDocumentationSeeder extends Seeder
{
    public function run(RenderArticleBody $renderer): void
    {
        $publisher = app(KnowledgeDocumentationPublisher::class);
        $book = $publisher->book();
        $chapter = $publisher->chapter($book, 'user-management', [
            'name' => 'User Management',
            'description' => 'Users, technician profiles, roles, preferences, and access management.',
            'priority' => 300,
            'source_type' => 'user-management-docs',
        ]);
        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim(file_get_contents($article['path']));

            $publisher->article(
                $renderer,
                $book,
                $chapter,
                $userId,
                'user-management-docs',
                $article['slug'],
                $article['title'],
                $article['slug'],
                $markdown,
                ($index + 1) * 10,
                'UserManagement',
                $article['path'],
            );
        }
    }

    private function articles(): array
    {
        $basePath = app_path('Modules/UserManagement/Docs/knowledge');

        return [
            [
                'title' => 'Profile And User Management',
                'slug' => Str::slug('Profile And User Management'),
                'path' => $basePath . '/profile-and-user-management.md',
            ],
        ];
    }
}
