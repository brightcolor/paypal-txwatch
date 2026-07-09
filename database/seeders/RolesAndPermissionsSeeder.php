<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'manage-paypal-accounts',
        'manage-transactions',
        'manage-events',
        'manage-exports',
        'manage-users',
        'view-sync-logs',
        'view-audit-log',
        'view-reports',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Role::findOrCreate('admin');
        $admin->syncPermissions(self::PERMISSIONS);

        $manager = Role::findOrCreate('manager');
        $manager->syncPermissions([
            'manage-transactions',
            'manage-events',
            'manage-exports',
            'view-sync-logs',
            'view-reports',
        ]);

        $customer = Role::findOrCreate('customer');
        $customer->syncPermissions([
            'view-reports',
        ]);

        $auditor = Role::findOrCreate('auditor');
        $auditor->syncPermissions([
            'view-sync-logs',
            'view-audit-log',
            'view-reports',
        ]);
    }
}
