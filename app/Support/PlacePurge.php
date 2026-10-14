<?php

namespace App\Support;

use App\Models\Place;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * المسح النهائي للمحذوف بعد `Place::PURGE_AFTER_DAYS` (03 «جدول الانتقالات»، 4.8: `forceDelete` ممنوع لكل
 * الأدوار ومن الـ job بس). الـ job اليومي (`places:purge-deleted`) هو المستخدم الوحيد.
 *
 * بيمسح المكان وكل اللي ليه بالـ cascade (الصور والتصنيفات والخصائص والمواعيد والأحداث والإحصائيات والـ
 * redirects). سجل التغييرات (`activity_log`) بيفضل: هو أثر الحذف نفسه.
 *
 * الملفات (الأصل والنسخ) بتتمسح بعد ما الصف يتمسح، فلو مسحها فشل بيفضل ملف يتيم بس، مش صف بيشاور على ملف
 * مش موجود. مجلد المكان كله (`places/{id}`) بيتمسح من الاتنين disks، فبيشيل كمان اللي فشلت معالجته.
 */
final class PlacePurge
{
    /**
     * @return int عدد الأماكن اللي اتمسحت
     */
    public function run(): int
    {
        $purged = 0;

        Place::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(Place::PURGE_AFTER_DAYS))
            ->eachById(function (Place $place) use (&$purged): void {
                try {
                    $this->purge($place);
                    $purged++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $purged;
    }

    /**
     * يمسح مكان محذوف نهائياً بصوره. لو المكان مش محذوف (soft) بيرمي: ماينفعش يتمسح منشور بالغلط.
     */
    public function purge(Place $place): void
    {
        if (! $place->trashed()) {
            throw new \LogicException("المكان {$place->id} مش محذوف، ما ينفعش يتمسح نهائياً");
        }

        $id = $place->id;

        DB::transaction(fn () => $place->forceDelete());

        $this->deleteFiles($id);
    }

    private function deleteFiles(int $placeId): void
    {
        $directory = "places/{$placeId}";

        foreach (array_unique([config('wain.photos.original_disk'), config('wain.photos.disk')]) as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory($directory);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
