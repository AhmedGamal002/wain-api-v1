<?php

namespace App\Support\Search;

use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;

/**
 * البحث على الداتابيز (02 F4): `LIKE` على `places.search_text` لكل كلمة (AND بين الكلمات، OR بين بدائل
 * المرادفات)، والترتيب بدرجة التطابق لكل كلمة ومجموعهم: بداية كلمة في الاسم (7 للكلمة اللي المستخدم كتبها و6
 * لمرادفها) ثم جوه الاسم (4) ثم بداية كلمة في الـ alias (3) ثم جوه الـ alias (2) ثم الباقي (1)، وفوقهم 8 للاسم
 * اللي بيبدأ بالكلمة كلها. بداية الكلمة هي اللي بتفرّق «قهوة المحطة» عن «حبة قهوة»، وهي اللي بيحسّها المستخدم
 * وهو بيكتب، وبتاخد +100 جوه الدرجة عشان isPrefixMatch يعرفها من الـ relevance من غير عمود تاني.
 * التصحيح الإملائي (`correct`) بيعتمد على `SearchVocabulary`.
 *
 * `LIKE '%…%'` بيعمل scan على الأماكن المنشورة بس (status = active)، وده كفاية لمئات أو آلاف الأماكن
 * (القياس في CLAUDE.md). مفيش حروف خاصة في الكلمات (المطبِّع بيشيلها)، وبنعمل escape برضه احتياطي.
 */
final class DatabasePlaceSearch implements PlaceSearch
{
    /** أكتر عدد كلمات بنبحث بيه، الزيادة بتتتجاهل. */
    public const MAX_WORDS = 6;

    /** وزن «بداية كلمة» في درجة الكلمة الواحدة: أكبر من أي مجموع درجات عادية (6 كلمات × 7 + 8). */
    private const PREFIX_WEIGHT = 100;

    public function __construct(private readonly SynonymDictionary $synonyms) {}

    public function plan(string $query): SearchPlan
    {
        $normalized = SearchNormalizer::normalize($query);
        $words = $normalized === '' ? [] : explode(' ', $normalized);

        // حرف واحد («و»، «أ») بيطابق نص كتير وملهوش لازمة لو معاه كلمات تانية
        if (count($words) > 1) {
            $words = array_values(array_filter($words, fn (string $word) => mb_strlen($word) > 1));
        }

        $words = array_slice(array_map(SearchNormalizer::stripArticle(...), $words), 0, self::MAX_WORDS);

        [$units, $sources] = $this->units($words);

        return new SearchPlan($normalized, $units, $sources);
    }

    public function constrain(Builder $places, SearchPlan $plan): void
    {
        $sep = PlaceSearchText::SEPARATOR;
        $names = "SUBSTRING_INDEX(places.search_text, '{$sep}', 1)";
        // الاسمين والـ aliases من غير الباقي (الوصف والتصنيف): الباقي هو الأطول، وأغلب الصفوف بتطابق فيه بس
        $head = "SUBSTRING_INDEX(places.search_text, '{$sep}', 2)";
        $aliases = "SUBSTRING_INDEX({$head}, '{$sep}', -1)";
        // بداية كلمة = بعد مسافة، و«ال» بتتشال من أول كل كلمة: «بحر» بداية «البحر» (كلمة البحث بتتشال منها «ال» برضه)
        $spacedNames = "REPLACE(CONCAT(' ', {$names}), ' ال', ' ')";
        $spacedAliases = "REPLACE(CONCAT(' ', {$aliases}), ' ال', ' ')";

        $scores = [];
        $bindings = [];

        // الوحدة الأضيق (بدايل أقل، وكلمة أطول) الأول: الـ AND بيقيّم بالترتيب، فالوحدة الواسعة (كافيه وبدايلها 9)
        // بتتقيّم على الصفوف اللي عدّت الأضيقة بس بدل الأماكن كلها (القياس في CLAUDE.md)
        $order = array_keys($plan->units);
        usort($order, fn (int $a, int $b) => [count($plan->units[$a]), -mb_strlen($plan->sources[$a]), $a]
            <=> [count($plan->units[$b]), -mb_strlen($plan->sources[$b]), $b]);

        foreach ($order as $index) {
            $alternatives = $plan->units[$index];
            $anywhere = array_map(fn (string $alt) => '%'.self::escape($alt).'%', $alternatives);
            $wordStart = array_map(fn (string $alt) => '% '.self::escape($alt).'%', $alternatives);
            // الكلمة اللي المستخدم كتبها نفسها قبل مرادفاتها: «كافيه» تطلّع «نسمة كافيه» قبل «قهوة المحطة»
            $typedStart = ['% '.self::escape($plan->sources[$index]).'%'];

            $places->where(function (Builder $unit) use ($anywhere): void {
                foreach ($anywhere as $pattern) {
                    $unit->orWhere('places.search_text', 'like', $pattern);
                }
            });

            // درجة الكلمة: بداية كلمة في الاسم 107 (كلمته) أو 106 (مرادفها)، جوه الاسم 4، بداية كلمة في الـ alias 103،
            // جوه الـ alias 2، الباقي 1. المية دي علامة «بداية كلمة»: مجموع الدرجات / 100 = عدد الكلمات اللي هي
            // بدايات (isPrefixMatch)، فالترتيب بـ relevance لوحده بيحط الأماكن اللي كل كلماتها بدايات الأول. الفحص
            // الداخلي (4 شروط) بيتعمل بس للصفوف اللي الاسمين أو الـ aliases فيها الكلمة، والباقي بيعدّي بشرط واحد قصير.
            $scores[] = 'CASE WHEN ('.self::anyLike($head, count($anywhere)).') THEN CASE'
                .' WHEN ('.self::anyLike($spacedNames, count($typedStart)).') THEN 107'
                .' WHEN ('.self::anyLike($spacedNames, count($wordStart)).') THEN 106'
                .' WHEN ('.self::anyLike($names, count($anywhere)).') THEN 4'
                .' WHEN ('.self::anyLike($spacedAliases, count($wordStart)).') THEN 103'
                .' ELSE 2 END ELSE 1 END';
            array_push($bindings, ...$anywhere, ...$typedStart, ...$wordStart, ...$anywhere, ...$wordStart);
        }

        // الاسم اللي بيبدأ بالكلمة كلها قبل الاسم اللي الكلمة في نصّه: «قهوة المحطة» قبل «حبة قهوة»
        $lead = count($plan->units) === 1 ? $plan->sources[0] : $plan->text();
        $startsWith = [self::escape($lead).'%', 'ال'.self::escape($lead).'%'];
        $scores[] = 'CASE WHEN ('.self::anyLike($names, count($startsWith)).') THEN 8 ELSE 0 END';
        array_push($bindings, ...$startsWith);

        $places->selectRaw('('.implode(' + ', $scores).') AS relevance', $bindings);
    }

