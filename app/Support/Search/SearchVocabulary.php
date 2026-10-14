<?php

namespace App\Support\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * قاموس كلمات الأماكن المنشورة (من `search_text`) لتصحيح الأخطاء الإملائية (02 F4): لو نتايج البحث أقل من 3،
 * كل كلمة مش موجودة بتتبدّل بأقرب كلمة في القاموس بمسافة Levenshtein (1 للكلمات القصيرة و2 لما تزيد عن
 * 6 حروف). القيم مبدئية وبتتظبط بالقياس.
 *
 * بيتخزّن في الـ cache (array كلمة ← عدد الأماكن اللي فيها، من غير objects)، وبيتمسح مع أي تغيير في مكان
 * (`BuildPlaceSearchText`) ومع `places:rebuild-search`، والساعة هي الحد الأقصى لو حاجة فاتت.
 */
final class SearchVocabulary
{
    public const CACHE_KEY = 'search:vocabulary';

    public const TTL_MINUTES = 60;

    /** أقصر كلمة بتدخل القاموس (بعد شيل «ال»: البن ← بن). */
    public const MIN_WORD_LENGTH = 2;

    /** كلمة المستخدم الأقصر من كده ما بتتصحّحش (كلمة من حرفين بتبعد بحرف واحد عن نص القاموس). */
    public const MIN_LENGTH_TO_CORRECT = 3;

    /** بعد الطول ده المسافة المسموحة 2 بدل 1. */
    public const LONG_WORD_LENGTH = 6;

    /** @var array<string, string> حرف UTF-8 ← بايت واحد، لـ levenshtein() بتاعة PHP (بتحسب بايتات) */
    private static array $bytes = [];

    /**
     * @return array<string|int, int> الكلمة ← عدد الأماكن
     */
    public static function words(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::TTL_MINUTES), self::build(...));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * كلمات الاسمين والـ aliases للأماكن المنشورة + أسماء التصنيفات والخصائص والمناطق. الوصف بره: الغلط
     * الإملائي المهم هو في الأسماء، والوصف بيكبّر القاموس ويبطّئ بناءه (القياس على 5000 مكان في CLAUDE.md).
     *
     * @return array<string|int, int>
     */
    private static function build(): array
    {
        $words = [];
        $add = function (string $text) use (&$words): void {
            // «ال» بتتشال زي ما بتتشال من كلمة المستخدم، وإلا «المحطه» تبعد عن «محته» بحرفين
            $unique = array_unique(array_map(SearchNormalizer::stripArticle(...), SearchNormalizer::words($text)));

            foreach ($unique as $word) {
                if (mb_strlen($word) >= self::MIN_WORD_LENGTH) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
        };

        // الجزءين الأول والتاني من search_text (الاسمين | الـ aliases) بالـ SQL، من غير الوصف
        DB::table('places')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereNotNull('search_text')
            ->orderBy('id')
            ->selectRaw('id, SUBSTRING_INDEX(search_text, ?, 2) AS words', [PlaceSearchText::SEPARATOR])
            ->chunkById(1000, function ($rows) use ($add): void {
                foreach ($rows as $row) {
                    $add($row->words);
                }
            });

        foreach (DB::table('categories')->get(['name_ar', 'name_en']) as $row) {
            $add($row->name_ar.' '.$row->name_en);
        }

        foreach (DB::table('attributes')->where('is_active', true)->get(['name_ar', 'name_en']) as $row) {
            $add($row->name_ar.' '.$row->name_en);
        }

        foreach (DB::table('areas')->get(['name_ar', 'name_en', 'aliases']) as $row) {
            $add($row->name_ar.' '.$row->name_en.' '.implode(' ', (array) json_decode((string) $row->aliases, true)));
        }

        return $words;
    }

    /**
     * الكلمة موجودة كاملة أو جزء من أي كلمة في القاموس (البحث بـ LIKE على جزء من النص).
     *
     * @param  array<string|int, int>  $vocabulary
     */
    public static function contains(array $vocabulary, string $term): bool
    {
        if (isset($vocabulary[$term])) {
            return true;
        }

        foreach ($vocabulary as $word => $_) {
            if (str_contains((string) $word, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * أقرب كلمة في القاموس لـ $term داخل المسافة المسموحة (ولو اتساووا، الأكتر ظهوراً)، أو null.
     *
     * @param  array<string|int, int>  $vocabulary
     */
    public static function nearest(string $term, array $vocabulary): ?string
    {
        $length = mb_strlen($term);

        if ($length < self::MIN_LENGTH_TO_CORRECT) {
            return null;
        }

        $max = $length > self::LONG_WORD_LENGTH ? 2 : 1;
        $best = null;
        $bestDistance = $max + 1;
        $bestFrequency = 0;

        foreach ($vocabulary as $word => $frequency) {
            $word = (string) $word;

            if (abs(mb_strlen($word) - $length) > $max) {
                continue;
            }

            $distance = self::distance($term, $word);

            if ($distance > $max) {
                continue;
            }

            if ($distance < $bestDistance || ($distance === $bestDistance && $frequency > $bestFrequency)) {
                $best = $word;
                $bestDistance = $distance;
                $bestFrequency = $frequency;
            }
        }

        return $best;
    }

    /**
     * مسافة Levenshtein بالحروف (مش بالبايتات: الحرف العربي بايتين في UTF-8).
     */
    public static function distance(string $a, string $b): int
    {
        $x = self::encode($a);
        $y = self::encode($b);

        if ($x === null || $y === null) {
            return PHP_INT_MAX;
        }

        return levenshtein($x, $y);
    }

    /**
     * كل حرف بقى بايت واحد (بالترتيب اللي ظهر بيه)، فـ levenshtein() بتحسب صح. لو القاموس فيه أكتر من 255 حرف
     * مختلف (مش متوقع) الكلمة بتتخطّى.
     */
    private static function encode(string $word): ?string
    {
        $encoded = '';

        foreach (mb_str_split($word) as $char) {
            if (! isset(self::$bytes[$char])) {
                if (count(self::$bytes) >= 255) {
                    return null;
                }

                self::$bytes[$char] = chr(count(self::$bytes) + 1);
            }

            $encoded .= self::$bytes[$char];
        }

        return $encoded;
    }
}
