<?php

namespace Tests\Unit;

use App\Support\Search\SynonymDictionary;
use PHPUnit\Framework\TestCase;

class SynonymDictionaryTest extends TestCase
{
    public function test_every_member_expands_to_the_whole_group(): void
    {
        $dictionary = new SynonymDictionary([['كافيه', 'كوفي', 'cafe', 'coffee']]);

        foreach (['كافيه', 'كوفي', 'cafe', 'coffee'] as $term) {
            $this->assertEqualsCanonicalizing(['كافيه', 'كوفي', 'cafe', 'coffee'], $dictionary->alternatives($term), $term);
        }
    }

    public function test_members_are_normalized_like_the_search_text(): void
    {
        $dictionary = new SynonymDictionary([['كَافيه', 'Coffee Shop', 'قهوة']]);

        $this->assertTrue($dictionary->knows('كافيه'));
        $this->assertTrue($dictionary->knows('coffee shop'));
        // ة ← ه
        $this->assertTrue($dictionary->knows('قهوه'));
        $this->assertFalse($dictionary->knows('قهوة'));
    }

    public function test_the_article_is_removed_from_entries(): void
    {
        $dictionary = new SynonymDictionary([['التجمع الخامس', 'new cairo']]);

        $this->assertSame(['تجمع خامس', 'new cairo'], $dictionary->alternatives('تجمع خامس'));
        $this->assertSame([], $dictionary->alternatives('التجمع الخامس'));
    }

    public function test_unknown_terms_have_no_alternatives(): void
    {
        $dictionary = new SynonymDictionary([['كافيه', 'cafe']]);

        $this->assertSame([], $dictionary->alternatives('مطعم'));
        $this->assertFalse($dictionary->knows('مطعم'));
    }

    public function test_a_group_needs_two_distinct_members(): void
    {
        $dictionary = new SynonymDictionary([['كافيه'], ['روف', 'رووف', 'روف']]);

        $this->assertFalse($dictionary->knows('كافيه'));
        $this->assertTrue($dictionary->knows('روف'));
    }

    public function test_phrases_are_limited_to_three_words(): void
    {
        $dictionary = new SynonymDictionary([['one two three four', 'x'], ['a b c', 'y']]);

        $this->assertFalse($dictionary->knows('one two three four'));
        $this->assertTrue($dictionary->knows('a b c'));
    }

    public function test_a_term_in_two_groups_goes_to_the_last_one(): void
    {
        $dictionary = new SynonymDictionary([['جنينة', 'garden'], ['جنينة', 'outdoor']]);

        // الإدخالات والبحث مطبَّعين (ة ← ه)
        $this->assertEqualsCanonicalizing(['جنينه', 'outdoor'], $dictionary->alternatives('جنينه'));
        // الكلمة التانية فضلت في مجموعتها الأولى (الكلمة المتكررة بس هي اللي بتروح لآخر مجموعة)
        $this->assertEqualsCanonicalizing(['جنينه', 'garden'], $dictionary->alternatives('garden'));
    }
}
