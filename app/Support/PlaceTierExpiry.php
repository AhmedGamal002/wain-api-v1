<?php

namespace App\Support;

use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * انتهاء الباقات (03 القسم 7.4، 4.9): الـ job اليومي (`places:expire-tiers`) بيرجّع الباقة المنتهية free وبينبّه
 * الأدمنز قبل الانتهاء بـ 7 أيام.
 *
 * التجديد: لو الأدمن مدّد الباقة قبل ما تنتهي (`tier_ends_at` بقى في المستقبل) الـ job ما بيلمسهاش.
 */
final class PlaceTierExpiry
{
    /** الأدمنز بيتنبّهوا قبل الانتهاء بالمدة دي (يوم واحد بس، لحد ما الباقة تنتهي). */
    public const WARN_DAYS = 7;

    private const TZ = 'Africa/Cairo';

    private const TIER_LABELS = ['featured' => 'مميز', 'premium' => 'بريميوم'];

    /**
     * بيرجّع الأماكن اللي `tier_ends_at` بتاعها عدّى لـ free من غير تواريخ، والسجل بيقول إنها انتهت لوحدها
     * (من غير مستخدم). الحفظ من الموديل: الـ Observer بيظبط `tier_rank` وبيطلق `PlaceChanged` (الـ cache والترتيب).
     * مكان واحد بيفشل بيتسجّل (`report`) وما بيوقّفش الباقي.
     */
    public function expire(?Carbon $now = null): int
    {
        $now ??= now();
        $expired = 0;

        Place::query()
            ->where('listing_tier', '!=', 'free')
            ->whereNotNull('tier_ends_at')
            ->where('tier_ends_at', '<', $now)
            ->eachById(function (Place $place) use (&$expired): void {
                try {
                    $place->logNote = 'انتهت مدة الباقة';
                    $place->forceFill(['listing_tier' => 'free', 'tier_starts_at' => null, 'tier_ends_at' => null])->save();
                    $place->logNote = null;
                    $expired++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $expired;
    }

    /**
     * إشعار الجرس للأدمنز النشطين لكل باقة بتنتهي بعد 7 أيام بالظبط (بتوقيت القاهرة). الـ job بيشتغل مرة
     * واحدة في اليوم، فكل باقة بتتنبّه مرة واحدة من غير ما نخزّن حالة. باقة مدتها أقل من 7 أيام ما بتتنبّهش.
     *
     * @return int عدد الباقات اللي اتنبّه عليها
     */
    public function warn(?Carbon $now = null): int
    {
        $day = ($now ?? now())->copy()->timezone(self::TZ)->addDays(self::WARN_DAYS);

        $places = Place::query()
            ->where('listing_tier', '!=', 'free')
            ->whereBetween('tier_ends_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('id')
            ->get();

        if ($places->isEmpty()) {
            return 0;
        }

        $admins = User::query()->role('admin')->where('status', 'active')->get();

        foreach ($places as $place) {
            foreach ($admins as $admin) {
                try {
                    $this->notification($place)->sendToDatabase($admin);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $places->count();
    }

    private function notification(Place $place): Notification
    {
        $tier = self::TIER_LABELS[$place->listing_tier] ?? $place->listing_tier;
        $ends = $place->tier_ends_at->copy()->timezone(self::TZ)->toDateString();

        return Notification::make()
            ->title("باقة «{$tier}» لمكان «{$place->name_ar}» بتنتهي يوم {$ends}")
            ->body('جدّد الباقة قبل التاريخ ده، أو هترجع مجانية لوحدها.')
            ->warning()
            ->actions([
                Action::make('open')
                    ->label('افتح')
                    // panel صريح: الـ job من الـ scheduler ومفيش panel حالي
                    ->url(PlaceResource::getUrl('edit', ['record' => $place], panel: 'admin'))
                    ->markAsRead(),
            ]);
    }
}
