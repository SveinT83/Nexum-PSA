<?php

namespace App\Modules\Task\Support;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Browser and scoped API readers receive the same authoritative Task guide bytes. */
final class TaskDocumentation
{
    public function response(): BinaryFileResponse
    {
        $path = app_path('Modules/Task/Docs/knowledge/task-overview.md');
        abort_unless(is_file($path) && is_readable($path), 404);

        return response()->file($path, ['Content-Type' => 'text/markdown']);
    }
}
