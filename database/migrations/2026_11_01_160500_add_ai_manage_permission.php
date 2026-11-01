<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('ai.manage', 'web');

        $role = Role::query()
            ->where('name', 'org_owner')
            ->where('guard_name', 'web')
            ->first();

        if ($role !== null && ! $role->hasPermissionTo('ai.manage', 'web')) {
            $role->givePermissionTo($permission);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::query()
            ->where('name', 'ai.manage')
            ->where('guard_name', 'web')
            ->first();

        if ($permission !== null) {
            $permission->delete();
        }
    }
};
