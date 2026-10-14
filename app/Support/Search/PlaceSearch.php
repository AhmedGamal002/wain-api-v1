<?php

namespace App\Support\Search;

use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;

/**
 * البحث في الأماكن (02 F4). الـ API (`GET /search`) بيتكلّم مع الـ interface ده بس، فلو الاستضافة اتغيّرت
 * لـ VPS نضيف driver لـ Meilisearch من غير تغيير في الـ endpoint ولا الموبايل. الحالي على الداتابيز
 * (`DatabasePlaceSearch`) لإن الاستضافة المشتركة مفيهاش محرك بحث.
 */
interface PlaceSearch
{
    /** لو نتايج البحث أقل من العدد ده بنجرّب تصحيح الكلمات (02 F4). */
    public const MIN_RESULTS_WITHOUT_CORRECTION = 3;

    /**
     * يحلّل كلمة المستخدم: تطبيع ثم توسيع المرادفات. الخطة الفاضية (علامات ترقيم بس) معناها مفيش نتايج.
     */
    public function plan(string $query): SearchPlan;

    /**
     * يضيّق استعلام الأماكن (اللي فيه الفلاتر والنشر) بالخطة، ويضيف عمود `relevance` (أكبر = أقرب) للترتيب.
     *
     * @param  Builder<Place>  $places
     */
    public function constrain(Builder $places, SearchPlan $plan): void;

    /**
     * هل المكان ده (جاي من استعلام اتضيّق بـ `constrain`) كل كلمة فيه هي بداية كلمة في اسمه أو الـ alias بتاعه؟
     * اقتراحات الكتابة بتفضّل دول وبتشيل الباقي لو في منهم.
     */
    public function isPrefixMatch(Place $place, SearchPlan $plan): bool;

    /**
     * خطة بكلمات مصحّحة إملائياً، أو null لو مفيش حاجة تتصحّح.
     */
    public function correct(SearchPlan $plan): ?SearchPlan;
}
