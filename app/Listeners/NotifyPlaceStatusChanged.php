<?php

namespace App\Listeners;

use App\Events\PlaceStatusChanged;
use App\Filament\Resources\Places\PlaceResource;
use App\Models\Place;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * إشعارات الجرس في اللوحة لتغيّر حالة المكان (03 القسم 4.9):
 *   - اتبعت للمراجعة: كل الـ Admins (غير اللي بعت).
 *   - اتنشر (من قيد المراجعة) أو اترفض: اللي أضافه (لو مش هو اللي عمل التغيير).
 *
 * متزامن: كتابة صف أو اتنين في `notifications`، ومش بتعطّل الحفظ. فشل الإشعار ما بيوقّفش تغيير الحالة.
 */
class NotifyPlaceStatusChanged
{
    public function handle(PlaceStatusChanged $event): void
    {
        $place = $event->place;

        try {
            match ($event->to) {
                Place::STATUS_PENDING => $this->toAdmins($place, $event),
                Place::STATUS_ACTIVE => $event->from === Place::STATUS_PENDING ? $this->toCreator($place, $event, 'مكانك «'.$place->name_ar.'» اتنشر', null, true) : null,
                Place::STATUS_REJECTED => $this->toCreator($place, $event, 'مكانك «'.$place->name_ar.'» اترفض', $place->status_reason, false),
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function toAdmins(Place $place, PlaceStatusChanged $event): void
    {
        $admins = User::query()
            ->role('admin')
            ->where('status', 'active')
            ->when($event->actorId !== null, fn ($query) => $query->whereKeyNot($event->actorId))
            ->get();

        foreach ($admins as $admin) {
            $this->notification('مكان جديد مستني مراجعة: '.$place->name_ar, null, $place, false)->sendToDatabase($admin);
        }
    }

    private function toCreator(Place $place, PlaceStatusChanged $event, string $title, ?string $body, bool $success): void
    {
        if ($place->created_by === null || $place->created_by === $event->actorId) {
            return;
        }

        $creator = User::query()->find($place->created_by);

        if ($creator === null) {
            return;
        }

        $this->notification($title, $body, $place, $success)->sendToDatabase($creator);
    }

    private function notification(string $title, ?string $body, Place $place, bool $success): Notification
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->actions([
                Action::make('open')
                    ->label('افتح')
                    // panel صريح: الإشعار ممكن يتبعت من CLI أو الـ queue ومفيش panel حالي
                    ->url(PlaceResource::getUrl('edit', ['record' => $place], panel: 'admin'))
                    ->markAsRead(),
            ]);

        return $success ? $notification->success() : $notification->warning();
    }
}
