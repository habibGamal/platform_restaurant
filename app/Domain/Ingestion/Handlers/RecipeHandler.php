<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\HandleResult;
use App\Models\Branch;
use App\Models\Warehouse\DimRecipe;

class RecipeHandler implements DocumentHandler
{
    public function type(): string
    {
        return 'recipe';
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function handle(Branch $branch, array $document): HandleResult
    {
        $version = (int) $document['version'];
        $op = (string) ($document['op'] ?? 'upsert');
        $data = (array) ($document['data'] ?? []);

        // recipe uuid is product uuid
        $productUuid = (string) ($data['product_uuid'] ?? $document['uuid']);

        $maxVersion = DimRecipe::where('branch_id', $branch->id)
            ->where('product_uuid', $productUuid)
            ->lockForUpdate()
            ->max('source_version');

        if ($maxVersion !== null && (int) $maxVersion >= $version) {
            return HandleResult::skipped();
        }

        if ($op === 'delete') {
            if ($maxVersion === null) {
                return HandleResult::skipped();
            }

            DimRecipe::where('branch_id', $branch->id)
                ->where('product_uuid', $productUuid)
                ->delete();

            return HandleResult::applied();
        }

        // op === 'upsert'
        DimRecipe::where('branch_id', $branch->id)
            ->where('product_uuid', $productUuid)
            ->delete();

        $components = (array) ($data['components'] ?? []);
        if (! empty($components)) {
            $recipeRows = [];
            foreach ($components as $component) {
                $recipeRows[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'product_uuid' => $productUuid,
                    'component_uuid' => (string) $component['component_uuid'],
                    'quantity' => (string) ($component['quantity'] ?? '0.0000'),
                    'source_version' => $version,
                ];
            }
            DimRecipe::insert($recipeRows);
        }

        return HandleResult::applied();
    }
}
