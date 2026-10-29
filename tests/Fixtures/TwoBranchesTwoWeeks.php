<?php

namespace Tests\Fixtures;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Warehouse\DimCategory;
use App\Models\Warehouse\DimExpenseType;
use App\Models\Warehouse\DimProduct;
use App\Models\Warehouse\DimSupplier;
use App\Models\Warehouse\WhDaySummary;
use App\Models\Warehouse\WhExpense;
use App\Models\Warehouse\WhInventoryDay;
use App\Models\Warehouse\WhOrder;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhOrderReturn;
use App\Models\Warehouse\WhOrderReturnItem;
use App\Models\Warehouse\WhPayment;
use App\Models\Warehouse\WhPurchase;
use App\Models\Warehouse\WhPurchaseItem;
use App\Models\Warehouse\WhRefund;
use App\Models\Warehouse\WhShift;
use App\Models\Warehouse\WhStocktaking;
use App\Models\Warehouse\WhStocktakingItem;
use App\Models\Warehouse\WhWaste;
use App\Models\Warehouse\WhWasteItem;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Shared test fixture:
 * 2 Branches (A = busy, B = quiet), 14 business days (2026-10-03 to 2026-10-16).
 *
 * EXPECTED numbers table (hand-calculated):
 * ------------------------------------------------------------------------------------------------------------------------
 * Date       | Br | Orders | Net Sales | Net Sales After Ret | COGS   | Gross Profit | Expenses | Labor   | Waste | Shortage | Net Profit
 * ------------------------------------------------------------------------------------------------------------------------
 * 2026-10-03 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-04 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00 (1 cancl order)
 * 2026-10-05 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 1050.00  | 1000.00 | 0.00  | 0.00     | -490.00 (salaries)
 * 2026-10-06 | A  | 6      | 920.00    | 820.00              | 360.00 | 500.00       | 50.00    | 0.00    | 0.00  | 0.00     | 450.00 (return 100)
 * 2026-10-07 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 50.00 | 0.00     | 460.00 (waste 50)
 * 2026-10-08 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 80.00    | 430.00 (shortage 80)
 * 2026-10-09 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-10 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-11 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-12 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-13 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-14 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-15 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * 2026-10-16 | A  | 6      | 920.00    | 920.00              | 360.00 | 560.00       | 50.00    | 0.00    | 0.00  | 0.00     | 510.00
 * ------------------------------------------------------------------------------------------------------------------------
 * Branch A 14-day totals: Orders = 84, Net Sales After Returns = 12780.00, Net Profit = 5950.00
 * ------------------------------------------------------------------------------------------------------------------------
 * 2026-10-03 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-04 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-05 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 520.00   | 500.00  | 0.00  | 0.00     | -325.00 (salaries)
 * 2026-10-06 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-07 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-08 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-09 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-10 | B  | (MISSING DAY - NO WAREHOUSE DATA, NO AGG ROW)
 * 2026-10-11 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 30.00 | 0.00     | 145.00 (waste 30)
 * 2026-10-12 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-13 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-14 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00 (mismatch in summary)
 * 2026-10-15 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * 2026-10-16 | B  | 2      | 320.00    | 320.00              | 125.00 | 195.00       | 20.00    | 0.00    | 0.00  | 0.00     | 175.00
 * ------------------------------------------------------------------------------------------------------------------------
 * Branch B 14-day totals (13 days data): Orders = 26, Net Sales After Returns = 4160.00, Net Profit = 1745.00
 * ------------------------------------------------------------------------------------------------------------------------
 */
class TwoBranchesTwoWeeks
{
    public Organization $organization;

    public Restaurant $restaurant;

    public Branch $branchA;

    public Branch $branchB;

    public User $owner;

    public User $analyst;

    public User $managerA;