    public function isPrefixMatch(Place $place, SearchPlan $plan): bool
    {
        return intdiv((int) $place->getAttribute('relevance'), self::PREFIX_WEIGHT) >= count($plan->units);
    }

    /**
     * `expr LIKE ? OR expr LIKE ? ...` بعدد الأنماط.
     */
    private static function anyLike(string $expression, int $count): string
    {
        return implode(' OR ', array_fill(0, $count, "{$expression} LIKE ?"));
    }

    /**
     * مفيش حروف خاصة في الكلمات (المطبِّع بيشيلها)، وده احتياطي.
     */
    private static function escape(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    public function correct(SearchPlan $plan): ?SearchPlan
    {
        $vocabulary = SearchVocabulary::words();

        if ($vocabulary === []) {
            return null;
        }

        $units = [];
        $sources = [];
        $changed = false;

        foreach ($plan->units as $index => $alternatives) {
            $source = $plan->sources[$index];

            // كلمة من القاموس، أو جزء من كلمة في الأماكن، أو فيها أرقام: مفيش حاجة تتصحّح
            $known = $this->synonyms->knows($source)
                || preg_match('/\d/u', $source) === 1
                || SearchVocabulary::contains($vocabulary, $source);

            $nearest = $known ? null : SearchVocabulary::nearest($source, $vocabulary);

            if ($nearest === null) {
                $units[] = $alternatives;
                $sources[] = $source;

                continue;
            }

            $changed = true;
            $units[] = $this->synonyms->alternatives($nearest) ?: [$nearest];
            $sources[] = $nearest;
        }

        return $changed ? new SearchPlan($plan->query, $units, $sources, corrected: true) : null;
    }

    /**
     * كل كلمة (أو عبارة من القاموس، الأطول الأول) وحدة ببدائلها.
     *
     * @param  list<string>  $words
     * @return array{0: list<list<string>>, 1: list<string>}
     */
    private function units(array $words): array
    {
        $units = [];
        $sources = [];
        $count = count($words);
        $i = 0;

        while ($i < $count) {
            $phraseLength = 0;

            for ($n = min(SynonymDictionary::MAX_PHRASE_WORDS, $count - $i); $n >= 2; $n--) {
                $phrase = implode(' ', array_slice($words, $i, $n));
                $alternatives = $this->synonyms->alternatives($phrase);

                if ($alternatives !== []) {
                    $units[] = [$phrase, ...array_values(array_diff($alternatives, [$phrase]))];
                    $sources[] = $phrase;
                    $phraseLength = $n;

                    break;
                }
            }

            if ($phraseLength > 0) {
                $i += $phraseLength;

                continue;
            }

            $word = $words[$i];
            $alternatives = $this->synonyms->alternatives($word);

            $units[] = $alternatives !== [] ? [$word, ...array_values(array_diff($alternatives, [$word]))] : [$word];
            $sources[] = $word;
            $i++;
        }

        return [$units, $sources];
    }
}
