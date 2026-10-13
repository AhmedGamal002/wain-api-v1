<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\Pages\Concerns\HandlesPlaceSave;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\Places\PlaceSaveIntent;
use App\Filament\Resources\Places\Schemas\PlaceFormData;
use App\Models\Place;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * تعديل المكان (03 القسم 4.3). أزرار الحفظ حسب الحالة:
 *   مسودة / مرفوض: «حفظ كمسودة» (المرفوض بيرجع مسودة) + «حفظ وإرسال للمراجعة» + «حفظ ونشر» (Admin).
 *   قيد المراجعة:  «حفظ» + «حفظ ونشر» (Admin).
 *   منشور / مخفي / مغلق: «حفظ» بس، وممنوع يكسر شرط نشر.
 */
class EditPlace extends EditRecord
{
    use HandlesPlaceSave;

    protected static string $resource = PlaceResource::class;

    protected function defaultSaveIntent(): PlaceSaveIntent
    {
        return $this->isDraftLike() ? PlaceSaveIntent::Draft : PlaceSaveIntent::Keep;
    }

    /** مسودة أو مرفوض: أي حفظ بيرجّعه مسودة (جدول الانتقالات في 03 القسم 2). */
    private function isDraftLike(): bool
    {
        return in_array($this->getRecord()->status, [Place::STATUS_DRAFT, Place::STATUS_REJECTED], true);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PlaceFormData::forFill($data, $this->getRecord());
    }

    /** بعد validation الحقول وقبل أي كتابة. */
    protected function beforeSave(): void
    {
        $this->guardPlaceSave($this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->placeColumns($data, $this->getRecord());
    }

    protected function afterSave(): void
    {
        $this->syncPlaceRelations($this->getRecord());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->currentSaveIntent()->savedTitle();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            $this->submitAction(),
            $this->publishAction(),
            $this->getCancelFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label(fn () => $this->isDraftLike() ? 'حفظ كمسودة' : 'حفظ');
    }

    private function submitAction(): Action
    {
        return Action::make('saveAndSubmit')
            ->label('حفظ وإرسال للمراجعة')
            ->color('gray')
            ->visible(fn () => $this->isDraftLike() && auth()->user()?->can('submit', $this->getRecord()))
            ->action(function () {
                $this->saveIntent = PlaceSaveIntent::Submit;
                $this->save();
            });
    }

    private function publishAction(): Action
    {
        return Action::make('saveAndPublish')
            ->label('حفظ ونشر')
            ->color('success')
            ->visible(fn () => in_array($this->getRecord()->status, [Place::STATUS_DRAFT, Place::STATUS_PENDING, Place::STATUS_REJECTED], true)
                && auth()->user()?->can('publish', $this->getRecord()))
            ->action(function () {
                $this->saveIntent = PlaceSaveIntent::Publish;
                $this->save();
            });
    }
}
