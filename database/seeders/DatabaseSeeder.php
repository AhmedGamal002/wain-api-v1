<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * الـ seeders اللي بتروح على production: التصنيفات والمدينة والمنطقة والخصائص (والأدوار
     * والإعدادات في أسبوع 2، وباقي المناطق في أسبوع 3). الأماكن التجريبية `local` بس.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            CityAreaSeeder::class,
            AttributeSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoPlaceSeeder::class);
        }
    }
}
