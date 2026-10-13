<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * الـ seeders اللي بتروح على production: الأدوار وحسابات الأدمن والإعدادات والتصنيفات والمدينة
     * والمنطقة والخصائص (وباقي المناطق في أسبوع 3). الأماكن التجريبية `local` بس.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            SettingSeeder::class,
            CategorySeeder::class,
            CityAreaSeeder::class,
            AttributeSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoPlaceSeeder::class);
        }
    }
}
