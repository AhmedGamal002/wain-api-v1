<?php

namespace App\Support;

use App\Http\Requests\IndexPlacesRequest;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * cache قوائم الأماكن (`places:list:v{places:version}:{hash(params)}`، 60 ثانية، 06 القسم 5).
 *
 * اللي بيتخزن هو **ترتيب الـ ids وعدد النتايج** بس (arrays وscalars، `cache.serializable_classes = false`)؛
 * الكارت نفسه (الاسم واللغة و`is_open_now` والمسافة والتصنيف والمنطقة والصورة) بيتبني كل طلب من الداتابيز بالـ ids
 * دي، فاللي بيختلف حسب المستخدم والوقت (`is_saved` و`distance_km` و`is_open_now`) عمره ما يتخزّن، وتغيير اسم تصنيف
 * بيبان فوراً.
 *
 * النسخة (`places:version`) بتتغيّر مع أي حفظ أو تغيير حالة في مكان (`BumpPlaceListVersion` على `PlaceChanged`) ومع أي
 * تعديل في المرجعيات (`IsCatalogItem`). الـ file cache مفيهوش tags ولا مسح بنمط، فالمفاتيح القديمة بتتساب لحد
 * ما تنتهي، و`cache:prune-expired` بيمسحها كل ليلة.
 *
 * مش بيتخزن (عشان المفاتيح ما تتكاثرش من غير فايدة): ترتيب «الأقرب» (المفتاح فيه إحداثيات)، و«مفتوح دلوقتي»
 * (النتيجة بتتغيّر مع الدقيقة)، والصفحات بعد الخامسة.
 */
final class PlaceListCache
{
    public const TTL_SECONDS = 60;

    /** أبعد صفحة بتتخزن: اللي بيوصلها المستخدمين فعلاً هي الأولى. */
    public const MAX_CACHED_PAGE = 5;

    private const VERSION_KEY = 'places:version';

    /**
     * @param  Closure(): array{ids: list<int>, meta: array<string, int>}  $resolve  بيجيب الصفحة من الداتابيز
     * @return array{ids: list<int>, meta: array<string, int>}
     */
    public static function remember(IndexPlacesRequest $request, Closure $resolve): array
    {
        if (! self::cacheable($request)) {
            return $resolve();
        }

        $key = self::key($request);
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['ids'], $cached['meta'])) {
            return $cached;
        }

        $fresh = $resolve();
        Cache::put($key, $fresh, now()->addSeconds(self::TTL_SECONDS));

        return $fresh;
    }

    /**
     * أي تغيير في مكان أو مرجعية بيخلّي كل القوايم المخزّنة تتعامل كـ miss. token (uuid) مش عدّاد: ما فيهوش
     * race بين طلبين بيزوّدوا في نفس اللحظة.
     */
    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::uuid());
    }

    public static function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '0');
    }

    public static function cacheable(IndexPlacesRequest $request): bool
    {
        return ! $request->boolean('open_now')
            && $request->sort() !== 'nearest'
            && self::page($request) <= self::MAX_CACHED_PAGE;
    }

    public static function key(IndexPlacesRequest $request): string
    {
        $filters = $request->validated();
        $sorted = fn (string $field): array => collect($filters[$field] ?? [])->unique()->sort()->values()->all();

        $parts = [
            'area' => $filters['area'] ?? null,
            'categories' => $sorted('categories'),
            'attributes' => $sorted('attributes'),
            'price_min' => isset($filters['price_min']) ? (int) $filters['price_min'] : null,
            'price_max' => isset($filters['price_max']) ? (int) $filters['price_max'] : null,
            'rating_min' => isset($filters['rating_min']) ? (float) $filters['rating_min'] : null,
            'sort' => $request->sort(),
            'page' => self::page($request),
            'per_page' => $request->perPage(),
            // الترتيب المقترح بيتبدّل كل يوم بتوقيت القاهرة (PlaceListOrder)
            'day' => $request->sort() === 'recommended' ? now(OpenNow::TIMEZONE)->toDateString() : null,
        ];

        return 'places:list:v'.self::version().':'.md5((string) json_encode($parts));
    }

    private static function page(IndexPlacesRequest $request): int
    {
        return max(1, (int) ($request->validated('page') ?? 1));
    }
}
