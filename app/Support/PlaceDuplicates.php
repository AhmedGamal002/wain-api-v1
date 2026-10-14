<?php

namespace App\Support;

use App\Models\Place;
use App\Support\Search\SearchNormalizer;
use App\Support\Search\SearchVocabulary;

/**
 * تحذير التكرار (03 القسم 4.3 «الموقع» و4.6): أماكن موجودة جوه 200 متر من الـ pin واسمها شبه اسم المكان.
 *
 * «شبه» = بعد التطبيع (`SearchNormalizer`، المصدر الوحيد لتطبيع العربي) الاسم العربي أو الإنجليزي مطابق أو
 * جوه التاني (3 حروف فأكتر) أو الفرق بينهم حرفين بالكتير (لأسماء 5 حروف فأكتر). المكان القريب باسم مختلف
 * خالص (ناس كتير في مول واحد) مش بيتحذّر منه: ده الغلط الشائع الأقل، والتحذير الكتير بيتتجاهل.
 *
 * بيدوّر على كل الأماكن غير المحذوفة (مسودة ومخفي وغيرهم)، لإن الغلط الشائع تكرار مسودة زميل.
 */
final class PlaceDuplicates
{
    public const RADIUS_METERS = 200;

    /** المستطيل أكبر من الدايرة شوية، والدقة من المسافة الفعلية. */
    private const BOX_KM = 0.3;

    private const MAX_RESULTS = 20;

    /**
     * @return list<array{id: int, name_ar: string, name_en: string, status: string, distance_m: int}>
     */
    public function near(float $lat, float $lng, ?string $nameAr, ?string $nameEn, ?int $ignoreId = null): array
    {
        if (! PlaceLocation::isInsideEgypt($lat, $lng)) {
            return [];
        }

        $rows = Place::query()
            ->select(['places.id', 'places.name_ar', 'places.name_en', 'places.status'])
            ->selectRaw(PlaceLocation::distanceMetersSql($lat, $lng, 'places.location').' as distance_m')
            ->whereRaw(PlaceLocation::boundingBoxSql($lat, $lng, self::BOX_KM, 'places.location'))
            ->whereRaw(PlaceLocation::distanceMetersSql($lat, $lng, 'places.location').' <= ?', [self::RADIUS_METERS])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('distance_m')
            ->limit(self::MAX_RESULTS)
            ->get();

        $names = array_values(array_filter([
            SearchNormalizer::normalize($nameAr),
            SearchNormalizer::normalize($nameEn),
        ], fn (string $name) => $name !== ''));

        if ($names === []) {
            return [];
        }

        return $rows
            ->filter(fn (Place $place) => $this->similar($names, [
                SearchNormalizer::normalize($place->name_ar),
                SearchNormalizer::normalize($place->name_en),
            ]))
            ->map(fn (Place $place) => [
                'id' => $place->id,
                'name_ar' => (string) $place->name_ar,
                'name_en' => (string) $place->name_en,
                'status' => (string) $place->status,
                'distance_m' => (int) round((float) $place->getAttribute('distance_m')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $mine
     * @param  list<string>  $theirs
     */
    private function similar(array $mine, array $theirs): bool
    {
        foreach ($mine as $a) {
            foreach ($theirs as $b) {
                if ($b === '') {
                    continue;
                }

                if ($a === $b) {
                    return true;
                }

                if (mb_strlen($a) >= 3 && mb_strlen($b) >= 3 && (str_contains($a, $b) || str_contains($b, $a))) {
                    return true;
                }

                if (mb_strlen($a) >= 5 && mb_strlen($b) >= 5 && SearchVocabulary::distance($a, $b) <= 2) {
                    return true;
                }
            }
        }

        return false;
    }
}
