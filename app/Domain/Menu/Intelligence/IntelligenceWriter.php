<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use BackedEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Atomic writes for the nightly build, so readers never see a half-built period.
 * Array values are stored as JSON and backed enums as their value. Every row of one call must
 * carry the same columns (nullable ones included), or the write throws.
 */
final class IntelligenceWriter
{
    private const CHUNK = 500;

    /** Period-keyed tables replace() may write; combo_candidates also carries updated_at. */
    private const PERIOD_TABLES = [
        'item_period_stats' => false,
        'item_affinities' => false,
        'item_rankings' => false,
        'combo_candidates' => true,
    ];

    /** Profile field => the source column that marks it as owner-set. */
    private const OWNER_FIELDS = [
        'serves_n' => 'serves_source',
        'price_tier' => 'price_tier_source',
        'season' => 'season_source',
    ];

    /**
     * Swap every row of one org + period for the given rows, in one transaction.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function replace(string $table, int $organizationId, string $periodKey, iterable $rows): int
    {
        if (! array_key_exists($table, self::PERIOD_TABLES)) {
            throw new InvalidArgumentException("Table [{$table}] is not a period-keyed intelligence table.");
        }

        $now = Carbon::now();
        $stamps = self::PERIOD_TABLES[$table]
            ? ['created_at' => $now, 'updated_at' => $now]
            : ['created_at' => $now];

        return DB::transaction(function () use ($table, $organizationId, $periodKey, $rows, $stamps): int {
            DB::table($table)->where('organization_id', $organizationId)->where('period_key', $periodKey)->delete();

            $count = 0;
            $chunk = [];
            $columns = null;

            foreach ($rows as $row) {
                $row = ['organization_id' => $organizationId, 'period_key' => $periodKey] + $stamps + $row;
                $columns ??= array_keys($row);
                self::assertColumns($columns, $row);
                $chunk[] = self::encode($row);

                if (count($chunk) === self::CHUNK) {
                    DB::table($table)->insert($chunk);
                    $count += count($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
                $count += count($chunk);
            }

            return $count;
        });
    }

    /**
     * Upsert the org's item profiles by item_key, keeping owner-set serves_n / price_tier / season,
     * and delete profiles whose item is no longer present.
     *
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function replaceProfiles(int $organizationId, iterable $rows): int
    {
        $now = Carbon::now();

        return DB::transaction(function () use ($organizationId, $rows, $now): int {
            /** @var array<string, stdClass> $existing */
            $existing = DB::table('item_profiles')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->get(['item_key', ...array_keys(self::OWNER_FIELDS), ...array_values(self::OWNER_FIELDS)])
                ->keyBy('item_key')
                ->all();

            $seen = [];
            $count = 0;
            $chunk = [];
            $columns = null;

            foreach ($rows as $row) {
                $key = (string) $row['item_key'];
                $seen[$key] = true;
                $row = ['organization_id' => $organizationId, 'created_at' => $now, 'updated_at' => $now]
                    + $row + ['built_at' => $now];

                $current = $existing[$key] ?? null;
                foreach (self::OWNER_FIELDS as $field => $sourceField) {
                    if ($current !== null && $current->{$sourceField} === 'owner') {
                        $row[$field] = $current->{$field};
                        $row[$sourceField] = 'owner';
                    }
                }

                $columns ??= array_keys($row);
                self::assertColumns($columns, $row);
                $chunk[] = self::encode($row);

                if (count($chunk) === self::CHUNK) {
                    $count += self::upsertProfiles($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $count += self::upsertProfiles($chunk);
            }

            $stale = array_keys(array_diff_key($existing, $seen));
            foreach (array_chunk($stale, self::CHUNK) as $keys) {
                DB::table('item_profiles')->where('organization_id', $organizationId)->whereIn('item_key', $keys)->delete();
            }

            return $count;
        });
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $chunk
     */
    private static function upsertProfiles(array $chunk): int
    {
        $update = array_values(array_diff(array_keys($chunk[0]), ['organization_id', 'item_key', 'created_at']));
        DB::table('item_profiles')->upsert($chunk, ['organization_id', 'item_key'], $update);

        return count($chunk);
    }

    /**
     * Bulk insert/upsert takes its columns from the first row, so a row with a different key set
     * would silently land values in the wrong columns. Builders must pass every column on every row.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     */
    private static function assertColumns(array $columns, array $row): void
    {
        $keys = array_keys($row);
        $missing = array_diff($columns, $keys);
        $extra = array_diff($keys, $columns);

        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException(sprintf(
                'Every row must have the same columns as the first row. Missing: [%s]. Extra: [%s].',
                implode(', ', $missing),
                implode(', ', $extra),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function encode(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value)) {
                $row[$column] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif ($value instanceof BackedEnum) {
                $row[$column] = $value->value;
            }
        }

        return $row;
    }
}
