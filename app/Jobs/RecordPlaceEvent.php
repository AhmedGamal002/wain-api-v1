<?php

namespace App\Jobs;

use App\Models\PlaceEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بيكتب حدث موبايل واحد في `place_events` (03 القسم 5.5): الـ endpoint بيرد 204 فوراً والكتابة على الـ queue،
 * فبطء الداتابيز أو الـ worker ما بيأخّرش الموبايل.
 *
 * `occurredAt` وقت الطلب مش وقت تشغيل الـ job: الـ worker على الاستضافة بيمشي كل دقيقة، فحدث 11:59:50 ممكن
 * يتنفّذ 12:00:10 ولازم يتحسب في يومه.
 *
 * حدث `view` بيتحسب مرة واحدة لكل جهاز في الساعة لكل مكان (إعادة فتح الصفحة والرجوع ليها مش مشاهدة جديدة).
 * الفحص هنا مش في الـ controller عشان الـ worker هو الوحيد اللي بيكتب في الجدول فمفيش سباق بين طلبين.
 */
class RecordPlaceEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $placeId,
        public readonly string $type,
        public readonly string $deviceId,
        public readonly ?int $userId,
        public readonly int $occurredAt,
    ) {}

    public function handle(): void
    {
        $at = Carbon::createFromTimestampUTC($this->occurredAt);

        if ($this->type === 'view' && $this->seenRecently($at)) {
            return;
        }

        // المكان ممكن يتمسح نهائياً بين الطلب والـ job (الـ FK هيرفض الصف): insertOrIgnore بيعدّيها من غير خطأ
        DB::table('place_events')->insertOrIgnore([
            'place_id' => $this->placeId,
            'type' => $this->type,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'created_at' => $at,
        ]);
    }

    /**
     * فيه `view` من نفس الجهاز لنفس المكان جوه ساعة (قبل أو بعد، لأن الـ jobs ممكن تتنفّذ بترتيب مختلف).
     */
    private function seenRecently(Carbon $at): bool
    {
        $window = PlaceEvent::VIEW_DEDUPE_MINUTES;

        return PlaceEvent::query()
            ->where('device_id', $this->deviceId)
            ->where('place_id', $this->placeId)
            ->where('type', 'view')
            ->where('created_at', '>', $at->copy()->subMinutes($window))
            ->where('created_at', '<', $at->copy()->addMinutes($window))
            ->exists();
    }
}
