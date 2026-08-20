<?php

namespace Database\Seeders;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class KPOneReferenceSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $organisation = Organisation::query()->firstOrNew(['code' => 'KLINIK_PUTRIJAYA']);
        $organisation->forceFill(['name' => 'Klinik Putrijaya', 'is_active' => true])->save();

        foreach (['CHERAS' => 'Cheras', 'SUNGAI_BESI' => 'Sungai Besi', 'PUCHONG' => 'Puchong'] as $code => $name) {
            $branch = Branch::query()->firstOrNew(
                ['organisation_id' => $organisation->id, 'code' => $code],
            );
            $branch->forceFill([
                'name' => $name,
                'timezone' => 'Asia/Kuala_Lumpur',
                'is_active' => true,
            ])->save();
        }

        foreach ([
            'Clinical',
            'Clinic Operations',
            'Leadership',
            'Panel',
            'Finance',
            'Business Development',
            'Technology',
            'HR / Management',
            'Marketing',
        ] as $name) {
            $department = Department::query()->firstOrNew(
                ['organisation_id' => $organisation->id, 'code' => Str::of($name)->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString()],
            );
            $department->forceFill(['name' => $name, 'is_active' => true])->save();
        }

        foreach (PermissionCatalogue::all() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (PermissionCatalogue::roles() as $name => $permissions) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
                ->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
