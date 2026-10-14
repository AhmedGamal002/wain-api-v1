<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\SearchSuggestionController;
use App\Models\Place;
use App\Models\User;
use App\Support\Search\SynonymDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /search/suggestions` (02 F4): اقتراحات الكتابة. نفس بحث `GET /search` (التطبيع والمرادفات والتصحيح) بس
 * بصف صغير لكل مكان، ومن غير فلاتر، وبتفضّل بداية الكلمة على جوّاها.
 */
class SearchSuggestionsApiTest extends TestCase
{
    use RefreshDatabase;

    private const AR = ['Accept-Language' => 'ar'];

    private static int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SynonymDictionary::flush();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function place(string $nameAr, array $attributes = []): Place
    {
        self::$counter++;

        return Place::factory()->status(Place::STATUS_ACTIVE)->create([
            'name_ar' => $nameAr,
            'name_en' => 'Qz'.self::$counter.' Venue',
            ...$attributes,
        ]);
    }

    private function suggest(string $q, array $headers = self::AR): TestResponse
    {
        return $this->getJson('/api/v1/search/suggestions?'.http_build_query(['q' => $q]), $headers);
    }

    /**
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('data'), 'name');
    }

    public function test_it_suggests_places_by_what_is_typed_so_far(): void
    {
        $this->place('حديقة النرجس');
        $this->place('مطعم السلطان', ['name_en' => 'Sultan Grill']);

        $this->assertSame(['حديقة النرجس'], $this->names($this->suggest('حدي')));
        $this->assertSame(['حديقة النرجس'], $this->names($this->suggest('نرج')));
        $this->assertSame(['مطعم السلطان'], $this->names($this->suggest('sult')));
    }

    public function test_the_row_is_small_and_has_only_what_a_suggestion_shows(): void
    {
        $place = $this->place('حديقة النرجس');

        $row = $this->suggest('نرجس')->assertOk()->json('data.0');

        $this->assertSame(['id', 'slug', 'name', 'category', 'area', 'thumb'], array_keys($row));
        $this->assertSame($place->id, $row['id']);
        $this->assertSame($place->slug, $row['slug']);
        $this->assertSame(['name', 'icon'], array_keys($row['category']));
        $this->assertSame($place->primaryCategory->name_ar, $row['category']['name']);
        $this->assertSame($place->area->name_ar, $row['area']);
        $this->assertNull($row['thumb']);
    }

    public function test_the_thumb_is_the_ready_cover_variant_and_missing_while_processing(): void
    {
        $place = $this->place('حديقة النرجس');
        $uploader = User::factory()->create();

        $photoId = DB::table('place_photos')->insertGetId([
            'place_id' => $place->id,
            'path' => 'places/original.jpg',
            'variants' => json_encode(['thumb' => 'https://cdn.example.test/p/thumb.webp', 'medium' => 'https://cdn.example.test/p/medium.webp']),
            'status' => 'ready',
            'uploaded_by' => $uploader->id,
        ]);
        DB::table('places')->where('id', $place->id)->update(['cover_photo_id' => $photoId]);

        $this->assertSame('https://cdn.example.test/p/thumb.webp', $this->suggest('نرجس')->json('data.0.thumb'));

        DB::table('place_photos')->where('id', $photoId)->update(['status' => 'processing']);

        $this->assertNull($this->suggest('نرجس')->json('data.0.thumb'));
    }

    public function test_the_start_of_a_word_hides_matches_inside_a_word(): void
    {
        // «حديقة» بعد التطبيع «حديقه» وفيها «قه» في نصّها، بس «قه» بداية «قهوة» مش «حديقه»
        $this->place('قهوة المحطة');
        $this->place('حديقة الزهور');

        $this->assertSame(['قهوة المحطة'], $this->names($this->suggest('قه')));
    }

    public function test_looser_matches_show_when_no_name_starts_with_the_word(): void
    {
        $place = $this->place('مكان من غير كلمات مميزة', ['description_ar' => 'بيقدّم بيتزا لذيذة']);

        $this->assertSame([$place->name_ar], $this->names($this->suggest('بيتزا')));
    }

    public function test_a_name_that_starts_with_the_word_comes_before_one_that_has_it_later(): void
    {
        $later = $this->place('حبة قهوة');
        $first = $this->place('قهوة المحطة');

        $this->assertSame([$first->name_ar, $later->name_ar], $this->names($this->suggest('قهوة')));
    }

    public function test_the_typed_word_comes_before_its_synonyms(): void
    {
        $synonym = $this->place('قهوة المحطة');
        $typed = $this->place('نسمة كافيه');

        // «قهوة» مرادف «كافيه»، فالاتنين بيتطابقوا، والاسم اللي فيه كلمة المستخدم نفسها الأول
        $this->assertSame([$typed->name_ar, $synonym->name_ar], $this->names($this->suggest('كافيه')));
    }

    public function test_the_article_does_not_stop_a_name_from_starting_with_the_word(): void
    {
        $this->place('البحر الأزرق');
        $this->place('مطعم بحر الخير');

        // «بحر» بداية «البحر» (بعد «ال») وبداية «بحر» في التاني، فالاتنين بداية كلمة
        $this->assertEqualsCanonicalizing(['البحر الأزرق', 'مطعم بحر الخير'], $this->names($this->suggest('بحر')));
    }

    public function test_a_misspelled_word_is_corrected_and_reported(): void
    {
        $this->place('حديقة النرجس');
        $this->place('مكان تاني');

        $response = $this->suggest('حديقة ترجس')->assertOk();

        $this->assertSame(['حديقة النرجس'], array_column($response->json('data'), 'name'));
        $this->assertSame('حديقه نرجس', $response->json('meta.corrected_query'));
    }

    public function test_a_correct_word_is_not_corrected(): void
    {
        $this->place('حديقة النرجس');

        $this->assertNull($this->suggest('نرجس')->json('meta.corrected_query'));
    }

    public function test_at_most_six_suggestions_come_back_best_first(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->place("مطعم السلطان {$i}");
        }
        $best = $this->place('السلطان');

        $names = $this->names($this->suggest('سلطان'));

        $this->assertCount(SearchSuggestionController::LIMIT, $names);
        $this->assertSame($best->name_ar, $names[0]);
    }

    public function test_only_published_places_are_suggested(): void
    {
        $this->place('مكان منشور سلطان');

        foreach ([Place::STATUS_DRAFT, Place::STATUS_PENDING, Place::STATUS_HIDDEN, Place::STATUS_REJECTED, Place::STATUS_CLOSED] as $status) {
            Place::factory()->status($status)->create(['name_ar' => "مكان {$status} سلطان", 'name_en' => "Qz {$status}"]);
        }

        $this->place('مكان محذوف سلطان')->delete();

        $this->assertSame(['مكان منشور سلطان'], $this->names($this->suggest('سلطان')));
    }

    public function test_names_follow_the_request_language(): void
    {
        $this->place('حديقة النرجس', ['name_en' => 'Narges Garden']);

        $this->assertSame(['Narges Garden'], $this->names($this->suggest('narges', ['Accept-Language' => 'en'])));
        $this->assertSame(['حديقة النرجس'], $this->names($this->suggest('narges', self::AR)));
    }

    public function test_the_word_is_required_and_between_two_and_sixty_characters(): void
    {
        $this->getJson('/api/v1/search/suggestions', self::AR)->assertStatus(422)->assertJsonValidationErrors('q');
        $this->suggest('ح')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->suggest(str_repeat('ح', 61))->assertStatus(422)->assertJsonValidationErrors('q');
        $this->suggest('حد')->assertOk();
        $this->suggest(str_repeat('ح', 60))->assertOk();
    }

    public function test_a_word_of_symbols_only_returns_an_empty_list_not_everything(): void
    {
        $this->place('حديقة النرجس');

        $this->assertSame([], $this->suggest('!?')->assertOk()->json('data'));
        $this->assertSame([], $this->suggest('%%')->assertOk()->json('data'));
    }

    public function test_nothing_matching_returns_an_empty_list(): void
    {
        $this->place('حديقة النرجس');

        $response = $this->suggest('xyzqq')->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertNull($response->json('meta.corrected_query'));
    }

    public function test_it_is_public_and_a_token_changes_nothing(): void
    {
        $this->place('حديقة النرجس');
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $guest = $this->suggest('نرجس')->assertOk()->json('data');
        $this->app['auth']->forgetGuards();
        $signedIn = $this->suggest('نرجس', self::AR + ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');

        $this->assertSame($guest, $signedIn);
    }
}
