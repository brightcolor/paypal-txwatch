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
        'manage-pretix-connections',
        'manage-transactions',
        'manage-events',
        'manage-exports',
        'manage-users',
        'view-sync-logs',
        'view-audit-log',
        'view-reports',
        'view-audience',
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
            // Their own events only: AudienceQuery scopes every figure through CustomerScope.
            'view-audience',
        ]);

        $auditor = Role::findOrCreate('auditor');
        $auditor->syncPermissions([
            'view-sync-logs',
            'view-audit-log',
            'view-reports',
        ]);
    }
}
