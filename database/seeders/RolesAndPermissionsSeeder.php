<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'analytics.view',
            'analytics.view_group',
            'analytics.view_other_branch_names',
            'ai.view',
            'ai.manage',
            'targets.manage',
            'users.manage',
            'alerts.manage',
            'alerts.acknowledge',
            'branches.manage',
            'export',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $rolesWithPermissions = [
            'operator' => [
                'branches.manage',
            ],
            'org_owner' => $permissions,
            'org_analyst' => [
                'analytics.view',
                'analytics.view_group',
                'analytics.view_other_branch_names',
                'ai.view',
                'export',
            ],
            'branch_manager' => [
                'analytics.view',
                'alerts.acknowledge',
                'export',
            ],
        ];

        foreach ($rolesWithPermissions as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }
    }
}
