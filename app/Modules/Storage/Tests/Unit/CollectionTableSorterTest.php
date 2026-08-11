<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Modules\Storage\Support\CollectionTableSorter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CollectionTableSorterTest extends TestCase
{
    private CollectionTableSorter $sorter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sorter = new CollectionTableSorter;
    }

    #[Test]
    public function missing_or_disallowed_columns_preserve_the_loaded_order(): void
    {
        $rows = collect([
            $this->row(2, ['name' => 'Zulu']),
            $this->row(1, ['name' => 'Alpha']),
        ]);
        $columns = $this->columns([
            'name' => CollectionTableSorter::TYPE_STRING,
        ]);

        $this->assertSame([2, 1], $this->ids($this->sorter->sort($rows, null, 'asc', $columns)));
        $this->assertSame([2, 1], $this->ids($this->sorter->sort($rows, 'forged', 'desc', $columns)));
        $this->assertNull($this->sorter->normalizeColumn('forged', $columns));
    }

    #[Test]
    public function string_sorting_is_natural_null_last_and_uses_model_keys_for_stable_ties(): void
    {
        $rows = collect([
            $this->row(2, ['name' => 'Item 2']),
            $this->row(1, ['name' => 'item 2']),
            $this->row(4, ['name' => 'Item 10']),
            $this->row(3, ['name' => null]),
        ]);
        $columns = $this->columns([
            'name' => CollectionTableSorter::TYPE_STRING,
        ]);

        $this->assertSame([1, 2, 4, 3], $this->ids($this->sorter->sort($rows, 'name', 'asc', $columns)));
        $this->assertSame([4, 1, 2, 3], $this->ids($this->sorter->sort($rows, 'name', 'desc', $columns)));
    }

    #[Test]
    public function number_date_and_boolean_columns_use_typed_comparison(): void
    {
        $rows = collect([
            $this->row(1, ['quantity' => 10, 'when' => Carbon::parse('2026-08-02'), 'active' => false]),
            $this->row(2, ['quantity' => 2, 'when' => Carbon::parse('2026-08-01'), 'active' => true]),
            $this->row(3, ['quantity' => 30, 'when' => null, 'active' => true]),
        ]);
        $columns = $this->columns([
            'quantity' => CollectionTableSorter::TYPE_NUMBER,
            'when' => CollectionTableSorter::TYPE_DATE,
            'active' => CollectionTableSorter::TYPE_BOOLEAN,
        ]);

        $this->assertSame([2, 1, 3], $this->ids($this->sorter->sort($rows, 'quantity', 'asc', $columns)));
        $this->assertSame([1, 2, 3], $this->ids($this->sorter->sort($rows, 'when', 'desc', $columns)));
        $this->assertSame([2, 3, 1], $this->ids($this->sorter->sort($rows, 'active', 'desc', $columns)));
    }

    #[Test]
    public function direction_normalization_accepts_only_ascending_or_descending(): void
    {
        $this->assertSame('asc', $this->sorter->normalizeDirection('ASC'));
        $this->assertSame('desc', $this->sorter->normalizeDirection('DESC'));
        $this->assertSame('asc', $this->sorter->normalizeDirection('sideways'));
        $this->assertSame('desc', $this->sorter->normalizeDirection(null, 'desc'));
    }

    /**
     * @param  array<string, string>  $types
     * @return array<string, array{type: string, value: callable(Model): mixed}>
     */
    private function columns(array $types): array
    {
        return collect($types)->mapWithKeys(fn (string $type, string $attribute): array => [
            $attribute => [
                'type' => $type,
                'value' => fn (Model $row): mixed => $row->getAttribute($attribute),
            ],
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(int $id, array $attributes): Model
    {
        $row = new class extends Model
        {
            public $timestamps = false;

            protected $guarded = [];
        };
        $row->forceFill(['id' => $id] + $attributes);

        return $row;
    }

    /**
     * @param  Collection<int, Model>  $rows
     * @return list<int>
     */
    private function ids(Collection $rows): array
    {
        return $rows->map(fn (Model $row): int => (int) $row->getKey())->all();
    }
}
