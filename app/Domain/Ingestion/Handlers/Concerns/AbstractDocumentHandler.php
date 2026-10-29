<?php

namespace App\Domain\Ingestion\Handlers\Concerns;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\HandleResult;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

abstract class AbstractDocumentHandler implements DocumentHandler
{
    use InteractsWithPayload;

    abstract public function type(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * Map payload data to parent model attributes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    abstract protected function mapParentAttributes(Branch $branch, array $data, array $document): array;

    /**
     * Replace child rows for the parent.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $document
     */
    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        // Default: no child rows
    }

    protected function hasBusinessDate(): bool
    {
        return true;
    }

    protected function isDayClosed(): bool
    {
        return false;
    }

    /**
     * Extract business date string (Y-m-d) from payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractBusinessDateString(array $data): ?string
    {
        if (! $this->hasBusinessDate()) {
            return null;
        }

        $date = $data['business_date'] ?? $data['date'] ?? null;

        return $date ? (string) $date : null;
    }

    /**
     * Extract business date string (Y-m-d) from existing model.
     */
    protected function getBusinessDateString(?Model $existing): ?string
    {
        if ($existing === null || ! $this->hasBusinessDate()) {
            return null;
        }

        $date = $existing->getAttribute('business_date');
        if ($date === null) {
            return null;
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return (string) $date;
    }

    /**
     * Compute dirty dates when op is 'delete'.
     *
     * @return list<string>
     */
    protected function computeDirtyDatesForDelete(Branch $branch, Model $existing): array
    {
        $stored = $this->getBusinessDateString($existing);

        return $stored ? [$stored] : [];
    }

    /**
     * Compute dirty dates when op is 'upsert'.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function computeDirtyDatesForUpsert(
        Branch $branch,
        ?string $oldBusinessDate,
        ?string $newBusinessDate,
        array $data,
        ?Model $existing,
    ): array {
        if (! $this->hasBusinessDate()) {
            return [];
        }

        $dates = [];
        if ($newBusinessDate) {
            $dates[] = $newBusinessDate;
        }
        if ($oldBusinessDate && $oldBusinessDate !== $newBusinessDate) {
            $dates[] = $oldBusinessDate;
        }

        return array_values(array_unique($dates));
    }

    /**
     * @param  array<string, mixed>  $document
     */
    public function handle(Branch $branch, array $document): HandleResult
    {
        $uuid = (string) $document['uuid'];
        $version = (int) $document['version'];
        $op = (string) ($document['op'] ?? 'upsert');
        $data = (array) ($document['data'] ?? []);

        $modelClass = $this->modelClass();

        /** @var Model|null $existing */
        $existing = $modelClass::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('branch_id', $branch->id)
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first();

        // Version guard: if existing and source_version >= document version => skip
        if ($existing !== null && (int) $existing->getAttribute('source_version') >= $version) {
            return HandleResult::skipped();
        }

        if ($op === 'delete') {
            if ($existing === null) {
                return HandleResult::skipped();
            }

            $dirtyDates = $this->computeDirtyDatesForDelete($branch, $existing);

            $existing->setAttribute('deleted_at', Carbon::now());
            $existing->setAttribute('source_version', $version);
            $existing->setAttribute('ingested_at', Carbon::now());
            $existing->save();

            return HandleResult::applied($dirtyDates);
        }

        // op === 'upsert'
        $oldBusinessDate = $this->getBusinessDateString($existing);
        $newBusinessDate = $this->extractBusinessDateString($data);

        // Compute dirty dates before modifying $existing
        $dirtyDates = $this->computeDirtyDatesForUpsert(
            $branch,
            $oldBusinessDate,
            $newBusinessDate,
            $data,
            $existing
        );

        $parentAttributes = $this->mapParentAttributes($branch, $data, $document);
        $parentAttributes['organization_id'] = $branch->organization_id;
        $parentAttributes['branch_id'] = $branch->id;
        $parentAttributes['uuid'] = $uuid;
        $parentAttributes['source_version'] = $version;
        $parentAttributes['deleted_at'] = null;
        $parentAttributes['ingested_at'] = Carbon::now();

        if ($existing !== null) {
            $existing->fill($parentAttributes);
            $existing->setAttribute('deleted_at', null);
            $existing->save();
            $parent = $existing;
        } else {
            $parent = $modelClass::create($parentAttributes);
        }

        $this->replaceChildren($branch, $parent, $data, $document);

        return HandleResult::applied($dirtyDates, $this->isDayClosed());
    }
}
