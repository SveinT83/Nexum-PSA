<?php

namespace App\Modules\Report\Contracts;

/**
 * Defines one report entry shown by the Report domain.
 *
 * Domain modules own report calculations and detail routes. The Report domain
 * owns discovery, navigation, and the shared reporting hub.
 */
interface ReportDefinition
{
    public function key(): string;

    public function title(): string;

    public function description(): string;

    public function domain(): string;

    public function routeName(): string;

    public function permission(): string;

    public function icon(): string;

    /**
     * @return array<int, string>
     */
    public function tags(): array;
}
