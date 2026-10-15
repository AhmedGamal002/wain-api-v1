<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPlaceEventRequest;
use App\Jobs\RecordPlaceEvent;
use App\Models\Place;
use Illuminate\Http\Response;

/**
 * @group Places
 */
class PlaceEventController extends Controller
{
    /**
     * Record a place event
     *
     * تسجيل حدث للمكان (ANL-02): مشاهدة الصفحة أو ضغطة على اتجاهات أو اتصال أو واتساب أو إنستجرام أو المنيو
     * أو المشاركة. بتغذّي إحصائيات المكان (لوحة الأدمن والتقارير، وصاحب المكان بعد كده).
     *
     * - **من غير دخول:** الطلب بيتقبل من زائر. لو فيه token صالح بيتسجّل `user_id` كمان.
     * - **`X-Device-Id` إلزامي** (UUID): من غيره أو بشكل غلط 422. الـ id عشوائي بيتولّد من التطبيق، مش مربوط بأي
     *   بيانات شخصية، والموقع ما بيتبعتش مع الحدث. الأحداث الخام بتتمسح بعد 90 يوم والمتجمّع بيفضل.
     * - **`view`:** بيتحسب مرة واحدة لكل جهاز لكل مكان في الساعة، والموبايل بيبعته مرة لكل فتحة صفحة. الباقي
     *   كل ضغطة حدث.
     * - **الكتابة على الـ queue**، فالرد 204 فوراً والحدث بيبان في الإحصائيات بعد دقيقة أو دقيقتين. الموبايل
     *   بيبعت من غير ما يستنّى ولا يعرض خطأ لو فشل.
     * - المكان المخفي أو المسودة أو المحذوف أو مش موجود: 404 `PLACE_NOT_AVAILABLE` زي صفحة المكان.
     * - الحد الأقصى 60 حدث في الدقيقة للجهاز أو المستخدم، وبره الحد العام للطلبات عشان التتبّع ما ياكلش من
     *   ميزانية التصفّح. وفوقه سقف أوسع على الـ IP كله (600 في الدقيقة)، فتغيير الـ `X-Device-Id` في كل طلب
     *   مش بيفتح ميزانية جديدة (`429 TOO_MANY_REQUESTS`).
     *
     * @urlParam slug string required slug المكان. Example: el-mahatta-coffee
     *
     * @header X-Device-Id 3f6c1c2e-8c1a-4b6f-9d55-2f5b8c0e7a11
     *
     * @response 204 scenario="اتسجّل" {}
     * @response 404 scenario="مخفي أو مسودة أو محذوف أو مش موجود" {"message": "المكان مش متاح دلوقتي.", "code": "PLACE_NOT_AVAILABLE"}
     * @response 422 scenario="من غير X-Device-Id أو نوع غلط" {"message": "...", "code": "VALIDATION_ERROR", "errors": {"device_id": ["..."]}}
     */
    public function store(RecordPlaceEventRequest $request, string $slug): Response
    {
        $placeId = Place::viewableIdForSlug($slug);

        if ($placeId === null) {
            throw new ApiException(404, 'PLACE_NOT_AVAILABLE');
        }

        RecordPlaceEvent::dispatch(
            $placeId,
            $request->eventType(),
            $request->deviceId(),
            $request->user('sanctum')?->getAuthIdentifier(),
            now()->getTimestamp(),
        );

        return response()->noContent();
    }
}
