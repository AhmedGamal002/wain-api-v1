<?php

namespace Tests\Unit;

use App\Support\Search\DatabasePlaceSearch;
use App\Support\Search\SynonymDictionary;
use PHPUnit\Framework\TestCase;

/**
 * تحليل كلمة البحث لخطة (تطبيع، وشيل «ال»، وتوسيع المرادفات والعبارات). الاستعلام نفسه في SearchApiTest.
 */
class DatabasePlaceSearchPlanTest extends TestCase
{
    private function search(): DatabasePlaceSearch
    {
        return new DatabasePlaceSearch(new SynonymDictionary([
            ['كافيه', 'كوفي', 'cafe', 'coffee'],
            ['فاست فود', 'وجبات سريعة', 'fast food'],
            ['روف', 'roof', 'rooftop'],
        ]));
    }

    public function test_a_word_expands_to_its_synonyms(): void
    {
        $plan = $this->search()->plan('كوفي');

        $this->assertCount(1, $plan->units);
        $this->assertEqualsCanonicalizing(['كافيه', 'كوفي', 'cafe', 'coffee'], $plan->units[0]);
        $this->assertSame(['كوفي'], $plan->sources);
    }

    public function test_the_definite_article_and_hamza_do_not_matter(): void
    {
        $plan = $this->search()->plan('الكَافيه');

        $this->assertSame(['كافيه'], $plan->sources);
        $this->assertSame('الكافيه', $plan->query);
    }

    public function test_every_word_is_its_own_unit(): void
    {
        $plan = $this->search()->plan('كافيه هادي روف');

        $this->assertCount(3, $plan->units);
        $this->assertSame(['هادي'], $plan->units[1]);
        $this->assertSame(['كافيه', 'هادي', 'روف'], $plan->sources);
    }

    public function test_a_known_phrase_is_one_unit(): void
    {
        $plan = $this->search()->plan('فاست فود');

        $this->assertCount(1, $plan->units);
        $this->assertSame(['فاست فود'], $plan->sources);
        $this->assertContains('وجبات سريعه', $plan->units[0]);
    }

    public function test_a_phrase_next_to_other_words(): void
    {
        $plan = $this->search()->plan('اجمل فاست فود رخيص');

        $this->assertSame(['اجمل', 'فاست فود', 'رخيص'], $plan->sources);
    }

    public function test_one_letter_words_are_dropped_when_there_are_others(): void
    {
        $this->assertSame(['كافيه'], $this->search()->plan('و كافيه')->sources);
        // لو دي الكلمة الوحيدة بتفضل
        $this->assertSame(['و'], $this->search()->plan('و')->sources);
    }

    public function test_only_symbols_is_an_empty_plan(): void
    {
        $this->assertTrue($this->search()->plan('؟؟ !!')->isEmpty());
        $this->assertTrue($this->search()->plan('   ')->isEmpty());
        $this->assertTrue($this->search()->plan('%_|')->isEmpty());
    }

    public function test_the_number_of_words_is_capped(): void
    {
        $plan = $this->search()->plan('واحد اتنين تلاتة اربعة خمسة ستة سبعة تمانية');

        $this->assertCount(DatabasePlaceSearch::MAX_WORDS, $plan->units);
    }

    public function test_the_text_of_a_plan_is_what_is_searched(): void
    {
        $this->assertSame('كافيه روف', $this->search()->plan('الكافيه الروف')->text());
    }
}
