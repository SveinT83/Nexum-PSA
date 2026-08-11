<?php

namespace App\Modules\Storage\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class CollectionTableSorter
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATE = 'date';

    public const TYPE_NUMBER = 'number';

    public const TYPE_STRING = 'string';

    /**
     * Sort an already-loaded table collection without changing its default order when no allowed column is selected.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $items
     * @param  array<string, array{type: string, value: callable(TValue): mixed}>  $columns
     * @return Collection<int, TValue>
     */
    public function sort(Collection $items, mixed $requestedColumn, mixed $requestedDirection, array $columns): Collection
    {
        $column = $this->normalizeColumn($requestedColumn, $columns);

        if ($column === null) {
            return $items->values();
        }

        $definition = $columns[$column];
        $this->assertDefinition($definition);
        $direction = $this->normalizeDirection($requestedDirection);

        return $items
            ->values()
            ->map(fn (mixed $item, int $index): array => [
                'index' => $index,
                'item' => $item,
                'value' => $definition['value']($item),
            ])
            ->sort(function (array $left, array $right) use ($definition, $direction): int {
                $leftMissing = $this->isMissing($left['value']);
                $rightMissing = $this->isMissing($right['value']);

                if ($leftMissing !== $rightMissing) {
                    return $leftMissing ? 1 : -1;
                }

                $comparison = $leftMissing
                    ? 0
                    : $this->compareValues($left['value'], $right['value'], $definition['type']);

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }

                return $this->compareStableKeys(
                    $left['item'],
                    $right['item'],
                    $left['index'],
                    $right['index']
                );
            })
            ->pluck('item')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    public function normalizeColumn(mixed $requestedColumn, array $columns): ?string
    {
        if (! is_string($requestedColumn) || ! array_key_exists($requestedColumn, $columns)) {
            return null;
        }

        return $requestedColumn;
    }

    public function normalizeDirection(mixed $requestedDirection, string $default = 'asc'): string
    {
        $default = strtolower($default) === 'desc' ? 'desc' : 'asc';

        if (! is_string($requestedDirection)) {
            return $default;
        }

        $direction = strtolower($requestedDirection);

        return in_array($direction, ['asc', 'desc'], true) ? $direction : $default;
    }

    /**
     * @param  array{type?: mixed, value?: mixed}  $definition
     */
    private function assertDefinition(array $definition): void
    {
        if (! in_array($definition['type'] ?? null, [
            self::TYPE_BOOLEAN,
            self::TYPE_DATE,
            self::TYPE_NUMBER,
            self::TYPE_STRING,
        ], true) || ! is_callable($definition['value'] ?? null)) {
            throw new InvalidArgumentException('Collection table sort columns require a supported type and value callback.');
        }
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function compareValues(mixed $left, mixed $right, string $type): int
    {
        return match ($type) {
            self::TYPE_BOOLEAN => ((int) (bool) $left) <=> ((int) (bool) $right),
            self::TYPE_DATE => $this->dateValue($left) <=> $this->dateValue($right),
            self::TYPE_NUMBER => ((float) $left) <=> ((float) $right),
            self::TYPE_STRING => strnatcasecmp((string) $left, (string) $right),
            default => throw new InvalidArgumentException('Unsupported collection table sort type.'),
        };
    }

    private function dateValue(mixed $value): float
    {
        if ($value instanceof DateTimeInterface) {
            return (float) $value->format('U.u');
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? 0.0 : (float) $timestamp;
    }

    private function compareStableKeys(mixed $left, mixed $right, int $leftIndex, int $rightIndex): int
    {
        $leftKey = $left instanceof Model ? $left->getKey() : null;
        $rightKey = $right instanceof Model ? $right->getKey() : null;

        if ($leftKey !== null && $rightKey !== null) {
            $comparison = is_numeric($leftKey) && is_numeric($rightKey)
                ? ((float) $leftKey) <=> ((float) $rightKey)
                : strnatcasecmp((string) $leftKey, (string) $rightKey);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return $leftIndex <=> $rightIndex;
    }
}
