<?php

namespace App\Support\Search;

use App\Models\SearchLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * بيكتب عملية بحث في `search_logs` (06 القسم 5، 02 F4 «تسجيل البحث»). بيتنادى بس لما الطلب جاي بـ `log=1`،
 * والموبايل بيبعتها لما المستخدم يدوس Enter أو يفتح نتيجة أو تثبت شاشة «مفيش نتائج» لثانية، فمفيش صف لكل حرف.
 * بيتسجّل: الكلمة مطبَّعة (زي ما اتبحث بيها)، والمنطقة، وعدد النتايج اللي المستخدم شافها، والمستخدم لو داخل،
 * والجهاز لو بعت `X-Device-Id` UUID صحيح.
 */
final class SearchLogger
{
    /** طول عمود `query`. */
    public const MAX_QUERY_LENGTH = 120;

    public static function record(string $normalizedQuery, int $resultsCount, ?string $areaSlug, ?int $userId, ?string $deviceId): ?SearchLog
    {
        $query = mb_substr(trim($normalizedQuery), 0, self::MAX_QUERY_LENGTH);

        if ($query === '') {
            return null;
        }

        return SearchLog::query()->create([
            'query' => $query,
            'area_id' => $areaSlug === null ? null : DB::table('areas')->where('slug', $areaSlug)->value('id'),
            'results_count' => max(0, $resultsCount),
            'user_id' => $userId,
            'device_id' => $deviceId !== null && Str::isUuid($deviceId) ? strtolower($deviceId) : null,
        ]);
    }
}
