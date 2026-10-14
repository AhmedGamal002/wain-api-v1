<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Support\PlaceDuplicates;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CityAreaSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحذير التكرار (03 القسم 4.6): مكان شبه اسمه جوه 200 متر. الأماكن بتتحط بإحداثيات معروفة، والمسافة بالمتر:
 * 0.001 درجة عرض ≈ 111 متر.
 */
class PlaceDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 30.0300000;

    private const LNG = 31.4700000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, SettingSeeder::class, CategorySeeder::class, CityAreaSeeder::class, AttributeSeeder::class]);
    }

    /** @return list<array{id: int, name_ar: string, name_en: string, status: string, distance_m: int}> */
    private function near(string $nameAr, string $nameEn, ?int $ignore = null, float $latOffset = 0.0): array
    {
        return (new PlaceDuplicates)->near(self::LAT + $latOffset, self::LNG, $nameAr, $nameEn, $ignore);
    }

    private function place(string $nameAr, string $nameEn, float $latOffset = 0.0, array $extra = []): Place
    {
        return Place::factory()->create([
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'latitude' => self::LAT + $latOffset,
            'longitude' => self::LNG,
            ...$extra,
        ]);
    }

    public function test_the_same_name_within_200_meters_is_a_duplicate(): void
    {
        $existing = $this->place('سيلانترو', 'Cilantro', 0.001);

        $found = $this->near('سيلانترو', 'Cilantro');

        $this->assertCount(1, $found);
        $this->assertSame($existing->id, $found[0]['id']);
        $this->assertGreaterThan(100, $found[0]['distance_m']);
        $this->assertLessThan(125, $found[0]['distance_m']);
    }

    public function test_the_same_name_beyond_200_meters_is_not(): void
    {
        $this->place('سيلانترو', 'Cilantro', 0.003);

        $this->assertSame([], $this->near('سيلانترو', 'Cilantro'));
    }

    public function test_a_different_name_nearby_is_not_a_duplicate(): void
    {
        $this->place('مطعم الشرق', 'El Sharq Restaurant', 0.0005);

        $this->assertSame([], $this->near('سيلانترو', 'Cilantro'));
    }

    public function test_similar_spellings_are_duplicates(): void
    {
        $this->place('كافيه الأمل', 'Cafe El Amal', 0.0005);

        // همزة وتاء مربوطة ورا ومسافة، وإنجليزي بحروف كبيرة
        $this->assertCount(1, $this->near('كافيه الامل', 'CAFE EL AMAL'));
        // الاسم الإنجليزي لوحده كفاية
        $this->assertCount(1, $this->near('اسم تاني خالص', 'Cafe El Amal'));
        // أحد الاسمين جوه التاني
        $this->assertCount(1, $this->near('كافيه الأمل التجمع', 'Cafe El Amal New Cairo'));
    }

    public function test_a_place_does_not_warn_about_itself_or_deleted_places(): void
    {
        $self = $this->place('سيلانترو', 'Cilantro');
        $deleted = $this->place('سيلانترو', 'Cilantro', 0.0005);
        $deleted->delete();

        $this->assertSame([], $this->near('سيلانترو', 'Cilantro', ignore: $self->id));
    }

    public function test_drafts_and_hidden_places_count_too(): void
    {
        $this->place('سيلانترو', 'Cilantro', 0.0005, ['status' => Place::STATUS_DRAFT]);
        $this->place('سيلانترو', 'Cilantro', 0.0006, ['status' => Place::STATUS_HIDDEN]);

        $this->assertCount(2, $this->near('سيلانترو', 'Cilantro'));
    }

    public function test_coordinates_outside_egypt_find_nothing(): void
    {
        $this->assertSame([], (new PlaceDuplicates)->near(48.85, 2.35, 'سيلانترو', 'Cilantro'));
    }
}
