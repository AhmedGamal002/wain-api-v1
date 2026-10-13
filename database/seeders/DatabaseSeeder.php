<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * الـ seeders اللي بتروح على production: الأدوار وحسابات الأدمن والإعدادات والتصنيفات والمدينة
     * والمنطقة وباقي المناطق (غير فعّالة) والخصائص. الأماكن التجريبية `local` بس.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            SettingSeeder::class,
            CategorySeeder::class,
            CityAreaSeeder::class,
            AreaSeeder::class,
            AttributeSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoPlaceSeeder::class);
        }
    }
}
