<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Core\User;
use App\Modules\Knowledge\Support\KnowledgeDocumentationPublisher;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Synchronizes module Markdown documentation into Knowledge records.
 *
 * This keeps repository documentation, the in-app Knowledge module, and
 * BookStack sync aligned without relying on database seeders for day-to-day
 * documentation publishing.
 */
class SyncRepositoryKnowledgeDocs
{
    public function __construct(
        private readonly KnowledgeDocumentationPublisher $publisher,
        private readonly RenderArticleBody $renderer,
    ) {}

    /**
     * @param  array<int, string>  $onlyModules
     * @return array{chapters: int, articles: int, skipped: int, modules: array<int, string>}
     */
    public function handle(array $onlyModules = []): array
    {
        $summary = [
            'chapters' => 0,
            'articles' => 0,
            'skipped' => 0,
            'modules' => [],
        ];

        $book = $this->publisher->book();
        $userId = User::query()->value('id');
        $requested = collect($onlyModules)
            ->filter()
            ->map(fn (string $module) => Str::lower($module))
            ->values();

        foreach ($this->moduleDocumentationDefinitions() as $module => $definition) {
            if ($requested->isNotEmpty() && ! $requested->contains(Str::lower($module))) {
                continue;
            }

            $path = $definition['path'];

            if (! is_dir($path)) {
                $summary['skipped']++;

                continue;
            }

            $files = $this->markdownFiles($path);

            if ($files === []) {
                $summary['skipped']++;

                continue;
            }

            $chapter = $this->publisher->chapter($book, $definition['slug'], [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'priority' => $definition['priority'],
                'source_type' => 'repository-docs',
                'source_id' => $definition['slug'],
            ]);

            $summary['chapters']++;
            $summary['modules'][] = $module;

            foreach ($files as $index => $file) {
                $markdown = trim(file_get_contents($file->getPathname()));
                $metadata = $this->articleMetadata($definition['slug'], $markdown, $file);

                $this->publisher->article(
                    $this->renderer,
                    $book,
                    $chapter,
                    $userId,
                    'repository-docs',
                    $definition['slug'].'/'.$file->getBasename('.md'),
                    $metadata['title'],
                    $metadata['slug'],
                    $markdown,
                    ($index + 1) * 10,
                    $module,
                    $file->getPathname(),
                );

                $summary['articles']++;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    private function moduleDocumentationDefinitions(): array
    {
        return [
            'Asset' => $this->definition('assets', 'Assets', 180),
            'Calendar' => $this->definition('calendar', 'Calendar', 580),
            'Clients' => $this->definition('clients', 'Clients', 190),
            'Commercial' => $this->definition('commercial', 'Commercial', 700),
            'Contact' => $this->definition('contacts', 'Contacts', 200),
            'Documentation' => $this->definition('documentation', 'Documentation', 540),
            'Economy' => $this->definition('economy', 'Economy', 800),
            'Email' => $this->definition('email', 'Email', 810),
            'Integration' => $this->definition('integrations', 'Integrations', 850),
            'Knowledge' => $this->definition('knowledge', 'Knowledge', 860),
            'LeadIntelligence' => $this->definition('lead-intelligence', 'Lead Intelligence', 820),
            'Nextcloud' => $this->definition('nextcloud', 'Nextcloud', 870),
            'Notification' => $this->definition('notifications', 'Notifications', 880),
            'Report' => $this->definition('reports', 'Reports', 890),
            'Relationship' => $this->definition('relationships', 'Nexum Relationships', 845),
            'Risk' => $this->definition('risk', 'Risk', 550),
            'Sales' => $this->definition('sales', 'Sales', 750),
            'Storage' => $this->definition('storage', 'Storage', 600),
            'System' => $this->definition('system', 'System', 900),
            'Task' => $this->definition('tasks', 'Tasks', 500),
            'Taxonomy' => $this->definition('taxonomy', 'Taxonomy', 520),
            'Ticket' => $this->definition('tickets', 'Tickets', 400),
            'UserManagement' => $this->definition('user-management', 'User Management', 300),
            'Warroom' => $this->definition('warroom', 'Warroom', 100),
            'WorkContext' => $this->definition('work-context', 'Work Context', 830),
        ];
    }

    /**
     * @return array{slug: string, name: string, description: string, priority: int, path: string}
     */
    private function definition(string $slug, string $name, int $priority): array
    {
        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $name.' documentation.',
            'priority' => $priority,
            'path' => app_path('Modules/'.$this->moduleDirectory($name).'/Docs/knowledge'),
        ];
    }

    private function moduleDirectory(string $name): string
    {
        return match ($name) {
            'Clients' => 'Clients',
            'Lead Intelligence' => 'LeadIntelligence',
            'Nexum Relationships' => 'Relationship',
            'Sales' => 'Sales',
            'User Management' => 'UserManagement',
            'Work Context' => 'WorkContext',
            default => str_replace(' ', '', Str::singular($name)),
        };
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function markdownFiles(string $path): array
    {
        return iterator_to_array(
            (new Finder)
                ->files()
                ->in($path)
                ->name('*.md')
                ->sortByName(),
            false,
        );
    }

    private function titleFromMarkdown(string $markdown, SplFileInfo $file): string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches)) {
            return trim($matches[1]);
        }

        return Str::headline($file->getBasename('.md'));
    }

