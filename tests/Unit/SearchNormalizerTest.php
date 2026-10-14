<?php

namespace Tests\Unit;

use App\Support\Search\SearchNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * تطبيع البحث (02 F4): أ إ آ = ا، ة = ه، ى = ي، من غير تشكيل، وباقي القواعد.
 */
class SearchNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'alef forms' => ['أحمد إبراهيم آمن ٱلله', 'احمد ابراهيم امن الله'],
            'ta marbuta and alef maqsura' => ['مدرسة مستشفى', 'مدرسه مستشفي'],
            'tashkeel' => ['قَهْوَةٌ مُخْتَصَّة', 'قهوه مختصه'],
            'tatweel' => ['كـــافيه', 'كافيه'],
            'arabic indic digits' => ['٢٠٢٥ و٣٣', '2025 و33'],
            'persian digits and letters' => ['۱۲۳ کیف', '123 كيف'],
            'latin lowercase' => ['Coffee SHOP', 'coffee shop'],
            'punctuation becomes space' => ["Cafe's (Roof)! ... كافيه، روف", 'cafe s roof كافيه روف'],
            'separator and like wildcards are removed' => ['100%_|x', '100 x'],
            'repeated spaces' => ["  قهوة \t  \n مختصة ", 'قهوه مختصه'],
            'empty' => ['', ''],
            'null' => [null, ''],
            'symbols only' => ['؟؟ !! --', ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, SearchNormalizer::normalize($input));
    }

    public function test_the_output_never_contains_the_search_text_separator(): void
    {
        $this->assertStringNotContainsString('|', SearchNormalizer::normalize('a|b || c'));
    }

    public function test_it_is_idempotent(): void
    {
        $once = SearchNormalizer::normalize('أَحْمَد  الكافيه (روف) ٢٠٢٥');

        $this->assertSame($once, SearchNormalizer::normalize($once));
    }

    public function test_words(): void
    {
        $this->assertSame(['قهوه', 'مختصه'], SearchNormalizer::words('قهوة   مختصة'));
        $this->assertSame([], SearchNormalizer::words('؟؟'));
        $this->assertSame([], SearchNormalizer::words(null));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function articles(): array
    {
        return [
            'long word' => ['الكافيه', 'كافيه'],
            'area' => ['التجمع', 'تجمع'],
            'short word stays' => ['الف', 'الف'],
            'two letters stay' => ['ال', 'ال'],
            'no article' => ['كافيه', 'كافيه'],
            'latin' => ['alcafe', 'alcafe'],
        ];
    }

    #[DataProvider('articles')]
    public function test_strip_article(string $word, string $expected): void
    {
        $this->assertSame($expected, SearchNormalizer::stripArticle($word));
    }
}
