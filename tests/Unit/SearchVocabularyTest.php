<?php

namespace Tests\Unit;

use App\Support\Search\SearchVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * التصحيح الإملائي (02 F4): مسافة Levenshtein بالحروف (مش البايتات)، 1 للكلمات القصيرة و2 لما تزيد عن 6.
 */
class SearchVocabularyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function distances(): array
    {
        return [
            'same' => ['قهوه', 'قهوه', 0],
            'arabic substitution is one, not two bytes' => ['طازج', 'طازح', 1],
            'arabic insertion' => ['محطه', 'مححطه', 1],
            'arabic deletion' => ['بنن', 'بن', 1],
            'latin' => ['pizzaa', 'pizza', 1],
            'two edits' => ['rstaurnt', 'restaurant', 2],
            'mixed scripts' => ['cafe', 'كافيه', 5],
        ];
    }

    #[DataProvider('distances')]
    public function test_distance(string $a, string $b, int $expected): void
    {
        $this->assertSame($expected, SearchVocabulary::distance($a, $b));
    }

    public function test_nearest_picks_the_closest_word_inside_the_allowed_distance(): void
    {
        $vocabulary = ['طازج' => 5, 'طازجه' => 9, 'مطعم' => 12];

        // 4 حروف: مسافة 1 بس، فـ «طازجه» (مسافة 2) مش مرشحة حتى لو أكتر ظهوراً
        $this->assertSame('طازج', SearchVocabulary::nearest('طازح', $vocabulary));
    }

    public function test_nearest_returns_null_when_nothing_is_close_or_the_word_is_too_short(): void
    {
        $vocabulary = ['طازج' => 5, 'مطعم' => 12, 'قط' => 3];

        $this->assertNull(SearchVocabulary::nearest('كباب', $vocabulary));
        // حرفين: ما بتتصحّحش
        $this->assertNull(SearchVocabulary::nearest('قح', $vocabulary));
    }

    public function test_long_words_allow_two_edits_and_six_letters_only_one(): void
    {
        $vocabulary = ['restaurant' => 4, 'ستاديز' => 2];

        // 8 حروف، حرفين ناقصين
        $this->assertSame('restaurant', SearchVocabulary::nearest('rstaurnt', $vocabulary));
        // 6 حروف بحرفين غلط: خارج الحد (الأطول من 6 بس ياخد 2)
        $this->assertNull(SearchVocabulary::nearest('ستدييز', $vocabulary));
    }

    public function test_ties_go_to_the_word_in_more_places(): void
    {
        $vocabulary = ['بيتي' => 2, 'بيتا' => 7];

        $this->assertSame('بيتا', SearchVocabulary::nearest('بيتو', $vocabulary));
    }

    public function test_contains_matches_inside_a_word(): void
    {
        $vocabulary = ['كافيهات' => 3, 'مطعم' => 8];

        $this->assertTrue(SearchVocabulary::contains($vocabulary, 'كافيه'));
        $this->assertTrue(SearchVocabulary::contains($vocabulary, 'مطعم'));
        $this->assertFalse(SearchVocabulary::contains($vocabulary, 'كشري'));
    }

    public function test_numeric_words_work_as_keys(): void
    {
        // PHP بيحوّل المفتاح «2025» لرقم، والـ API ما يتأثرش
        $vocabulary = ['2025' => 2, 'مطعم' => 1];

        $this->assertTrue(SearchVocabulary::contains($vocabulary, '2025'));
        $this->assertSame('2025', SearchVocabulary::nearest('2025x', $vocabulary));
    }
}