    public const EXPECTED = [
        'A' => [
            '2026-10-03' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-04' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-05' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '1050.00',
                'labor_cost' => '1000.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '-490.00',
            ],
            '2026-10-06' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '820.00',
                'cogs' => '360.00',
                'gross_profit' => '500.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '450.00',
            ],
            '2026-10-07' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '50.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '460.00',
            ],
            '2026-10-08' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '80.00',
                'net_profit' => '430.00',
            ],
            '2026-10-09' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-10' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-11' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-12' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-13' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-14' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-15' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
            '2026-10-16' => [
                'orders_count' => 6,
                'net_sales' => '920.00',
                'net_sales_after_returns' => '920.00',
                'cogs' => '360.00',
                'gross_profit' => '560.00',
                'expenses_total' => '50.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '510.00',
            ],
        ],
        'B' => [
            '2026-10-03' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-04' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-05' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '520.00',
                'labor_cost' => '500.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '-325.00',
            ],
            '2026-10-06' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-07' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-08' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-09' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            // 2026-10-10: missing day, no agg row
            '2026-10-11' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '30.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '145.00',
            ],
            '2026-10-12' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-13' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-14' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-15' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
            '2026-10-16' => [
                'orders_count' => 2,
                'net_sales' => '320.00',
                'net_sales_after_returns' => '320.00',
                'cogs' => '125.00',
                'gross_profit' => '195.00',
                'expenses_total' => '20.00',
                'labor_cost' => '0.00',
                'waste_cost' => '0.00',
                'inventory_variance_cost' => '0.00',
                'net_profit' => '175.00',
            ],
        ],
    ];

    /** @var array<string, array<string, array<string, mixed>>> */
    public static array $EXPECTED = self::EXPECTED;

    public static function create(): self
    {
        $fixture = new self;

        // 1. Roles & Permissions
        (new RolesAndPermissionsSeeder)->run();

        // 2. Organization & Restaurant
        $fixture->organization = Organization::create([
            'name' => 'Org Pro',
            'slug' => 'org-pro-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $fixture->restaurant = Restaurant::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Restaurant R',
        ]);

        // 3. Branches A & B
        $fixture->branchA = Branch::create([
            'organization_id' => $fixture->organization->id,
            'restaurant_id' => $fixture->restaurant->id,
            'name' => 'Branch A',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);

        $fixture->branchB = Branch::create([
            'organization_id' => $fixture->organization->id,
            'restaurant_id' => $fixture->restaurant->id,
            'name' => 'Branch B',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);

        // 4. Users
        $fixture->owner = User::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Owner',
            'email' => 'owner-'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $fixture->owner->assignRole('org_owner');

        $fixture->analyst = User::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Analyst',
            'email' => 'analyst-'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $fixture->analyst->assignRole('org_analyst');

        $fixture->managerA = User::create([
            'organization_id' => $fixture->organization->id,
            'name' => 'Manager A',
            'email' => 'managera-'.Str::random(5).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $fixture->managerA->assignRole('branch_manager');
        $fixture->managerA->branches()->attach($fixture->branchA->id);

        // 5. Dimension Rows (shared product_ref, 2 categories, 6 products, 3 expense types, 1 supplier)
        $fixture->seedDimensions();

        // 6. 14 Days Warehouse Data
        $fixture->seedWarehouseData();

        // 7. Call metrics:rebuild for both branches over the range
        Artisan::call('metrics:rebuild', [
            '--branch' => [$fixture->branchA->id, $fixture->branchB->id],
            '--from' => '2026-10-03',
            '--to' => '2026-10-16',
        ]);

        return $fixture;
    }

    protected function seedDimensions(): void
    {
        $orgId = $this->organization->id;

        // Categories
        foreach ([$this->branchA, $this->branchB] as $branch) {
            DimCategory::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "cat-burgers-{$branch->id}",
                'name' => 'Burgers',
                'source_version' => 1,
            ]);
            DimCategory::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "cat-sides-{$branch->id}",
                'name' => 'Drinks & Sides',
                'source_version' => 1,
            ]);
        }

        // 6 Products with shared product_ref
        $productDefs = [
            ['BURGER-1', 'Single Burger', 'cat-burgers-', 100.00, 40.00],
            ['BURGER-2', 'Double Burger', 'cat-burgers-', 150.00, 60.00],
            ['BURGER-3', 'Cheese Burger', 'cat-burgers-', 120.00, 50.00],
            ['SIDE-1', 'Fries', 'cat-sides-', 40.00, 15.00],
            ['DRINK-1', 'Cola', 'cat-sides-', 30.00, 10.00],
            ['DRINK-2', 'Water', 'cat-sides-', 15.00, 5.00],
        ];

        foreach ($productDefs as $idx => [$ref, $name, $catPrefix, $price, $cost]) {
            CatalogItem::create([
                'organization_id' => $orgId,
                'product_ref' => $ref,
                'canonical_name' => $name,
            ]);

            foreach ([$this->branchA, $this->branchB] as $branch) {
                DimProduct::create([
                    'organization_id' => $orgId,
                    'branch_id' => $branch->id,
                    'uuid' => "prod-{$branch->id}-".($idx + 1),
                    'source_version' => 1,
                    'name' => $name,
                    'product_ref' => $ref,
                    'category_uuid' => "{$catPrefix}{$branch->id}",
                    'type' => 'manufactured',
                    'unit' => 'pcs',
                    'price' => $price,
                    'cost' => $cost,
                ]);
            }
        }

        // Expense Types
        foreach ([$this->branchA, $this->branchB] as $branch) {
            DimExpenseType::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "exp-salaries-{$branch->id}",
                'source_version' => 1,
                'name' => 'Salaries',
                'category' => 'labor',
                'avg_month_rate' => 30000.00,
            ]);
            DimExpenseType::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "exp-rent-{$branch->id}",
                'source_version' => 1,
                'name' => 'Rent',
                'category' => 'rent',
                'avg_month_rate' => null,
            ]);
            DimExpenseType::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "exp-utilities-{$branch->id}",
                'source_version' => 1,
                'name' => 'Utilities',
                'category' => 'other',
                'avg_month_rate' => null,
            ]);

            // Supplier
            DimSupplier::create([
                'organization_id' => $orgId,
                'branch_id' => $branch->id,
                'uuid' => "sup-1-{$branch->id}",
                'source_version' => 1,
                'name' => 'Main Supplier',
                'phone' => '01000000000',
            ]);
        }
    }

    protected function seedWarehouseData(): void
    {
        $dates = [
            '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06',
            '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10',
            '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14',
            '2026-10-15', '2026-10-16',
        ];

        $firstOrderItemUuidOnA = null;

        foreach ($dates as $date) {
            // Seed Branch A (busy: 6 orders/day)
            $firstOrderItemUuidOnA = $this->seedDayForBranchA($date, $firstOrderItemUuidOnA);

            // Seed Branch B (quiet: 2 orders/day, except missing on 2026-10-10)
            if ($date !== '2026-10-10') {
                $this->seedDayForBranchB($date);
            }
        }
    }

    protected function seedDayForBranchA(string $date, ?string $firstOrderItemUuid): ?string
    {
        $orgId = $this->organization->id;
        $branchId = $this->branchA->id;

        // 2 shifts
        $shift1Uuid = "shift-a-{$date}-1";
        $shift2Uuid = "shift-a-{$date}-2";

        WhShift::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $shift1Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'user_uuid' => 'user-cashier-1',
            'start_at' => "{$date} 09:00:00",
            'end_at' => "{$date} 17:00:00",
            'start_cash' => 500.00,
            'end_cash' => 1000.00,
            'real_cash' => 1000.00,
            'closed' => true,
        ]);

        WhShift::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $shift2Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'user_uuid' => 'user-cashier-2',
            'start_at' => "{$date} 17:00:00",
            'end_at' => "{$date} 23:00:00",
            'start_cash' => 1000.00,
            'end_cash' => 1500.00,
            'real_cash' => 1500.00,
            'closed' => true,
        ]);

        // 6 completed orders: 3 in shift 1, 3 in shift 2
        // Shift 1:
        // Order 1: 1×Burger 1 (100, cost 40) + 1×Cola (30, cost 10) = 130, cogs 50 (Cash)
        $o1Uuid = "order-a-{$date}-1";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o1Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 11,
            'sub_total' => 130.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 130.00,
            'items_discount' => 0.00,
            'cogs' => 50.00,
        ]);
        $item1Uuid = "item-a-{$date}-1-1";
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'uuid' => $item1Uuid,
            'product_uuid' => "prod-{$branchId}-1",
            'product_ref' => 'BURGER-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'item_discount' => 0.00,
            'total' => 100.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'uuid' => "item-a-{$date}-1-2",
            'product_uuid' => "prod-{$branchId}-5",
            'product_ref' => 'DRINK-1',
            'quantity' => 1,
            'price' => 30.00,
            'unit_cost' => 10.00,
            'item_discount' => 0.00,
            'total' => 30.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'method' => 'cash',
            'amount' => 130.00,
            'shift_uuid' => $shift1Uuid,
        ]);

        if ($firstOrderItemUuid === null) {
            $firstOrderItemUuid = $item1Uuid;
        }

        // Order 2: 1×Burger 2 (150, cost 60) + 1×Fries (40, cost 15) = 190, cogs 75 (Card)
        $o2Uuid = "order-a-{$date}-2";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o2Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 13,
            'sub_total' => 190.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 190.00,
            'items_discount' => 0.00,
            'cogs' => 75.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'uuid' => "item-a-{$date}-2-1",
            'product_uuid' => "prod-{$branchId}-2",
            'product_ref' => 'BURGER-2',
            'quantity' => 1,
            'price' => 150.00,
            'unit_cost' => 60.00,
            'item_discount' => 0.00,
            'total' => 150.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'uuid' => "item-a-{$date}-2-2",
            'product_uuid' => "prod-{$branchId}-4",
            'product_ref' => 'SIDE-1',
            'quantity' => 1,
            'price' => 40.00,
            'unit_cost' => 15.00,
            'item_discount' => 0.00,
            'total' => 40.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'method' => 'card',
            'amount' => 190.00,
            'shift_uuid' => $shift1Uuid,
        ]);

        // Order 3: 1×Burger 1 (100, cost 40) + 1×Fries (40, cost 15) = 140, cogs 55 (Cash)
        $o3Uuid = "order-a-{$date}-3";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o3Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'type' => 'takeaway',
            'status' => 'completed',
            'hour_of_day' => 15,
            'sub_total' => 140.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 140.00,
            'items_discount' => 0.00,
            'cogs' => 55.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o3Uuid,
            'uuid' => "item-a-{$date}-3-1",
            'product_uuid' => "prod-{$branchId}-1",
            'product_ref' => 'BURGER-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'item_discount' => 0.00,
            'total' => 100.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o3Uuid,
            'uuid' => "item-a-{$date}-3-2",
            'product_uuid' => "prod-{$branchId}-4",
            'product_ref' => 'SIDE-1',
            'quantity' => 1,
            'price' => 40.00,
            'unit_cost' => 15.00,
            'item_discount' => 0.00,
            'total' => 40.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o3Uuid,
            'method' => 'cash',
            'amount' => 140.00,
            'shift_uuid' => $shift1Uuid,
        ]);

        // Shift 2 (Orders 4, 5, 6 identical in amount and cogs to Orders 1, 2, 3)
        // Order 4:
        $o4Uuid = "order-a-{$date}-4";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o4Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift2Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 18,
            'sub_total' => 130.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 130.00,
            'items_discount' => 0.00,
            'cogs' => 50.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o4Uuid,
            'uuid' => "item-a-{$date}-4-1",
            'product_uuid' => "prod-{$branchId}-1",
            'product_ref' => 'BURGER-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'item_discount' => 0.00,
            'total' => 100.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o4Uuid,
            'uuid' => "item-a-{$date}-4-2",
            'product_uuid' => "prod-{$branchId}-5",
            'product_ref' => 'DRINK-1',
            'quantity' => 1,
            'price' => 30.00,
            'unit_cost' => 10.00,
            'item_discount' => 0.00,
            'total' => 30.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o4Uuid,
            'method' => 'cash',
            'amount' => 130.00,
            'shift_uuid' => $shift2Uuid,
        ]);

        // Order 5:
        $o5Uuid = "order-a-{$date}-5";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o5Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift2Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 20,
            'sub_total' => 190.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 190.00,
            'items_discount' => 0.00,
            'cogs' => 75.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o5Uuid,
            'uuid' => "item-a-{$date}-5-1",
            'product_uuid' => "prod-{$branchId}-2",
            'product_ref' => 'BURGER-2',
            'quantity' => 1,
            'price' => 150.00,
            'unit_cost' => 60.00,
            'item_discount' => 0.00,
            'total' => 150.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o5Uuid,
            'uuid' => "item-a-{$date}-5-2",
            'product_uuid' => "prod-{$branchId}-4",
            'product_ref' => 'SIDE-1',
            'quantity' => 1,
            'price' => 40.00,
            'unit_cost' => 15.00,
            'item_discount' => 0.00,
            'total' => 40.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o5Uuid,
            'method' => 'card',
            'amount' => 190.00,
            'shift_uuid' => $shift2Uuid,
        ]);

        // Order 6:
        $o6Uuid = "order-a-{$date}-6";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o6Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift2Uuid,
            'type' => 'takeaway',
            'status' => 'completed',
            'hour_of_day' => 22,
            'sub_total' => 140.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 140.00,
            'items_discount' => 0.00,
            'cogs' => 55.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o6Uuid,
            'uuid' => "item-a-{$date}-6-1",
            'product_uuid' => "prod-{$branchId}-1",
            'product_ref' => 'BURGER-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'item_discount' => 0.00,
            'total' => 100.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o6Uuid,
            'uuid' => "item-a-{$date}-6-2",
            'product_uuid' => "prod-{$branchId}-4",
            'product_ref' => 'SIDE-1',
            'quantity' => 1,
            'price' => 40.00,
            'unit_cost' => 15.00,
            'item_discount' => 0.00,
            'total' => 40.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o6Uuid,
            'method' => 'cash',
            'amount' => 140.00,
            'shift_uuid' => $shift2Uuid,
        ]);

        // Daily utility expense
        WhExpense::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "exp-a-{$date}-util",
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'expense_type_uuid' => "exp-utilities-{$branchId}",
            'amount' => 50.00,
            'notes' => 'Electricity and water',
        ]);

        $dayExpenses = 50.00;
        $dayWaste = 0.00;

        // Specific events on A:
        // 2026-10-04: 1 cancelled order & purchase invoice
        if ($date === '2026-10-04') {
            WhOrder::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => "order-a-{$date}-cancelled",
                'source_version' => 1,
                'business_date' => $date,
                'shift_uuid' => $shift1Uuid,
                'type' => 'dine_in',
                'status' => 'cancelled',
                'hour_of_day' => 12,
                'sub_total' => 100.00,
                'total' => 100.00,
            ]);

            $pUuid = "purch-a-{$date}";
            WhPurchase::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $pUuid,
                'source_version' => 1,
                'business_date' => $date,
                'kind' => 'invoice',
                'supplier_uuid' => "sup-1-{$branchId}",
                'total' => 500.00,
            ]);
            WhPurchaseItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'purchase_uuid' => $pUuid,
                'product_uuid' => "prod-{$branchId}-1",
                'quantity' => 10,
                'price' => 50.00,
                'total' => 500.00,
            ]);
        }

        // 2026-10-05: labor expense (1000.00)
        if ($date === '2026-10-05') {
            WhExpense::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => "exp-a-{$date}-salaries",
                'source_version' => 1,
                'business_date' => $date,
                'shift_uuid' => $shift1Uuid,
                'expense_type_uuid' => "exp-salaries-{$branchId}",
                'amount' => 1000.00,
                'notes' => 'Staff monthly salaries',
            ]);
            $dayExpenses += 1000.00;
        }

        // 2026-10-06: order_return with reverse_stock = 1 (refund 100, returned_cogs 40)
        if ($date === '2026-10-06') {
            $retUuid = "return-a-{$date}";
            WhOrderReturn::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $retUuid,
                'source_version' => 1,
                'business_date' => $date,
                'order_uuid' => 'order-a-2026-10-03-1',
                'shift_uuid' => $shift1Uuid,
                'total_refund' => 100.00,
                'reverse_stock' => true,
                'returned_cogs' => 40.00,
            ]);
            WhOrderReturnItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'return_uuid' => $retUuid,
                'order_item_uuid' => (string) $firstOrderItemUuid,
                'quantity' => 1,
                'refund_amount' => 100.00,
            ]);
            WhRefund::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'return_uuid' => $retUuid,
                'method' => 'cash',
                'amount' => 100.00,
            ]);
        }

        // 2026-10-07: waste (50.00)
        if ($date === '2026-10-07') {
            $wasteUuid = "waste-a-{$date}";
            WhWaste::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $wasteUuid,
                'source_version' => 1,
                'business_date' => $date,
                'total' => 50.00,
            ]);
            WhWasteItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'waste_uuid' => $wasteUuid,
                'product_uuid' => "prod-{$branchId}-1",
                'quantity' => 1,
                'price' => 50.00,
                'total' => 50.00,
            ]);
            $dayWaste = 50.00;
        }

        // 2026-10-08: stocktaking shortage (80.00)
        if ($date === '2026-10-08') {
            $stockUuid = "stock-a-{$date}";
            WhStocktaking::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $stockUuid,
                'source_version' => 1,
                'business_date' => $date,
                'total' => 80.00,
                'variance_cost' => 80.00,
            ]);
            WhStocktakingItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'stocktaking_uuid' => $stockUuid,
                'product_uuid' => "prod-{$branchId}-1",
                'stock_quantity' => 10,
                'real_quantity' => 8,
                'price' => 40.00,
                'total' => 80.00,
            ]);
        }

        // 2026-10-13: purchase return (50.00)
        if ($date === '2026-10-13') {
            WhPurchase::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => "purch-return-a-{$date}",
                'source_version' => 1,
                'business_date' => $date,
                'kind' => 'return',
                'supplier_uuid' => "sup-1-{$branchId}",
                'total' => 50.00,
            ]);
        }

        // Closed day records: inventory_day + day_summary
        WhInventoryDay::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "inv-day-a-{$date}",
            'source_version' => 1,
            'business_date' => $date,
        ]);

        WhDaySummary::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "day-sum-a-{$date}",
            'source_version' => 1,
            'business_date' => $date,
            'orders_count' => 6,
            'completed_count' => 6,
            'net_total' => 920.00,
            'discount_total' => 0.00,
            'cogs_total' => 360.00,
            'expenses_total' => $dayExpenses,
            'waste_total' => $dayWaste,
        ]);

        return $firstOrderItemUuid;
    }

    protected function seedDayForBranchB(string $date): void
    {
        $orgId = $this->organization->id;
        $branchId = $this->branchB->id;

        // 2 shifts
        $shift1Uuid = "shift-b-{$date}-1";
        $shift2Uuid = "shift-b-{$date}-2";

        WhShift::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $shift1Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'user_uuid' => 'user-b-cashier-1',
            'start_at' => "{$date} 10:00:00",
            'end_at' => "{$date} 16:00:00",
            'start_cash' => 200.00,
            'end_cash' => 500.00,
            'real_cash' => 500.00,
            'closed' => true,
        ]);

        WhShift::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $shift2Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'user_uuid' => 'user-b-cashier-2',
            'start_at' => "{$date} 16:00:00",
            'end_at' => "{$date} 22:00:00",
            'start_cash' => 500.00,
            'end_cash' => 800.00,
            'real_cash' => 800.00,
            'closed' => true,
        ]);

        // 2 completed orders: 1 in shift 1, 1 in shift 2
        // Shift 1: 1×Burger 1 (100, cost 40) + 1×Cola (30, cost 10) = 130, cogs 50 (Cash)
        $o1Uuid = "order-b-{$date}-1";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o1Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 12,
            'sub_total' => 130.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 130.00,
            'items_discount' => 0.00,
            'cogs' => 50.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'uuid' => "item-b-{$date}-1-1",
            'product_uuid' => "prod-{$branchId}-1",
            'product_ref' => 'BURGER-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'item_discount' => 0.00,
            'total' => 100.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'uuid' => "item-b-{$date}-1-2",
            'product_uuid' => "prod-{$branchId}-5",
            'product_ref' => 'DRINK-1',
            'quantity' => 1,
            'price' => 30.00,
            'unit_cost' => 10.00,
            'item_discount' => 0.00,
            'total' => 30.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o1Uuid,
            'method' => 'cash',
            'amount' => 130.00,
            'shift_uuid' => $shift1Uuid,
        ]);

        // Shift 2: 1×Burger 2 (150, cost 60) + 1×Fries (40, cost 15) = 190, cogs 75 (Card)
        $o2Uuid = "order-b-{$date}-2";
        WhOrder::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => $o2Uuid,
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift2Uuid,
            'type' => 'dine_in',
            'status' => 'completed',
            'hour_of_day' => 18,
            'sub_total' => 190.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'service' => 0.00,
            'total' => 190.00,
            'items_discount' => 0.00,
            'cogs' => 75.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'uuid' => "item-b-{$date}-2-1",
            'product_uuid' => "prod-{$branchId}-2",
            'product_ref' => 'BURGER-2',
            'quantity' => 1,
            'price' => 150.00,
            'unit_cost' => 60.00,
            'item_discount' => 0.00,
            'total' => 150.00,
        ]);
        WhOrderItem::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'uuid' => "item-b-{$date}-2-2",
            'product_uuid' => "prod-{$branchId}-4",
            'product_ref' => 'SIDE-1',
            'quantity' => 1,
            'price' => 40.00,
            'unit_cost' => 15.00,
            'item_discount' => 0.00,
            'total' => 40.00,
        ]);
        WhPayment::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'order_uuid' => $o2Uuid,
            'method' => 'card',
            'amount' => 190.00,
            'shift_uuid' => $shift2Uuid,
        ]);

        // Daily utility expense (20.00)
        WhExpense::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "exp-b-{$date}-util",
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => $shift1Uuid,
            'expense_type_uuid' => "exp-utilities-{$branchId}",
            'amount' => 20.00,
            'notes' => 'Branch B utilities',
        ]);

        $dayExpenses = 20.00;
        $dayWaste = 0.00;

        // 2026-10-04: purchase invoice (200.00)
        if ($date === '2026-10-04') {
            $pUuid = "purch-b-{$date}";
            WhPurchase::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $pUuid,
                'source_version' => 1,
                'business_date' => $date,
                'kind' => 'invoice',
                'supplier_uuid' => "sup-1-{$branchId}",
                'total' => 200.00,
            ]);
            WhPurchaseItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'purchase_uuid' => $pUuid,
                'product_uuid' => "prod-{$branchId}-1",
                'quantity' => 4,
                'price' => 50.00,
                'total' => 200.00,
            ]);
        }

        // 2026-10-05: labor expense (500.00)
        if ($date === '2026-10-05') {
            WhExpense::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => "exp-b-{$date}-salaries",
                'source_version' => 1,
                'business_date' => $date,
                'shift_uuid' => $shift1Uuid,
                'expense_type_uuid' => "exp-salaries-{$branchId}",
                'amount' => 500.00,
                'notes' => 'Branch B salaries',
            ]);
            $dayExpenses += 500.00;
        }

        // 2026-10-11: waste (30.00)
        if ($date === '2026-10-11') {
            $wUuid = "waste-b-{$date}";
            WhWaste::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'uuid' => $wUuid,
                'source_version' => 1,
                'business_date' => $date,
                'total' => 30.00,
            ]);
            WhWasteItem::create([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'waste_uuid' => $wUuid,
                'product_uuid' => "prod-{$branchId}-5",
                'quantity' => 1,
                'price' => 30.00,
                'total' => 30.00,
            ]);
            $dayWaste = 30.00;
        }

        // Closed day records: inventory_day + day_summary
        WhInventoryDay::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "inv-day-b-{$date}",
            'source_version' => 1,
            'business_date' => $date,
        ]);

        // Deliberate mismatch on 2026-10-14: net_total = 400.00 vs actual 320.00
        $summaryNetTotal = ($date === '2026-10-14') ? 400.00 : 320.00;

        WhDaySummary::create([
            'organization_id' => $orgId,
            'branch_id' => $branchId,
            'uuid' => "day-sum-b-{$date}",
            'source_version' => 1,
            'business_date' => $date,
            'orders_count' => 2,
            'completed_count' => 2,
            'net_total' => $summaryNetTotal,
            'discount_total' => 0.00,
            'cogs_total' => 125.00,
            'expenses_total' => $dayExpenses,
            'waste_total' => $dayWaste,
        ]);
    }
}
