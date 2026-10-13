<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Resources\Places\Pages\Concerns\HandlesPlaceSave;
use App\Filament\Resources\Places\PlaceResource;
use App\Filament\Resources\Places\PlaceSaveIntent;
use App\Filament\Resources\Places\Schemas\PlaceFormData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

/**
 * إضافة مكان (03 القسم 4.3). الإضافة دايماً مسودة: «حفظ كمسودة» بيفتح صفحة التعديل، و«حفظ وإضافة مكان تاني»
 * بيفتح فورم جديد ونفس المدينة والمنطقة متعبيين. الإرسال للمراجعة والنشر من صفحة التعديل بعد ما الصور
 * (صورة الغلاف شرط نشر) تتضاف، فمفيش زرار نشر هنا.
 */
class CreatePlace extends CreateRecord
{
    use HandlesPlaceSave;

    protected static string $resource = PlaceResource::class;

    protected function defaultSaveIntent(): PlaceSaveIntent
    {
        return PlaceSaveIntent::Draft;
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(PlaceFormData::forCreate());

        $this->callHook('afterFill');
    }

    protected function beforeCreate(): void
    {
        $this->guardPlaceSave(null);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->placeColumns($data, null);
    }

    /** بعد ما المكان والتصنيفات اتحفظوا. */
    protected function afterCreate(): void
    {
        $this->syncPlaceRelations($this->getRecord());
    }

    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, ['city_id', 'area_id']);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return PlaceSaveIntent::Draft->savedTitle();
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('حفظ كمسودة'),
            $this->getCreateAnotherFormAction()->label('حفظ وإضافة مكان تاني'),
            $this->getCancelFormAction(),
        ];
    }

    // الأزرار بتنادي على نفس الميثود، فبنأكّد إن الزرار هو الـ intent
    public function create(bool $another = false): void
    {
        $this->saveIntent = PlaceSaveIntent::Draft;

        parent::create($another);
    }
}
