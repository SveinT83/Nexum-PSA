<?php

namespace App\Modules\Report\Support;

use App\Models\Core\User;
use App\Modules\Report\Contracts\ReportDefinition;
use Illuminate\Support\Collection;

class ReportRegistry
{
    /**
     * @param array<int, class-string<ReportDefinition>> $definitions
     */
    public function __construct(private readonly array $definitions = []) {}

    /**
     * @return Collection<int, ReportEntry>
     */
    public function all(): Collection
    {
        $definitions = $this->definitions ?: config('reports.definitions', []);

        return collect($definitions)
            ->map(fn (string $definition) => ReportEntry::fromDefinition(app($definition)))
            ->sortBy([['domain', 'asc'], ['title', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, ReportEntry>
     */
    public function visibleFor(?User $user, ?string $domain = null): Collection
    {
        return $this->all()
            ->filter(fn (ReportEntry $report) => $this->isVisible($report, $user))
            ->when($domain, fn (Collection $reports) => $reports->where('domain', $domain))
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    public function domains(): Collection
    {
        return $this->all()
            ->pluck('domain')
            ->unique()
            ->sort()
            ->values();
    }

    private function isVisible(ReportEntry $report, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole('Superuser')) {
            return true;
        }

        return $user->can($report->permission) || $user->can('report.view');
    }
}
