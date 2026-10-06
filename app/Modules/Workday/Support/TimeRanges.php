<?php

namespace App\Modules\Workday\Support;

use Carbon\CarbonImmutable;

class TimeRanges
{
    /** Union half-open UTC intervals so concurrent evidence never doubles elapsed time. */
    public static function union(array $ranges): array
    {
        usort($ranges, fn ($a, $b) => strcmp($a['start'], $b['start']));
        $result = [];
        foreach ($ranges as $range) {
            if ($range['start'] >= $range['end']) {
                continue;
            }
            $i = count($result) - 1;
            if ($i >= 0 && $result[$i]['end'] >= $range['start']) {
                $result[$i]['end'] = max($result[$i]['end'], $range['end']);
            } else {
                $result[] = ['start' => $range['start'], 'end' => $range['end']];
            }
        }

        return $result;
    }

    public static function intersect(array $ranges, array $limits): array
    {
        $result = [];
        foreach ($ranges as $a) {
            foreach ($limits as $b) {
                $start = max($a['start'], $b['start']);
                $end = min($a['end'], $b['end']);
                if ($start < $end) {
                    $result[] = compact('start', 'end');
                }
            }
        }

        return self::union($result);
    }

    public static function subtract(array $ranges, array $remove): array
    {
        foreach (self::union($remove) as $cut) {
            $result = [];
            foreach ($ranges as $range) {
                if ($cut['end'] <= $range['start'] || $cut['start'] >= $range['end']) {
                    $result[] = $range;

                    continue;
                }
                if ($range['start'] < $cut['start']) {
                    $result[] = ['start' => $range['start'], 'end' => $cut['start']];
                }
                if ($cut['end'] < $range['end']) {
                    $result[] = ['start' => $cut['end'], 'end' => $range['end']];
                }
            }
            $ranges = $result;
        }

        return self::union($ranges);
    }

    public static function minutes(array $ranges): int
    {
        return array_sum(array_map(fn ($r) => intdiv(CarbonImmutable::parse($r['end'])->timestamp - CarbonImmutable::parse($r['start'])->timestamp, 60), self::union($ranges)));
    }

    public static function instant($time): string
    {
        return CarbonImmutable::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
