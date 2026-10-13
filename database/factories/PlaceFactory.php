<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\City;
use App\Models\Place;
use App\Models\PlacePhoto;
use App\Models\User;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * مكان للتستات. الافتراضي مسودة بالحقول الإلزامية بس (الاسمين والتصنيف والمنطقة والموقع والعنوان)، والـ
 * states بتكمّله: `complete()` بيحقق شروط النشر التسعة، و`published()` بيكمّل وينشر.
 *
 * بيستخدم التصنيفات والمدينة والمنطقة المبدئية (seeders)، وبيشغّلها لو لسه ما اتزرعتش.
 *
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    protected $model = Place::class;

    private static int $counter = 0;

    public function definition(): array
    {
        $n = ++self::$counter;
        [$category, $city, $area] = $this->catalog();

        return [
            'name_ar' => "مكان تجريبي {$n}",
            'name_en' => "Test Place {$n}",
            'primary_category_id' => $category->id,
            'city_id' => $city->id,
            'area_id' => $area->id,
            'address_ar' => "شارع التسعين {$n}، التجمع الخامس",
            'latitude' => 30.0300000,
            'longitude' => 31.4700000,
        ];
    }

    /**
     * التصنيف الرئيسي دايماً ضمن تصنيفات المكان (`place_category`)، زي ما الـ form هيحفظه.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Place $place) {
            $place->categories()->syncWithoutDetaching([$place->primary_category_id]);
        });
    }

    /**
     * يحقق شروط النشر التسعة: الوصف والسعر والمواعيد في الحقول، والخصائص والغلاف بعد الإنشاء.
     */
    public function complete(): static
    {
        return $this
            ->state(fn () => [
                'description_ar' => 'مكان هادي في التجمع الخامس مناسب للقعدات الطويلة والشغل والمذاكرة، وفيه منيو واسع.',
                'price_min' => 100,
                'price_max' => 200,
                'opening_hours' => self::weekHours(),
            ])
            ->afterCreating(function (Place $place) {
                $place->placeAttributes()->sync($this->attributeIds(3));

                $userId = $place->created_by ?? User::factory()->create()->id;
                $cover = PlacePhoto::create([
                    'place_id' => $place->id,
                    'type' => 'gallery',
                    'path' => "tests/{$place->slug}/cover.jpg",
                    'variants' => ['thumb' => 'https://example.test/t.webp', 'medium' => 'https://example.test/m.webp'],
                    'status' => 'ready',
                    'uploaded_by' => $userId,
                ]);

                $place->forceFill(['cover_photo_id' => $cover->id])->saveQuietly();
            });
    }

    public function published(): static
    {
        return $this->complete()->state(fn () => ['status' => Place::STATUS_ACTIVE]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /**
     * مواعيد الأيام السبعة: كل يوم 09:00 لـ 23:30.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function weekHours(): array
    {
        return array_fill_keys(
            ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'],
            ['state' => 'open', 'slots' => [['09:00', '23:30']]],
        );
    }

    /**
     * @return array{0: Category, 1: City, 2: Area}
     */
    private function catalog(): array
    {
        if (Category::query()->doesntExist()) {
            app(CategorySeeder::class)->run();
        }
        if (Area::query()->doesntExist()) {
            app(CityAreaSeeder::class)->run();
        }

        return [
            Category::query()->orderBy('id')->firstOrFail(),
            City::query()->orderBy('id')->firstOrFail(),
            Area::query()->orderBy('id')->firstOrFail(),
        ];
    }

    /**
     * @return list<int>
     */
    private function attributeIds(int $count): array
    {
        if (Attribute::query()->doesntExist()) {
            app(AttributeSeeder::class)->run();
        }

        return Attribute::query()->orderBy('id')->limit($count)->pluck('id')->all();
    }
}
