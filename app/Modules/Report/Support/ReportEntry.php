<?php

namespace App\Modules\Report\Support;

use App\Modules\Report\Contracts\ReportDefinition;

class ReportEntry
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly string $domain,
        public readonly string $routeName,
        public readonly string $permission,
        public readonly string $icon,
        public readonly array $tags,
        public readonly ?\App\Modules\Report\Contracts\ReportVisibility $visibility = null,
        public readonly array $apiEndpoints = [],
    ) {}

    public static function fromDefinition(ReportDefinition $definition): self
    {
        return new self(
            key: $definition->key(),
            title: $definition->title(),
            description: $definition->description(),
            domain: $definition->domain(),
            routeName: $definition->routeName(),
            permission: $definition->permission(),
            icon: $definition->icon(),
            tags: $definition->tags(),
            apiEndpoints: $definition instanceof \App\Modules\Report\Contracts\ReportApiDefinition ? $definition->apiEndpoints() : [],
            visibility: $definition instanceof \App\Modules\Report\Contracts\ReportVisibility ? $definition : null,
        );
    }
}
