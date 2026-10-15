<?php

namespace App\Listeners;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * جرس اللوحة لشغل فشل في الخلفية (06 القسم 5): job خلّص محاولاته (صور، متوسط التقييم، أحداث، استيراد) وراح على
 * `failed_jobs`. من غير الجرس ده الفشل صامت: الأدمن مش بيفتح الداتابيز، والمستخدم شايف «بتتعالج» وخلاص.
 *
 * - الـ Admins الشغّالين بس، ومرة واحدة لكل نوع job في الـ`THROTTLE_MINUTES` (دفعة فاشلة كلها جرس واحد مش خمسين).
 * - الرسالة فيها اسم الـ job وأول سطر من الخطأ (مقصوص)، والتفاصيل الكاملة في `storage/logs` و`failed_jobs`.
 * - متزامن: صف أو اتنين في `notifications`، وفشل الإشعار نفسه ما بيكسرش الـ worker.
 *
 * `failed_jobs` بيتنضّف بعد 7 أيام (`queue:prune-failed` في `routes/console.php`).
 */
class NotifyAdminsOfFailedJob
{
    public const THROTTLE_MINUTES = 30;

    private const MESSAGE_MAX = 140;

    public function handle(JobFailed $event): void
    {
        try {
            $name = class_basename((string) ($event->job->payload()['displayName'] ?? $event->job->resolveName()));

            // مرة لكل نوع job في الفترة دي. `add` بترجّع false لو المفتاح موجود
            if (! Cache::add("failed-job-notified:{$name}", true, now()->addMinutes(self::THROTTLE_MINUTES))) {
                return;
            }

            $message = Str::limit(trim(Str::before($event->exception->getMessage(), "\n")), self::MESSAGE_MAX);
            $attempts = $event->job->attempts();

            $admins = User::query()->role('admin')->where('status', 'active')->get();

            foreach ($admins as $admin) {
                Notification::make()
                    ->danger()
                    ->title('شغل في الخلفية فشل: '.$name)
                    ->body(trim("{$message}\nاتحاول {$attempts} مرات. التفاصيل في سجل السيرفر (failed_jobs)."))
                    ->persistent()
                    ->sendToDatabase($admin);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
