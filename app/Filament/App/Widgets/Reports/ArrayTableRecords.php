<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use Illuminate\Pagination\LengthAwarePaginator;

final class ArrayTableRecords
{
    /** @param array<int|string, array<string, mixed>> $rows
     * @param  array<string, mixed>  $columnSearches
     */
    public static function paginate(array $rows, array $columnSearches, ?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = array_filter($rows, function (array $row) use ($columnSearches, $search): bool {
            foreach ($columnSearches as $column => $value) {
                if (is_string($value) && $value !== '' && ! self::contains($row[$column] ?? '', $value)) {
                    return false;
                }
            }

            if ($search === null || $search === '') {
                return true;
            }

            foreach ($row as $key => $value) {
                if (! str_starts_with((string) $key, '_') && self::contains($value, $search)) {
                    return true;
                }
            }

            return false;
        });

        if ($sortColumn !== null) {
            uasort($rows, function (array $a, array $b) use ($sortColumn, $sortDirection): int {
                $left = self::sortValue($a[$sortColumn] ?? null);
                $right = self::sortValue($b[$sortColumn] ?? null);
                $comparison = is_float($left) && is_float($right) ? $left <=> $right : strnatcasecmp((string) $left, (string) $right);

                return $sortDirection === 'desc' ? -$comparison : $comparison;
            });
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage, true), count($rows), $perPage, $page);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode(preg_replace('/<[^>]*>/u', ' ', (string) $value) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private static function contains(mixed $value, string $search): bool
    {
        return mb_stripos(self::text($value), $search) !== false;
    }

    private static function sortValue(mixed $value): float|string
    {
        $text = preg_replace('/^[↑↓#]\s*/u', '', self::text($value)) ?? '';
        if (preg_match('/^\s*([+-]?[\d,]+(?:\.\d+)?)(?:\s|%|$)/u', $text, $match)) {
            return (float) str_replace(',', '', $match[1]);
        }

        return $text;
    }
}
