<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * البحث على الداتابيز (02 F4): `LIKE` على `places.search_text` لكل كلمة (AND بين الكلمات، OR بين بدائل
 * المرادفات)، والترتيب بدرجة التطابق: الاسم (3) ثم الـ alias (2) ثم الباقي (1) لكل كلمة، ومجموعهم.
 * التصحيح الإملائي (`correct`) بيعتمد على `SearchVocabulary`.
 *
 * `LIKE '%…%'` بيعمل scan على الأماكن المنشورة بس (status = active)، وده كفاية لمئات أو آلاف الأماكن
 * (القياس في CLAUDE.md). مفيش حروف خاصة في الكلمات (المطبِّع بيشيلها)، وبنعمل escape برضه احتياطي.
 */
final class DatabasePlaceSearch implements PlaceSearch
{
    /** أكتر عدد كلمات بنبحث بيه، الزيادة بتتتجاهل. */
    public const MAX_WORDS = 6;

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
        $aliases = "SUBSTRING_INDEX(SUBSTRING_INDEX(places.search_text, '{$sep}', 2), '{$sep}', -1)";

        $scores = [];
        $bindings = [];

        // الوحدة الأضيق (بدايل أقل، وكلمة أطول) الأول: الـ AND بيقيّم بالترتيب، فالوحدة الواسعة (كافيه وبدايلها 9)
        // بتتقيّم على الصفوف اللي عدّت الأضيقة بس بدل الأماكن كلها (القياس في CLAUDE.md)
        $order = array_keys($plan->units);
        usort($order, fn (int $a, int $b) => [count($plan->units[$a]), -mb_strlen($plan->sources[$a]), $a]
            <=> [count($plan->units[$b]), -mb_strlen($plan->sources[$b]), $b]);

        foreach ($order as $index) {
            $alternatives = $plan->units[$index];
            $patterns = array_map(fn (string $alt) => '%'.addcslashes($alt, '%_\\').'%', $alternatives);

            $places->where(function (Builder $unit) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $unit->orWhere('places.search_text', 'like', $pattern);
                }
            });

            $inNames = implode(' OR ', array_fill(0, count($patterns), "{$names} LIKE ?"));
            $inAliases = implode(' OR ', array_fill(0, count($patterns), "{$aliases} LIKE ?"));

            $scores[] = "CASE WHEN ({$inNames}) THEN 3 WHEN ({$inAliases}) THEN 2 ELSE 1 END";
            array_push($bindings, ...$patterns, ...$patterns);
        }

        $places->selectRaw('('.implode(' + ', $scores).') AS relevance', $bindings);
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
