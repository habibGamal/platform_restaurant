<?php

namespace Database\Seeders;

use App\Enums\BranchStatus;
use App\Enums\OrganizationPlan;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Platform Operator (no organization)
        $operator = User::updateOrCreate(
            ['email' => 'operator@example.com'],
            [
                'name' => 'Platform Operator',
                'password' => Hash::make('password'),
                'organization_id' => null,
            ]
        );
        $operator->syncRoles(['operator']);

        // Demo Organization
        $org = Organization::updateOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'مجموعة تجريبية',
                'plan' => OrganizationPlan::PRO,
                'ai_enabled' => true,
                'timezone' => 'Africa/Cairo',
                'currency' => 'EGP',
            ]
        );

        // Demo Restaurant
        $restaurant = Restaurant::withoutGlobalScopes()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'name' => 'مطعم تجريبي',
            ]
        );

        // Demo Branches (2 active)
        $branch1 = Branch::withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'فرع المعادي',
            ],
            [
                'status' => BranchStatus::ACTIVE,
                'timezone' => 'Africa/Cairo',
            ]
        );
        $branch1->update(['status' => BranchStatus::ACTIVE]);

        $branch2 = Branch::withoutGlobalScopes()->firstOrCreate(
            [
                'organization_id' => $org->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'فرع مدينة نصر',
            ],
            [
                'status' => BranchStatus::ACTIVE,
                'timezone' => 'Africa/Cairo',
            ]
        );
        $branch2->update(['status' => BranchStatus::ACTIVE]);

        // Demo Users
        $owner = User::updateOrCreate(
            ['email' => 'owner@example.com'],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'organization_id' => $org->id,
            ]
        );
        $owner->syncRoles(['org_owner']);

        $analyst = User::updateOrCreate(
            ['email' => 'analyst@example.com'],
            [
                'name' => 'Demo Analyst',
                'password' => Hash::make('password'),
                'organization_id' => $org->id,
            ]
        );
        $analyst->syncRoles(['org_analyst']);

        $manager = User::updateOrCreate(
            ['email' => 'manager@example.com'],
            [
                'name' => 'Demo Manager',
                'password' => Hash::make('password'),
                'organization_id' => $org->id,
            ]
        );
        $manager->syncRoles(['branch_manager']);
        $manager->branches()->sync([$branch1->id]);
    }
}
