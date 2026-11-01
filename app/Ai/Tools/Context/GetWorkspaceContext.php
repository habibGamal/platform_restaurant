<?php

declare(strict_types=1);

namespace App\Ai\Tools\Context;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\BranchSet;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class GetWorkspaceContext implements Tool
{
    use CachesToolResults, FormatsToolResult, UsesReportFilters;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'get_workspace_context';
    }

    public function description(): string
    {
        return 'Get current workspace context: today date, timezone, currency, user roles, accessible restaurants, branches, sets, AI permissions, org plan, and data freshness summary.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        try {
            return $this->cached($this->name(), $request->all(), function (): string {
                return $this->execute();
            });
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    protected function execute(): string
    {
        $timezone = $this->userTimezone();
        $today = CarbonImmutable::now($timezone);

        /** @var list<string> $roles */
        $roles = $this->user->getRoleNames()->values()->all();

        $accessibleBranches = AccessibleBranches::query($this->user)->get();
        $accessibleBranchIds = $accessibleBranches->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $restaurantIds = $accessibleBranches->pluck('restaurant_id')->filter()->unique()->all();
        $restaurants = Restaurant::withoutGlobalScopes()
            ->whereIn('id', $restaurantIds)
            ->get(['id', 'name'])
            ->map(fn ($r): array => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
            ])
            ->values()
            ->all();

        $branches = $accessibleBranches->map(function ($b): array {
            return [
                'id' => (int) $b->id,
                'restaurant_id' => (int) $b->restaurant_id,
                'name' => (string) $b->name,
                'timezone' => (string) $b->timezone,
                'opened_on' => (string) $b->getRawOriginal('opened_on'),
                'status' => (string) $b->getRawOriginal('status'),
            ];
        })->values()->all();

        $branchSets = BranchSet::withoutGlobalScopes()
            ->where('organization_id', $this->user->organization_id)
            ->where(function ($q): void {
                $q->whereNull('user_id')->orWhere('user_id', $this->user->id);
            })
            ->with('branches:id')
            ->get(['id', 'name'])
            ->map(fn ($set): array => [
                'id' => (int) $set->id,
                'name' => (string) $set->name,
                'branch_ids' => $set->branches
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $checkPermissions = [
            'ai.view',
            'analytics.view_group',
            'analytics.view_other_branch_names',
            'alerts.manage',
            'alerts.acknowledge',
            'branches.manage',
        ];
        $grantedPermissions = array_values(array_filter($checkPermissions, fn (string $p): bool => $this->user->can($p)));

        $dataFreshness = null;
        if (! empty($accessibleBranchIds)) {
            $mq = MetricsQuery::for($this->user, ReportFilters::fromArray([], $today));
            $health = DataHealthReport::for($mq)->summary();
            $dataFreshness = [
                'total_branches' => $health->totalBranches,
                'online_branches' => $health->onlineBranches,
                'delayed_branches' => $health->delayedBranches,
                'offline_branches' => $health->offlineBranches,
                'total_days' => $health->totalDays,
                'complete_days' => $health->completeDays,
                'open_reconciliation_issues' => $health->openReconciliationIssues,
                'rejected_docs_7_days' => $health->rejectedDocs7Days,
            ];
        }

        $org = $this->user->organization;

        $payload = [
            'today' => $today->toDateString(),
            'timezone' => $timezone,
            'currency' => $org !== null ? (string) $org->currency : 'EGP',
            'roles' => $roles,
            'accessible_restaurants' => $restaurants,
            'accessible_branches' => $branches,
            'branch_sets' => $branchSets,
            'permissions' => $grantedPermissions,
            'organization' => [
                'plan' => $org !== null ? (string) $org->getRawOriginal('plan') : 'basic',
                'ai_enabled' => $org !== null && (bool) $org->ai_enabled,
            ],
            'data_freshness' => $dataFreshness,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
