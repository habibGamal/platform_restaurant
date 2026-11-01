<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

use App\Models\CatalogItem;
use App\Models\Restaurant;
use App\Support\Weekday;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DimensionNameResolver
{
    /**
     * Resolve dimension names for a collection of query rows.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<string>  $groupBy
     * @param  list<int>  $branchIds
     * @param  array<int, string>  $branchMap
     * @return Collection<int, \stdClass>
     */
    public function resolve(
        Collection $rows,
        array $groupBy,
        int $organizationId,
        array $branchIds,
        array $branchMap,
    ): Collection {
        if ($rows->isEmpty() || empty($groupBy)) {
            return $rows;
        }

        $categoriesMap = null;
        $productsMeta = null;
        $suppliersMap = null;
        $cashiersMap = null;
        $restaurantsMap = null;

        if (in_array('category', $groupBy, true)) {
            $categoriesMap = DB::table('dim_categories')
                ->whereIn('branch_id', $branchIds)
                ->whereNull('deleted_at')
                ->pluck('name', 'uuid')
                ->all();
        }

        if (in_array('supplier', $groupBy, true)) {
            $suppliersMap = DB::table('dim_suppliers')
                ->whereIn('branch_id', $branchIds)
                ->whereNull('deleted_at')
                ->pluck('name', 'uuid')
                ->all();
        }

        if (in_array('cashier', $groupBy, true)) {
            $cashiersMap = DB::table('dim_staff')
                ->whereIn('branch_id', $branchIds)
                ->whereNull('deleted_at')
                ->pluck('name', 'uuid')
                ->all();
        }

        if (in_array('restaurant', $groupBy, true)) {
            $restaurantsMap = Restaurant::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->pluck('name', 'id')
                ->all();
        }

        if (in_array('product', $groupBy, true)) {
            $productsMeta = $this->resolveProductsMeta($rows, $organizationId, $branchIds);
        }

        foreach ($rows as $row) {
            // Branch name
            if (isset($row->branch_id)) {
                $bId = (int) $row->branch_id;
                $row->branch_name = $branchMap[$bId] ?? "فرع {$bId}";
            }

            // Restaurant name
            if (isset($row->restaurant_id) && $restaurantsMap !== null) {
                $rId = (int) $row->restaurant_id;
                $row->restaurant_name = $restaurantsMap[$rId] ?? "مطعم {$rId}";
            }

            // Product name
            if (isset($row->product_key) && $productsMeta !== null) {
                $pKey = (string) $row->product_key;
                $row->product_name = $productsMeta[$pKey] ?? $pKey;
            }

            // Category name
            if (isset($row->category_uuid) && $categoriesMap !== null) {
                $cUuid = (string) $row->category_uuid;
                $row->category_name = $categoriesMap[$cUuid] ?? $cUuid;
            }

            // Supplier name
            if (isset($row->supplier_uuid) && $suppliersMap !== null) {
                $sUuid = (string) $row->supplier_uuid;
                $row->supplier_name = $suppliersMap[$sUuid] ?? ($sUuid === '' ? 'بدون مورد' : $sUuid);
            }

            // Cashier name
            if (isset($row->cashier_uuid) && $cashiersMap !== null) {
                $cUuid = (string) $row->cashier_uuid;
                $row->cashier_name = $cashiersMap[$cUuid] ?? $cUuid;
            }

            // Weekday name
            if (isset($row->weekday)) {
                $w = (int) $row->weekday;
                $row->weekday_name = Weekday::label($w);
            }
        }

        return $rows;
    }

    /**
     * Resolve product names mapping by product_key (product_ref or product_uuid).
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<int>  $branchIds
     * @return array<string, string>
     */
    private function resolveProductsMeta(Collection $rows, int $organizationId, array $branchIds): array
    {
        $productRefs = [];
        $productUuids = [];

        foreach ($rows as $row) {
            if (isset($row->product_ref) && $row->product_ref !== '') {
                $productRefs[] = (string) $row->product_ref;
            }
            if (isset($row->product_uuid) && $row->product_uuid !== '') {
                $productUuids[] = (string) $row->product_uuid;
            }
            if (isset($row->product_key) && $row->product_key !== '') {
                $productRefs[] = (string) $row->product_key;
            }
        }

        $productRefs = array_values(array_unique($productRefs));
        $productUuids = array_values(array_unique($productUuids));

        // 1. Catalog items by product_ref
        $catalogMap = [];
        if (! empty($productRefs)) {
            $catalogMap = CatalogItem::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereIn('product_ref', $productRefs)
                ->pluck('canonical_name', 'product_ref')
                ->all();
        }

        // 2. Dim products by uuid / product_ref
        $dimMap = [];
        if (! empty($branchIds) && (! empty($productUuids) || ! empty($productRefs))) {
            $dimRows = DB::table('dim_products')
                ->whereIn('branch_id', $branchIds)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($productUuids, $productRefs): void {
                    if (! empty($productUuids)) {
                        $q->whereIn('uuid', $productUuids);
                    }
                    if (! empty($productRefs)) {
                        $q->orWhereIn('product_ref', $productRefs);
                    }
                })
                ->get(['uuid', 'product_ref', 'name']);

            foreach ($dimRows as $d) {
                if ($d->product_ref !== null && $d->product_ref !== '') {
                    $dimMap[(string) $d->product_ref] = (string) $d->name;
                }
                $dimMap[(string) $d->uuid] = (string) $d->name;
            }
        }

        // Merge maps: catalog item canonical_name takes priority, then dim_products name
        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row->product_key ?? '');
            if ($key === '') {
                continue;
            }

            $ref = isset($row->product_ref) ? (string) $row->product_ref : null;
            $uuid = isset($row->product_uuid) ? (string) $row->product_uuid : null;

            if ($ref !== null && isset($catalogMap[$ref])) {
                $result[$key] = $catalogMap[$ref];
            } elseif ($ref !== null && isset($dimMap[$ref])) {
                $result[$key] = $dimMap[$ref];
            } elseif ($uuid !== null && isset($dimMap[$uuid])) {
                $result[$key] = $dimMap[$uuid];
            } elseif (isset($catalogMap[$key])) {
                $result[$key] = $catalogMap[$key];
            } elseif (isset($dimMap[$key])) {
                $result[$key] = $dimMap[$key];
            } else {
                $result[$key] = $key;
            }
        }

        return $result;
    }
}
