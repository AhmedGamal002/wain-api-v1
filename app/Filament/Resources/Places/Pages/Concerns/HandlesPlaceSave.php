<?php

namespace App\Filament\Resources\Places\Pages\Concerns;

use App\Events\PlaceChanged;
use App\Filament\Resources\Places\PlaceSaveIntent;
use App\Filament\Resources\Places\Schemas\PlaceForm;
use App\Filament\Resources\Places\Schemas\PlaceFormData;
use App\Models\Attribute;
use App\Models\Place;
use App\Support\PlacePublishCheck;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

/**
 * منطق الحفظ المشترك بين صفحتي الإضافة والتعديل: التحقق حسب الزرار (`PlaceSaveIntent`)، والحالة الجديدة،
 * ومجموعات الخصائص، وإطلاق `PlaceChanged` بعد ما كل العلاقات اتحفظت.
 *
 * شروط النشر نفسها من `PlacePublishCheck` (ماتتكررش هنا): اللقطة بتتبني من حالة الفورم بـ `PlaceForm::snapshot`.
 */
trait HandlesPlaceSave
{
    /** مفاتيح شروط النشر اللي منعت آخر حفظ (`PlacePublishCheck::CONDITIONS`): بتظهر كنقطة حمرا على التابز. */
    public array $missingConditions = [];

    protected ?PlaceSaveIntent $saveIntent = null;

    /** الإجراء اللي بيحصل لو الفورم اتبعت من غير زرار بعينه (Enter). */
    abstract protected function defaultSaveIntent(): PlaceSaveIntent;

    protected function currentSaveIntent(): PlaceSaveIntent
    {
        return $this->saveIntent ?? $this->defaultSaveIntent();
    }

    /** ديما بنحفظ جوه transaction: منع الحفظ بسبب شرط ناقص لازم يرجّع كل حاجة (العلاقات كمان). */
    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    /**
     * يتحقق من الحفظ حسب الزرار، ولو ممنوع بيبعت رسالة بتسمّي الناقص ويوقف الحفظ (rollback).
     * بيتنادى بعد validation الحقول وقبل أي كتابة.
     */
    protected function guardPlaceSave(?Place $record): void
    {
        $intent = $this->currentSaveIntent();
        $this->missingConditions = [];

        $user = auth()->user();
        $target = $record ?? new Place;

        if ($intent === PlaceSaveIntent::Publish) {
            abort_unless($user !== null && Gate::forUser($user)->allows('publish', $target), 403);
        }

        if ($intent === PlaceSaveIntent::Submit) {
            abort_unless($user !== null && Gate::forUser($user)->allows('submit', $target), 403);
        }

        $after = PlacePublishCheck::check(PlaceForm::snapshot($this->data, $record));

        if ($intent->requiresCompletePlace() && ! $after->isComplete()) {
            $this->missingConditions = $after->missing();

            $this->failSave(
                $intent === PlaceSaveIntent::Publish
                    ? $after->message()
                    : 'ما ينفعش يتبعت للمراجعة. ناقص: '.implode('، ', $after->missingLabels()),
            );
        }

        // «حفظ» على مكان في حالة تانية: ممنوع يكسر شرط كان متحقق (اللي كان ناقص قبل كده ما بيمنعش)
        if ($intent === PlaceSaveIntent::Keep && $record?->exists) {
            $before = PlacePublishCheck::for($record);
            $broken = array_values(array_diff($after->missing(), $before->missing()));

            if ($broken !== []) {
                $this->missingConditions = $broken;

                $this->failSave('الحفظ ده هيكسر شرط نشر: '.implode('، ', array_map(
                    fn (string $key) => PlacePublishCheck::LABELS[$key],
                    $broken,
                )));
            }
        }
    }

    /**
     * أعمدة المكان من ناتج الفورم + الحالة الجديدة حسب الزرار.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function placeColumns(array $data, ?Place $record): array
    {
        $data = PlaceFormData::forSave($data, $this->data);
        $intent = $this->currentSaveIntent();

        $status = $intent->targetStatus($record?->status);
        if ($status !== null) {
            $data['status'] = $status;
        }

        // الإرسال والنشر بيمسحوا سبب الرفض القديم
        if ($intent->requiresCompletePlace()) {
            $data['status_reason'] = null;
        }

        return $data;
    }

    /**
     * مجموعات الخصائص وإطلاق `PlaceChanged`. بيتنادى بعد حفظ المكان وعلاقاته: ده اللي بيخلّي الـ listeners
     * (cache التفاصيل و`place_hours` و`search_text` عند معلوم) تشوف التصنيفات والخصائص الجديدة.
     *
     * الخاصية المخفية (`is_active = false`) مش ظاهرة في الفورم، فبتتحافظ عليها بدل ما الـ sync يشيلها.
     */
    protected function syncPlaceRelations(Place $place): void
    {
        $selected = Attribute::query()
            ->whereIn('id', PlaceForm::attributeIds($this->data))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $hidden = $place->placeAttributes()
            ->where('attributes.is_active', false)
            ->pluck('attributes.id')
            ->all();

        $place->placeAttributes()->sync(array_values(array_unique([...$selected, ...$hidden])));

        PlaceChanged::dispatch($place->refresh());
    }

    private function failSave(string $message): never
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->persistent()
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }
}
