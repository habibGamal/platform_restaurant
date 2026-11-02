<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Assistant\AiAccess;
use App\Domain\Menu\RoleClassifier;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Assigns roles to the organization's menu items that do not have a trusted one yet. */
class ClassifyItemRoles implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** Seconds the unique lock is held at most, so a killed worker cannot block the organization for good. */
    public int $uniqueFor = 900;

    public function __construct(public int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(RoleClassifier $classifier): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $classifier->classify($organization);
    }

    /** Queue one job for every organization that has AI access. */
    public static function dispatchForEligibleOrganizations(): void
    {
        $access = app(AiAccess::class);

        Organization::query()
            ->where('ai_enabled', true)
            ->each(function (Organization $organization) use ($access): void {
                if ($access->allowsOrganization($organization)) {
                    self::dispatch($organization->id);
                }
            });
    }
}
