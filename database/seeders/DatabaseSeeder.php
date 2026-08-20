<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(KPOneReferenceSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call(KPOneDevelopmentSeeder::class);
        }
    }
}