    /**
     * @return array{title: string, slug: string}
     */
    private function articleMetadata(string $chapterSlug, string $markdown, SplFileInfo $file): array
    {
        $fileKey = $file->getBasename('.md');
        $override = $this->articleMetadataOverrides()[$chapterSlug][$fileKey] ?? null;

        if ($override) {
            return $override;
        }

        $title = $this->titleFromMarkdown($markdown, $file);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
        ];
    }

    /**
     * Keep repository sync aligned with existing BookStack/Knowledge page slugs.
     *
     * @return array<string, array<string, array{title: string, slug: string}>>
     */
    private function articleMetadataOverrides(): array
    {
        return [
            'integrations' => [
                'bookstack-integration' => ['title' => 'BookStack Integration', 'slug' => 'bookstack-integration'],
            ],
            'nextcloud' => [
                '01-overview' => ['title' => 'Nextcloud Overview', 'slug' => 'nextcloud-overview'],
                '02-admin-setup' => ['title' => 'Nextcloud Admin Setup', 'slug' => 'nextcloud-admin-setup'],
                '03-users-groups-calendars' => ['title' => 'Nextcloud Users Groups And Calendars', 'slug' => 'nextcloud-users-groups-and-calendars'],
                '04-sso-future-plan' => ['title' => 'Nextcloud SSO Future Plan', 'slug' => 'nextcloud-sso-future-plan'],
                '05-talk-bot' => ['title' => 'Nextcloud Talk Bot', 'slug' => 'nextcloud-talk-bot'],
            ],
            'relationships' => [
                'two-instance-test-plan' => ['title' => 'Nexum Relationship Two-Instance Test Plan', 'slug' => 'nexum-relationship-two-instance-test-plan'],
            ],
            'storage' => [
                'storage-vendors-suppliers' => ['title' => 'Storage Vendors And Suppliers', 'slug' => 'storage-vendors-and-suppliers'],
            ],
            'tickets' => [
                'storage-cost-reservations' => ['title' => 'Ticket Storage Cost Reservations', 'slug' => 'ticket-storage-cost-reservations'],
                'ticket-email-communication' => ['title' => 'Ticket Email And Communication', 'slug' => 'ticket-email-and-communication'],
                'ticket-lifecycle-workflows' => ['title' => 'Ticket Lifecycle And Workflows', 'slug' => 'ticket-lifecycle-and-workflows'],
                'ticket-rules-assignment' => ['title' => 'Ticket Rules And Assignment', 'slug' => 'ticket-rules-and-assignment'],
                'ticket-sla' => ['title' => 'Ticket SLA', 'slug' => 'ticket-sla'],
                'time-registration' => ['title' => 'Ticket Time Registration', 'slug' => 'ticket-time-registration'],
            ],
        ];
    }
}
