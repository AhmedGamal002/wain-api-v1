<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مولّد الـ slug للمكان (03 القسم 7.7): من `name_en`، ولو متكرر بيتضاف slug المنطقة، ولو لسه متكرر بيتضاف رقم.
 *
 * بيتخطى `Place::RESERVED_SLUGS` (`nearby` و`count` و`map`) لإنها مسارات ثابتة تحت `/places`، وبيتخطى
 * الـ slugs القديمة في `place_slug_redirects` عشان مكان جديد ما يخطفش رابط متشارك لمكان تاني. المحذوف (soft)
 * محسوب كمان لإن الـ unique index بيشمله.
 */
final class PlaceSlug
{
    /** أقصى طول للأساس، والباقي لاسم المنطقة والرقم (العمود VARCHAR(140)). */
    private const BASE_LIMIT = 100;

    public static function generate(string $nameEn, ?int $areaId = null, ?int $ignorePlaceId = null): string
    {
        $base = rtrim(Str::limit(Str::slug($nameEn), self::BASE_LIMIT, ''), '-');
        $base = $base === '' ? 'place' : $base;

        if (! self::isTaken($base, $ignorePlaceId)) {
            return $base;
        }

        $areaSlug = $areaId === null ? null : DB::table('areas')->where('id', $areaId)->value('slug');
        if (is_string($areaSlug) && $areaSlug !== '') {
            $withArea = $base.'-'.$areaSlug;
            if (! self::isTaken($withArea, $ignorePlaceId)) {
                return $withArea;
            }
            $base = $withArea;
        }

        $suffix = 2;
        while (self::isTaken($base.'-'.$suffix, $ignorePlaceId)) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    /**
     * الـ slug مش متاح: مسار ثابت، أو لمكان تاني (حتى المحذوف)، أو slug قديم لمكان تاني.
     */
    public static function isTaken(string $slug, ?int $ignorePlaceId = null): bool
    {
        if (in_array($slug, Place::RESERVED_SLUGS, true)) {
            return true;
        }

        $places = DB::table('places')->where('slug', $slug);
        $redirects = DB::table('place_slug_redirects')->where('old_slug', $slug);
        if ($ignorePlaceId !== null) {
            $places->where('id', '!=', $ignorePlaceId);
            $redirects->where('place_id', '!=', $ignorePlaceId);
        }

        return $places->exists() || $redirects->exists();
    }
}
