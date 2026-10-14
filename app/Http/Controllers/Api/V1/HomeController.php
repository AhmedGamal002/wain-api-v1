<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\HomeRequest;
use App\Http\Resources\PlaceCardResource;
use App\Support\NearbyPlaces;
use App\Support\OpenNow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * @group Home
 */
class HomeController extends Controller
{
    /**
     * «قريب منك» في الرئيسية: أقرب 10 أماكن جوه 30 كم (أقصى نصف قطر في `GET /places/nearby`)، فالمستخدم البعيد
     * عن التغطية بيشوف أقرب أماكن بمسافتها الحقيقية بدل شريط فاضي، والموبايل هو اللي بيقرر يقول «أقرب مكان على
     * بعد X كم».
     */
    public const NEARBY_RADIUS_KM = 30.0;

    public const NEARBY_LIMIT = 10;

    /**
     * Home
     *
     * الرئيسية في request واحد (F3): المجموعات المنتقاة للمنطقة و«قريب منك». التصنيفات والفلاتر وقايمة «كل
     * الأماكن» مش هنا: شريط التصنيفات من `GET /categories` والقايمة من `GET /places` بالتحميل المستمر.
     *
     * - **`collections`:** المجموعات الظاهرة للمنطقة (A5). بعد الإطلاق (P6)، فبترجع قايمة فاضية لحد ما A5
     *   يتعمل، والموبايل بيخفي الجزء ده لو فاضي.
     * - **`nearby`:** لحد 10 أماكن منشورة جوه 30 كم من `lat`/`lng` بالأقرب أول، بنفس كارت `GET /places` ومعاه
     *   `distance_km`. قايمة فاضية من غير `lat`/`lng` (الموبايل بيخفي «قريب منك» بالكامل لو الموقع مش متاح).
     * - `is_saved` و`distance_km` و`is_open_now` بتتحسب مع كل طلب (مش في cache).
     * - `area` لازم تكون منطقة فعّالة؛ غير كده 422 والموبايل بيطلب اختيار منطقة تاني.
     *
     * @response 200 {"data": {"collections": [], "nearby": []}}
     */
    public function index(HomeRequest $request): JsonResponse
    {
        $request->attributes->set(PlaceCardResource::OPEN_NOW_ATTRIBUTE, OpenNow::at());

        $nearby = $request->hasLocation()
            ? NearbyPlaces::find($request->lat(), $request->lng(), self::NEARBY_RADIUS_KM, self::NEARBY_LIMIT)
            : new Collection;

        return response()->json([
            'data' => [
                'collections' => [],
                'nearby' => PlaceCardResource::collection($nearby)->resolve($request),
            ],
        ]);
    }
}
