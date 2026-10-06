<?php

namespace App\Modules\Knowledge\Support;

/**
 * Produces a compact line diff without an external process dependency.
 */
class ArticleRevisionDiff
{
    /** @return array{before: list<string>, removed: list<string>, added: list<string>, after: list<string>} */
    public function between(string $before, string $after): array
    {
        $beforeLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $before));
        $afterLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $after));
        $prefix = 0;

        while (isset($beforeLines[$prefix], $afterLines[$prefix]) && $beforeLines[$prefix] === $afterLines[$prefix]) {
            $prefix++;
        }

        $beforeTail = count($beforeLines) - 1;
        $afterTail = count($afterLines) - 1;

        while ($beforeTail >= $prefix && $afterTail >= $prefix && $beforeLines[$beforeTail] === $afterLines[$afterTail]) {
            $beforeTail--;
            $afterTail--;
        }

        return [
            'before' => array_slice($beforeLines, 0, $prefix),
            'removed' => array_slice($beforeLines, $prefix, $beforeTail - $prefix + 1),
            'added' => array_slice($afterLines, $prefix, $afterTail - $prefix + 1),
            'after' => array_slice($afterLines, $beforeTail + 1),
        ];
    }
}
