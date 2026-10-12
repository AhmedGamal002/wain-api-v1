<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * الـ seeders اللي بتروح على production: التصنيفات والمدينة والمنطقة (والأدوار والخصائص
     * والإعدادات بتتضاف في أسبوع 2 و3). الأماكن التجريبية `local` بس.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            CityAreaSeeder::class,
        ]);
    }
}
