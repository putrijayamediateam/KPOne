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

        $branches = [
            'CHERAS' => [
                'Cheras',
                'No. 19 G, Jalan Dwitasik 1, Dataran Dwitasik, 56000 Cheras, Kuala Lumpur',
                'https://www.google.com/maps/place/Klinik+Putrijaya+Cheras/@3.0993644,101.7122966,17z/data=!3m1!4b1!4m6!3m5!1s0x31cc35f6d3880513:0x75d150adf5d53ee1!8m2!3d3.0993644!4d101.7122966!16s%2Fg%2F1q67p_j5g?entry=ttu&g_ep=EgoyMDI2MDkzMC4wIKXMDSoASAFQAw%3D%3D',
                3.0993644,
                101.7122966,
            ],
            'SUNGAI_BESI' => [
                'Sungai Besi',
                'Medan Niaga Tasik Damai, 71, Jalan Tasik Utama 6, Sungai Besi, 57000 Kuala Lumpur',
                'https://www.google.com/maps/place/Klinik+Putrijaya+Sungai+Besi/@3.0626414,101.7081911,16z/data=!3m1!4b1!4m6!3m5!1s0x4792701f0389d70d:0xdc695bcc8ad1e057!8m2!3d3.062636!4d101.710766!16s%2Fg%2F11vxh6h3js?entry=tts&g_ep=EgoyMDI2MDYyOC4wIPu8ASoASAFQAw%3D%3D&skid=1277ae9f-ec37-440a-84ce-9ffa556ccdbb',
                3.062636,
                101.710766,
            ],
            'PUCHONG' => [
                'Puchong',
                '13, Jalan PU 13/2, Taman Puchong Utama, 47140 Puchong, Selangor',
                'https://www.google.com/maps/place/Klinik+Putrijaya+Puchong/@2.9832384,101.6061256,17z/data=!3m1!4b1!4m6!3m5!1s0x31cdb1c00f03346d:0xeb7dc8b8d0961590!8m2!3d2.983233!4d101.6087005!16s%2Fg%2F11lnwg16nn?entry=tts&g_ep=EgoyMDI2MDYyOC4wIPu8ASoASAFQAw%3D%3D&skid=b6b1aa18-c50e-47aa-8dad-b3680aef363d',
                2.983233,
                101.6087005,
            ],
        ];

        foreach ($branches as $code => [$name, $address, $mapUrl, $latitude, $longitude]) {
            $branch = Branch::query()->firstOrNew(
                ['organisation_id' => $organisation->id, 'code' => $code],
            );
            $branch->forceFill([
                'name' => $name,
                'timezone' => 'Asia/Kuala_Lumpur',
                'is_active' => true,
                'public_address' => $address,
                'public_map_url' => $mapUrl,
                'public_latitude' => $latitude,
                'public_longitude' => $longitude,
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
