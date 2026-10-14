<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchSuggestionsRequest;
use App\Http\Resources\PlaceSuggestionResource;
use App\Models\Place;
use App\Support\PlaceListOrder;
use App\Support\Search\PlaceSearch;
use App\Support\Search\SearchPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * @group Places
 */
class SearchSuggestionController extends Controller
{
    /** عدد الاقتراحات: بيملا الشاشة فوق الكيبورد من غير scroll. */
    public const LIMIT = 6;

    public function __construct(private readonly PlaceSearch $search) {}

    /**
     * Search suggestions
     *
     * اقتراحات سريعة وهو بيكتب (typeahead): أحسن 6 أماكن منشورة لللي اتكتب لحد دلوقتي، بصف صغير لكل مكان (الاسم
     * والتصنيف والمنطقة وصورة مصغّرة). الموبايل بيعرضها تحت حقل البحث، والضغط على اقتراح بيفتح المكان مباشرة، والنتايج
     * الكاملة (كروت وفلاتر وترتيب) من `GET /search` لما المستخدم يدوس بحث.
     *
     * - **نفس البحث:** نفس التطبيع والمرادفات والتصحيح الإملائي والترتيب بتاع `GET /search` (بداية كلمة في الاسم
     *   قبل جوه الاسم قبل الباقي)، فالاقتراحات متتناقضش مع النتايج. `meta.corrected_query` بيرجع بالكلمة المصحّحة
     *   لو اتصحّحت.
     * - **من غير فلاتر ولا pagination ولا عدّاد:** استعلام واحد صغير بدل استعلام النتايج كله، عشان يتبعت مع كل وقفة في
     *   الكتابة.
     * - **حد خاص بيه:** مش بيتحسب على الحد العام للتطبيق (60 زائر / 120 مستخدم في الدقيقة)، وله حده الواسع
     *   (`WAIN_RATE_LIMIT_SUGGEST_GUEST`/`_USER`، 120 و240)، فكتابة كتير ما تحجبش الرئيسية ولا صفحة المكان.
     * - علامات الترقيم بس (أو كلمة من غير حروف) بترجّع قايمة فاضية مش خطأ.
     *
     * @response 200 scenario="اقتراحات" {"data": [{"id": 26, "slug": "narges-garden", "name": "حديقة النرجس", "category": {"name": "حدائق ومتنزهات", "icon": "🌳"}, "area": "التجمع الخامس", "thumb": null}], "meta": {"corrected_query": null}}
     */
    public function index(SearchSuggestionsRequest $request): JsonResponse
    {
        $plan = $this->search->plan($request->searchQuery());

        if ($plan->isEmpty()) {
            return $this->respond($request, new Collection, null);
        }

        $places = $this->places($plan);
        $correctedQuery = null;

        if ($places->count() < PlaceSearch::MIN_RESULTS_WITHOUT_CORRECTION) {
            $fixed = $this->search->correct($plan);

            if ($fixed !== null) {
                $retry = $this->places($fixed);

                // زي `GET /search`: التصحيح بيتقبل بس لو جاب أكتر من اللي المستخدم كتبه
                if ($retry->count() > $places->count()) {
                    $places = $retry;
                    $correctedQuery = $fixed->text();
                }
            }
        }

        return $this->respond($request, $places, $correctedQuery);
    }

    /**
     * @return Collection<int, Place>
     */
    private function places(SearchPlan $plan): Collection
    {
        $query = Place::query()
            ->published()
            // الأعمدة اللي الصف بيعرضها بس (من غير `search_text` والوصف والمواعيد)
            ->with(['primaryCategory:id,name_ar,name_en,icon', 'area:id,name_ar,name_en', 'coverPhoto:id,status,variants'])
            ->select([
                'places.id', 'places.slug', 'places.name_ar', 'places.name_en',
                'places.primary_category_id', 'places.area_id', 'places.cover_photo_id',
            ]);

        $this->search->constrain($query, $plan);

        // التطابق الأول (اللي كلماته كلها بدايات كلمات في الاسم أو الـ alias بياخد درجة أعلى بكتير)، ثم الترتيب المقترح
        $query->orderByDesc('relevance');
        PlaceListOrder::apply($query, 'recommended');

        $places = $query->limit(self::LIMIT)->get();

        // «قه» بتطلّع «قهوة المحطة» و«حبة قهوة»، مش «حديقة» (ة = ه بتخلّيها فيها «قه» في نص كلمة): لو في تطابق بداية
        // كلمة، الباقي بيتشال. من غيره (كافيه هادي) الاقتراحات بتفضل بالتطابق الأوسع بدل ما تفضى.
        $prefix = $places->filter(fn (Place $place) => $this->search->isPrefixMatch($place, $plan));

        return $prefix->isEmpty() ? $places : $prefix->values();
    }

    /**
     * @param  Collection<int, Place>  $places
     */
    private function respond(SearchSuggestionsRequest $request, Collection $places, ?string $correctedQuery): JsonResponse
    {
        return response()->json([
            'data' => PlaceSuggestionResource::collection($places)->resolve($request),
            'meta' => ['corrected_query' => $correctedQuery],
        ]);
    }
}
